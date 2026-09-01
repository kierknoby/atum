#!/usr/bin/env php
<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Installer must be run from the command line.\n");
    exit(1);
}
if (getenv('ATUM_INSTALL_BOOTSTRAPPED') !== '1') {
    fwrite(STDERR, "Do not invoke install.php directly. Run ./install --development from the repository root.\n");
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "System installation must run as root.\n");
    exit(1);
}
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    fwrite(STDERR, "Atum requires PHP 8.2 or newer.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Atum requires pdo_sqlite.\n");
    exit(1);
}

$options = [
    'prefix' => '/usr/share/atum',
    'state-dir' => '/var/lib/atum',
    'config-dir' => '/etc/atum',
    'kamailio-config' => '',
    'os-id' => 'unknown',
    'package-manager' => '',
    'packages-added' => '',
    'user-created' => '0',
    'group-created' => '0',
    'install-id' => '',
    'transaction-dir' => '/var/lib/atum-install-transaction',
    'remote' => '0',
    'listen-address' => '',
    'listen-port' => '',
    'web-server' => '',
    'web-config' => '',
    'web-enable-link' => '',
    'web-service' => '',
    'web-group' => '',
    'fpm-config' => '',
    'fpm-socket' => '',
    'fpm-service' => '',
    'service-command' => 'systemctl',
    'openssl' => 'openssl',
];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    if (array_key_exists($key, $options)) {
        $options[$key] = $value;
    }
}

$source = __DIR__;
$target = rtrim($options['prefix'], '/');
$stateDir = rtrim($options['state-dir'], '/');
$configDir = rtrim($options['config-dir'], '/');
$kamailioConfig = $options['kamailio-config'];
$cliLink = '/usr/local/sbin/atum';
$uninstallLink = '/usr/local/sbin/atum-uninstall';
$uninstallLinkTarget = $target . '/uninstall.php';
$stage = dirname($target) . '/.' . basename($target) . '.stage-' . bin2hex(random_bytes(6));
$created = [];
$committed = false;
$cliCreated = false;
$uninstallCreated = false;
$installId = $options['install-id'];
if (!preg_match('/^[a-f0-9]{32}$/', $installId)) { throw new RuntimeException('Invalid provisional installation ID.'); }
$transactionDir = rtrim($options['transaction-dir'], '/');
$remoteDeployment = null;
$remoteIntegration = null;

$kamailioSnapshot = [];
$snapshotScope = ['scope' => 'no Kamailio configuration selected', 'confidence' => 'none', 'effective_configuration_proven' => false];
if ($kamailioConfig !== '' && is_readable($kamailioConfig)) {
    require_once $source . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
    $initialScan = (new AtumKamailioScanner())->scan($kamailioConfig);
    $snapshotScope = $initialScan['completeness'];
    foreach ($initialScan['files'] as $file) {
        if (is_file($file) && is_readable($file)) {
            $kamailioSnapshot[$file] = hash_file('sha256', $file);
        }
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function journalWrite(string $directory, string $name, string $value): void
{
    $temporary = $directory . '/.' . $name . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $value, LOCK_EX) === false || !rename($temporary, $directory . '/' . $name)) {
        @unlink($temporary); throw new RuntimeException('Unable to update provisional installation journal.');
    }
    @chmod($directory . '/' . $name, 0600);
}

function copyTree(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create staging directory ' . $target);
    }
    $manifest = $source . '/install-files.txt';
    $entries = is_readable($manifest) ? file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if (!is_array($entries)) { throw new RuntimeException('Install file manifest is missing.'); }
    $entries[] = 'install-files.txt';
    foreach ($entries as $relative) {
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $relative) || str_contains($relative, '..')) { throw new RuntimeException('Unsafe install manifest entry: ' . $relative); }
        $item = $source . '/' . $relative;
        if (!is_file($item) || is_link($item)) { throw new RuntimeException('Install manifest file is missing or not regular: ' . $relative); }
        $destination = $target . '/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) { throw new RuntimeException('Unable to create ' . dirname($destination)); }
        if (!copy($item, $destination)) { throw new RuntimeException('Unable to copy ' . $item); }
    }
}

function readSecret(string $prompt): string
{
    $file = getenv('ATUM_ADMIN_PASSWORD_FILE');
    if ($file !== false && $file !== '') {
        $value = @file_get_contents($file);
        if ($value === false) {
            throw new RuntimeException('Unable to read ATUM_ADMIN_PASSWORD_FILE.');
        }
        return rtrim($value, "\r\n");
    }
    $tty = @fopen('/dev/tty', 'r+');
    if ($tty === false) {
        throw new RuntimeException('A TTY or ATUM_ADMIN_PASSWORD_FILE is required to create the first administrator.');
    }
    fwrite($tty, $prompt);
    $state = trim((string) shell_exec('stty -g < /dev/tty'));
    shell_exec('stty -echo < /dev/tty');
    try {
        $value = rtrim((string) fgets($tty), "\r\n");
    } finally {
        shell_exec('stty ' . escapeshellarg($state !== '' ? $state : 'echo') . ' < /dev/tty');
        fwrite($tty, "\n");
        fclose($tty);
    }
    return $value;
}

