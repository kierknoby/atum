#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

$root = dirname(__DIR__); $failures = 0;
function check(bool $ok, string $message): void { global $failures; echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; if (!$ok) { $failures++; } }
function removeFixture(string $path): void { if (!is_dir($path)) { return; } $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($path); }

require_once $root . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
require_once $root . '/admin/libraries/Atum/Kamailio/Semantics.class.php';
require_once $root . '/admin/libraries/Atum/Manifest.class.php';
$fixture = sys_get_temp_dir() . '/atum-scanner-' . bin2hex(random_bytes(5)); mkdir($fixture, 0700);
file_put_contents($fixture . '/included.cfg', "loadmodule 'dispatcher.so'\nroute[FROM_INCLUDE] { return; }\n");
file_put_contents($fixture . '/root.cfg', <<<'CFG'
#!define BESPOKE_AUTH "must-not-escape"
include_file "included.cfg"
include_file DYNAMIC_INCLUDE
#!ifdef WITH_DB
loadmodule "db_mysql.so"
#!endif
loadmodule "sl.so"
loadmodule "vendor_custom.so"
loadmodule "tm.so"
loadmodule "sanity.so"
modparam("sl", "debug", 1)
modparam("sl", "auth_key", "non-obvious-secret")
modparam("sl",
 "pin_code",
 "4321")
modparam("db_mysql", "db_url", "mysql://user:password@localhost/kamailio")
request_route {
    xlog("private routing value");
    custom_auth unquoted-secret-material;
}
CFG);
$report = (new AtumKamailioScanner())->scan($fixture . '/root.cfg');
$json = json_encode($report, JSON_UNESCAPED_SLASHES);
check(count($report['files']) === 2, 'scanner follows literal recursive includes');
check($report['completeness']['effective_configuration_proven'] === false, 'scanner states that effective configuration is not proven');
check(count($report['unknown']) >= 3 && ($report['routes'][1]['statements'][1]['kind'] ?? '') === 'custom', 'scanner retains top-level unknown content and structurally preserves route custom statements');
check(in_array('conditional', array_column(array_column($report['modules'], 'source'), 'confidence'), true), 'scanner marks conditional discoveries');
check(!str_contains((string) $json, 'must-not-escape') && !str_contains((string) $json, 'non-obvious-secret') && !str_contains((string) $json, 'unquoted-secret-material') && !str_contains((string) $json, '4321') && !str_contains((string) $json, 'password'), 'scanner fails closed for obvious, bespoke and unquoted secrets');
check(str_contains((string) $json, '"debug","value":"1"'), 'scanner retains positively classified safe values');
check(in_array('mysql', $report['database_schemes'], true), 'scanner reports a database scheme without credentials');

