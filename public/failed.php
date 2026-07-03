<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$where = 'action = :action';
$params = [':action' => 'FailedAccess'];
$perPage = 100;
$page = current_page();
$offset = ($page - 1) * $perPage;
$total = count_events($where, $params);
$events = fetch_recent_events($where, $params, $perPage, $offset);

render_header('Failed Access');
?>
<p class="result-count"><?= h($total) ?> failed access event<?= $total === 1 ? '' : 's' ?></p>
<?php
render_events_table($events);
render_pagination($page, $total, $perPage);
render_footer();
