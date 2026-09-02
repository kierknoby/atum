<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
$modulePresentation = $report['presentation']['modules'] ?? ['capabilities' => [], 'coverage' => ['total' => 0, 'recognised' => 0, 'unclassified' => 0], 'groups' => []];
$system = $report['presentation']['system'] ?? ['findings' => [], 'listeners' => [], 'routes' => ['groups' => [], 'custom_by_component' => []], 'composition' => [], 'confidence' => ['level' => 'partial', 'scope' => 'unknown', 'reasons' => [], 'gaps' => [], 'unclassified_modules' => 0, 'unknown_statements' => 0, 'warnings' => 0]];
$requestProcessing = $report['presentation']['request_processing'] ?? ['flows' => [], 'coverage' => ['recognised' => 0, 'custom' => 0, 'unresolved' => 0, 'cycles' => [], 'unreferenced' => []]];
$operator = $report['presentation']['operator'] ?? ['overview' => [], 'stages' => [], 'connectivity' => [], 'routing' => [], 'media' => [], 'access' => [], 'gaps' => [], 'coverage' => []];
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

    <?php if ($view === 'overview'): ?>
      <section class="operator-hero">
        <h2>What this server does</h2>
        <?php foreach ($operator['overview'] as $summary): ?><p><?= $e($summary) ?></p><?php endforeach; ?>
        <div class="operator-confidence"><strong>Understanding: <?= $e(ucfirst((string) $system['confidence']['level'])) ?></strong><span><?= (int) $requestProcessing['coverage']['custom'] ?> custom processing steps · <?= (int) $modulePresentation['coverage']['unclassified'] ?> unclassified modules · <?= (int) $requestProcessing['coverage']['unresolved'] ?> unresolved destinations</span></div>
      </section>
      <section class="panel"><div class="panel-heading"><h2>Incoming request path</h2></div><div class="panel-body compact-stage-list">
        <?php foreach ($operator['stages'] as $stage): ?><article class="operator-stage operator-stage-<?= $e($stage['kind']) ?>"><strong><?= $e($stage['title']) ?></strong><?php if ($stage['conditions'] !== []): ?><span><?= $e(implode(' / ', $stage['conditions'])) ?></span><?php endif; ?></article><?php endforeach; ?>
      </div></section>
    <?php elseif ($view === 'call-flow'): ?>
      <section class="panel"><div class="panel-heading"><h2>Call flow</h2></div><div class="panel-body visual-flow">
        <?php foreach ($operator['stages'] as $stage): ?><article class="operator-stage operator-stage-<?= $e($stage['kind']) ?>"><strong><?= $e($stage['title']) ?></strong><?php if ($stage['conditions'] !== []): ?><span><?= $e(implode(' / ', $stage['conditions'])) ?></span><?php endif; ?><details><summary>Technical details</summary><ul><?php foreach ($stage['evidence'] as $evidence): ?><li><?= $e($evidence['meaning']) ?> <code><?= $e($evidence['source']['file']) ?>:<?= (int) $evidence['source']['line'] ?></code></li><?php endforeach; ?></ul></details></article><?php endforeach; ?>
        <?php if ($operator['gaps'] !== []): ?><article class="operator-stage operator-stage-custom"><strong>Custom logic</strong><span>Atum cannot yet interpret <?= count($operator['gaps']) ?> processing step<?= count($operator['gaps']) === 1 ? '' : 's' ?>.</span></article><?php endif; ?>
      </div></section>
    <?php elseif ($view === 'connectivity'): ?>
      <section class="panel"><div class="panel-heading"><h2>Where SIP traffic enters</h2></div><div class="panel-body operator-list"><?php foreach ($operator['connectivity'] as $listener): ?><article><strong><?= $e($listener['label']) ?></strong><p><?= $e($listener['description']) ?></p><details><summary>Technical details</summary><code><?= $e($listener['source']['file']) ?>:<?= (int) $listener['source']['line'] ?></code></details></article><?php endforeach; ?></div></section>
    <?php elseif ($view === 'routing'): ?>
      <section class="panel"><div class="panel-heading"><h2>Routing and destinations</h2></div><div class="panel-body operator-list"><?php if ($operator['routing'] === []): ?><p class="muted-copy">No recognised routing or destination-selection steps were identified.</p><?php endif; ?><?php foreach ($operator['routing'] as $step): ?><article><strong><?= $e($step['meaning']) ?></strong><p><?= ($step['category'] ?? '') === 'dispatching' ? 'Destination data is external to the interpreted configuration.' : 'This step is part of the statically interpreted routing path.' ?></p><details><summary>Technical details</summary><code><?= $e($step['source']['file']) ?>:<?= (int) $step['source']['line'] ?></code></details></article><?php endforeach; ?></div></section>
    <?php elseif ($view === 'media'): ?>
      <section class="panel"><div class="panel-heading"><h2>Media and NAT handling</h2></div><div class="panel-body operator-list"><?php if ($operator['media'] === []): ?><p class="muted-copy">No recognised media processing was identified in the interpreted route flow. Loaded media support alone does not prove use.</p><?php endif; ?><?php foreach ($operator['media'] as $step): ?><article><strong><?= $e($step['meaning']) ?></strong><p><?= $e($step['route_type'] === 'onreply_route' ? 'This applies to SIP replies.' : 'This appears in request processing.') ?></p><details><summary>Technical details</summary><code><?= $e($step['source']['file']) ?>:<?= (int) $step['source']['line'] ?></code></details></article><?php endforeach; ?></div></section>
    <?php elseif ($view === 'access'): ?>
      <section class="panel"><div class="panel-heading"><h2>Access and endpoint registration</h2></div><div class="panel-body operator-list"><?php if ($operator['access'] === []): ?><p class="muted-copy">No recognised local endpoint-registration or subscriber-authentication handling was identified in the interpreted configuration. Discovery is partial.</p><?php endif; ?><?php foreach ($operator['access'] as $step): ?><article><strong><?= $e($step['meaning']) ?></strong><details><summary>Technical details</summary><code><?= $e($step['source']['file']) ?>:<?= (int) $step['source']['line'] ?></code></details></article><?php endforeach; ?></div></section>
    <?php endif; ?>

    <?php if ($view === 'evidence'): ?>
    <section class="panel request-processing">
      <div class="panel-heading"><h2>How requests are handled</h2></div>
      <div class="panel-body">
        <p class="flow-intro">Static interpretation of recognised routing constructs. It describes configured control flow, not observed SIP traffic.</p>
        <p class="flow-coverage"><strong>Route interpretation:</strong> <?= (int) $requestProcessing['coverage']['recognised'] ?> recognised statements · <?= (int) $requestProcessing['coverage']['custom'] ?> custom statements · <?= (int) $requestProcessing['coverage']['unresolved'] ?> unresolved route calls</p>
        <?php if ($requestProcessing['flows'] === []): ?>
          <p class="muted-copy">No route bodies were discovered.</p>
        <?php endif; ?>
        <?php foreach ($requestProcessing['flows'] as $flow): ?>
          <article class="flow-route">
            <header><h3><?= $e($flow['label']) ?></h3><small><code><?= $e($flow['source']['file']) ?>:<?= (int) $flow['source']['line'] ?></code></small></header>
            <?php if ($flow['statements'] === []): ?><p class="muted-copy">No statically recognised statements in this route body.</p><?php endif; ?>
            <ol class="flow-steps">
              <?php foreach ($flow['statements'] as $step): ?>
                <li class="flow-step flow-step-<?= $e($step['kind']) ?>" style="--flow-depth: <?= (int) $step['depth'] ?>">
                  <?php if ($step['conditions'] !== []): ?><span class="flow-condition"><?= $e(implode(' / ', $step['conditions'])) ?></span><?php endif; ?>
                  <span><?= $e($step['meaning']) ?></span>
                  <?php if (($step['terminal'] ?? false) === true): ?><small>terminal branch</small><?php endif; ?>
                  <?php if ($step['confidence'] !== 'syntactic'): ?><small><?= $e(ucfirst((string) $step['confidence'])) ?> discovery</small><?php endif; ?>
                  <code><?= $e($step['source']['file']) ?>:<?= (int) $step['source']['line'] ?></code>
                </li>
              <?php endforeach; ?>
            </ol>
          </article>
        <?php endforeach; ?>
        <?php if ($requestProcessing['coverage']['cycles'] !== []): ?><p class="finding-caveat">Static route-call cycle detected. Atum shows the configured references but does not follow them indefinitely.</p><?php endif; ?>
        <?php if ($requestProcessing['coverage']['unreferenced'] !== []): ?><p class="finding-caveat"><?= count($requestProcessing['coverage']['unreferenced']) ?> named route<?= count($requestProcessing['coverage']['unreferenced']) === 1 ? '' : 's' ?> had no static reference found.</p><?php endif; ?>
      </div>
    </section>

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

    <?php endif; ?>

  </div>
<?php endif; ?>
<script src="module-asset.php?module=discovery&amp;file=js/discovery.js"></script>
