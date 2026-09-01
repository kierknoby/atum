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
  <title>Atum GUI</title>
  <link rel="stylesheet" href="assets/atum.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php?display=dashboard">ATUM <span>GUI</span></a>
  <div class="topbar-actions">
    <?php if ($readOnly): ?><span class="read-only-pill">READ ONLY</span><?php endif; ?>
    <span class="development-pill">DEVELOPMENT PREVIEW · NOT FOR PRODUCTION</span>
    <span class="signed-in"><?= $e($user['username'] ?? '') ?></span>
    <form class="logout-form" method="post" action="index.php">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button class="logout-link logout-button" type="submit" name="logout" value="1">Sign out</button>
    </form>
    <button class="apply-button" disabled title="Configuration writes are not enabled in v0.1">Apply Config</button>
  </div>
</header>
<div class="app-shell">
  <aside class="sidebar">
    <?php foreach ($menu as $category => $items): ?>
      <div class="menu-category"><?= $e($category) ?></div>
      <?php foreach ($items as $item): ?>
        <a class="menu-item <?= $display === $item['id'] ? 'active' : '' ?>" href="index.php?display=<?= urlencode($item['id']) ?>">
          <?= $e($item['name']) ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </aside>
  <main class="content"><?= $content ?></main>
</div>
</body>
</html>