$presented = (new AtumKamailioSemantics())->present($report);
$modulePresentation = $presented['presentation']['modules'];
$presentedModules = [];
foreach ($modulePresentation['groups'] as $group) {
    foreach ($group['modules'] as $module) { $presentedModules[$module['name']] = $module; }
}
$capabilityLabels = array_column($modulePresentation['capabilities'], 'label');
check($modulePresentation['coverage'] === ['total' => 6, 'recognised' => 5, 'unclassified' => 1], 'module presentation reports recognised and unclassified coverage');
check($presentedModules['tm']['purpose'] === 'Stateful SIP transaction management' && $presentedModules['tm']['semantic_status'] === 'recognised', 'recognised modules receive concise purpose metadata');
check($presentedModules['vendor_custom']['semantic_status'] === 'unclassified' && $presentedModules['vendor_custom']['group_label'] === 'Other / unclassified', 'unknown modules remain visible without guessed classification');
check(array_column($modulePresentation['groups'], 'label') === ['Core SIP processing', 'Transactions and routing', 'Dispatching and load balancing', 'Database and storage', 'Other / unclassified'], 'module groups use deterministic taxonomy order');
check(array_column($modulePresentation['groups'][0]['modules'], 'name') === ['sanity', 'sl'], 'modules are ordered deterministically within functional groups');
check(in_array('Transaction handling', $capabilityLabels, true) && in_array('Dispatcher and load-balancing support', $capabilityLabels, true), 'confident recognised modules produce loaded-capability summaries');
check(!in_array('MySQL/MariaDB module support', $capabilityLabels, true) && !str_contains(implode(' ', $capabilityLabels), 'vendor_custom'), 'conditional and unknown modules do not produce false capability inference');
$rawSl = $report['modules'][array_search('sl', array_column($report['modules'], 'name'), true)];
check($presentedModules['sl']['source'] === $rawSl['source'] && $presentedModules['sl']['params'][0]['source'] === $rawSl['params'][0]['source'], 'module and parameter provenance survive semantic presentation');
check(count($presentedModules['sl']['params']) === 3 && $presentedModules['tm']['params'] === [], 'modules with and without discovered parameters are preserved');
check(AtumKamailioSemantics::recognisedModuleNames() === array_values(array_unique(AtumKamailioSemantics::recognisedModuleNames())), 'recognised module catalogue is deterministic and unique');
$discoveryManifest = AtumManifest::parse($root . '/admin/modules/discovery/module.xml');
check(array_column($discoveryManifest['menuitems'][0]['children'], 'id') === ['overview', 'connectivity', 'routing', 'media', 'access', 'evidence'], 'Discovery keeps technical trace navigation under Configuration and Evidence');
$dashboardManifest = AtumManifest::parse($root . '/admin/modules/dashboard/module.xml');
check($dashboardManifest['menuitems'][0]['children'] === [], 'flat menu manifests remain backward compatible');
$systemMapManifest = AtumManifest::parse($root . '/admin/modules/systemmap/module.xml');
$callHandlingManifest = AtumManifest::parse($root . '/admin/modules/callhandling/module.xml');
check($systemMapManifest['menuitems'][0]['category'] === 'System' && $systemMapManifest['permission'] === 'systemmap.view' && $systemMapManifest['depends'] === ['framework', 'discovery'], 'System Map registers as a least-privilege System module over Discovery');
check($callHandlingManifest['menuitems'][0]['category'] === 'Routing' && $callHandlingManifest['permission'] === 'callhandling.view' && $callHandlingManifest['depends'] === ['framework', 'discovery'], 'Call Handling registers as a least-privilege Routing module over Discovery');
removeFixture($fixture);

