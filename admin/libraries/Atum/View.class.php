<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumView
{
    public function load(string $file, array $vars = []): string
    {
        if (!is_readable($file)) {
            throw new RuntimeException('View not found: ' . $file);
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
