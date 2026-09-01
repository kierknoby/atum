<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading"><div><h1>Module Admin</h1><p>Installed Atum GUI modules and dependency state.</p></div></div>
<section class="panel"><div class="panel-body table-wrap"><table>
<thead><tr><th>Module</th><th>Version</th><th>Dependencies</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($modules as $module): ?>
<tr>
  <td><strong><?= $e($module['name']) ?></strong><br><code><?= $e($module['rawname']) ?></code><br><?= $e($module['description']) ?></td>
  <td><?= $e($module['version']) ?></td>
  <td><?= $e(implode(', ', $module['depends'] ?? []) ?: 'None') ?></td>
  <td>
    <?php if (!($module['installed'] ?? false)): ?>Not installed
    <?php elseif ($module['enabled']): ?><span class="status-good">Enabled</span>
    <?php else: ?>Disabled<?php endif; ?>
  </td>
  <td>
    <?php if ($module['rawname'] === 'framework'): ?>
      Core
    <?php elseif (!($module['installed'] ?? false)): ?>
      <button class="button module-toggle" data-module="<?= $e($module['rawname']) ?>" data-action="install">Install</button>
    <?php else: ?>
      <button class="button module-toggle" data-module="<?= $e($module['rawname']) ?>" data-action="<?= $module['enabled'] ? 'disable' : 'enable' ?>"><?= $module['enabled'] ? 'Disable' : 'Enable' ?></button>
      <button class="button module-toggle" data-module="<?= $e($module['rawname']) ?>" data-action="uninstall">Uninstall</button>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></section>
<div id="moduleadmin-data" data-csrf="<?= $e($csrf) ?>"></div>
<script src="module-asset.php?module=moduleadmin&amp;file=js/moduleadmin.js"></script>
