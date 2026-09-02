<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading">
  <div><h1>Call Handling</h1><p>How recognised SIP journeys move through this system.</p></div>
  <span class="model-basis">Configuration-derived</span>
</div>

<?php if ($error !== null): ?>
  <div class="notice notice-warning"><strong>Call Handling unavailable.</strong><br><?= $e($error) ?></div>
<?php elseif ($model !== null): ?>
  <div class="notice notice-info">Only journeys established by static configuration evidence are shown. This is not live call activity.</div>
  <?php if ($model['journeys'] === []): ?><section class="panel"><div class="panel-body"><p class="muted-copy">No operator-level call journey could be established from the interpreted configuration.</p></div></section><?php endif; ?>
  <div class="journey-list">
    <?php foreach ($model['journeys'] as $journey): ?>
      <section class="journey" aria-labelledby="journey-<?= $e($journey['id']) ?>">
        <header><div><span class="journey-kicker">Recognised journey</span><h2 id="journey-<?= $e($journey['id']) ?>"><?= $e($journey['label']) ?></h2></div><span class="journey-outcome">Outcome: <?= $e($journey['outcome']['label']) ?></span></header>
        <div class="journey-flow">
          <article class="journey-stage journey-entry"><span>Starts with</span><strong><?= $e($journey['entry']) ?></strong></article>
          <?php foreach ($journey['stages'] as $stage): ?>
            <span class="journey-arrow" aria-hidden="true">↓</span>
            <article class="journey-stage journey-stage-<?= $e($stage['kind']) ?>">
              <strong><?= $e($stage['label']) ?></strong><p><?= $e($stage['summary']) ?></p>
              <?php if ($stage['kind'] === 'custom'): ?><span class="partial-badge">Partial understanding</span><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
        <footer><span>Based on <?= array_sum(array_map(static fn(array $stage): int => count($stage['evidence']), $journey['stages'])) ?> configuration evidence point<?= array_sum(array_map(static fn(array $stage): int => count($stage['evidence']), $journey['stages'])) === 1 ? '' : 's' ?>.</span><a href="index.php?display=discovery&amp;view=evidence#processing-trace">View Discovery evidence →</a></footer>
      </section>
    <?php endforeach; ?>
  </div>
  <?php if (!$model['access']['registration']['identified']): ?><p class="access-absence"><?= $e($model['access']['registration']['summary']) ?></p><?php endif; ?>
<?php endif; ?>