function prompt(string $prompt, string $default): string
{
    $preset = getenv('ATUM_ADMIN_USER');
    if ($preset !== false && $preset !== '') {
        return $preset;
    }
    $tty = @fopen('/dev/tty', 'r+');
    if ($tty === false) {
        return $default;
    }
    fwrite($tty, $prompt . ' [' . $default . ']: ');
    $value = trim((string) fgets($tty));
    fclose($tty);
    return $value !== '' ? $value : $default;
}

function chmodTreeOwner(string $path, string $user, string $group): void
{
    @chown($path, $user);
    @chgrp($path, $group);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        @chown($item->getPathname(), $user);
        @chgrp($item->getPathname(), $group);
    }
}

try {
    if (file_exists($target) || file_exists($stateDir) || file_exists($configDir)) {
        throw new RuntimeException('Atum target, state or configuration path already exists. Refusing to overwrite it.');
    }
    if (file_exists($cliLink) || is_link($cliLink) || file_exists($uninstallLink) || is_link($uninstallLink)) {
        throw new RuntimeException('Atum CLI/uninstaller path is already occupied. Refusing to overwrite it.');
    }

    journalWrite($transactionDir, 'application-stage', $stage . "\n");
    journalWrite($transactionDir, 'intended-application-stage', "1\n");
    if (!mkdir($stage, 0755, true) && !is_dir($stage)) { throw new RuntimeException('Unable to create staging directory ' . $stage); }
    file_put_contents($stage . '/.atum-provisional-install-id', $installId . "\n", LOCK_EX);
    copyTree($source, $stage);
    $created[] = ['type' => 'directory', 'path' => $target];

    journalWrite($transactionDir, 'intended-state', "1\n");
    if (!mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
        throw new RuntimeException('Unable to create ' . $stateDir);
    }
    file_put_contents($stateDir . '/.atum-provisional-install-id', $installId . "\n", LOCK_EX);
    journalWrite($transactionDir, 'created-state', "1\n");
    $created[] = ['type' => 'directory', 'path' => $stateDir];
    journalWrite($transactionDir, 'intended-configuration', "1\n");
    if (!mkdir($configDir, 0750, true) && !is_dir($configDir)) {
        throw new RuntimeException('Unable to create ' . $configDir);
    }
    file_put_contents($configDir . '/.atum-provisional-install-id', $installId . "\n", LOCK_EX);
    journalWrite($transactionDir, 'created-configuration', "1\n");
    $created[] = ['type' => 'directory', 'path' => $configDir];

    $config = "# Atum GUI configuration\n# v0.1 is a development preview and is NOT SUITABLE FOR PRODUCTION.\n"
        . 'KAMAILIO_CONFIG="' . addcslashes($kamailioConfig, "\\\"") . "\"\n"
        . 'ATUM_STATE_DIR="' . addcslashes($stateDir, "\\\"") . "\"\n"
        . "ATUM_READ_ONLY=true\n"
        . "ATUM_BIND=\"127.0.0.1:8090\"\n"
        . "# Non-loopback plain HTTP is rejected regardless. Set true to require HTTPS even on loopback.\n"
        . "ATUM_REQUIRE_HTTPS=" . ($options['remote'] === '1' ? 'true' : 'false') . "\n";
    if (file_put_contents($configDir . '/atum.conf', $config, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write Atum configuration.');
    }
    @chmod($configDir . '/atum.conf', 0640);

    // Initialise Atum state and create the first local administrator against
    // the staged application. The application is not committed until this succeeds.
    putenv('ATUM_STATE_DIR=' . $stateDir);
    putenv('KAMAILIO_CONFIG=' . $kamailioConfig);
    require_once $stage . '/admin/bootstrap.php';
    $atum = Atum::create();
    $atum->Modules->installBundled(true);
    $username = prompt('Administrator username', 'admin');
    $password = readSecret('Administrator password: ');
    if (getenv('ATUM_ADMIN_PASSWORD_FILE') === false) {
        $confirm = readSecret('Confirm password: ');
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('Administrator passwords did not match.');
        }
    }
    $atum->Auth->createUser($username, $password, 'admin');

    journalWrite($transactionDir, 'intended-application', "1\n");
    if (!rename($stage, $target)) {
        throw new RuntimeException('Unable to commit staged Atum application.');
    }
    file_put_contents($target . '/.atum-provisional-install-id', $installId . "\n", LOCK_EX);
    journalWrite($transactionDir, 'created-application', "1\n");

    foreach ([$target, $stateDir, $configDir] as $ownedTree) {
        if (file_put_contents($ownedTree . '/.atum-install-id', $installId . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to mark Atum-owned path ' . $ownedTree);
        }
        @chmod($ownedTree . '/.atum-install-id', 0600);
        @unlink($ownedTree . '/.atum-provisional-install-id');
    }

    @chmod($target . '/bin/atum', 0755);
    @chmod($target . '/install', 0755);
    @chmod($target . '/install.php', 0755);
    @chmod($target . '/uninstall', 0755);
    @chmod($target . '/uninstall.php', 0755);

    if (!symlink($target . '/bin/atum', $cliLink)) {
        throw new RuntimeException('Unable to create ' . $cliLink);
    }
    $cliCreated = true;
    $created[] = ['type' => 'symlink', 'path' => $cliLink, 'target' => $target . '/bin/atum'];

    if (!symlink($uninstallLinkTarget, $uninstallLink)) {
        throw new RuntimeException('Unable to create ' . $uninstallLink);
    }
    $uninstallCreated = true;
    $created[] = ['type' => 'symlink', 'path' => $uninstallLink, 'target' => $uninstallLinkTarget];

    if ($options['remote'] === '1') {
        require_once $target . '/admin/libraries/Atum/RemoteDeployment.class.php';
        $remoteDeployment = new AtumRemoteDeployment($transactionDir);
        $remoteIntegration = $remoteDeployment->install([
            'target' => $target,
            'state-dir' => $stateDir,
            'config-dir' => $configDir,
            'listen-address' => $options['listen-address'],
            'listen-port' => $options['listen-port'],
            'web-server' => $options['web-server'],
            'web-config' => $options['web-config'],
            'web-enable-link' => $options['web-enable-link'],
            'web-service' => $options['web-service'],
            'web-group' => $options['web-group'],
            'fpm-config' => $options['fpm-config'],
            'fpm-socket' => $options['fpm-socket'],
            'fpm-service' => $options['fpm-service'],
            'service-command' => $options['service-command'],
            'openssl' => $options['openssl'],
        ]);
    }

    $kamailioFiles = [];
    if ($kamailioConfig !== '' && is_readable($kamailioConfig)) {
        require_once $target . '/admin/libraries/Atum/Kamailio/Scanner.class.php';
        $scan = (new AtumKamailioScanner())->scan($kamailioConfig);
        foreach ($scan['files'] as $file) {
            if (is_file($file) && is_readable($file)) {
                $kamailioFiles[$file] = hash_file('sha256', $file);
            }
        }
        if ($kamailioFiles !== $kamailioSnapshot) {
            throw new RuntimeException('Kamailio configuration changed during Atum installation. Nothing will be committed against a moving target.');
        }
    }

    $ledger = [
        'schema' => 1,
        'install_id' => $installId,
        'installed_at' => gmdate(DATE_ATOM),
        'atum_version' => (string) ($atum->Modules->getInfo('framework')['version'] ?? 'unknown'),
        'os_id' => $options['os-id'],
        'package_manager' => $options['package-manager'],
        'packages_added' => array_values(array_filter(preg_split('/\s+/', trim($options['packages-added'])) ?: [])),
        'paths' => [
            'application' => $target,
            'state' => $stateDir,
            'configuration' => $configDir,
        ],
        'created' => $created,
        'system_account' => [
            'user' => 'atum',
            'group' => 'atum',
            'user_created' => $options['user-created'] === '1',
            'group_created' => $options['group-created'] === '1',
        ],
        'kamailio' => [
            'root_config' => $kamailioConfig,
            'files_at_install' => $kamailioFiles,
            'installer_modified' => false,
            'snapshot_scope' => $snapshotScope,
        ],
        'host_integrations' => [
            'services' => [],
            'created_files' => $remoteIntegration['created_files'] ?? [],
            'modified_files' => [],
            'reload_services' => $remoteIntegration['reload_services'] ?? [],
        ],
        'remote_development' => $remoteIntegration === null ? null : [
            'enabled' => true,
            'web_server' => $remoteIntegration['web_server'],
            'listen_address' => $options['listen-address'],
            'listen_port' => (int) $options['listen-port'],
            'https' => true,
            'tls_certificate' => $remoteIntegration['tls_certificate'],
            'tls_key' => $remoteIntegration['tls_key'],
            'certificate_identity' => 'self-signed development certificate',
        ],
    ];
    $ledgerPath = $configDir . '/install-ledger.json';
    journalWrite($configDir, 'install-ledger.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @chmod($ledgerPath, 0600);
    @chown($ledgerPath, 'root');
    @chgrp($ledgerPath, 'root');

    $safeFacts = [
        'installed_at' => $ledger['installed_at'],
        'atum_version' => $ledger['atum_version'],
        'os_id' => $ledger['os_id'],
        'install_path' => $target,
        'kamailio_config' => $kamailioConfig,
    ];
    file_put_contents($stateDir . '/install-facts.json', json_encode($safeFacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    chmodTreeOwner($stateDir, 'atum', 'atum');
    @chgrp($configDir, 'atum');
    @chgrp($configDir . '/atum.conf', 'atum');

    $committed = true;
    echo "Atum installed to {$target}.\n";
    echo "Initial administrator: {$username}\n";
    echo "Kamailio configuration was not modified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Install failed: {$e->getMessage()}\n");
    if ($cliCreated) { @unlink($cliLink); }
    if ($uninstallCreated) { @unlink($uninstallLink); }
    if ($remoteDeployment instanceof AtumRemoteDeployment) { $remoteDeployment->rollback($options['service-command']); }
    removeTree($stage);
    removeTree($target);
    removeTree($configDir);
    removeTree($stateDir);
    exit(1);
}
