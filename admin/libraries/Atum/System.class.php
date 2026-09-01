<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumSystem
{
    private AtumConfig $Config;

    public function __construct(AtumConfig $config)
    {
        $this->Config = $config;
    }

    public function check(): array
    {
        $os = $this->osRelease();
        $kamailioConfig = (string) $this->Config->get('KAMAILIO_CONFIG', '/etc/kamailio/kamailio.cfg');
        $kamailioBinary = $this->commandPath('kamailio');

        $extensions = [];
        foreach (['PDO', 'pdo_sqlite', 'session', 'openssl'] as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        $dbAdapters = [];
        foreach (['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'] as $extension) {
            $dbAdapters[$extension] = extension_loaded($extension);
        }

        return [
            'os' => $os,
            'php' => [
                'version' => PHP_VERSION,
                'supported' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'extensions' => $extensions,
                'database_adapters' => $dbAdapters,
            ],
            'kamailio' => [
                'binary' => $kamailioBinary,
                'version' => $kamailioBinary !== null ? $this->kamailioVersion($kamailioBinary) : null,
                'config' => $kamailioConfig,
                'config_exists' => is_file($kamailioConfig),
                'config_readable' => is_readable($kamailioConfig),
            ],
            'package_manager' => $this->packageManager(),
            'web_server' => $this->webServer(),
            'service_manager' => $this->serviceManager(),
            'install_facts' => $this->installFacts(),
        ];
    }

    private function osRelease(): array
    {
        $values = [];
        if (is_readable('/etc/os-release')) {
            $parsed = parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $values = $parsed;
            }
        }

        return [
            'id' => strtolower((string) ($values['ID'] ?? PHP_OS_FAMILY)),
            'name' => (string) ($values['PRETTY_NAME'] ?? PHP_OS),
            'version' => (string) ($values['VERSION_ID'] ?? ''),
        ];
    }

    private function commandPath(string $command): ?string
    {
        $output = [];
        $status = 1;
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $status);
        return $status === 0 && isset($output[0]) ? trim($output[0]) : null;
    }

    private function kamailioVersion(string $binary): ?string
    {
        $output = [];
        $status = 1;
        exec(escapeshellarg($binary) . ' -V 2>/dev/null', $output, $status);
        if ($status !== 0) {
            return null;
        }

        foreach ($output as $line) {
            if (preg_match('/^version:\s+kamailio\s+([^\s]+)/i', $line, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    private function packageManager(): string
    {
        foreach (['apt-get', 'dnf', 'yum', 'zypper', 'apk', 'pacman', 'pkg'] as $manager) {
            if ($this->commandPath($manager) !== null) {
                return $manager;
            }
        }
        return 'unknown';
    }

    private function webServer(): string
    {
        if ($this->commandPath('apache2') !== null || $this->commandPath('httpd') !== null) {
            return 'apache';
        }
        if ($this->commandPath('nginx') !== null) {
            return 'nginx';
        }
        return 'none';
    }

    private function serviceManager(): string
    {
        if ($this->commandPath('systemctl') !== null) {
            return 'systemd';
        }
        if ($this->commandPath('rc-service') !== null) {
            return 'openrc';
        }
        if ($this->commandPath('service') !== null) {
            return 'service';
        }
        return 'unknown';
    }

    private function installFacts(): array
    {
        $file = rtrim((string) $this->Config->get('ATUM_STATE_DIR', '/var/lib/atum'), '/') . '/install-facts.json';
        if (!is_readable($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }
}
