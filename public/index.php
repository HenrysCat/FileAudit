<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$pdo = db();
$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_events,
        COALESCE(SUM(action IN ('Deleted', 'DeleteRequested')), 0) AS deletions,
        COALESCE(SUM(action IN ('Created', 'Modified', 'Written')), 0) AS modifications,
        COALESCE(SUM(action = 'PermissionChanged'), 0) AS permission_changes,
        COALESCE(SUM(action = 'FailedAccess'), 0) AS failed_access
    FROM audit_events
    WHERE time_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND (object_name IS NULL OR object_name NOT LIKE '%.tmp')
      AND (relative_target_name IS NULL OR relative_target_name NOT LIKE '%.tmp')
");
$summary = $summaryStmt->fetch() ?: [];
$events = fetch_recent_events(null, [], 100);

render_header('Dashboard');
?>
<section class="cards">
    <div class="card"><span>Total Events</span><strong><?= h($summary['total_events'] ?? 0) ?></strong></div>
    <div class="card"><span>Deletion Activity</span><strong><?= h($summary['deletions'] ?? 0) ?></strong></div>
    <div class="card"><span>Modifications/Writes</span><strong><?= h($summary['modifications'] ?? 0) ?></strong></div>
    <div class="card"><span>Permission Changes</span><strong><?= h($summary['permission_changes'] ?? 0) ?></strong></div>
    <div class="card"><span>Failed Access</span><strong><?= h($summary['failed_access'] ?? 0) ?></strong></div>
</section>

<h2>Recent 100 Events</h2>
<?php render_events_table($events); ?>
<?php render_footer(); ?>