$interpretationFixture = sys_get_temp_dir() . '/atum-interpretation-' . bin2hex(random_bytes(5)); mkdir($interpretationFixture, 0700);
mkdir($interpretationFixture . '/components', 0700);
file_put_contents($interpretationFixture . '/components/custom-routes.inc', <<<'CFG'
onreply_route[RTP] {
    rtpengine_answer("secret media flags");
    return;
}
failure_route[FAILED] {
    send_reply(500, "private failure");
}
branch_route[BRANCH] {
    add_contact_alias();
}
route[POLICY] {
    if (is_method("INVITE")) {
        rtpengine_offer("private flags");
        route(BACKEND);
    }
    custom_vendor_logic secret-material;
}
route[BACKEND] {
    ds_select_dst(1, 4);
    route(LOOP);
}
route[LOOP] {
    route(BACKEND);
}
route[ORPHAN] {
    return;
}
CFG);
file_put_contents($interpretationFixture . '/kamailio.cfg', <<<'CFG'
listen=udp:198.51.100.10:5060
loadmodule "tm.so"
loadmodule "rr.so"
loadmodule "nathelper.so"
loadmodule "rtpengine.so"
loadmodule "vendor_extension.so"
include_file "components/custom-routes.inc"
request_route {
    record_route();
    t_on_reply("RTP");
    t_on_failure("FAILED");
    t_on_branch("BRANCH");
    route(POLICY);
    route($var(dynamic_target));
    t_relay();
}
CFG);
$routeReport = (new AtumKamailioScanner())->scan($interpretationFixture . '/kamailio.cfg');
$routeJson = json_encode($routeReport, JSON_UNESCAPED_SLASHES);
$presentedRouteReport = (new AtumKamailioSemantics())->present($routeReport);
$interpretation = $presentedRouteReport['presentation']['system'];
$includedRouteComponent = realpath($interpretationFixture . '/components/custom-routes.inc');
$requestProcessing = $presentedRouteReport['presentation']['request_processing'];
$operator = $presentedRouteReport['presentation']['operator'];
$interpretationFindings = array_column($interpretation['findings'], 'explanation');
check(in_array('SIP over UDP listening on 198.51.100.10:5060 (non-loopback address).', $interpretationFindings, true), 'listener interpretation identifies SIP transport and non-loopback binding without claiming public reachability');
check(in_array('RTPengine media handling appears to be configured.', $interpretationFindings, true), 'module plus matching reply route produces stronger configured evidence');
$rtpengineFinding = array_values(array_filter($interpretation['findings'], static fn(array $finding): bool => $finding['title'] === 'RTPengine media handling'))[0];
check(count($rtpengineFinding['evidence']) >= 2 && str_contains($rtpengineFinding['caveat'], 'does not establish'), 'correlated media finding preserves provenance and does not claim active traffic');
check(in_array('Stateful transaction handling is available through tm.', $interpretationFindings, true) && str_contains(implode(' ', array_column($interpretation['findings'], 'caveat')), 'does not prove active use'), 'module-only findings remain availability statements');
check(is_string($includedRouteComponent) && array_keys($interpretation['routes']['custom_by_component']) === [$includedRouteComponent] && count($interpretation['routes']['custom_by_component'][$includedRouteComponent] ?? []) === 4, 'custom named routes are grouped by included source component');
$interpretationAlias = $interpretationFixture . '-alias';
if (!symlink($interpretationFixture, $interpretationAlias)) { throw new RuntimeException('Unable to create equivalent-path interpretation fixture'); }
$aliasRouteReport = (new AtumKamailioScanner())->scan($interpretationAlias . '/kamailio.cfg');
$aliasInterpretation = (new AtumKamailioSemantics())->present($aliasRouteReport)['presentation']['system'];
check($aliasRouteReport['root'] === $routeReport['root']
    && $aliasRouteReport['files'] === $routeReport['files']
    && array_column($aliasRouteReport['includes'], 'resolved') === array_column($routeReport['includes'], 'resolved')
    && array_column($aliasRouteReport['routes'], 'source') === array_column($routeReport['routes'], 'source')
    && $aliasInterpretation['routes']['custom_by_component'] === $interpretation['routes']['custom_by_component'], 'equivalent filesystem paths preserve canonical provenance and route grouping');
