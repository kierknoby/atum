<?php
// Test-only trusted web-server boundary: production Nginx/Apache sets HTTPS
// directly when terminating TLS and does not rely on client proxy headers.
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$script = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '/index.php', PHP_URL_PATH));
if (!in_array($script, ['index.php', 'ajax.php', 'module-asset.php'], true)) {
    $script = 'index.php';
}
require dirname(__DIR__) . '/public/' . $script;
