<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumConfig
{
    private array $values = [
        'KAMAILIO_CONFIG' => '/etc/kamailio/kamailio.cfg',
        'ATUM_READ_ONLY' => true,
        'ATUM_BIND' => '127.0.0.1:8090',
        'ATUM_STATE_DIR' => '',
        'ATUM_REQUIRE_HTTPS' => false,
    ];

    public function __construct()
    {
        $configDirectory = getenv('ATUM_CONFIG_DIR') ?: '/etc/atum';
        $files = [
            ATUM_ROOT . '/config/atum.conf',
            rtrim($configDirectory, '/') . '/atum.conf',
        ];

        foreach ($files as $file) {
            if (is_readable($file)) {
                $parsed = parse_ini_file($file, false, INI_SCANNER_TYPED);
                if (is_array($parsed)) {
                    $this->values = array_replace($this->values, $parsed);
                }
            }
        }

        if ($this->values['ATUM_STATE_DIR'] === '') {
            $this->values['ATUM_STATE_DIR'] = ATUM_ROOT . '/var';
        }

        foreach (array_keys($this->values) as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $this->values[$key] = $this->normaliseEnvironmentValue($value);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    private function normaliseEnvironmentValue(string $value): mixed
    {
        $lower = strtolower(trim($value));
        return match ($lower) {
            'true', 'yes', 'on' => true,
            'false', 'no', 'off' => false,
            default => $value,
        };
    }
}
