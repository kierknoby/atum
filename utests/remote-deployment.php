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
file_put_contents($service, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($temporary . '/services.log') . "\n");
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
    $result = $deployment->install([
        'target' => $temporary . '/usr/share/atum', 'state-dir' => $paths['state'], 'config-dir' => $paths['config'],
        'listen-address' => '0.0.0.0', 'listen-port' => '8443', 'web-server' => 'nginx',
        'web-config' => $paths['host'] . '/atum-nginx.conf', 'web-enable-link' => '',
        'web-service' => 'nginx', 'web-group' => 'www-data',
        'fpm-config' => $paths['host'] . '/atum-fpm.conf', 'fpm-socket' => '/run/php/atum-fpm.sock',
        'fpm-service' => 'php8.3-fpm', 'service-command' => $service, 'openssl' => '/usr/bin/openssl',
    ]);
    expect(($result['web_server'] ?? '') === 'nginx', 'Remote deployment did not report Nginx.');
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
    expect(str_contains($commands, 'reload nginx') && str_contains($commands, 'reload php8.3-fpm'), 'Required services were not reloaded.');
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
    $apacheDeployment = new AtumRemoteDeployment($apacheRoot . '/transaction');
    $apacheResult = $apacheDeployment->install([
        'target' => $apacheRoot . '/application', 'state-dir' => $apacheRoot . '/state', 'config-dir' => $apacheRoot . '/config',
        'listen-address' => '::', 'listen-port' => '9443', 'web-server' => 'apache',
        'web-config' => $apacheRoot . '/available/atum.conf', 'web-enable-link' => $apacheRoot . '/enabled/atum.conf',
        'web-service' => 'apache2', 'web-group' => 'www-data', 'fpm-config' => $apacheRoot . '/fpm/atum.conf',
        'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php8.3-fpm',
        'service-command' => $service, 'openssl' => '/usr/bin/openssl',
    ]);
    $apache = (string) file_get_contents($apacheRoot . '/available/atum.conf');
    expect(($apacheResult['web_server'] ?? '') === 'apache' && str_contains($apache, '<VirtualHost [::]:9443>'), 'Apache IPv6 HTTPS vhost was not generated correctly.');
    expect(str_contains($apache, 'DocumentRoot ' . $apacheRoot . '/application/public'), 'Apache document root is not public/.');
    expect(is_link($apacheRoot . '/enabled/atum.conf') && readlink($apacheRoot . '/enabled/atum.conf') === $apacheRoot . '/available/atum.conf', 'Apache enablement symlink is incorrect.');
    $apacheDeployment->rollback($service);
    expect(!is_link($apacheRoot . '/enabled/atum.conf') && !is_file($apacheRoot . '/available/atum.conf'), 'Apache rollback left Atum integration behind.');

    $refusal = new AtumRemoteDeployment($paths['transaction']);
    try {
        $refusal->install([
            'target' => '/usr/share/atum', 'state-dir' => $paths['state'], 'config-dir' => $paths['config'],
            'listen-address' => '0.0.0.0', 'listen-port' => '8443', 'web-server' => 'nginx',
            'web-config' => $paths['host'] . '/atum-nginx.conf', 'web-enable-link' => '', 'web-service' => 'nginx',
            'web-group' => 'www-data', 'fpm-config' => $paths['host'] . '/second-fpm.conf',
            'fpm-socket' => '/run/php/atum-fpm.sock', 'fpm-service' => 'php8.3-fpm',
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
