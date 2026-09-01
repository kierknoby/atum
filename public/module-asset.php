<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('ATUM_IS_AUTH', true);
require_once dirname(__DIR__) . '/admin/bootstrap.php';

AtumSecurity::headers();
$Atum = Atum::create();
AtumSecurity::enforceTransport($Atum->Config);
$Atum->Auth->startSession();
if (!$Atum->Auth->isAuthenticated()) {
    http_response_code(401);
    exit;
}

$module = strtolower((string) ($_GET['module'] ?? ''));
$file = (string) ($_GET['file'] ?? '');
if (!preg_match('/^[a-z0-9_-]+$/', $module) || $file === '' || str_contains($file, "\0")) {
    http_response_code(400);
    exit;
}

$parts = preg_split('#[\\\\/]#', $file) ?: [];
foreach ($parts as $part) {
    if ($part === '' || $part === '.' || $part === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
        http_response_code(400);
        exit;
    }
}

$info = $Atum->Modules->getInfo($module);
if ($info === [] || !($info['installed'] ?? false) || !($info['enabled'] ?? false)) {
    http_response_code(404);
    exit;
}
if (!$Atum->Auth->hasPermission((string) ($info['permission'] ?? 'view'))) {
    http_response_code(403);
    exit;
}

$base = realpath((string) $info['path'] . '/assets');
$target = $base !== false ? realpath($base . '/' . implode('/', $parts)) : false;
if ($base === false || $target === false || !is_file($target) || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$types = [
    'js' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
];
if (!isset($types[$extension])) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . (string) filesize($target));
readfile($target);
