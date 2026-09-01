#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

$root = dirname(__DIR__); $failures = 0;
function check(bool $ok, string $message): void { global $failures; echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; if (!$ok) { $failures++; } }
function removeFixture(string $path): void { if (!is_dir($path)) { return; } $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); } rmdir($path); }

require_once $root . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
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
removeFixture($fixture);

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
