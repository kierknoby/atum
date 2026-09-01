<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

final class AtumSecurity
{
    public static function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'; form-action 'self'; script-src 'self'; style-src 'self'");
        header('Cache-Control: no-store');
    }

    public static function enforceTransport(AtumConfig $config): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $https = self::isHttps();
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $loopback = in_array($remote, ['127.0.0.1', '::1'], true);

        if (!$https && ((bool) $config->get('ATUM_REQUIRE_HTTPS', false) || !$loopback)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Atum refuses insecure non-loopback access. Use HTTPS or a loopback SSH tunnel.\n";
            exit;
        }
    }

    public static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }
}
