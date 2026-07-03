<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$where = "action IN ('Deleted', 'DeleteRequested')";
$params = [];
$perPage = 100;
$page = current_page();
$offset = ($page - 1) * $perPage;
$total = count_events($where, $params);
$events = fetch_recent_events($where, $params, $perPage, $offset);

render_header('Deletion Activity');
?>
<p class="result-count"><?= h($total) ?> deletion-related event<?= $total === 1 ? '' : 's' ?></p>
<p class="hint">Deleted means Windows logged a 4660 confirmation. Delete Activity means Windows logged DELETE access against that path, usually with event 4663 or 4656, and is often the best path-bearing deletion signal.</p>
<?php
render_events_table($events);
render_pagination($page, $total, $perPage);
render_footer();
