<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
$modulePresentation = $report['presentation']['modules'] ?? ['capabilities' => [], 'coverage' => ['total' => 0, 'recognised' => 0, 'unclassified' => 0], 'groups' => []];
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

    <section class="panel discovery-modules">
      <div class="panel-heading module-panel-heading">
        <div>
          <h2>Loaded Modules</h2>
          <p>Functional interpretation of modules found in the scanned configuration.</p>
        </div>
        <span class="module-coverage"><?= (int) $modulePresentation['coverage']['recognised'] ?> of <?= (int) $modulePresentation['coverage']['total'] ?> recognised</span>
      </div>
      <div class="panel-body">
        <div class="capability-summary">
          <h3>Detected module support</h3>
          <?php if ($modulePresentation['capabilities'] !== []): ?>
            <ul class="capability-list">
              <?php foreach ($modulePresentation['capabilities'] as $capability): ?>
                <li>
                  <span><?= $e($capability['label']) ?></span>
                  <small><?= $e(implode(', ', $capability['modules'])) ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted-copy">No capability summary can be asserted from confidently discovered, recognised modules.</p>
          <?php endif; ?>
          <p class="module-summary-note">A loaded module makes this support available; it does not by itself prove the capability is actively used.</p>
          <?php if ($modulePresentation['coverage']['unclassified'] > 0): ?>
            <p class="module-unknown-note"><strong><?= (int) $modulePresentation['coverage']['unclassified'] ?> discovered module<?= $modulePresentation['coverage']['unclassified'] === 1 ? '' : 's' ?></strong> remain unclassified and are shown below without inferred semantics.</p>
          <?php endif; ?>
        </div>

        <?php foreach ($modulePresentation['groups'] as $group): ?>
          <section class="module-group" aria-labelledby="module-group-<?= $e($group['key']) ?>">
            <div class="module-group-heading">
              <h3 id="module-group-<?= $e($group['key']) ?>"><?= $e($group['label']) ?></h3>
              <span><?= count($group['modules']) ?></span>
            </div>
            <div class="module-list">
              <?php foreach ($group['modules'] as $module): ?>
                <?php $parameterCount = count($module['params']); ?>
                <article class="module-card">
                  <div class="module-card-summary">
                    <div>
                      <h4><code><?= $e($module['name']) ?></code></h4>
                      <p><?= $e($module['purpose']) ?></p>
                    </div>
                    <div class="module-statuses">
                      <span class="semantic-status semantic-status-<?= $e($module['semantic_status']) ?>"><?= $module['semantic_status'] === 'recognised' ? 'Recognised' : 'Unclassified' ?></span>
                      <?php if (($module['source']['confidence'] ?? '') !== 'syntactic'): ?>
                        <span class="confidence-status"><?= $e(ucfirst((string) $module['source']['confidence'])) ?> discovery</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <details class="module-details">
                    <summary><?= $parameterCount ?> discovered parameter<?= $parameterCount === 1 ? '' : 's' ?> · provenance</summary>
                    <dl class="module-provenance">
                      <dt>Loaded from</dt>
                      <dd><code><?= $e($module['source']['file']) ?>:<?= (int) $module['source']['line'] ?></code></dd>
                      <dt>Recognition</dt>
                      <dd><?= $module['semantic_status'] === 'recognised' ? 'Known module presentation metadata' : 'Discovered name; no semantic metadata' ?></dd>
                      <dt>Confidence</dt>
                      <dd><?= $e(ucfirst((string) ($module['source']['confidence'] ?? 'unknown'))) ?> configuration match</dd>
                    </dl>
                    <?php if ($module['params'] !== []): ?>
                      <div class="table-wrap module-parameter-table">
                        <table>
                          <thead><tr><th>Parameter</th><th>Discovered value</th><th>Classification</th><th>Source</th></tr></thead>
                          <tbody>
                          <?php foreach ($module['params'] as $parameter): ?>
                            <tr>
                              <td><code><?= $e($parameter['name']) ?></code></td>
                              <td><code><?= $e($parameter['value']) ?></code></td>
                              <td><?= $e($parameter['value_classification']) ?></td>
                              <td><code><?= $e($parameter['source']['file']) ?>:<?= (int) $parameter['source']['line'] ?></code></td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <p class="muted-copy module-no-parameters">No module parameters were discovered in the statically recognised configuration.</p>
                    <?php endif; ?>
                  </details>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
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
