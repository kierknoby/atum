<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
$modulePresentation = $report['presentation']['modules'] ?? ['capabilities' => [], 'coverage' => ['total' => 0, 'recognised' => 0, 'unclassified' => 0], 'groups' => []];
$system = $report['presentation']['system'] ?? ['findings' => [], 'listeners' => [], 'routes' => ['groups' => [], 'custom_by_component' => []], 'composition' => [], 'confidence' => ['level' => 'partial', 'scope' => 'unknown', 'reasons' => [], 'gaps' => [], 'unclassified_modules' => 0, 'unknown_statements' => 0, 'warnings' => 0]];
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
      <div class="stat"><span>Discovery confidence</span><strong><?= $e(ucfirst((string) $system['confidence']['level'])) ?></strong></div>
    </div>

    <section class="panel">
      <div class="panel-heading"><h2>System interpretation</h2></div>
      <div class="panel-body interpretation-list">
        <?php if ($system['findings'] === []): ?>
          <p class="muted-copy">No conservative system findings can be derived from the scanned configuration.</p>
        <?php endif; ?>
        <?php foreach ($system['findings'] as $finding): ?>
            <article class="interpretation-finding">
            <h3><?= $e($finding['title']) ?></h3>
              <p><?= $e($finding['explanation']) ?><?php if ($finding['confidence'] !== 'syntactic'): ?> <small><?= $e(ucfirst((string) $finding['confidence'])) ?> discovery</small><?php endif; ?></p>
            <?php if ($finding['caveat'] !== ''): ?><p class="finding-caveat"><?= $e($finding['caveat']) ?></p><?php endif; ?>
            <details class="finding-evidence">
              <summary>Evidence and provenance</summary>
              <ul>
                <?php foreach ($finding['evidence'] as $evidence): ?>
                  <li><?= $e($evidence['label']) ?> <code><?= $e($evidence['source']['file']) ?>:<?= (int) $evidence['source']['line'] ?></code></li>
                <?php endforeach; ?>
              </ul>
            </details>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-heading"><h2>Discovery confidence</h2></div>
      <div class="panel-body confidence-body">
        <p><strong><?= $e(ucfirst((string) $system['confidence']['level'])) ?> understanding.</strong> <?= $e($system['confidence']['scope']) ?>; effective runtime configuration is not proven.</p>
        <?php if ($system['confidence']['gaps'] !== [] || $system['confidence']['reasons'] !== []): ?>
          <ul class="gap-list">
            <?php foreach ($system['confidence']['gaps'] as $gap): ?><li><?= $e($gap) ?></li><?php endforeach; ?>
            <?php foreach ($system['confidence']['reasons'] as $reason): ?><li><?= $e($reason) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($system['confidence']['warnings'] > 0): ?><p class="finding-caveat"><?= (int) $system['confidence']['warnings'] ?> scanner warning<?= $system['confidence']['warnings'] === 1 ? '' : 's' ?> remain in the raw discovery result.</p><?php endif; ?>
      </div>
    </section>

    <div class="two-column discovery-overview">
      <section class="panel">
        <div class="panel-heading"><h2>Listeners and signalling</h2></div>
        <div class="panel-body">
          <?php if ($system['listeners'] === []): ?><p class="muted-copy">No safely interpretable listeners were discovered.</p><?php endif; ?>
          <?php foreach ($system['listeners'] as $listener): ?>
            <article class="listener-item"><strong><?= $e($listener['label']) ?></strong><span><?= $e($listener['description']) ?></span><small><code><?= $e($listener['source']['file']) ?>:<?= (int) $listener['source']['line'] ?></code></small></article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel">
        <div class="panel-heading"><h2>Route structure</h2></div>
        <div class="panel-body">
          <?php if ($system['routes']['groups'] === []): ?><p class="muted-copy">No recognised route declarations were discovered.</p><?php endif; ?>
          <?php foreach ($system['routes']['groups'] as $group): ?>
            <div class="route-group"><strong><?= $e($group['label']) ?></strong><span><?= count($group['routes']) ?></span>
              <?php foreach ($group['routes'] as $route): ?><p><code><?= $e($route['name'] ?? 'main') ?></code> <small><?= $e($route['source']['file']) ?>:<?= (int) $route['source']['line'] ?><?= $route['confidence'] === 'syntactic' ? '' : ' · ' . $e(ucfirst((string) $route['confidence'])) ?></small></p><?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($system['routes']['custom_by_component'] !== []): ?>
            <div class="custom-route-components">
              <strong>Custom logic by included component</strong>
              <?php foreach ($system['routes']['custom_by_component'] as $component => $routes): ?>
                <div><code><?= $e($component) ?></code><?php foreach ($routes as $route): ?> <span><code><?= $e($route['name']) ?></code></span><?php endforeach; ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="panel">
      <div class="panel-heading"><h2>Configuration composition</h2></div>
      <div class="panel-body composition-list">
        <?php foreach ($system['composition'] as $component): ?>
          <article class="component-item">
            <div><strong><?= $e($component['kind']) ?></strong><code><?= $e($component['path']) ?></code>
              <?php if ($component['included_from'] !== null): ?><small>Included from <?= $e($component['included_from']['file']) ?>:<?= (int) $component['included_from']['line'] ?></small><?php endif; ?>
            </div>
            <p><?= (int) $component['routes'] ?> routes · <?= (int) $component['modules'] ?> modules · <?= (int) $component['listeners'] ?> listeners · <?= (int) $component['defines'] ?> defines</p>
          </article>
        <?php endforeach; ?>
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

  </div>
<?php endif; ?>
<script src="module-asset.php?module=discovery&amp;file=js/discovery.js"></script>
