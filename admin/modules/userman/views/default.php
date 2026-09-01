<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading"><div><h1>Administrators</h1><p>Local accounts for the Atum GUI.</p></div></div>

<section class="panel">
  <div class="panel-heading"><h2>Create account</h2></div>
  <div class="panel-body">
    <form id="user-create-form" class="inline-form" autocomplete="off">
      <label>Username<input name="username" required minlength="3" maxlength="64" autocomplete="off"></label>
      <label>Role
        <select name="role">
          <option value="viewer">Viewer</option>
          <option value="admin">Administrator</option>
        </select>
      </label>
      <label>Password<input type="password" name="password" required minlength="12" maxlength="1024" autocomplete="new-password"></label>
      <button class="button button-primary" type="submit">Create</button>
    </form>
  </div>
</section>

<section class="panel">
  <div class="panel-heading"><h2>Accounts</h2></div>
  <div class="panel-body table-wrap">
    <table>
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th>Password</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $account): ?>
        <tr data-user-id="<?= (int) $account['id'] ?>">
          <td><?= $e($account['username']) ?></td>
          <td><?= $e($account['role']) ?></td>
          <td><?= (int) $account['enabled'] === 1 ? '<span class="status-good">Enabled</span>' : 'Disabled' ?></td>
          <td><?= $e($account['last_login_at'] ?? 'Never') ?></td>
          <td>
            <div class="password-action">
              <input class="user-new-password" type="password" minlength="12" maxlength="1024" placeholder="New password" autocomplete="new-password" aria-label="New password for <?= $e($account['username']) ?>">
              <button class="button user-password" type="button">Set</button>
            </div>
          </td>
          <td class="action-buttons">
            <button class="button user-toggle" type="button" data-action="<?= (int) $account['enabled'] === 1 ? 'disable' : 'enable' ?>"><?= (int) $account['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
            <button class="button user-delete" type="button">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="notice notice-info">Atum prevents the last enabled administrator from being disabled or deleted. Password and account changes invalidate affected authenticated sessions.</div>
<div id="userman-data" data-csrf="<?= $e($csrf) ?>"></div>
<script src="module-asset.php?module=userman&amp;file=js/userman.js"></script>
