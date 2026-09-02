<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Optional read-only source of backing-data or runtime facts for the shared
 * system model. Providers return the same structured object families as the
 * static model and must retain their own evidence provenance.
 */
interface AtumSystemModelProvider
{
    /** @return array{interfaces?:list<array<string,mixed>>,destinations?:list<array<string,mixed>>,gaps?:list<string>} */
    public function provide(array $discovery): array;
}

/**
 * Builds an operator-facing description of the adopted SIP system from
 * scanner facts and semantic/control-flow interpretation.
 *
 * This is deliberately above the Kamailio parser. UI modules consume this
 * stable model rather than route labels, rendered Discovery HTML, or raw
 * configuration syntax.
 */
final class AtumKamailioSystemModel
{
    /** @var list<AtumSystemModelProvider> */
    private array $providers;

    /** @param list<AtumSystemModelProvider> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof AtumSystemModelProvider) {
                throw new InvalidArgumentException('System Model providers must implement AtumSystemModelProvider');
            }
        }
        $this->providers = $providers;
    }

    /** @return array<string,mixed> */
    public function build(array $report): array
    {
        $processing = $report['presentation']['request_processing'] ?? ['flows' => [], 'edges' => [], 'coverage' => []];
        $system = $report['presentation']['system'] ?? ['listeners' => [], 'composition' => [], 'confidence' => []];
        $mediaPresentation = $report['presentation']['media'] ?? ['available' => false, 'used_in_flow' => false, 'module' => null, 'stages' => []];
        $allSteps = $this->allSteps($processing);
        $reachable = $this->reachableRequestSteps($processing);
        $interfaces = $this->interfaces($system['listeners'] ?? []);
        $destinations = $this->destinations($reachable, $report);
        $access = $this->access($reachable);
        $journeys = $this->journeys($processing, $access);
        $media = $this->media($mediaPresentation, $journeys);
        $customComponents = $this->customComponents($processing, $system, (string) ($report['root'] ?? ''));

        $providerGaps = [];
        $providerEvidence = false;
        foreach ($this->providers as $provider) {
            $contribution = $provider->provide($report);
            foreach (($contribution['interfaces'] ?? []) as $interface) { $interfaces[] = $interface; }
            foreach (($contribution['destinations'] ?? []) as $destination) { $destinations[] = $destination; }
            foreach (($contribution['gaps'] ?? []) as $gap) { $providerGaps[] = (string) $gap; }
            $providerEvidence = true;
        }

        $roles = $this->roles($interfaces, $reachable, $allSteps, $media, $access);
        $gaps = $this->gaps($destinations, $customComponents, $report, $journeys, $providerGaps);
        $understanding = $this->understanding($interfaces, $journeys, $media, $destinations, $access, $gaps, $system);
        $server = [
            'label' => 'This server',
            'primary_role' => $roles[0]['label'] ?? 'Custom SIP application',
            'summary' => $roles[0]['summary'] ?? 'Atum cannot yet determine a primary SIP role from the interpreted configuration.',
            'roles' => $roles,
            'configuration_source' => (string) ($report['root'] ?? ''),
            'evidence_kind' => 'static-configuration',
        ];
        [$objects, $relationships, $tiers] = $this->map($interfaces, $server, $journeys, $destinations, $media, $access, $customComponents);

        return [
            'schema_version' => 1,
            'basis' => [
                'primary' => 'static-configuration',
                'static_evidence' => true,
                'backing_data_evidence' => $providerEvidence,
                'runtime_evidence' => false,
                'statement' => 'This model describes interpreted configuration, not observed live traffic.',
            ],
            'server' => $server,
            'interfaces' => $interfaces,
            'journeys' => $journeys,
            'routing_stages' => $journeys['new-call']['stages'] ?? [],
            'destinations' => $destinations,
            'media' => $media,
            'access' => $access,
            'custom_components' => $customComponents,
            'outcomes' => $this->outcomes($journeys),
            'gaps' => $gaps,
            'understanding' => $understanding,
            'map' => ['objects' => $objects, 'relationships' => $relationships, 'tiers' => $tiers],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function interfaces(array $listeners): array
    {
        $interfaces = [];
        foreach ($listeners as $index => $listener) {
            $transport = strtoupper((string) ($listener['transport'] ?? 'UDP'));
            $address = (string) ($listener['address'] ?? '');
            $port = isset($listener['port']) ? (int) $listener['port'] : null;
            $scope = ($listener['non_loopback'] ?? false) ? 'non-loopback' : 'loopback';
            $endpoint = $address . ($port === null ? '' : ':' . $port);
            $interfaces[] = [
                'id' => 'sip-interface-' . ($index + 1),
                'label' => 'SIP signalling',
                'transport' => $transport,
                'address' => $address,
                'port' => $port,
                'scope' => $scope,
                'summary' => $transport . ' on ' . $endpoint . ' (' . $scope . ').',
                'evidence' => [['kind' => 'static-configuration', 'source' => $listener['source'] ?? []]],
            ];
        }
        usort($interfaces, static fn(array $a, array $b): int => strnatcasecmp($a['summary'], $b['summary']));
        foreach ($interfaces as $index => &$interface) { $interface['id'] = 'sip-interface-' . ($index + 1); }
        unset($interface);
        return $interfaces;
    }

    /** @return list<array<string,mixed>> */
    private function roles(array $interfaces, array $reachable, array $allSteps, array $media, array $access): array
    {
        $roles = [];
        $forward = $this->firstStep($reachable, static fn(array $step): bool => ($step['function'] ?? '') === 't_relay');
        $dispatch = $this->firstStep($reachable, static fn(array $step): bool => ($step['category'] ?? '') === 'dispatching');
        if ($interfaces !== [] && $forward !== null) {
            $roles[] = ['key' => 'routing-proxy', 'label' => 'SIP routing proxy', 'summary' => 'This system appears primarily to act as a SIP routing proxy.', 'evidence' => [$interfaces[0]['evidence'][0], $this->stepEvidence($forward)]];
        }
        if ($dispatch !== null) {
            $roles[] = ['key' => 'dispatcher', 'label' => 'Backend routing and selection', 'summary' => 'Atum identified backend destination selection before SIP forwarding.', 'evidence' => [$this->stepEvidence($dispatch)]];
        }
        if ($media['used']) {
            $roles[] = ['key' => 'media-proxy', 'label' => 'Media-handling proxy', 'summary' => 'Media-relay processing is present in interpreted call paths.', 'evidence' => $media['evidence']];
        }
        if ($access['registration']['identified']) {
            $roles[] = ['key' => 'registrar', 'label' => 'Endpoint registrar', 'summary' => 'Local endpoint registration or location handling is identified.', 'evidence' => $access['registration']['evidence']];
        }
        if ($access['authentication']['identified']) {
            $roles[] = ['key' => 'authentication', 'label' => 'Authentication gateway', 'summary' => 'Subscriber authentication handling is identified.', 'evidence' => $access['authentication']['evidence']];
        }
        if ($roles === [] && $this->firstStep($allSteps, static fn(array $step): bool => ($step['kind'] ?? '') === 'custom') !== null) {
            $roles[] = ['key' => 'custom-sip-application', 'label' => 'Custom SIP application', 'summary' => 'This appears to be a custom SIP application that Atum only partially understands.', 'evidence' => []];
        }
        return $roles;
    }

    /** @return array<string,array<string,mixed>> */
    private function journeys(array $processing, array $access): array
    {
        $reachable = $this->reachableRequestSteps($processing);
        if ($reachable === []) { return []; }
        $definitions = [
            'new-call' => ['label' => 'New calls', 'entry' => 'Incoming call', 'matches' => fn(array $conditions): bool => !$this->hasSpecialCondition($conditions)],
            'existing-call' => ['label' => 'Requests within an existing call', 'entry' => 'Existing call request', 'matches' => fn(array $conditions): bool => in_array('If the request is in-dialog', $conditions, true)],
            'bye' => ['label' => 'Call termination', 'entry' => 'BYE received', 'matches' => fn(array $conditions): bool => in_array('If request method is BYE', $conditions, true)],
            'cancel' => ['label' => 'Cancelled calls', 'entry' => 'CANCEL received', 'matches' => fn(array $conditions): bool => in_array('If request method is CANCEL', $conditions, true)],
            'register' => ['label' => 'Endpoint registration', 'entry' => 'REGISTER received', 'matches' => fn(array $conditions): bool => in_array('If request method is REGISTER', $conditions, true)],
        ];
        $journeys = [];
        foreach ($definitions as $key => $definition) {
            $steps = array_values(array_filter($reachable, static fn(array $step): bool => $definition['matches']($step['effective_conditions'] ?? [])));
            if ($key === 'register' && (!$access['registration']['identified'] || $steps === [])) { continue; }
            if ($key !== 'new-call' && $steps === []) { continue; }
            $stages = $this->stages($steps, $key);
            if ($stages === []) { continue; }
            $journeys[$key] = [
                'id' => $key,
                'label' => $definition['label'],
                'entry' => $definition['entry'],
                'stages' => $stages,
                'outcome' => $this->journeyOutcome($stages),
                'evidence_kind' => 'static-configuration',
            ];
        }
        return $journeys;
    }

    private function hasSpecialCondition(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if ($condition === 'If the request is in-dialog' || preg_match('/^If request method is (BYE|CANCEL|REGISTER)$/', $condition)) { return true; }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function stages(array $steps, string $journey): array
    {
        $stages = [];
        foreach ($steps as $step) {
            $stage = $this->stage($step, $journey);
            if ($stage === null) { continue; }
            $last = array_key_last($stages);
            if ($last !== null && $stages[$last]['key'] === $stage['key'] && $stages[$last]['outcome'] === $stage['outcome']) {
                $stages[$last]['evidence'][] = $this->stepEvidence($step);
                continue;
            }
            $stage['evidence'] = [$this->stepEvidence($step)];
            $stages[] = $stage;
        }
        return $stages;
    }

    /** @return array<string,mixed>|null */
    private function stage(array $step, string $journey): ?array
    {
        $kind = (string) ($step['kind'] ?? '');
        $category = (string) ($step['category'] ?? '');
        $function = (string) ($step['function'] ?? '');
        if (in_array($kind, ['condition', 'wiring'], true)) { return null; }
        if ($kind === 'route-call') {
            return match ($journey) {
                'existing-call' => $this->stageValue('existing-call', 'Existing-call handling', 'Apply the recognised branch for requests within an established call.', 'routing'),
                'bye' => $this->stageValue('termination', 'Termination processing', 'Apply the recognised call-termination branch.', 'routing'),
                'cancel' => $this->stageValue('cancellation', 'Cancellation handling', 'Apply the recognised cancellation branch.', 'routing'),
                'register' => $this->stageValue('registration', 'Registration handling', 'Apply the recognised endpoint-registration branch.', 'access'),
                default => $this->stageValue('routing-policy', 'Routing policy', 'Apply the configured routing policy.', 'routing'),
            };
        }
        if ($kind === 'unresolved-route-call' || $kind === 'custom') {
            return $this->stageValue('custom', 'Custom processing', 'Atum cannot yet fully interpret this step.', 'custom');
        }
        if ($category === 'validation') { return $this->stageValue('validation', 'Initial checks', 'Validate the incoming SIP request before routing.', 'validation'); }
        if ($category === 'routing') {
            return $function === 'loose_route'
                ? $this->stageValue('existing-call', 'Existing-call handling', 'Keep an established call on its recognised signalling path.', 'routing')
                : $this->stageValue('signalling-path', 'Keep this server in the signalling path', 'Prepare signalling so subsequent requests continue through this server.', 'routing');
        }
        if ($category === 'dispatching') { return $this->stageValue('destination-selection', 'Select destination', 'Choose a backend using dispatcher data.', 'routing'); }
        if ($category === 'media') {
            if ($function === 'rtpengine_delete') {
                $label = $journey === 'bye' ? 'Remove media session' : ($journey === 'cancel' ? 'Media cleanup' : 'Clean up media session');
                return $this->stageValue('media-cleanup', $label, 'Remove the media-relay session for this recognised path.', 'media');
            }
            if (str_ends_with($function, '_offer')) { return $this->stageValue('media-setup', 'Prepare media', 'Prepare media-relay handling for call setup.', 'media'); }
            return $this->stageValue('media', 'Media handling', 'Apply recognised media-relay processing.', 'media');
        }
        if ($category === 'nat') { return $this->stageValue('nat', 'NAT handling', 'Apply recognised NAT traversal processing.', 'media'); }
        if ($category === 'registration') {
            return $function === 'save'
                ? $this->stageValue('save-contact', 'Save endpoint contact', 'Store the endpoint contact using the configured location service.', 'access')
                : $this->stageValue('location', 'Find registered endpoint', 'Look up a registered endpoint destination.', 'access');
        }
        if ($category === 'authentication') { return $this->stageValue('authentication', 'Authenticate endpoint', 'Apply recognised subscriber authentication.', 'access'); }
        if ($category === 'reply') { return $this->stageValue('local-reply', 'Send SIP response', 'Complete this path with a local SIP response.', 'outcome', 'local-reply'); }
        if ($category === 'transaction' && $function === 't_relay') {
            return $this->stageValue('forward', $journey === 'new-call' ? 'Forward call' : 'Forward request', 'Forward signalling to the selected or current destination.', 'outcome', 'forward');
        }
        $meaning = (string) ($step['meaning'] ?? '');
        if ($meaning === 'Drop request') { return $this->stageValue('drop', 'Drop request', 'Discard this request without forwarding it.', 'outcome', 'drop'); }
        if ($meaning === 'Stop route processing') { return $this->stageValue('stop', 'Stop processing', 'Stop processing this request.', 'outcome', 'stop'); }
        if ($meaning === 'Return to calling route') {
            if (($step['call_depth'] ?? 0) > 0) { return null; }
            return $this->stageValue('return', 'Return from processing', 'Return control without a statically identified final destination.', 'outcome', 'return');
        }
        if ($category === 'transaction') { return $this->stageValue('transaction', 'Prepare forwarding', 'Prepare stateful SIP transaction handling.', 'routing'); }
        return null;
    }

    /** @return array{key:string,label:string,summary:string,kind:string,outcome:?string} */
    private function stageValue(string $key, string $label, string $summary, string $kind, ?string $outcome = null): array
    {
        return ['key' => $key, 'label' => $label, 'summary' => $summary, 'kind' => $kind, 'outcome' => $outcome];
    }

    /** @return list<array<string,mixed>> */
    private function destinations(array $reachable, array $report): array
    {
        $dispatch = $this->firstStep($reachable, static fn(array $step): bool => ($step['category'] ?? '') === 'dispatching');
        if ($dispatch !== null) {
            $databaseBacked = false;
            foreach (($report['modules'] ?? []) as $module) {
                if (strtolower((string) ($module['name'] ?? '')) !== 'dispatcher') { continue; }
                foreach (($module['params'] ?? []) as $param) {
                    if (($param['name'] ?? '') === 'db_url' && ($param['value_classification'] ?? '') === 'redacted-unclassified') { $databaseBacked = true; }
                }
            }
            return [[
                'id' => 'backend-selection',
                'label' => 'Backend selection',
                'mechanism' => 'dispatcher',
                'knowledge' => 'external',
                'target_known' => false,
                'backing_source' => $databaseBacked ? 'database' : 'external dispatcher data',
                'summary' => $databaseBacked
                    ? 'A backend is selected using an external database; destination records were not read.'
                    : 'A backend is selected using data stored outside the interpreted configuration.',
                'evidence' => [$this->stepEvidence($dispatch)],
            ]];
        }
        $forward = $this->firstStep($reachable, static fn(array $step): bool => ($step['function'] ?? '') === 't_relay');
        if ($forward !== null) {
            return [[
                'id' => 'unresolved-destination',
                'label' => 'Forward destination',
                'mechanism' => 'unresolved',
                'knowledge' => 'unresolved',
                'target_known' => false,
                'backing_source' => null,
                'summary' => 'SIP forwarding is identified, but the final destination cannot be determined statically.',
                'evidence' => [$this->stepEvidence($forward)],
            ]];
        }
        return [];
    }

    /** @return array<string,array<string,mixed>> */
    private function access(array $steps): array
    {
        $registration = array_values(array_filter($steps, static fn(array $step): bool => ($step['category'] ?? '') === 'registration'));
        $authentication = array_values(array_filter($steps, static fn(array $step): bool => ($step['category'] ?? '') === 'authentication'));
        return [
            'registration' => [
                'identified' => $registration !== [],
                'label' => 'Endpoint registration',
                'summary' => $registration === [] ? 'Local endpoint registration handling was not identified in the interpreted configuration.' : 'Local endpoint registration or location handling is identified.',
                'evidence' => array_map(fn(array $step): array => $this->stepEvidence($step), $registration),
            ],
            'authentication' => [
                'identified' => $authentication !== [],
                'label' => 'Authentication',
                'summary' => $authentication === [] ? 'Subscriber authentication handling was not identified in the interpreted configuration.' : 'Subscriber authentication handling is identified.',
                'evidence' => array_map(fn(array $step): array => $this->stepEvidence($step), $authentication),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function media(array $presentation, array $journeys): array
    {
        $used = ($presentation['used_in_flow'] ?? false) === true;
        $name = strtolower((string) ($presentation['module']['name'] ?? ''));
        $label = $name === 'rtpengine' ? 'RTPengine media relay' : ($name === 'rtpproxy' ? 'RTPProxy media relay' : 'Media relay');
        $relationships = [];
        $evidence = [];
        foreach (($presentation['stages'] ?? []) as $stage) {
            $relationships[] = ['key' => $stage['key'], 'label' => $stage['title'], 'summary' => $stage['summary'], 'triggers' => array_keys($stage['triggers'] ?? [])];
            foreach (($stage['evidence'] ?? []) as $item) { $evidence[] = $this->stepEvidence($item); }
        }
        $journeyTriggers = ['new-call' => ['setup', 'During new-call setup'], 'existing-call' => ['in-dialog', 'During an existing call'], 'bye' => ['cleanup', 'When BYE is processed'], 'cancel' => ['cleanup', 'When CANCEL is processed']];
        foreach ($journeyTriggers as $journeyKey => [$relationshipKey, $trigger]) {
            $journey = $journeys[$journeyKey] ?? null;
            if (!is_array($journey) || !array_filter($journey['stages'], static fn(array $stage): bool => str_starts_with($stage['key'], 'media'))) { continue; }
            $relationshipIndex = array_search($relationshipKey, array_column($relationships, 'key'), true);
            if ($relationshipIndex === false) {
                $relationships[] = ['key' => $relationshipKey, 'label' => $relationshipKey === 'cleanup' ? 'Media session cleanup' : ($relationshipKey === 'in-dialog' ? 'Established-call media processing' : 'Call setup'), 'summary' => 'Media handling is established by the interpreted call journey.', 'triggers' => [$trigger]];
            } elseif (!in_array($trigger, $relationships[$relationshipIndex]['triggers'], true)) {
                $relationships[$relationshipIndex]['triggers'][] = $trigger;
                sort($relationships[$relationshipIndex]['triggers'], SORT_NATURAL | SORT_FLAG_CASE);
            }
        }
        return [
            'available' => ($presentation['available'] ?? false) === true,
            'used' => $used,
            'label' => $label,
            'summary' => $used ? $label . ' participates in interpreted request and reply paths.' : (($presentation['available'] ?? false) ? $label . ' is loaded, but use in an interpreted call path was not identified.' : 'No recognised media relay was identified.'),
            'relationships' => $relationships,
            'evidence' => $evidence,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function customComponents(array $processing, array $system, string $root): array
    {
        $components = [];
        foreach (($system['composition'] ?? []) as $component) {
            $path = (string) ($component['path'] ?? '');
            if ($path === '' || $path === $root || (int) ($component['routes'] ?? 0) === 0) { continue; }
            $steps = [];
            foreach (($processing['flows'] ?? []) as $flow) {
                if (($flow['source']['file'] ?? '') !== $path) { continue; }
                foreach (($flow['statements'] ?? []) as $step) { $steps[] = $step; }
            }
            $areas = [];
            foreach ($steps as $step) {
                $category = (string) ($step['category'] ?? '');
                if (in_array($category, ['routing', 'dispatching', 'transaction'], true) || ($step['kind'] ?? '') === 'route-call') { $areas['routing'] = 'Routing'; }
                if (in_array($category, ['media', 'nat'], true)) { $areas['media'] = 'Media and NAT'; }
                if (in_array($category, ['registration', 'authentication'], true)) { $areas['access'] = 'Access and registration'; }
            }
            if ($areas === []) { $areas['routing'] = 'Routing'; }
            $customCount = count(array_filter($steps, static fn(array $step): bool => in_array($step['kind'] ?? '', ['custom', 'unresolved-route-call'], true)));
            $components[] = [
                'id' => 'custom-component-' . (count($components) + 1),
                'label' => isset($areas['routing']) ? 'Custom routing component' : 'Custom configuration component',
                'areas' => array_values($areas),
                'understanding' => $customCount > 0 ? 'partial' : 'recognised',
                'summary' => $customCount > 0 ? 'Contains system processing that Atum only partially understands.' : 'Contains included processing that Atum can structurally interpret.',
                'custom_decisions' => $customCount,
                'evidence' => [['kind' => 'static-configuration', 'source' => ['file' => $path, 'line' => 1]]],
            ];
        }
        return $components;
    }

    /** @return list<string> */
    private function gaps(array $destinations, array $components, array $report, array $journeys, array $providerGaps): array
    {
        $gaps = [];
        foreach ($destinations as $destination) {
            if (!($destination['target_known'] ?? false)) { $gaps[] = (string) $destination['summary']; }
        }
        $custom = array_sum(array_column($components, 'custom_decisions'));
        if ($custom > 0) { $gaps[] = $custom === 1 ? 'One custom system-level routing decision remains only partially understood.' : $custom . ' custom system-level routing decisions remain only partially understood.'; }
        $unclassified = (int) ($report['presentation']['modules']['coverage']['unclassified'] ?? 0);
        if ($unclassified > 0) { $gaps[] = $unclassified . ' loaded module' . ($unclassified === 1 ? ' remains' : 's remain') . ' unclassified at system level.'; }
        if (!isset($journeys['new-call'])) { $gaps[] = 'A main new-call journey could not be established from the interpreted configuration.'; }
        foreach ($providerGaps as $gap) { $gaps[] = $gap; }
        return array_values(array_unique($gaps));
    }

    /** @return array<string,mixed> */
    private function understanding(array $interfaces, array $journeys, array $media, array $destinations, array $access, array $gaps, array $system): array
    {
        $understands = [];
        if ($interfaces !== []) { $understands[] = 'SIP ingress'; }
        if (isset($journeys['new-call'])) { $understands[] = 'Main new-call path'; }
        if (isset($journeys['existing-call'])) { $understands[] = 'Existing-call handling'; }
        if ($media['used']) { $understands[] = 'Media handling'; }
        if (isset($journeys['bye'])) { $understands[] = 'BYE termination handling'; }
        if (isset($journeys['cancel'])) { $understands[] = 'CANCEL handling'; }
        foreach ($journeys as $journey) { if (($journey['outcome']['key'] ?? '') === 'forward') { $understands[] = 'SIP forwarding'; break; } }
        if ($destinations !== []) { $understands[] = 'Backend selection mechanism'; }
        if ($access['registration']['identified']) { $understands[] = 'Endpoint registration'; }
        if ($access['authentication']['identified']) { $understands[] = 'Authentication'; }
        return [
            'level' => ucfirst((string) ($system['confidence']['level'] ?? 'partial')),
            'understands' => array_values(array_unique($understands)),
            'gaps' => $gaps,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function outcomes(array $journeys): array
    {
        $outcomes = [];
        foreach ($journeys as $journey) {
            $outcome = $journey['outcome'] ?? null;
            if (!is_array($outcome) || ($outcome['key'] ?? 'unresolved') === 'unresolved') { continue; }
            $outcomes[$outcome['key']] = $outcome;
        }
        return array_values($outcomes);
    }

    /** @return array{key:string,label:string} */
    private function journeyOutcome(array $stages): array
    {
        for ($index = count($stages) - 1; $index >= 0; $index--) {
            if (($stages[$index]['outcome'] ?? null) !== null) { return ['key' => $stages[$index]['outcome'], 'label' => $stages[$index]['label']]; }
        }
        return ['key' => 'unresolved', 'label' => 'Outcome not statically determined'];
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,string>>,2:list<list<string>>} */
    private function map(array $interfaces, array $server, array $journeys, array $destinations, array $media, array $access, array $components): array
    {
        $objects = [];
        $tiers = [];
        $relationships = [];
        $ingressTier = [];
        foreach ($interfaces as $interface) {
            $objects[] = ['id' => $interface['id'], 'type' => 'ingress', 'label' => $interface['label'], 'summary' => $interface['summary']];
            $ingressTier[] = $interface['id'];
            $relationships[] = ['from' => $interface['id'], 'to' => 'server', 'label' => 'SIP enters'];
        }
        if ($ingressTier !== []) { $tiers[] = $ingressTier; }
        $objects[] = ['id' => 'server', 'type' => 'server', 'label' => $server['label'], 'summary' => $server['primary_role']];
        $tiers[] = ['server'];
        $serviceTier = [];
        if (isset($journeys['new-call'])) {
            $objects[] = ['id' => 'routing', 'type' => 'routing', 'label' => 'Call routing', 'summary' => 'Policy and forwarding decisions'];
            $serviceTier[] = 'routing';
            $relationships[] = ['from' => 'server', 'to' => 'routing', 'label' => 'Applies call policy'];
        }
        if ($media['used']) {
            $objects[] = ['id' => 'media', 'type' => 'media', 'label' => 'Media relay', 'summary' => $media['label']];
            $serviceTier[] = 'media';
            $relationships[] = ['from' => 'server', 'to' => 'media', 'label' => 'Coordinates media'];
        }
        if ($access['registration']['identified']) {
            $objects[] = ['id' => 'registration', 'type' => 'access', 'label' => 'Endpoint registration', 'summary' => 'Local contact handling'];
            $serviceTier[] = 'registration';
            $relationships[] = ['from' => 'server', 'to' => 'registration', 'label' => 'Handles registration'];
        }
        if ($access['authentication']['identified']) {
            $objects[] = ['id' => 'authentication', 'type' => 'access', 'label' => 'Authentication', 'summary' => 'Subscriber access checks'];
            $serviceTier[] = 'authentication';
            $relationships[] = ['from' => 'server', 'to' => 'authentication', 'label' => 'Checks access'];
        }
        foreach ($components as $component) {
            $objects[] = ['id' => $component['id'], 'type' => 'custom', 'label' => $component['label'], 'summary' => $component['summary']];
            $serviceTier[] = $component['id'];
            $relationships[] = ['from' => 'server', 'to' => $component['id'], 'label' => 'Includes custom processing'];
        }
        if ($serviceTier !== []) { $tiers[] = $serviceTier; }
        $outcomeTier = [];
        foreach ($destinations as $destination) {
            $objects[] = ['id' => $destination['id'], 'type' => 'destination', 'label' => $destination['label'], 'summary' => $destination['summary']];
            $outcomeTier[] = $destination['id'];
            $relationships[] = ['from' => isset($journeys['new-call']) ? 'routing' : 'server', 'to' => $destination['id'], 'label' => 'Selects backend'];
        }
        $forward = false;
        foreach ($journeys as $journey) { if (($journey['outcome']['key'] ?? '') === 'forward') { $forward = true; break; } }
        if ($forward) {
            $objects[] = ['id' => 'forward', 'type' => 'outcome', 'label' => 'Forward SIP', 'summary' => 'Forward to the selected or current destination'];
            $outcomeTier[] = 'forward';
            $relationships[] = ['from' => isset($journeys['new-call']) ? 'routing' : 'server', 'to' => 'forward', 'label' => 'Forwards signalling'];
            if ($media['used']) { $relationships[] = ['from' => 'media', 'to' => 'forward', 'label' => 'Media prepared']; }
        }
        if ($outcomeTier !== []) { $tiers[] = $outcomeTier; }
        return [$objects, $relationships, $tiers];
    }

    /** @return list<array<string,mixed>> */
    private function allSteps(array $processing): array
    {
        $steps = [];
        foreach (($processing['flows'] ?? []) as $flow) {
            foreach (($flow['statements'] ?? []) as $step) { $steps[] = $step + ['flow_id' => $flow['id'] ?? -1, 'flow_type' => $flow['type'] ?? '']; }
        }
        return $steps;
    }

    /** @return list<array<string,mixed>> */
    private function reachableRequestSteps(array $processing): array
    {
        $flows = [];
        $root = null;
        foreach (($processing['flows'] ?? []) as $flow) {
            $flows[(int) $flow['id']] = $flow;
            if (($flow['type'] ?? '') === 'request_route' && $root === null) { $root = (int) $flow['id']; }
        }
        if ($root === null) { return []; }
        $walk = function (int $id, array $inherited, array $path, int $depth) use (&$walk, $flows): array {
            if (isset($path[$id]) || !isset($flows[$id])) { return []; }
            $path[$id] = true;
            $result = [];
            foreach (($flows[$id]['statements'] ?? []) as $step) {
                $conditions = array_values(array_unique([...$inherited, ...($step['conditions'] ?? [])]));
                $current = $step + ['flow_id' => $id, 'flow_type' => $flows[$id]['type'] ?? '', 'effective_conditions' => $conditions, 'call_depth' => $depth];
                $result[] = $current;
                if (($step['kind'] ?? '') === 'route-call' && isset($step['edge']['to'])) {
                    $result = [...$result, ...$walk((int) $step['edge']['to'], $conditions, $path, $depth + 1)];
                }
            }
            return $result;
        };
        return $walk($root, [], [], 0);
    }

    /** @return array<string,mixed>|null */
    private function firstStep(array $steps, callable $matches): ?array
    {
        foreach ($steps as $step) { if ($matches($step)) { return $step; } }
        return null;
    }

    /** @return array{kind:string,source:array<string,mixed>,confidence:string,semantic:array<string,mixed>} */
    private function stepEvidence(array $step): array
    {
        return [
            'kind' => 'static-configuration',
            'source' => $step['source'] ?? [],
            'confidence' => (string) ($step['confidence'] ?? 'unknown'),
            'semantic' => array_filter([
                'kind' => $step['kind'] ?? null,
                'meaning' => $step['meaning'] ?? null,
                'function' => $step['function'] ?? null,
                'flow_type' => $step['flow_type'] ?? $step['route_type'] ?? null,
            ], static fn(mixed $value): bool => $value !== null),
        ];
    }
}
