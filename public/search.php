<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'username' => trim((string)($_GET['username'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'server_name' => trim((string)($_GET['server_name'] ?? '')),
    'source_ip' => trim((string)($_GET['source_ip'] ?? '')),
    'handle_id' => trim((string)($_GET['handle_id'] ?? '')),
    'path' => trim((string)($_GET['path'] ?? '')),
];

$page = current_page();
$perPage = 100;
$offset = ($page - 1) * $perPage;
$where = [];
$params = [];

if ($filters['date_from'] !== '') {
    $where[] = 'time_created >= :date_from';
    $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
}

if ($filters['date_to'] !== '') {
    $where[] = 'time_created <= :date_to';
    $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
}

foreach (['username', 'action', 'server_name', 'source_ip', 'handle_id'] as $field) {
    if ($filters[$field] !== '') {
        $where[] = $field . ' = :' . $field;
        $params[':' . $field] = $filters[$field];
    }
}

if ($filters['path'] !== '') {
    $where[] = '(object_name LIKE :path_object OR relative_target_name LIKE :path_relative OR share_name LIKE :path_share)';
    $params[':path_object'] = '%' . $filters['path'] . '%';
    $params[':path_relative'] = '%' . $filters['path'] . '%';
    $params[':path_share'] = '%' . $filters['path'] . '%';
}

$whereSql = $where ? implode(' AND ', $where) : null;

$countSql = 'SELECT COUNT(*) FROM audit_events' . ($whereSql ? ' WHERE ' . $whereSql : '');
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$events = fetch_recent_events($whereSql, $params, $perPage, $offset);

$usernameStmt = db()->query("
    SELECT DISTINCT username
    FROM audit_events
    WHERE username IS NOT NULL AND username <> ''
    ORDER BY username ASC
    LIMIT 500
");
$usernames = $usernameStmt->fetchAll(PDO::FETCH_COLUMN);

$actions = [
    'Deleted' => 'Deleted',
    'DeleteRequested' => 'DeleteRequested',
    'Created' => 'Created',
    'Modified' => 'Modified',
    'Written' => 'Written',
    'PermissionChanged' => 'Permission Changed',
    'FailedAccess' => 'Failed Access',
    'HandleRequested' => 'Handle Requested',
    'Unknown' => 'Unknown',
];

render_header('Search');
?>
<form class="filters" method="get">
    <label>Date from <input type="date" name="date_from" value="<?= h($filters['date_from']) ?>"></label>
    <label>Date to <input type="date" name="date_to" value="<?= h($filters['date_to']) ?>"></label>
    <label>
        Username
        <select name="username">
            <option value="">Any user</option>
            <?php foreach ($usernames as $username): ?>
                <option value="<?= h($username) ?>" <?= $filters['username'] === $username ? 'selected' : '' ?>><?= h($username) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Action
        <select name="action">
            <option value="">Any action</option>
            <?php foreach ($actions as $action => $label): ?>
                <option value="<?= h($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Server <input type="text" name="server_name" value="<?= h($filters['server_name']) ?>"></label>
    <label>Source IP <input type="text" name="source_ip" value="<?= h($filters['source_ip']) ?>"></label>
    <label>Handle ID <input type="text" name="handle_id" value="<?= h($filters['handle_id']) ?>"></label>
    <label>Object/path text <input type="text" name="path" value="<?= h($filters['path']) ?>"></label>
    <div class="filter-actions">
        <button type="submit">Search</button>
        <a class="button secondary" href="/search.php">Reset</a>
    </div>
</form>

<p class="result-count"><?= h($total) ?> result<?= $total === 1 ? '' : 's' ?></p>
<?php render_events_table($events); ?>

<?php render_pagination($page, $total, $perPage); ?>
<?php render_footer(); ?>
