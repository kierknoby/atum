<?php
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/SystemModel.class.php';

/**
 * Adds conservative operator-facing meaning to factual Kamailio discovery.
 *
 * The scanner remains the authority for what was found. This service only
 * describes explicitly catalogued names and never classifies unknown modules.
 */
final class AtumKamailioSemantics
{
    private const GROUPS = [
        'core-sip' => 'Core SIP processing',
        'transactions-routing' => 'Transactions and routing',
        'dialog-state' => 'Dialog and state',
        'registration-location' => 'Registration and location',
        'authentication-permissions' => 'Authentication and permissions',
        'nat-media' => 'NAT and media',
        'dispatching' => 'Dispatching and load balancing',
        'database-storage' => 'Database and storage',
        'transport-protocol' => 'Transport and protocol',
        'observability' => 'Observability and diagnostics',
        'utilities' => 'Utilities',
        'unclassified' => 'Other / unclassified',
    ];

    /** @var array<string, array{group: string, purpose: string, capability?: string}> */
    private const MODULES = [
        'sl' => ['group' => 'core-sip', 'purpose' => 'Stateless SIP reply generation'],
        'maxfwd' => ['group' => 'core-sip', 'purpose' => 'Max-Forwards header processing'],
        'sanity' => ['group' => 'core-sip', 'purpose' => 'SIP message formatting sanity checks'],
        'siputils' => ['group' => 'core-sip', 'purpose' => 'General SIP message and URI utilities'],

        'tm' => ['group' => 'transactions-routing', 'purpose' => 'Stateful SIP transaction management', 'capability' => 'Transaction handling'],
        'tmx' => ['group' => 'transactions-routing', 'purpose' => 'Extended transaction-management functions', 'capability' => 'Transaction handling'],
        'rr' => ['group' => 'transactions-routing', 'purpose' => 'Record-Route and loose routing support', 'capability' => 'Record-Route support'],

        'dialog' => ['group' => 'dialog-state', 'purpose' => 'Stateful SIP dialog tracking', 'capability' => 'Dialog tracking'],

        'registrar' => ['group' => 'registration-location', 'purpose' => 'SIP registrar request handling', 'capability' => 'SIP registration support'],
        'usrloc' => ['group' => 'registration-location', 'purpose' => 'Registered-contact location storage', 'capability' => 'User location support'],

        'auth' => ['group' => 'authentication-permissions', 'purpose' => 'SIP authentication interface', 'capability' => 'SIP authentication support'],
        'auth_db' => ['group' => 'authentication-permissions', 'purpose' => 'Database-backed SIP authentication', 'capability' => 'Database-backed authentication'],
        'permissions' => ['group' => 'authentication-permissions', 'purpose' => 'Source-address and group permission checks', 'capability' => 'Permission checks'],

        'nathelper' => ['group' => 'nat-media', 'purpose' => 'NAT traversal and contact keepalive helpers', 'capability' => 'NAT traversal support'],
        'rtpengine' => ['group' => 'nat-media', 'purpose' => 'Media relay control through RTPengine', 'capability' => 'RTPengine media relay support'],
        'rtpproxy' => ['group' => 'nat-media', 'purpose' => 'Media relay control through RTPProxy', 'capability' => 'RTPProxy media relay support'],

        'dispatcher' => ['group' => 'dispatching', 'purpose' => 'Destination selection and load balancing', 'capability' => 'Dispatcher and load-balancing support'],
        'drouting' => ['group' => 'dispatching', 'purpose' => 'Database-backed dynamic prefix routing', 'capability' => 'Dynamic routing support'],

        'db_mysql' => ['group' => 'database-storage', 'purpose' => 'MySQL/MariaDB backend for the Kamailio database API', 'capability' => 'MySQL/MariaDB module support'],
        'db_postgres' => ['group' => 'database-storage', 'purpose' => 'PostgreSQL backend for the Kamailio database API', 'capability' => 'PostgreSQL module support'],
        'db_sqlite' => ['group' => 'database-storage', 'purpose' => 'SQLite backend for the Kamailio database API', 'capability' => 'SQLite module support'],
        'sqlops' => ['group' => 'database-storage', 'purpose' => 'SQL queries and result handling from routing logic', 'capability' => 'Direct SQL operation support'],

        'tls' => ['group' => 'transport-protocol', 'purpose' => 'TLS transport configuration and operations', 'capability' => 'TLS transport support'],
        'websocket' => ['group' => 'transport-protocol', 'purpose' => 'SIP transport over WebSocket connections', 'capability' => 'WebSocket transport support'],
        'tcpops' => ['group' => 'transport-protocol', 'purpose' => 'Runtime TCP connection controls'],
        'xhttp' => ['group' => 'transport-protocol', 'purpose' => 'Basic HTTP request handling'],

        'acc' => ['group' => 'observability', 'purpose' => 'SIP transaction accounting', 'capability' => 'SIP accounting support'],
        'debugger' => ['group' => 'observability', 'purpose' => 'Interactive configuration debugging'],
        'siptrace' => ['group' => 'observability', 'purpose' => 'SIP traffic tracing', 'capability' => 'SIP tracing support'],
        'xlog' => ['group' => 'observability', 'purpose' => 'Structured logging from routing logic'],

        'corex' => ['group' => 'utilities', 'purpose' => 'Extensions to Kamailio core operations'],
        'ctl' => ['group' => 'utilities', 'purpose' => 'Binary control interface for external management tools'],
        'jsonrpcs' => ['group' => 'utilities', 'purpose' => 'JSON-RPC server transport'],
        'kex' => ['group' => 'utilities', 'purpose' => 'Kamailio core extension functions'],
        'pv' => ['group' => 'utilities', 'purpose' => 'Additional pseudo-variables and transformations'],
        'textops' => ['group' => 'utilities', 'purpose' => 'SIP message text operations'],
        'textopsx' => ['group' => 'utilities', 'purpose' => 'Extended SIP message text operations'],
    ];

