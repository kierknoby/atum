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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $token = $_SERVER['HTTP_X_ATUM_CSRF'] ?? ($_POST['csrf'] ?? null);
    if (!$Atum->Auth->validateCsrf(is_string($token) ? $token : null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

$module = strtolower((string) ($_REQUEST['module'] ?? ''));
$command = strtolower((string) ($_REQUEST['command'] ?? ''));
$Atum->Ajax->doRequest($module, $command);
