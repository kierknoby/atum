<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading">
  <div>
    <h1>Atum GUI</h1>
    <p>Read-only overview of the adopted Kamailio installation.</p>
  </div>
</div>

<?php if ($error !== null): ?>
  <div class="notice notice-warning">
    <strong>Kamailio has not been discovered yet.</strong><br>
    <?= $e($error) ?>
  </div>
<?php else: ?>
  <div class="stats-grid">
    <div class="stat"><span>Configuration files</span><strong><?= count($report['files']) ?></strong></div>
    <div class="stat"><span>Kamailio modules</span><strong><?= count($report['modules']) ?></strong></div>
    <div class="stat"><span>Listeners</span><strong><?= count($report['listeners']) ?></strong></div>
    <div class="stat"><span>Routes</span><strong><?= count($report['routes']) ?></strong></div>
    <div class="stat"><span>Warnings</span><strong><?= count($report['warnings']) ?></strong></div>
  </div>
<?php endif; ?>

<div class="two-column">
  <section class="panel">
    <div class="panel-heading"><h2>System status</h2></div>
    <div class="panel-body">
      <dl class="details">
        <dt>Mode</dt><dd><span class="status-good">Read only</span></dd>
        <dt>Kamailio config</dt><dd><code><?= $e((string) Atum::create()->Config->get('KAMAILIO_CONFIG')) ?></code></dd>
        <dt>KEMI detected</dt><dd><?= $report !== null && $report['kemi_detected'] ? 'Yes' : 'No' ?></dd>
      </dl>
    </div>
  </section>

  <section class="panel">
    <div class="panel-heading"><h2>Atum modules</h2></div>
    <div class="panel-body">
      <dl class="details">
        <dt>Installed</dt><dd><?= count($modules) ?></dd>
        <dt>Enabled</dt><dd><?= count(array_filter($modules, static fn(array $m): bool => $m['enabled'])) ?></dd>
      </dl>
      <p><a href="index.php?display=moduleadmin">Open Module Admin</a></p>
    </div>
  </section>
</div>
