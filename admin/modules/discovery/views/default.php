<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading">
  <div>
    <h1>Discovery</h1>
    <p>Read-only interpretation of the existing Kamailio configuration.</p>
  </div>
  <button class="button button-primary" id="rescan">Rescan</button>
</div>

<div class="notice notice-info">
  <strong>Configuration:</strong> <?= $e($configPath) ?>
  <span class="read-only-pill">READ ONLY</span>
</div>

<?php if ($error !== null): ?>
  <div class="notice notice-danger"><strong>Discovery failed:</strong> <?= $e($error) ?></div>
<?php elseif ($report !== null): ?>
  <div id="discovery-content">
    <div class="stats-grid">
      <div class="stat"><span>Files</span><strong><?= count($report['files']) ?></strong></div>
      <div class="stat"><span>Modules</span><strong><?= count($report['modules']) ?></strong></div>
      <div class="stat"><span>Listeners</span><strong><?= count($report['listeners']) ?></strong></div>
      <div class="stat"><span>Routes</span><strong><?= count($report['routes']) ?></strong></div>
      <div class="stat"><span>Warnings</span><strong><?= count($report['warnings']) ?></strong></div>
    </div>

    <section class="panel">
      <div class="panel-heading"><h2>Listeners</h2></div>
      <div class="panel-body table-wrap">
        <table>
          <thead><tr><th>Listener</th><th>Source</th></tr></thead>
          <tbody>
          <?php foreach ($report['listeners'] as $listener): ?>
            <tr><td><code><?= $e($listener['raw']) ?></code></td><td><?= $e($listener['source']['file']) ?>:<?= (int) $listener['source']['line'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-heading"><h2>Kamailio Modules</h2></div>
      <div class="panel-body table-wrap">
        <table>
          <thead><tr><th>Module</th><th>Parameters</th><th>Loaded from</th></tr></thead>
          <tbody>
          <?php foreach ($report['modules'] as $module): ?>
            <tr>
              <td><strong><?= $e($module['name']) ?></strong></td>
              <td><?= count($module['params']) ?></td>
              <td><?= $e($module['source']['file']) ?>:<?= (int) $module['source']['line'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-heading"><h2>Routes</h2></div>
      <div class="panel-body table-wrap">
        <table>
          <thead><tr><th>Type</th><th>Name</th><th>Source</th></tr></thead>
          <tbody>
          <?php foreach ($report['routes'] as $route): ?>
            <tr>
              <td><?= $e($route['type']) ?></td>
              <td><?= $e($route['name'] ?? 'main') ?></td>
              <td><?= $e($route['source']['file']) ?>:<?= (int) $route['source']['line'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
<?php endif; ?>
<script src="module-asset.php?module=discovery&amp;file=js/discovery.js"></script>
