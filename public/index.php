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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!$Atum->Auth->validateCsrf($_POST['csrf'] ?? null)) {
        $loginError = 'Your session token was invalid. Try again.';
    } elseif (!$Atum->Auth->authenticate((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        $loginError = 'Invalid username or password.';
    } else {
        header('Location: index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!$Atum->Auth->validateCsrf($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
    $Atum->Auth->logout();
    header('Location: index.php');
    exit;
}

if (!$Atum->Auth->isAuthenticated()) {
    $csrf = $Atum->Auth->csrfToken();
    require dirname(__DIR__) . '/admin/views/login.php';
    exit;
}

$display = isset($_GET['display']) ? strtolower((string) $_GET['display']) : 'dashboard';
if (!preg_match('/^[a-z0-9_-]+$/', $display)) {
    $display = 'dashboard';
}

$page = $Atum->Modules->pageFile($display);
if ($page === null) {
    http_response_code(404);
    $content = '<div class="notice notice-danger">Unknown Atum page.</div>';
} else {
    $bufferLevel = ob_get_level();
    try {
        ob_start();
        include $page;
        $content = (string) ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        try {
            $Atum->Audit->log('page.error', 'failure', 'page', $display, $e->getMessage());
        } catch (Throwable) {
            // Preserve the generic browser failure even if audit storage is unavailable.
        }
        http_response_code(500);
        $content = '<div class="notice notice-danger">The page could not be rendered. See the Atum audit log for details.</div>';
    }
}

echo $Atum->View->load(dirname(__DIR__) . '/admin/views/layout.php', [
    'content' => $content,
    'display' => $display,
    'menu' => $Atum->Modules->getMenu(),
    'readOnly' => (bool) $Atum->Config->get('ATUM_READ_ONLY', true),
    'user' => $Atum->Auth->user(),
    'csrf' => $Atum->Auth->csrfToken(),
]);