unlink($interpretationAlias);
check(array_column($interpretation['composition'], 'kind') === ['Main configuration', 'Included configuration'] && $interpretation['composition'][1]['routes'] === 7, 'configuration composition identifies included components and their discovered content');
check($interpretation['confidence']['level'] === 'partial' && $interpretation['confidence']['unclassified_modules'] === 1 && in_array('No recognised registrar/location modules were found in the scanned configuration.', $interpretation['confidence']['gaps'], true), 'partial confidence exposes unclassified content and conservative absence wording');
check($interpretation['confidence']['reasons'] === ['conditional preprocessing and custom/KEMI logic are not evaluated'], 'interpretation preserves scanner completeness limitations');
check($requestProcessing['flows'][0]['label'] === 'onreply_route[RTP]' || in_array('Incoming SIP request: request_route', array_column($requestProcessing['flows'], 'label'), true), 'route-body model preserves route type, source and ordered statements');
$requestFlow = array_values(array_filter($requestProcessing['flows'], static fn(array $flow): bool => $flow['type'] === 'request_route'))[0];
check(array_column($requestFlow['statements'], 'meaning') === ['Apply Record-Route', 'Reply processing is assigned to onreply_route[RTP]', 'Failure processing is assigned to failure_route[FAILED]', 'Branch processing is assigned to branch_route[BRANCH]', 'Call route[POLICY]', 'Dynamic route call', 'Relay request statefully'], 'request flow recognises ordered actions, static calls, wiring and terminal relay');
$policyFlow = array_values(array_filter($requestProcessing['flows'], static fn(array $flow): bool => $flow['name'] === 'POLICY'))[0];
check($policyFlow['statements'][0]['meaning'] === 'If request method is INVITE' && $policyFlow['statements'][1]['conditions'] === ['If request method is INVITE'] && $policyFlow['statements'][3]['kind'] === 'custom', 'method conditions, nesting and unknown custom statements are preserved');
check(count($requestProcessing['edges']) === 7 && $requestProcessing['coverage']['unresolved'] === 1 && $requestProcessing['coverage']['custom'] >= 1, 'graph records static route and reply/failure/branch edges while retaining unresolved/custom nodes');
check($requestProcessing['coverage']['cycles'] !== [] && array_column($requestProcessing['coverage']['unreferenced'], 'name') === ['ORPHAN'], 'graph detects recursive calls and reports only no-static-reference routes');
check(!str_contains((string) $routeJson, 'secret media flags') && !str_contains((string) $routeJson, 'secret-material'), 'route action arguments and custom statements remain redacted');
check(in_array('This system appears primarily to act as a SIP routing proxy.', $operator['overview'], true) && in_array('Media-relay processing is present in interpreted call paths.', $operator['overview'], true), 'Discovery operator summary is adapted from evidence-backed System Model roles');
check(array_column($operator['stages'], 'title') === array_column($presentedRouteReport['system_model']['journeys']['new-call']['stages'], 'label'), 'Discovery overview reuses compressed System Model stages');
check(($operator['stages'][0]['evidence'][0]['source']['file'] ?? '') !== '' && ($operator['stages'][array_key_last($operator['stages'])]['evidence'][0]['meaning'] ?? '') === 'Relay request statefully', 'System Model stage adapter retains technical evidence beneath primary labels');
check(array_column($operator['media'], 'meaning') === ['Apply RTPengine answer processing', 'Apply RTPengine offer processing'] && array_column($operator['access'], 'meaning') === [], 'operator media distinguishes interpreted request/reply processing from absent access handling');
check(in_array('Dynamic route call', array_column($operator['gaps'], 'meaning'), true) && in_array('Custom or uninterpreted statement', array_column($operator['gaps'], 'meaning'), true), 'operator model keeps custom and unresolved flow steps visible');
removeFixture($interpretationFixture);

$conditionFixture = sys_get_temp_dir() . '/atum-conditions-' . bin2hex(random_bytes(5)); mkdir($conditionFixture, 0700);
file_put_contents($conditionFixture . '/kamailio.cfg', "request_route {\nif (\$rm == \"BYE\") {\nreturn;\n}\nif (\$si == \"192.0.2.10\") {\nreturn;\n}\nif (\$sp == 5060) {\nreturn;\n}\n}\n");
$conditionStatements = (new AtumKamailioScanner())->scan($conditionFixture . '/kamailio.cfg')['routes'][0]['statements'];
check(array_column(array_values(array_filter($conditionStatements, static fn(array $statement): bool => $statement['kind'] === 'condition')), 'meaning') === ['If request method is BYE', 'If source IP equals 192.0.2.10', 'If source port equals 5060'] && array_column(array_values(array_filter($conditionStatements, static fn(array $statement): bool => $statement['kind'] === 'control')), 'control') === ['return', 'return', 'return'], 'common literal method, source IP and source port conditions are interpreted without retaining raw expressions');
removeFixture($conditionFixture);