    /** @var array<string,array{meaning:string,category:string,terminal?:bool}> */
    private const ROUTE_ACTIONS = [
        't_relay' => ['meaning' => 'Relay request statefully', 'category' => 'transaction', 'terminal' => true],
        't_newtran' => ['meaning' => 'Create a stateful transaction', 'category' => 'transaction'],
        'record_route' => ['meaning' => 'Apply Record-Route', 'category' => 'routing'],
        'loose_route' => ['meaning' => 'Apply loose routing', 'category' => 'routing'],
        'nat_uac_test' => ['meaning' => 'Test request for NAT handling', 'category' => 'nat'],
        'fix_nated_contact' => ['meaning' => 'Fix NATed Contact', 'category' => 'nat'],
        'fix_nated_register' => ['meaning' => 'Fix NATed registration', 'category' => 'nat'],
        'add_contact_alias' => ['meaning' => 'Add Contact alias', 'category' => 'nat'],
        'handle_ruri_alias' => ['meaning' => 'Handle R-URI alias', 'category' => 'nat'],
        'set_contact_alias' => ['meaning' => 'Set Contact alias', 'category' => 'nat'],
        'rtpengine_offer' => ['meaning' => 'Apply RTPengine offer processing', 'category' => 'media'],
        'rtpengine_answer' => ['meaning' => 'Apply RTPengine answer processing', 'category' => 'media'],
        'rtpengine_manage' => ['meaning' => 'Apply RTPengine media processing', 'category' => 'media'],
        'rtpengine_delete' => ['meaning' => 'Delete RTPengine media session', 'category' => 'media'],
        'rtpproxy_offer' => ['meaning' => 'Apply RTPProxy offer processing', 'category' => 'media'],
        'rtpproxy_answer' => ['meaning' => 'Apply RTPProxy answer processing', 'category' => 'media'],
        'save' => ['meaning' => 'Save registration or location data', 'category' => 'registration'],
        'lookup' => ['meaning' => 'Look up registered location', 'category' => 'registration'],
        'www_authorize' => ['meaning' => 'Check WWW authentication', 'category' => 'authentication'],
        'proxy_authorize' => ['meaning' => 'Check proxy authentication', 'category' => 'authentication'],
        'consume_credentials' => ['meaning' => 'Consume authentication credentials', 'category' => 'authentication'],
        'mf_process_maxfwd_header' => ['meaning' => 'Check request forwarding limit', 'category' => 'validation'],
        'sanity_check' => ['meaning' => 'Validate SIP request structure', 'category' => 'validation'],
        'ds_select_dst' => ['meaning' => 'Select dispatcher destination', 'category' => 'dispatching'],
        'ds_select_domain' => ['meaning' => 'Select dispatcher domain', 'category' => 'dispatching'],
        'ds_next_dst' => ['meaning' => 'Select next dispatcher destination', 'category' => 'dispatching'],
        'ds_mark_dst' => ['meaning' => 'Mark dispatcher destination', 'category' => 'dispatching'],
        'sl_send_reply' => ['meaning' => 'Send local stateless SIP reply', 'category' => 'reply', 'terminal' => true],
        'send_reply' => ['meaning' => 'Send local SIP reply', 'category' => 'reply', 'terminal' => true],
    ];

