<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · Atum GUI</title>
  <link rel="stylesheet" href="assets/atum.css">
</head>
<body class="login-body">
  <main class="login-card">
    <div class="login-brand">ATUM <span>GUI</span></div>
    <div class="notice notice-warning"><strong>Development preview.</strong> Not suitable for production use.</div>
    <h1>Sign in</h1>
    <?php if (isset($loginError)): ?><div class="notice notice-danger"><?= $e($loginError) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="login" value="1">
      <label>Username<input name="username" required minlength="3" maxlength="64" autocomplete="username"></label>
      <label>Password<input type="password" name="password" required minlength="12" maxlength="1024" autocomplete="current-password"></label>
      <button class="button button-primary" type="submit">Sign in</button>
    </form>
  </main>
</body>
</html>