$mediaFixture = sys_get_temp_dir() . '/atum-media-' . bin2hex(random_bytes(5)); mkdir($mediaFixture, 0700);
file_put_contents($mediaFixture . '/kamailio.cfg', <<<'CFG'
loadmodule "rtpengine.so"
loadmodule "nathelper.so"
request_route {
    t_on_reply("MEDIA_REPLY");
    t_on_failure("MEDIA_FAILED");
    if (is_method("INVITE")) {
        rtpengine_offer("private offer flags");
        fix_nated_contact();
    }
    if (is_method("BYE")) {
        rtpengine_delete("private cleanup flags");
    }
    if (is_method("CANCEL")) {
        rtpengine_delete("private cancel flags");
    }
    t_relay();
}
onreply_route[MEDIA_REPLY] {
    rtpengine_answer("private answer flags");
}
failure_route[MEDIA_FAILED] {
    rtpengine_delete("private failure flags");
}
route[IN_DIALOG] {
    if (has_totag()) {
        rtpengine_manage("private manage flags");
        rtpengine_manage("private manage flags again");
    }
    rtpengine_delete("private unknown cleanup flags");
}
CFG);
$mediaReport = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($mediaFixture . '/kamailio.cfg'));
$mediaModel = $mediaReport['presentation']['media'];
$mediaStages = array_column($mediaModel['stages'], null, 'key');
$mediaJson = json_encode($mediaReport, JSON_UNESCAPED_SLASHES);
check($mediaModel['available'] && $mediaModel['used_in_flow'], 'loaded RTPengine is distinguished from RTPengine used in the interpreted flow');
check(array_column($mediaModel['stages'], 'key') === ['setup', 'reply', 'in-dialog', 'cleanup'] && $mediaStages['cleanup']['count'] === 4, 'media operations aggregate deterministically into lifecycle stages and one cleanup concept');
check(array_keys($mediaStages['cleanup']['triggers']) === ['On a configured transaction failure path', 'Other media path; trigger not statically determined', 'When BYE is processed', 'When CANCEL is processed'], 'media cleanup names BYE, CANCEL and failure triggers only when structural evidence proves them');
check($mediaStages['setup']['evidence'][0]['nat_related'] === true && $mediaStages['reply']['triggers']['During SIP reply processing'] !== [], 'NAT is associated only with the co-located media path and replies remain distinct');
check($mediaStages['setup']['triggers']['During INVITE call setup'] !== [], 'media setup explains INVITE processing only when the condition proves it');
check($mediaStages['in-dialog']['count'] === 2 && $mediaStages['in-dialog']['triggers']['During an existing call'] !== [], 'repeated media management aggregates by established-call context');
check(!str_contains((string) $mediaJson, 'private offer flags') && !str_contains((string) $mediaJson, 'private unknown cleanup flags'), 'media aggregation preserves route argument redaction');
file_put_contents($mediaFixture . '/loaded-only.cfg', "loadmodule \"rtpengine.so\"\nrequest_route { return; }\n");
$loadedOnlyMedia = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($mediaFixture . '/loaded-only.cfg'))['presentation']['media'];
check($loadedOnlyMedia['available'] && !$loadedOnlyMedia['used_in_flow'] && $loadedOnlyMedia['stages'] === [], 'loaded-only RTPengine is not represented as active media processing');
removeFixture($mediaFixture);

