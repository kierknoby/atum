#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$skips = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

function skip(string $message): void
{
    global $skips;
    $skips++;
    echo "SKIP  {$message}\n";
}

require_once $root . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
try {
    $report = (new AtumKamailioScanner())->scan($root . '/examples/kamailio.cfg');
    check(count($report['files']) === 2, 'follows recursive includes');
    check(count($report['modules']) === 4, 'discovers loaded Kamailio modules');
    check(count($report['listeners']) === 2, 'discovers SIP listeners');
    check(count($report['routes']) === 3, 'discovers request and named routes');
    check($report['read_only'] === true, 'discovery report is explicitly read-only');
    check(($report['database_schemes'] ?? []) === ['mysql'], 'discovers database scheme without exposing credentials');
    $json = json_encode($report, JSON_UNESCAPED_SLASHES);
    check(!str_contains((string) $json, 'supersecret'), 'redacts database credentials');
    check(!str_contains((string) $json, 'define-secret-value'), 'redacts likely secret-bearing preprocessor defines');
    check(!str_contains((string) $json, 'define-password'), 'redacts credential-bearing DSNs even when the define name is not secret-like');
    check(!str_contains((string) $json, 'sql-password'), 'redacts credential-bearing DSNs embedded in connection strings');
    check(str_contains((string) $json, '<redacted-dsn>'), 'marks redacted DSNs');
} catch (Throwable $e) {
    check(false, 'discovery tests raised: ' . $e->getMessage());
}

if (!extension_loaded('pdo_sqlite')) {
    skip('framework/auth tests require pdo_sqlite; installer treats it as a mandatory dependency');
} else {
    $tmp = sys_get_temp_dir() . '/atum-test-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700, true);
    putenv('ATUM_STATE_DIR=' . $tmp);
    putenv('KAMAILIO_CONFIG=' . $root . '/examples/kamailio.cfg');
    require_once $root . '/admin/bootstrap.php';
    try {
        $atum = Atum::create();
        $atum->Modules->installBundled(true);
        $modules = $atum->Modules->getInfo();
        check(isset($modules['framework'], $modules['dashboard'], $modules['discovery'], $modules['moduleadmin'], $modules['userman'], $modules['auditlog']), 'loads modular Atum framework and security modules');
        check(Atum::Discovery() === Atum::Discovery(), 'BMO-style module accessor is stable');
        $id = $atum->Auth->createUser('testadmin', 'A-real-test-password-123!', 'admin');
        check($id > 0 && $atum->Auth->adminCount() === 1, 'creates a hashed local administrator account');
        $versionBefore = (int) $atum->State->db()->query("SELECT session_version FROM users WHERE username='testadmin'")->fetchColumn();
        $atum->Auth->changePassword($id, 'A-second-real-test-password-456!');
        $versionAfter = (int) $atum->State->db()->query("SELECT session_version FROM users WHERE username='testadmin'")->fetchColumn();
        check($versionAfter === $versionBefore + 1, 'password changes invalidate existing authenticated sessions');
        $row = $atum->State->db()->query("SELECT password_hash FROM users WHERE username='testadmin'")->fetch();
        check(is_array($row) && !str_contains((string) $row['password_hash'], 'A-real-test-password'), 'never stores the clear-text password');
        $system = $atum->System->check();
        check(isset($system['os'], $system['php'], $system['kamailio']), 'system pre-flight exposes OS, PHP and Kamailio state');
    } catch (Throwable $e) {
        check(false, 'framework/auth tests raised: ' . $e->getMessage());
    }
}

$install = file_get_contents($root . '/install.php');
$uninstall = file_get_contents($root . '/uninstall.php');
check(is_string($install) && str_contains($install, 'install-ledger.json'), 'installer writes a persistent install ledger');
check(is_string($uninstall) && str_contains($uninstall, 'Refusing to guess what belongs to Atum'), 'uninstaller refuses ledgerless removal');
check(is_string($uninstall) && str_contains($uninstall, "'installer_modified'"), 'uninstaller has an explicit Kamailio-installation mutation guard');
$bootstrap = file_get_contents($root . '/install');
$readme = file_get_contents($root . '/README.md');
$publicIndex = file_get_contents($root . '/public/index.php');
check(is_string($bootstrap) && str_contains($bootstrap, '--development'), 'system installation requires explicit development-preview acknowledgement');
check(is_string($readme) && str_contains($readme, 'NOT SUITABLE FOR PRODUCTION'), 'README prominently marks v0.1 as not suitable for production');
check(is_string($publicIndex) && str_contains($publicIndex, "REQUEST_METHOD'] === 'POST'") && str_contains($publicIndex, "isset(\$_POST['logout'])"), 'logout is state-changing POST rather than GET');

printf("\n%d failure(s), %d skip(s)\n", $failures, $skips);
exit($failures === 0 ? 0 : 1);