    public function present(array $report): array
    {
        $grouped = array_fill_keys(array_keys(self::GROUPS), []);
        $capabilityModules = [];
        $recognised = 0;

        foreach (($report['modules'] ?? []) as $module) {
            $name = strtolower((string) ($module['name'] ?? ''));
            $metadata = self::MODULES[$name] ?? null;
            $group = $metadata['group'] ?? 'unclassified';
            $module['semantic_status'] = $metadata === null ? 'unclassified' : 'recognised';
            $module['purpose'] = $metadata['purpose'] ?? 'No presentation metadata is available for this discovered module.';
            $module['group'] = $group;
            $module['group_label'] = self::GROUPS[$group];
            $grouped[$group][] = $module;

            if ($metadata !== null) {
                $recognised++;
                if (($module['source']['confidence'] ?? null) === 'syntactic' && isset($metadata['capability'])) {
                    $capabilityModules[$metadata['capability']][] = (string) $module['name'];
                }
            }
        }

        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            if ($grouped[$key] === []) {
                continue;
            }
            usort($grouped[$key], static fn(array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
            $groups[] = ['key' => $key, 'label' => $label, 'modules' => $grouped[$key]];
        }

        $capabilities = [];
        foreach ($capabilityModules as $label => $moduleNames) {
            $moduleNames = array_values(array_unique($moduleNames));
            sort($moduleNames, SORT_NATURAL | SORT_FLAG_CASE);
            $capabilities[] = ['label' => $label, 'modules' => $moduleNames];
        }
        usort($capabilities, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        $total = count($report['modules'] ?? []);
        $report['presentation']['modules'] = [
            'capabilities' => $capabilities,
            'coverage' => ['total' => $total, 'recognised' => $recognised, 'unclassified' => $total - $recognised],
            'groups' => $groups,
        ];
        $report['presentation']['system'] = $this->systemPresentation($report, $recognised, $total - $recognised);
        $report['presentation']['request_processing'] = $this->requestProcessing($report);
        $report['presentation']['media'] = $this->mediaPresentation(
            $report['presentation']['request_processing'],
            $report['modules'] ?? []
        );
        $report['system_model'] = (new AtumKamailioSystemModel())->build($report);
        $report['presentation']['operator'] = $this->operatorPresentation(
            $report['system_model'],
            $report['presentation']['request_processing'],
            $report['presentation']['system']
        );

        return $report;
    }

    /** @return array<string,mixed> */
    private function requestProcessing(array $report): array
    {
        $routes = $report['routes'] ?? [];
        $routeIndex = [];
        foreach ($routes as $index => $route) {
            $key = (string) ($route['type'] ?? '') . ':' . (string) ($route['name'] ?? 'main');
            $routeIndex[$key] = $index;
        }
        $flows = [];
        $edges = [];
        $recognised = 0;
        $custom = 0;
        $unresolved = 0;
        foreach ($routes as $index => $route) {
            $steps = [];
            foreach (($route['statements'] ?? []) as $statement) {
                $step = $this->routeStep($statement, $routeIndex);
                $steps[] = $step;
                if ($step['kind'] === 'custom') { $custom++; } else { $recognised++; }
                if ($step['kind'] === 'unresolved-route-call') { $unresolved++; }
                if (isset($step['edge'])) { $edges[] = $step['edge'] + ['from' => $index]; }
            }
            $flows[] = ['id' => $index, 'label' => $this->routeLabel($route), 'type' => $route['type'], 'name' => $route['name'] ?? null, 'source' => $route['source'], 'statements' => $steps];
        }
        $cycles = $this->routeCycles($edges);
        $referenced = array_unique(array_filter(array_map(static fn(array $edge): ?int => $edge['to'] ?? null, $edges), static fn(mixed $id): bool => is_int($id)));
        $unreferenced = [];
        foreach ($flows as $flow) {
            if ($flow['type'] === 'route' && !in_array($flow['id'], $referenced, true)) { $unreferenced[] = $flow; }
        }
        return ['flows' => $flows, 'edges' => $edges, 'coverage' => ['recognised' => $recognised, 'custom' => $custom, 'unresolved' => $unresolved, 'cycles' => $cycles, 'unreferenced' => $unreferenced]];
    }

    /** @return array<string,mixed> */
    private function operatorPresentation(array $model, array $processing, array $system): array
    {
        $flows = $processing['flows'] ?? [];
        $allSteps = [];
        foreach ($flows as $flow) { foreach (($flow['statements'] ?? []) as $step) { $allSteps[] = $step + ['route_type' => $flow['type'] ?? '', 'route_id' => $flow['id'] ?? -1, 'route_name' => $flow['name'] ?? null]; } }
        $stages = array_map(static function (array $stage): array {
            $evidence = array_map(static fn(array $item): array => ($item['semantic'] ?? []) + ['source' => $item['source'] ?? [], 'confidence' => $item['confidence'] ?? 'unknown'], $stage['evidence'] ?? []);
            return ['title' => $stage['label'], 'kind' => $stage['kind'], 'evidence' => $evidence, 'conditions' => []];
        }, $model['journeys']['new-call']['stages'] ?? []);
        $roles = array_column($model['server']['roles'] ?? [], 'summary');
        if ($roles === []) { $roles[] = 'Atum could not derive a broad operator role from the interpreted configuration.'; }
        $media = array_values(array_filter($allSteps, static fn(array $step): bool => ($step['category'] ?? '') === 'media'));
        $access = array_values(array_filter($allSteps, static fn(array $step): bool => in_array(($step['category'] ?? ''), ['registration', 'authentication'], true)));
        $routing = array_values(array_filter($allSteps, static fn(array $step): bool => in_array(($step['category'] ?? ''), ['routing', 'dispatching', 'transaction'], true) || ($step['kind'] ?? '') === 'route-call'));
        return [
            'overview' => $roles,
            'stages' => $stages,
            'connectivity' => $system['listeners'] ?? [],
            'routing' => $routing,
            'media' => $media,
            'access' => $access,
            'gaps' => array_values(array_filter($allSteps, static fn(array $step): bool => in_array($step['kind'] ?? '', ['custom', 'unresolved-route-call'], true))),
            'coverage' => $processing['coverage'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    private function mediaPresentation(array $processing, array $modules): array
    {
        $mediaModule = null;
        foreach ($modules as $module) {
            if (in_array(strtolower((string) ($module['name'] ?? '')), ['rtpengine', 'rtpproxy'], true)
                && ($module['source']['confidence'] ?? '') === 'syntactic') { $mediaModule = $module; break; }
        }
        $flows = $processing['flows'] ?? [];
        $wired = [];
        foreach (($processing['edges'] ?? []) as $edge) {
            if (($edge['kind'] ?? '') === 'wiring' && isset($edge['to'])) { $wired[$edge['to']][] = $edge['kind']; }
        }
        $groups = ['setup' => [], 'reply' => [], 'in-dialog' => [], 'cleanup' => [], 'other' => []];
        $natRouteIds = [];
        foreach ($flows as $flow) {
            foreach ($flow['statements'] as $step) {
                if (($step['category'] ?? '') === 'nat') { $natRouteIds[$flow['id']] = true; }
            }
        }
        foreach ($flows as $flow) {
            foreach ($flow['statements'] as $step) {
                if (($step['category'] ?? '') !== 'media') { continue; }
                $step += ['route_id' => $flow['id'], 'route_type' => $flow['type'], 'route_name' => $flow['name']];
                $function = (string) ($step['function'] ?? '');
                $group = match ($function) {
                    'rtpengine_offer', 'rtpproxy_offer' => 'setup',
                    'rtpengine_answer', 'rtpproxy_answer' => 'reply',
                    'rtpengine_delete' => 'cleanup',
                    default => ($flow['type'] === 'onreply_route' ? 'reply' : ($this->hasCondition($step, 'If the request is in-dialog') ? 'in-dialog' : 'other')),
                };
                $step['trigger'] = $this->mediaTrigger($step, $flow, $wired);
                $step['nat_related'] = isset($natRouteIds[$flow['id']]);
                $groups[$group][] = $step;
            }
        }
        $stages = [];
        foreach ([
            'setup' => ['Call setup', 'Media negotiation is prepared for new calls.'],
            'reply' => ['Reply media processing', 'Media information is processed for SIP replies.'],
            'in-dialog' => ['Established-call media processing', 'Media is processed for requests within an existing call.'],
            'cleanup' => ['Media session cleanup', 'Media sessions are explicitly removed from recognised processing paths.'],
            'other' => ['Other media processing', 'Media processing was found outside a recognised lifecycle context.'],
        ] as $key => [$title, $summary]) {
            if ($groups[$key] === []) { continue; }
            $triggers = [];
            foreach ($groups[$key] as $step) { $triggers[$step['trigger']][] = $step; }
            ksort($triggers, SORT_NATURAL | SORT_FLAG_CASE);
            $stages[] = ['key' => $key, 'title' => $title, 'summary' => $summary, 'count' => count($groups[$key]), 'triggers' => $triggers, 'evidence' => $groups[$key]];
        }
        return [
            'available' => $mediaModule !== null,
            'module' => $mediaModule,
            'used_in_flow' => $groups !== array_fill_keys(array_keys($groups), []),
            'stages' => $stages,
            'nat_related_count' => count(array_filter(array_merge(...array_values($groups)), static fn(array $step): bool => $step['nat_related'])),
            'custom_paths' => count(array_filter(array_merge(...array_values($groups)), static fn(array $step): bool => ($step['confidence'] ?? '') !== 'syntactic')),
        ];
    }

    private function hasCondition(array $step, string $condition): bool
    {
        return in_array($condition, $step['conditions'] ?? [], true);
    }

    private function mediaTrigger(array $step, array $flow, array $wired): string
    {
        foreach ($step['conditions'] ?? [] as $condition) {
            if (($step['function'] ?? '') === 'rtpengine_offer' && $condition === 'If request method is INVITE') {
                return 'During INVITE call setup';
            }
            if (preg_match('/^If request method is (BYE|CANCEL)$/', $condition, $match)) {
                return 'When ' . $match[1] . ' is processed';
            }
        }
        if ($flow['type'] === 'onreply_route') { return 'During SIP reply processing'; }
        if ($flow['type'] === 'failure_route' && isset($wired[$flow['id']])) { return 'On a configured transaction failure path'; }
        if ($this->hasCondition($step, 'If the request is in-dialog')) { return 'During an existing call'; }
        return 'Other media path; trigger not statically determined';
    }

    /** @return array<string,mixed> */
    private function routeStep(array $statement, array $routeIndex): array
    {
        $base = ['source' => $statement['source'], 'confidence' => $statement['confidence'] ?? 'unknown', 'depth' => $statement['depth'] ?? 0, 'conditions' => array_map(static fn(array $condition): string => $condition['meaning'], $statement['conditions'] ?? [])];
        if ($statement['kind'] === 'condition') { return $base + ['kind' => 'condition', 'meaning' => $statement['meaning']]; }
        if ($statement['kind'] === 'custom-condition') { return $base + ['kind' => 'custom', 'meaning' => 'Custom condition']; }
        if ($statement['kind'] === 'route-call') {
            $key = 'route:' . $statement['target'];
            return $base + ['kind' => 'route-call', 'meaning' => 'Call route[' . $statement['target'] . ']', 'edge' => ['kind' => 'call', 'label' => 'Calls route[' . $statement['target'] . ']', 'to' => $routeIndex[$key] ?? null]];
        }
        if ($statement['kind'] === 'unresolved-route-call') { return $base + ['kind' => 'unresolved-route-call', 'meaning' => 'Dynamic route call']; }
        if ($statement['kind'] === 'control') {
            $meaning = ['drop' => 'Drop request', 'exit' => 'Stop route processing', 'return' => 'Return to calling route'][$statement['control']] ?? 'Control flow';
            return $base + ['kind' => 'action', 'meaning' => $meaning, 'terminal' => $statement['control'] !== 'return'];
        }
        if ($statement['kind'] === 'function-call') {
            $function = $statement['function'];
            if (isset(self::ROUTE_ACTIONS[$function])) { return $base + ['kind' => 'action', 'function' => $function, 'meaning' => self::ROUTE_ACTIONS[$function]['meaning'], 'category' => self::ROUTE_ACTIONS[$function]['category'], 'terminal' => self::ROUTE_ACTIONS[$function]['terminal'] ?? false]; }
            $wiring = ['t_on_reply' => ['onreply_route', 'Reply processing is assigned to onreply_route'], 't_on_failure' => ['failure_route', 'Failure processing is assigned to failure_route'], 't_on_branch' => ['branch_route', 'Branch processing is assigned to branch_route']];
            if (isset($wiring[$function]) && isset($statement['target'])) {
                [$type, $meaning] = $wiring[$function];
                $key = $type . ':' . $statement['target'];
                return $base + ['kind' => 'wiring', 'meaning' => $meaning . '[' . $statement['target'] . ']', 'edge' => ['kind' => 'wiring', 'label' => $meaning . '[' . $statement['target'] . ']', 'to' => $routeIndex[$key] ?? null]];
            }
        }
        return $base + ['kind' => 'custom', 'meaning' => 'Custom or uninterpreted statement'];
    }

    private function routeLabel(array $route): string
    {
        return $route['type'] === 'request_route' ? 'Incoming SIP request: request_route' : $route['type'] . '[' . ($route['name'] ?? 'main') . ']';
    }

    /** @return list<list<int>> */
    private function routeCycles(array $edges): array
    {
        $adjacent = [];
        foreach ($edges as $edge) { if ($edge['kind'] === 'call' && isset($edge['to'])) { $adjacent[$edge['from']][] = $edge['to']; } }
        $cycles = []; $visiting = []; $visited = [];
        $visit = function (int $node, array $path) use (&$visit, &$adjacent, &$cycles, &$visiting, &$visited): void {
            if (isset($visiting[$node])) { $start = array_search($node, $path, true); $cycles[] = array_slice($path, $start === false ? 0 : $start); return; }
            if (isset($visited[$node])) { return; }
            $visiting[$node] = true;
            foreach ($adjacent[$node] ?? [] as $next) { $visit($next, [...$path, $node]); }
            unset($visiting[$node]); $visited[$node] = true;
        };
        foreach (array_keys($adjacent) as $node) { $visit($node, []); }
        return $cycles;
    }

    /** @return array<string,mixed> */
    private function systemPresentation(array $report, int $recognised, int $unclassified): array
    {
        $root = (string) ($report['root'] ?? '');
        $modules = [];
        foreach (($report['modules'] ?? []) as $module) {
            $name = strtolower((string) ($module['name'] ?? ''));
            if (($module['source']['confidence'] ?? '') === 'syntactic' && isset(self::MODULES[$name])) {
                $modules[$name] = $module;
            }
        }

        $listeners = [];
        foreach (($report['listeners'] ?? []) as $listener) {
            $interpreted = $this->listenerPresentation($listener);
            if ($interpreted !== null) {
                $listeners[] = $interpreted;
            }
        }
        usort($listeners, static fn(array $a, array $b): int => strnatcasecmp($a['description'], $b['description']));

        $routes = $this->routePresentation($report, $root);
        $findings = [];
        foreach ($listeners as $listener) {
            $findings[] = [
                'title' => 'SIP signalling',
                'explanation' => $listener['description'],
                'confidence' => $listener['confidence'],
                'evidence' => [['label' => 'Listener', 'source' => $listener['source']]],
                'caveat' => '',
            ];
        }

        foreach ([
            'tm' => ['Stateful transaction handling is available through tm.', 'Transaction handling'],
            'rr' => ['Record-Route / loose routing support is loaded.', 'Record-Route support'],
            'nathelper' => ['NAT handling support is loaded.', 'NAT traversal support'],
            'dispatcher' => ['Dispatcher and load-balancing support is loaded.', 'Dispatcher and load-balancing support'],
            'registrar' => ['SIP registration support is loaded.', 'SIP registration support'],
            'usrloc' => ['User location support is loaded.', 'User location support'],
            'auth' => ['SIP authentication support is loaded.', 'SIP authentication support'],
            'auth_db' => ['Database-backed SIP authentication support is loaded.', 'Database-backed authentication'],
            'dialog' => ['Dialog tracking support is loaded.', 'Dialog tracking'],
            'tls' => ['TLS transport support is loaded.', 'TLS transport support'],
            'websocket' => ['WebSocket transport support is loaded.', 'WebSocket transport support'],
        ] as $moduleName => [$explanation, $title]) {
            if (!isset($modules[$moduleName])) {
                continue;
            }
            $findings[] = [
                'title' => $title,
                'explanation' => $explanation,
                'confidence' => 'syntactic',
                'evidence' => [['label' => 'Module ' . $moduleName . ' loaded', 'source' => $modules[$moduleName]['source']]],
                'caveat' => 'A loaded module makes support available; it does not prove active use.',
            ];
        }

        foreach (['rtpengine' => 'RTPengine media handling', 'rtpproxy' => 'RTPProxy media handling'] as $moduleName => $title) {
            if (!isset($modules[$moduleName])) {
                continue;
            }
            $matchingRoutes = array_values(array_filter($routes['all'], static function (array $route) use ($moduleName): bool {
                if ($route['confidence'] !== 'syntactic') { return false; }
                if ($route['name'] !== null && stripos($route['name'], $moduleName) !== false) { return true; }
                foreach (($route['statements'] ?? []) as $statement) {
                    if (($statement['kind'] ?? '') === 'function-call' && str_starts_with((string) ($statement['function'] ?? ''), $moduleName . '_')) { return true; }
                }
                return false;
            }));
            $evidence = [['label' => 'Module ' . $moduleName . ' loaded', 'source' => $modules[$moduleName]['source']]];
            foreach ($matchingRoutes as $route) {
                $evidence[] = ['label' => $route['label'], 'source' => $route['source']];
            }
            $findings[] = [
                'title' => $title,
                'explanation' => $matchingRoutes === []
                    ? $title . ' support is loaded.'
                    : $title . ' appears to be configured.',
                'confidence' => 'syntactic',
                'evidence' => $evidence,
                'caveat' => $matchingRoutes === []
                    ? 'A loaded module makes support available; it does not prove active use.'
                    : 'This does not establish that media relay traffic is occurring.',
            ];
        }

        $confirmedCustomRoutes = array_values(array_filter($routes['custom'], static fn(array $route): bool => $route['confidence'] === 'syntactic'));
        if ($confirmedCustomRoutes !== []) {
            $count = count($confirmedCustomRoutes);
            $findings[] = [
                'title' => 'Custom routing logic detected',
                'explanation' => $count . ' named route' . ($count === 1 ? ' was' : 's were') . ' discovered in custom configuration components.',
                'confidence' => 'syntactic',
                'evidence' => array_map(static fn(array $route): array => ['label' => $route['label'], 'source' => $route['source']], $confirmedCustomRoutes),
                'caveat' => 'Custom route internals are not interpreted.',
            ];
        }
        usort($findings, static fn(array $a, array $b): int => strnatcasecmp($a['title'] . $a['explanation'], $b['title'] . $b['explanation']));

        $composition = $this->compositionPresentation($report, $root);
        $gaps = [];
        if ($unclassified > 0) {
            $gaps[] = $unclassified . ' loaded module' . ($unclassified === 1 ? ' is' : 's are') . ' unclassified.';
        }
        if ($confirmedCustomRoutes !== []) {
            $gaps[] = count($confirmedCustomRoutes) . ' custom named route' . (count($confirmedCustomRoutes) === 1 ? ' remains' : 's remain') . ' only partially understood.';
        }
        foreach ([
            'registrar/location' => ['registrar', 'usrloc'],
            'authentication' => ['auth', 'auth_db'],
            'dispatcher/load balancing' => ['dispatcher'],
            'database driver' => ['db_mysql', 'db_postgres', 'db_sqlite'],
        ] as $role => $names) {
            if (array_intersect($names, array_keys($modules)) === []) {
                $gaps[] = 'No recognised ' . $role . ' modules were found in the scanned configuration.';
            }
        }

        return [
            'findings' => $findings,
            'listeners' => $listeners,
            'routes' => $routes,
            'composition' => $composition,
            'confidence' => [
                'level' => (string) ($report['completeness']['confidence'] ?? 'partial'),
                'scope' => (string) ($report['completeness']['scope'] ?? 'unknown'),
                'reasons' => $report['completeness']['reasons'] ?? [],
                'gaps' => $gaps,
                'recognised_modules' => $recognised,
                'unclassified_modules' => $unclassified,
                'unknown_statements' => count($report['unknown'] ?? []),
                'warnings' => count($report['warnings'] ?? []),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function listenerPresentation(array $listener): ?array
    {
        if (($listener['value_classification'] ?? '') !== 'safe-network-listener') {
            return null;
        }
        $raw = trim((string) ($listener['raw'] ?? ''), " \t\r\n\"'");
        if (!preg_match('/^(?:(udp|tcp|tls|sctp|ws|wss):)?(\[[0-9a-f:]+\]|[A-Za-z0-9_.-]+)(?::([0-9]{1,5}))?/i', $raw, $match)) {
            return null;
        }
        $transport = strtolower($match[1] ?: 'udp');
        $address = $match[2];
        $endpoint = $address . (isset($match[3]) ? ':' . $match[3] : '');
        $label = ['udp' => 'SIP over UDP', 'tcp' => 'SIP over TCP', 'tls' => 'SIP over TLS', 'sctp' => 'SIP over SCTP', 'ws' => 'SIP over WebSocket', 'wss' => 'SIP over secure WebSocket'][$transport] ?? strtoupper($transport);
        $nonLoopback = !in_array(trim($address, '[]'), ['127.0.0.1', '::1', 'localhost'], true);
        return ['label' => $label, 'description' => $label . ' listening on ' . $endpoint . ($nonLoopback ? ' (non-loopback address).' : '.'), 'transport' => $transport, 'address' => $address, 'port' => isset($match[3]) ? (int) $match[3] : null, 'non_loopback' => $nonLoopback, 'source' => $listener['source'], 'confidence' => (string) ($listener['source']['confidence'] ?? 'unknown')];
    }

    /** @return array<string,mixed> */
    private function routePresentation(array $report, string $root): array
    {
        $types = ['request_route' => 'Main request route', 'route' => 'Named routes', 'onreply_route' => 'Reply routes', 'failure_route' => 'Failure routes', 'branch_route' => 'Branch routes', 'event_route' => 'Event routes', 'onsend_route' => 'Send routes'];
        $groups = array_fill_keys(array_keys($types), []);
        $all = [];
        foreach (($report['routes'] ?? []) as $route) {
            $type = (string) ($route['type'] ?? '');
            if (!isset($types[$type])) {
                continue;
            }
            $name = isset($route['name']) ? (string) $route['name'] : null;
            $entry = ['type' => $type, 'type_label' => $types[$type], 'name' => $name, 'label' => $type . ($name === null ? '' : '[' . $name . ']'), 'source' => $route['source'], 'confidence' => (string) ($route['source']['confidence'] ?? 'unknown'), 'component' => (string) ($route['source']['file'] ?? ''), 'statements' => $route['statements'] ?? []];
            $groups[$type][] = $entry;
            $all[] = $entry;
        }
        foreach ($groups as &$group) { usort($group, static fn(array $a, array $b): int => strnatcasecmp(($a['name'] ?? '') . $a['component'], ($b['name'] ?? '') . $b['component'])); }
        unset($group);
        $custom = array_values(array_filter($all, static fn(array $route): bool => $route['type'] === 'route' && $route['name'] !== null));
        $customByComponent = [];
        foreach ($custom as $route) {
            if ($route['component'] === $root) { continue; }
            $customByComponent[$route['component']][] = $route;
        }
        ksort($customByComponent, SORT_NATURAL | SORT_FLAG_CASE);
        return ['groups' => array_values(array_filter(array_map(static fn(string $type, array $items): array => ['key' => $type, 'label' => $types[$type], 'routes' => $items], array_keys($groups), $groups), static fn(array $group): bool => $group['routes'] !== [])), 'all' => $all, 'custom' => $custom, 'custom_by_component' => $customByComponent];
    }

    /** @return list<array<string,mixed>> */
    private function compositionPresentation(array $report, string $root): array
    {
        $includes = [];
        foreach (($report['includes'] ?? []) as $include) {
            if (($include['exists'] ?? false) === true) { $includes[(string) ($include['resolved'] ?? '')] = $include; }
        }
        $components = [];
        foreach (($report['files'] ?? []) as $file) {
            $components[] = ['path' => $file, 'kind' => $file === $root ? 'Main configuration' : 'Included configuration', 'included_from' => $includes[$file]['source'] ?? null, 'modules' => count(array_filter($report['modules'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'listeners' => count(array_filter($report['listeners'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'routes' => count(array_filter($report['routes'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file)), 'defines' => count(array_filter($report['defines'] ?? [], static fn(array $item): bool => ($item['source']['file'] ?? '') === $file))];
        }
        usort($components, static fn(array $a, array $b): int => ($a['kind'] === 'Main configuration' ? -1 : 1) <=> ($b['kind'] === 'Main configuration' ? -1 : 1) ?: strnatcasecmp($a['path'], $b['path']));
        return $components;
    }

    /** @return list<string> */
    public static function recognisedModuleNames(): array
    {
        $names = array_keys(self::MODULES);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }
}
