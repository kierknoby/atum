<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/admin/libraries/Atum/RemoteDeployment.class.php';
$temporary = sys_get_temp_dir() . '/atum-remote-' . bin2hex(random_bytes(6));
$paths = [
    'transaction' => $temporary . '/transaction',
    'config' => $temporary . '/etc/atum',
    'state' => $temporary . '/var/lib/atum',
    'host' => $temporary . '/host',
];
foreach ($paths as $path) { mkdir($path, 0700, true); }
$service = $temporary . '/service';
file_put_contents($service, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($temporary . '/services.log') . "\nstate_dir=" . escapeshellarg($temporary) . "\nstate=\"\$state_dir/service-active-\$2\"\ncase \"\$1\" in\nis-active) [ -f \"\$state\" ] || exit 3 ;;\nstart) : > \"\$state\" ;;\nreload) [ -f \"\$state\" ] ;;\nstop) rm -f \"\$state\" ;;\nesac\n");
chmod($service, 0700);

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function removeTestTree(string $path): void
{
    if (!is_dir($path)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($path);
}

try {
    $serveCommand = 'ATUM_STATE_DIR=' . escapeshellarg($paths['state'])
        . ' KAMAILIO_CONFIG=' . escapeshellarg($root . '/examples/kamailio.cfg')
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/atum') . ' serve 0.0.0.0:8090 2>&1';
    exec($serveCommand, $serveOutput, $serveStatus);
    expect($serveStatus !== 0 && str_contains(implode("\n", $serveOutput), 'loopback-only'), 'atum serve accepted a public bind.');

    $deployment = new AtumRemoteDeployment($paths['transaction']);
    ob_start();
    $result = $deployment->install([
        'target' => $temporary . '/usr/share/atum', 'state-dir' => $paths['state'], 'config-dir' => $paths['config'],
        'listen-address' => '0.0.0.0', 'listen-port' => '8443', 'web-server' => 'nginx',
        'web-config' => $paths['host'] . '/atum-nginx.conf', 'web-enable-link' => '',
        'web-service' => 'nginx', 'web-group' => 'www-data',
        'fpm-config' => $paths['host'] . '/atum-fpm.conf', 'fpm-socket' => '/run/php/atum-fpm.sock',
        'fpm-service' => 'php8.3-fpm', 'fpm-binary' => $service,
        'web-config-test-binary' => $service, 'web-config-test-argument' => '-t',
        'start-web-service' => '1',
        'start-fpm-service' => '1',
        'verbose' => '1',
        'service-command' => $service, 'openssl' => '/usr/bin/openssl',
    ]);
    $verboseOutput = (string) ob_get_clean();
    expect(($result['web_server'] ?? '') === 'nginx', 'Remote deployment did not report Nginx.');
    expect(str_contains($verboseOutput, '$ ') && str_contains($verboseOutput, 'start'), 'Verbose remote deployment did not expose underlying validation/service commands.');
    $nginx = (string) file_get_contents($paths['host'] . '/atum-nginx.conf');
    $pool = (string) file_get_contents($paths['host'] . '/atum-fpm.conf');
    expect(str_contains($nginx, 'ssl') && !preg_match('/(^|\s)listen\s+[^;]+(?<!ssl);/m', $nginx), 'Generated Nginx configuration permits plain HTTP.');
    expect(str_contains($nginx, $temporary . '/usr/share/atum/public'), 'Nginx document root is not public/.');
    expect(!str_contains($nginx, 'usr/share/atum/admin'), 'Nginx exposes the private application tree.');
    expect(str_contains($pool, "user = atum\n") && str_contains($pool, "group = atum\n"), 'PHP-FPM pool is not isolated as atum.');
    expect(str_contains($pool, 'clear_env = yes'), 'PHP-FPM environment is not cleared.');
    $key = $paths['config'] . '/tls/development.key';
    expect((fileperms($key) & 0777) === 0600, 'TLS private key permissions are unsafe.');
    exec('/usr/bin/openssl x509 -in ' . escapeshellarg($paths['config'] . '/tls/development.crt') . ' -noout', $unused, $certificateStatus);
    expect($certificateStatus === 0, 'Generated TLS certificate is invalid.');
    expect(count(glob($paths['transaction'] . '/host-created-*') ?: []) === 4, 'Remote artefacts were not provisionally journalled.');
    $commands = (string) file_get_contents($temporary . '/services.log');
    expect(substr_count($commands, '-t') >= 2, 'Native-style PHP-FPM and Nginx configuration validation did not run.');
    expect(str_contains($commands, 'start nginx') && str_contains($commands, 'start php8.3-fpm') && !str_contains($commands, 'reload php8.3-fpm'), 'New FPM and web services were not started only after validation.');
    expect(!preg_match('/\b(ufw|firewall-cmd|iptables|nft)\b/', $commands), 'A firewall command was executed.');

    // Changed administrator-owned host configuration must survive rollback.
    file_put_contents($paths['host'] . '/atum-nginx.conf', "administrator change\n");
    $deployment->rollback($service);
    expect(is_file($paths['host'] . '/atum-nginx.conf'), 'Rollback removed changed host configuration.');
    expect(!is_file($paths['host'] . '/atum-fpm.conf'), 'Rollback left an unchanged Atum PHP-FPM pool behind.');

    $apacheRoot = $temporary . '/apache-fixture';
    foreach (['transaction', 'config', 'state', 'available', 'enabled', 'fpm'] as $directory) {
        mkdir($apacheRoot . '/' . $directory, 0700, true);
    }
    $apacheLogOffset = filesize($temporary . '/services.log');
    file_put_contents($temporary . '/service-active-php8.3-fpm', 'active');
    $apacheDeployment = new AtumRemoteDeployment($apacheRoot . '/transaction');
    $apacheResult = $apacheDeployment->install([
        'target' => $apacheRoot . '/application', 'state-dir' => $apacheRoot . '/state', 'config-dir' => $apacheRoot . '/config',
        'listen-address' => '::', 'listen-port' => '9443', 'web-server' => 'apache',
        'web-config' => $apacheRoot . '/available/atum.conf', 'web-enable-link' => $apacheRoot . '/enabled/atum.conf',
        'web-service' => 'apache2', 'web-group' => 'www-data', 'fpm-config' => $apacheRoot . '/fpm/atum.conf',
        'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php8.3-fpm',
        'fpm-binary' => $service, 'web-config-test-binary' => $service, 'web-config-test-argument' => 'configtest',
        'start-fpm-service' => '0',
        'service-command' => $service, 'openssl' => '/usr/bin/openssl',
    ]);
    $apache = (string) file_get_contents($apacheRoot . '/available/atum.conf');
    expect(($apacheResult['web_server'] ?? '') === 'apache' && str_contains($apache, '<VirtualHost [::]:9443>'), 'Apache IPv6 HTTPS vhost was not generated correctly.');
    expect(str_contains($apache, 'DocumentRoot ' . $apacheRoot . '/application/public'), 'Apache document root is not public/.');
    expect(is_link($apacheRoot . '/enabled/atum.conf') && readlink($apacheRoot . '/enabled/atum.conf') === $apacheRoot . '/available/atum.conf', 'Apache enablement symlink is incorrect.');
    $apacheCommands = substr((string) file_get_contents($temporary . '/services.log'), $apacheLogOffset);
    expect(str_contains($apacheCommands, 'reload php8.3-fpm') && !str_contains($apacheCommands, 'start php8.3-fpm'), 'Pre-existing PHP-FPM was not reloaded.');
    $apacheDeployment->rollback($service);
    expect(!is_link($apacheRoot . '/enabled/atum.conf') && !is_file($apacheRoot . '/available/atum.conf'), 'Apache rollback left Atum integration behind.');

    $deferredRoot = $temporary . '/deferred-publication';
    foreach (['transaction', 'config', 'state', 'available', 'enabled', 'fpm'] as $directory) {
        mkdir($deferredRoot . '/' . $directory, 0700, true);
    }
    $deferredLogOffset = filesize($temporary . '/services.log');
    $deferredDeployment = new AtumRemoteDeployment($deferredRoot . '/transaction');
    $deferred = $deferredDeployment->prepare([
        'target' => $deferredRoot . '/application', 'state-dir' => $deferredRoot . '/state', 'config-dir' => $deferredRoot . '/config',
        'listen-address' => '127.0.0.1', 'listen-port' => '10443', 'web-server' => 'apache',
        'web-config' => $deferredRoot . '/available/atum.conf', 'web-enable-link' => $deferredRoot . '/enabled/atum.conf',
        'web-service' => 'apache2', 'web-group' => 'www-data', 'fpm-config' => $deferredRoot . '/fpm/atum.conf',
        'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php8.3-fpm',
        'fpm-binary' => $service, 'web-config-test-binary' => $service, 'web-config-test-argument' => 'configtest',
        'service-command' => $service, 'openssl' => '/usr/bin/openssl',
    ]);
    $deferredCommands = substr((string) file_get_contents($temporary . '/services.log'), $deferredLogOffset);
    expect(!is_link($deferredRoot . '/enabled/atum.conf') && !preg_match('/\b(is-active|start|reload|stop)\b/', $deferredCommands), 'Remote validation published an endpoint or changed a service.');
    $deferredDeployment->activate([
        'web-config' => $deferredRoot . '/available/atum.conf', 'web-enable-link' => $deferredRoot . '/enabled/atum.conf',
        'fpm-service' => 'php8.3-fpm', 'web-service' => 'apache2', 'service-command' => $service,
    ], $deferred);
    expect(is_link($deferredRoot . '/enabled/atum.conf'), 'Remote endpoint was not published after activation.');
    $deferredDeployment->rollback($service);

    $failureRoot = $temporary . '/validation-failure';
    foreach (['transaction', 'config', 'state', 'host', 'fpm'] as $directory) { mkdir($failureRoot . '/' . $directory, 0700, true); }
    $failedValidator = $failureRoot . '/reject-config';
    file_put_contents($failedValidator, "#!/bin/sh\nexit 1\n"); chmod($failedValidator, 0700);
    $failedDeployment = new AtumRemoteDeployment($failureRoot . '/transaction');
    try {
        $failedDeployment->install([
            'target' => $failureRoot . '/application', 'state-dir' => $failureRoot . '/state', 'config-dir' => $failureRoot . '/config',
            'listen-address' => '127.0.0.1', 'listen-port' => '10443', 'web-server' => 'nginx',
            'web-config' => $failureRoot . '/host/atum.conf', 'web-enable-link' => '', 'web-service' => 'nginx', 'web-group' => 'www-data',
            'fpm-config' => $failureRoot . '/fpm/atum.conf', 'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php-fpm',
            'fpm-binary' => $failedValidator, 'web-config-test-binary' => $service, 'web-config-test-argument' => '-t',
            'service-command' => $service, 'openssl' => '/usr/bin/openssl',
        ]);
        throw new RuntimeException('Invalid PHP-FPM configuration was accepted.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'rejected'), 'Unexpected native validation failure.');
        $failedDeployment->rollback($service);
    }
    expect(!is_file($failureRoot . '/fpm/atum.conf') && !is_file($failureRoot . '/host/atum.conf'), 'Validation failure rollback left host configuration behind.');

    $refusal = new AtumRemoteDeployment($paths['transaction']);
    try {
        $refusal->install([
            'target' => '/usr/share/atum', 'state-dir' => $paths['state'], 'config-dir' => $paths['config'],
            'listen-address' => '0.0.0.0', 'listen-port' => '8443', 'web-server' => 'nginx',
            'web-config' => $paths['host'] . '/atum-nginx.conf', 'web-enable-link' => '', 'web-service' => 'nginx',
            'web-group' => 'www-data', 'fpm-config' => $paths['host'] . '/second-fpm.conf',
            'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php8.3-fpm',
            'fpm-binary' => $service, 'web-config-test-binary' => $service, 'web-config-test-argument' => '-t',
            'service-command' => $service, 'openssl' => '/usr/bin/openssl',
        ]);
        throw new RuntimeException('Pre-existing vhost configuration was overwritten.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'Refusing to overwrite'), 'Unexpected pre-existing vhost failure.');
    }
    echo "PASS  remote HTTPS configuration, FPM isolation, TLS, journal, firewall and ownership behaviour\n";
} finally {
    removeTestTree($temporary);
}
