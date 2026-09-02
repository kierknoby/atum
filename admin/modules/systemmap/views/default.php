<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
$objects = [];
foreach (($model['map']['objects'] ?? []) as $object) { $objects[$object['id']] = $object; }
?>
<div class="page-heading">
  <div><h1>System Map</h1><p>How the major parts of this SIP system connect.</p></div>
  <span class="model-basis">Configuration-derived</span>
</div>

<?php if ($error !== null): ?>
  <div class="notice notice-warning"><strong>System Map unavailable.</strong><br><?= $e($error) ?></div>
<?php elseif ($model !== null): ?>
  <div class="system-map-layout">
    <section class="system-map-panel" aria-labelledby="system-map-title">
      <div class="system-map-heading"><div><h2 id="system-map-title">System architecture</h2><p><?= $e($model['basis']['statement']) ?></p></div><span class="understanding-badge"><?= $e($model['understanding']['level']) ?> understanding</span></div>
      <div class="system-map-canvas">
        <?php foreach ($model['map']['tiers'] as $tierIndex => $tier): ?>
          <?php if ($tierIndex > 0): ?><div class="map-connector" aria-hidden="true"><span>↓</span></div><?php endif; ?>
          <div class="map-tier map-tier-<?= count($tier) > 1 ? 'branch' : 'single' ?>">
            <?php foreach ($tier as $objectId): ?><?php $object = $objects[$objectId]; ?>
              <article class="map-node map-node-<?= $e($object['type']) ?>">
                <span class="map-node-type"><?= $e(match ($object['type']) { 'ingress' => 'Incoming', 'server' => 'System', 'outcome' => 'Outcome', default => ucfirst((string) $object['type']) }) ?></span>
                <strong><?= $e($object['label']) ?></strong>
                <small><?= $e($object['summary']) ?></small>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="map-relationships" aria-label="System relationships">
        <?php foreach ($model['map']['relationships'] as $relationship): ?>
          <span><?= $e($objects[$relationship['from']]['label'] ?? 'System') ?> <b>→</b> <?= $e($objects[$relationship['to']]['label'] ?? 'System') ?><small><?= $e($relationship['label']) ?></small></span>
        <?php endforeach; ?>
      </div>
    </section>

    <aside class="system-summary" aria-label="System summary">
      <h2>At a glance</h2>
      <dl>
        <dt>System role</dt><dd><strong><?= $e($model['server']['primary_role']) ?></strong><span><?= $e($model['server']['summary']) ?></span></dd>
        <dt>SIP signalling</dt><dd><?= $model['interfaces'] === [] ? 'Not identified' : $e(implode(' · ', array_column($model['interfaces'], 'summary'))) ?></dd>
        <dt>Routing</dt><dd><?= $model['destinations'] === [] ? 'No destination mechanism identified' : $e($model['destinations'][0]['summary']) ?></dd>
        <dt>Media</dt><dd><?= $e($model['media']['summary']) ?></dd>
        <dt>Endpoint registration</dt><dd><?= $e($model['access']['registration']['identified'] ? 'Identified' : 'Not identified') ?></dd>
        <dt>Authentication</dt><dd><?= $e($model['access']['authentication']['identified'] ? 'Identified' : 'Not identified') ?></dd>
        <dt>Custom configuration</dt><dd><?= count($model['custom_components']) ?> component<?= count($model['custom_components']) === 1 ? '' : 's' ?> affect interpreted system paths</dd>
        <dt>Understanding</dt><dd><strong><?= $e($model['understanding']['level']) ?></strong></dd>
      </dl>
      <a class="evidence-link" href="index.php?display=discovery&amp;view=evidence#processing-trace">View Discovery evidence →</a>
    </aside>
  </div>

  <div class="understanding-grid">
    <section class="panel"><div class="panel-heading"><h2>Atum understands</h2></div><div class="panel-body"><ul class="understood-list"><?php foreach ($model['understanding']['understands'] as $item): ?><li><?= $e($item) ?></li><?php endforeach; ?></ul></div></section>
    <section class="panel"><div class="panel-heading"><h2>Still unclear</h2></div><div class="panel-body"><?php if ($model['gaps'] === []): ?><p class="muted-copy">No system-level gaps were identified in the interpreted scope.</p><?php else: ?><ul class="gap-list"><?php foreach ($model['gaps'] as $gap): ?><li><?= $e($gap) ?></li><?php endforeach; ?></ul><?php endif; ?></div></section>
  </div>
<?php endif; ?>