$systemModelReport = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($root . '/utests/fixtures/kam0-system/kamailio.cfg'));
$systemModel = $systemModelReport['system_model'];
$secondSystemModel = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($root . '/utests/fixtures/kam0-system/kamailio.cfg'))['system_model'];
$journeys = $systemModel['journeys'];
$mapLabels = array_column($systemModel['map']['objects'], 'label');
$primaryLabels = array_merge(
    [$systemModel['server']['primary_role']],
    $mapLabels,
    array_column($journeys, 'label'),
    ...array_values(array_map(static fn(array $journey): array => array_column($journey['stages'], 'label'), $journeys))
);
check($systemModel === $secondSystemModel && $systemModel['schema_version'] === 1, 'System Model output is deterministic and versioned');
check($systemModel['server']['primary_role'] === 'SIP routing proxy' && array_column($systemModel['server']['roles'], 'key') === ['routing-proxy', 'dispatcher', 'media-proxy'], 'System Model infers evidence-backed server roles without claiming an SBC role');
check(count($systemModel['interfaces']) === 1 && $systemModel['interfaces'][0]['transport'] === 'UDP' && $systemModel['interfaces'][0]['address'] === '198.51.100.20' && $systemModel['interfaces'][0]['port'] === 5060 && $systemModel['interfaces'][0]['scope'] === 'non-loopback', 'System Model describes where SIP signalling enters');
check(array_column($journeys['new-call']['stages'], 'label') === ['Initial checks', 'Keep this server in the signalling path', 'Routing policy', 'Custom processing', 'Select destination', 'Prepare media', 'Forward call'] && count($journeys['new-call']['stages'][0]['evidence']) === 2, 'new-call journey aggregates low-level operations into system stages while preserving custom processing');
check(array_column($journeys['existing-call']['stages'], 'label') === ['Existing-call handling', 'Media handling', 'Forward request'], 'existing-dialog evidence produces an existing-call journey');
check(array_column($journeys['bye']['stages'], 'label') === ['Termination processing', 'Remove media session', 'Forward request'] && $journeys['bye']['outcome']['key'] === 'forward', 'BYE evidence produces a call-termination journey with its actual outcome');
check(array_column($journeys['cancel']['stages'], 'label') === ['Cancellation handling', 'Media cleanup', 'Forward request'] && $journeys['cancel']['outcome']['key'] === 'forward', 'CANCEL evidence produces a distinct cancellation journey with its actual outcome');
check(!isset($journeys['register']) && !$systemModel['access']['registration']['identified'] && $systemModel['access']['registration']['summary'] === 'Local endpoint registration handling was not identified in the interpreted configuration.' && !$systemModel['access']['authentication']['identified'], 'registration and authentication absence is conservative and no fake REGISTER journey is created');
check($systemModel['destinations'][0]['mechanism'] === 'dispatcher' && $systemModel['destinations'][0]['backing_source'] === 'database' && !$systemModel['destinations'][0]['target_known'] && $systemModel['destinations'][0]['summary'] === 'A backend is selected using an external database; destination records were not read.', 'backend selection distinguishes an external backing mechanism from an unknown final destination');
$mediaRelationships = array_column($systemModel['media']['relationships'], null, 'key');
check($systemModel['media']['used'] && $systemModel['media']['label'] === 'RTPengine media relay' && in_array('When BYE is processed', $mediaRelationships['cleanup']['triggers'], true) && in_array('When CANCEL is processed', $mediaRelationships['cleanup']['triggers'], true), 'System Model relates media relay to setup, reply, existing-call and proven cleanup journeys');
check(count($systemModel['custom_components']) === 1 && $systemModel['custom_components'][0]['label'] === 'Custom routing component' && $systemModel['custom_components'][0]['areas'] === ['Routing', 'Media and NAT'] && $systemModel['custom_components'][0]['custom_decisions'] === 1, 'included custom configuration remains a visible system object based on interpreted contents');
check(in_array('One custom system-level routing decision remains only partially understood.', $systemModel['gaps'], true) && !str_contains(implode(' ', $systemModel['gaps']), 'low-level processing statements'), 'understanding reports system-level gaps instead of overwhelming raw statement counts');
check(in_array('Backend selection', $mapLabels, true) && in_array('Media relay', $mapLabels, true) && in_array('Custom routing component', $mapLabels, true) && count($systemModel['map']['relationships']) >= 6, 'System Map objects and connections derive from the shared model');
check(!preg_match('/request_route|route\[|onreply_route|failure_route|t_relay|t_on_reply|rtpengine_manage|rtpengine_delete|ds_select_dst|has_totag|\$ru|\$du/i', implode(' ', $primaryLabels)), 'primary System Map and Call Handling labels require no Kamailio syntax knowledge');
check(!str_contains((string) json_encode($systemModelReport, JSON_UNESCAPED_SLASHES), 'fixture-password') && !str_contains((string) json_encode($systemModelReport, JSON_UNESCAPED_SLASHES), 'fixture flags must remain redacted'), 'System Model preserves scanner credential and route-argument redaction');
$knownDestinationProvider = new class implements AtumSystemModelProvider {
    public function provide(array $discovery): array
    {
        return ['destinations' => [[
            'id' => 'known-upstream',
            'label' => 'Known upstream',
            'mechanism' => 'static',
            'knowledge' => 'known',
            'target_known' => true,
            'backing_source' => 'read-only fixture provider',
            'summary' => 'A specific upstream SIP destination is known.',
            'evidence' => [['kind' => 'backing-data', 'source' => ['provider' => 'fixture']]],
        ]]];
    }
};
$providerModel = (new AtumKamailioSystemModel([$knownDestinationProvider]))->build($systemModelReport);
check($providerModel['basis']['backing_data_evidence'] && in_array(true, array_column($providerModel['destinations'], 'target_known'), true) && in_array('Known upstream', array_column($providerModel['map']['objects'], 'label'), true), 'read-only providers can add known backing-data objects without replacing static discovery');

