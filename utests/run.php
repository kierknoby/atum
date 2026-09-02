#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

$root = dirname(__DIR__); $failures = 0;
function check(bool $ok, string $message): void { global $failures; echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; if (!$ok) { $failures++; } }
function removeFixture(string $path): void { if (!is_dir($path)) { return; } $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($path); }

require_once $root . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
require_once $root . '/admin/libraries/Atum/Kamailio/Semantics.class.php';
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
check(count($report['unknown']) >= 4, 'scanner retains conditional, non-literal and unsupported configuration');
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
removeFixture($fixture);

$interpretationFixture = sys_get_temp_dir() . '/atum-interpretation-' . bin2hex(random_bytes(5)); mkdir($interpretationFixture, 0700);
mkdir($interpretationFixture . '/components', 0700);
file_put_contents($interpretationFixture . '/components/custom-routes.inc', "onreply_route[RTPengine] { return; }\nroute[POLICY] { return; }\nroute[DIALOG_BACKEND] { return; }\n");
file_put_contents($interpretationFixture . '/kamailio.cfg', "listen=udp:198.51.100.10:5060\nloadmodule \"tm.so\"\nloadmodule \"rr.so\"\nloadmodule \"nathelper.so\"\nloadmodule \"rtpengine.so\"\nloadmodule \"vendor_extension.so\"\ninclude_file \"components/custom-routes.inc\"\nrequest_route { return; }\n");
$interpretation = (new AtumKamailioSemantics())->present((new AtumKamailioScanner())->scan($interpretationFixture . '/kamailio.cfg'))['presentation']['system'];
$interpretationFindings = array_column($interpretation['findings'], 'explanation');
check(in_array('SIP over UDP listening on 198.51.100.10:5060 (non-loopback address).', $interpretationFindings, true), 'listener interpretation identifies SIP transport and non-loopback binding without claiming public reachability');
check(in_array('RTPengine media handling appears to be configured.', $interpretationFindings, true), 'module plus matching reply route produces stronger configured evidence');
$rtpengineFinding = array_values(array_filter($interpretation['findings'], static fn(array $finding): bool => $finding['title'] === 'RTPengine media handling'))[0];
check(count($rtpengineFinding['evidence']) === 2 && str_contains($rtpengineFinding['caveat'], 'does not establish'), 'correlated media finding preserves provenance and does not claim active traffic');
check(in_array('Stateful transaction handling is available through tm.', $interpretationFindings, true) && str_contains(implode(' ', array_column($interpretation['findings'], 'caveat')), 'does not prove active use'), 'module-only findings remain availability statements');
check(array_keys($interpretation['routes']['custom_by_component']) === [$interpretationFixture . '/components/custom-routes.inc'] && count($interpretation['routes']['custom_by_component'][$interpretationFixture . '/components/custom-routes.inc']) === 2, 'custom named routes are grouped by included source component');
check(array_column($interpretation['composition'], 'kind') === ['Main configuration', 'Included configuration'] && $interpretation['composition'][1]['routes'] === 3, 'configuration composition identifies included components and their discovered content');
check($interpretation['confidence']['level'] === 'partial' && $interpretation['confidence']['unclassified_modules'] === 1 && in_array('No recognised registrar/location modules were found in the scanned configuration.', $interpretation['confidence']['gaps'], true), 'partial confidence exposes unclassified content and conservative absence wording');
check($interpretation['confidence']['reasons'] === ['conditional preprocessing and custom/KEMI logic are not evaluated'], 'interpretation preserves scanner completeness limitations');
removeFixture($interpretationFixture);

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "FAIL  pdo_sqlite is mandatory; security tests cannot be skipped\n"); exit(1); }
$state = sys_get_temp_dir() . '/atum-state-' . bin2hex(random_bytes(5)); mkdir($state, 0700); putenv('ATUM_STATE_DIR=' . $state); putenv('KAMAILIO_CONFIG=' . $root . '/examples/kamailio.cfg');
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
check(in_array('dashboard.view', $permissions, true) && in_array('discovery.view', $permissions, true) && !in_array('admin', $permissions, true), 'module permissions register as explicit least-privilege grants');
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
check(!in_array('utests/run.php', $listed, true) && !in_array('.env', $listed, true), 'explicit install manifest excludes tests and arbitrary checkout files');

removeFixture($state);
printf("\n%d failure(s)\n", $failures); exit($failures === 0 ? 0 : 1);
