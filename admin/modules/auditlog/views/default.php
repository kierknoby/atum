<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$e = [AtumView::class, 'escape'];
?>
<div class="page-heading"><div><h1>Audit Log</h1><p>Atum security and management events.</p></div></div>
<section class="panel"><div class="panel-body table-wrap"><table>
<thead><tr><th>Time</th><th>User</th><th>Action</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($records as $row): ?><tr><td><?= $e($row['created_at']) ?></td><td><?= $e($row['username'] ?? 'system') ?></td><td><code><?= $e($row['action']) ?></code></td><td><?= $e($row['outcome']) ?></td><td><?= $e($row['detail'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