$registerFixture = sys_get_temp_dir() . '/atum-register-model-' . bin2hex(random_bytes(5)); mkdir($registerFixture, 0700);
file_put_contents($registerFixture . '/kamailio.cfg', "listen=udp:127.0.0.1:5060\nloadmodule \"registrar.so\"\nloadmodule \"usrloc.so\"\nloadmodule \"auth.so\"\nrequest_route {\nif (is_method(\"REGISTER\")) {\nwww_authorize(\"fixture.invalid\", \"subscriber\");\nsave(\"location\");\nsend_reply(200, \"accepted\");\n}\n}\n");
$registerModel = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($registerFixture . '/kamailio.cfg'))['system_model'];
check(isset($registerModel['journeys']['register']) && array_column($registerModel['journeys']['register']['stages'], 'label') === ['Authenticate endpoint', 'Save endpoint contact', 'Send SIP response'] && $registerModel['access']['registration']['identified'] && $registerModel['access']['authentication']['identified'], 'REGISTER journey appears only when authentication and contact handling are positively recognised');
removeFixture($registerFixture);

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "FAIL  pdo_sqlite is mandatory; security tests cannot be skipped\n"); exit(1); }
$state = sys_get_temp_dir() . '/atum-state-' . bin2hex(random_bytes(5)); mkdir($state, 0700); putenv('ATUM_STATE_DIR=' . $state); putenv('KAMAILIO_CONFIG=' . $root . '/utests/fixtures/kam0-system/kamailio.cfg');
require_once $root . '/admin/bootstrap.php'; $atum = Atum::create(); $atum->Modules->installBundled(true);
$admin1 = $atum->Auth->createUser('admin-one', 'Correct horse battery 123', 'admin');
$admin2 = $atum->Auth->createUser('admin-two', 'Correct horse battery 456', 'admin');
$viewer = $atum->Auth->createUser('viewer-one', 'Correct horse battery 789', 'viewer');
$hash = (string) $atum->State->db()->query("SELECT password_hash FROM users WHERE id=$admin1")->fetchColumn();
check(password_verify('Correct horse battery 123', $hash) && !password_verify('wrong password', $hash), 'password hashing verifies correct and rejects incorrect passwords');
$atum->Auth->setEnabled($admin1, false); $blocked = false; try { $atum->Auth->deleteUser($admin2); } catch (RuntimeException) { $blocked = true; }
check($blocked && $atum->Auth->adminCount() === 1, 'immediate transaction preserves the last enabled administrator');
$before = (int) $atum->State->db()->query("SELECT session_version FROM users WHERE id=$viewer")->fetchColumn(); $atum->Auth->changePassword($viewer, 'Changed viewer password 123'); $after = (int) $atum->State->db()->query("SELECT session_version FROM users WHERE id=$viewer")->fetchColumn();
check($after === $before + 1, 'password changes invalidate existing session versions');
$permissions = $atum->State->db()->query("SELECT permission FROM role_permissions WHERE role='viewer' ORDER BY permission")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('dashboard.view', $permissions, true) && in_array('discovery.view', $permissions, true) && in_array('systemmap.view', $permissions, true) && in_array('callhandling.view', $permissions, true) && !in_array('admin', $permissions, true), 'operator modules register explicit least-privilege viewer permissions');
if (!defined('ATUM_IS_AUTH')) { define('ATUM_IS_AUTH', true); }
$systemMapHtml = $atum->Systemmap->showPage();
$callHandlingHtml = $atum->Callhandling->showPage();
check(str_contains($systemMapHtml, 'SIP routing proxy') && str_contains($systemMapHtml, 'SIP signalling') && str_contains($systemMapHtml, 'Media relay') && str_contains($systemMapHtml, 'Backend selection') && str_contains($systemMapHtml, 'Custom routing component'), 'System Map renders the realistic fixture as connected operator-language system objects');
check(str_contains($systemMapHtml, 'map-relationships') && str_contains($systemMapHtml, 'View Discovery evidence') && !str_contains($systemMapHtml, 'flow-route'), 'System Map renders relationships and an evidence path without embedding the processing trace');
check(str_contains($callHandlingHtml, 'New calls') && str_contains($callHandlingHtml, 'Requests within an existing call') && str_contains($callHandlingHtml, 'Call termination') && str_contains($callHandlingHtml, 'Cancelled calls'), 'Call Handling renders all four evidence-backed realistic-fixture journeys');
check(str_contains($callHandlingHtml, 'Initial checks') && str_contains($callHandlingHtml, 'Select destination') && str_contains($callHandlingHtml, 'Remove media session') && str_contains($callHandlingHtml, 'Media cleanup') && str_contains($callHandlingHtml, 'Forward call'), 'Call Handling renders compressed stages and outcomes rather than one row per operation');
check(!preg_match('/request_route|route\[|onreply_route|failure_route|t_relay|t_on_reply|rtpengine_manage|rtpengine_delete|ds_select_dst|has_totag|\$ru|\$du/i', $systemMapHtml . $callHandlingHtml), 'rendered primary operator modules contain no raw Kamailio route or function vocabulary');
$atum->Audit->log('test.secret', 'failure', 'test', '1', 'mysql://user:secret@host/db'); $detail = (string) $atum->State->db()->query("SELECT detail FROM audit_log WHERE action='test.secret' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($detail === 'event=detail_redacted', 'audit destination rejects secret-bearing arbitrary detail');
check(AtumView::escape('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'view escaping neutralises HTML');
$operationFile = $state . '/declared-operation';
$operation = new class($operationFile) implements AtumChangeOperation {
    public function __construct(private string $path) {}
    public function describe(): array { return ['type' => 'test-file', 'target' => 'state-fixture']; }
    public function validate(): void {}
    public function apply(): void { file_put_contents($this->path, 'applied'); }
    public function verify(): void { throw new RuntimeException('verification failed with mysql://user:secret@host/db'); }
    public function rollback(): void { if (is_file($this->path)) { unlink($this->path); } }
};
$rolledBack = false; try { $atum->Lifecycle->execute('framework', [$operation]); } catch (RuntimeException) { $rolledBack = !file_exists($operationFile); }
$lifecycleStatus = (string) $atum->State->db()->query('SELECT status FROM lifecycle_journal ORDER BY id DESC LIMIT 1')->fetchColumn();
check($rolledBack && $lifecycleStatus === 'rolled_back', 'declared lifecycle operations are journalled and rolled back after verification failure');

$badManifest = sys_get_temp_dir() . '/atum-manifest-' . bin2hex(random_bytes(5)) . '.xml'; file_put_contents($badManifest, '<module><rawname>a</rawname><rawname>b</rawname><name>A</name><version>1</version></module>'); $rejected = false; try { AtumManifest::parse($badManifest); } catch (RuntimeException) { $rejected = true; } unlink($badManifest);
check($rejected, 'manifest parser rejects ambiguous duplicate identity fields');

$listed = array_filter(file($root . '/install-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)); $missing = array_filter($listed, static fn($file) => !is_file($root . '/' . $file));
check($missing === [], 'explicit install manifest names only present regular files');
check(in_array('admin/libraries/Atum/Kamailio/SystemModel.class.php', $listed, true) && in_array('admin/modules/systemmap/views/default.php', $listed, true) && in_array('admin/modules/callhandling/views/default.php', $listed, true), 'explicit install manifest includes the shared model and operator modules');
check(!in_array('utests/run.php', $listed, true) && !in_array('.env', $listed, true), 'explicit install manifest excludes tests and arbitrary checkout files');

removeFixture($state);
printf("\n%d failure(s)\n", $failures); exit($failures === 0 ? 0 : 1);
