<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'client_ip' => trim((string)($_GET['client_ip'] ?? '')),
    'query_name' => trim((string)($_GET['query_name'] ?? '')),
];
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
if ($filters['client_ip'] !== '') {
    $where[] = 'client_ip = :client_ip';
    $params[':client_ip'] = $filters['client_ip'];
}
if ($filters['query_name'] !== '') {
    $where[] = 'query_name LIKE :query_name';
    $params[':query_name'] = '%' . $filters['query_name'] . '%';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = db()->prepare('SELECT COUNT(*) FROM dns_queries' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$page = current_page();
$perPage = 100;
$queryStmt = db()->prepare('SELECT * FROM dns_queries' . $whereSql . ' ORDER BY time_created DESC, id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $queryStmt->bindValue($key, $value);
}
$queryStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$queryStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$queryStmt->execute();
$queries = $queryStmt->fetchAll();

render_header('URL Log');
?>
<p class="hint">DNS records show requested domain names, not full web addresses or page paths. Records older than <?= h(max(1, (int)config('DNS_LOG_RETENTION_DAYS', 7))) ?> day(s) are removed during DNS collection.</p>
<form class="filters" method="get">
    <label>Date from <input type="date" name="date_from" value="<?= h($filters['date_from']) ?>"></label>
    <label>Date to <input type="date" name="date_to" value="<?= h($filters['date_to']) ?>"></label>
    <label>Computer IP <input type="text" name="client_ip" value="<?= h($filters['client_ip']) ?>"></label>
    <label>Domain <input type="text" name="query_name" value="<?= h($filters['query_name']) ?>"></label>
    <div class="filter-actions"><button type="submit">Search</button><a class="button secondary" href="/url-log.php">Reset</a></div>
</form>
<p class="result-count"><?= h($total) ?> DNS quer<?= $total === 1 ? 'y' : 'ies' ?></p>
<div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Computer IP</th><th>Domain</th><th>Type</th><th>Response</th><th>DNS Server</th></tr></thead>
    <tbody>
    <?php if (!$queries): ?><tr><td colspan="6" class="empty">No DNS queries found.</td></tr><?php endif; ?>
    <?php foreach ($queries as $query): ?><tr>
        <td><?= h(format_event_time($query['time_created'])) ?></td><td><?= h($query['client_ip']) ?></td><td><?= h($query['query_name']) ?></td><td><?= h($query['query_type'] ?? '') ?></td><td><?= h($query['response_code'] ?? '') ?></td><td><?= h($query['dns_server']) ?></td>
    </tr><?php endforeach; ?>
    </tbody>
</table></div>
<?php render_pagination($page, $total, $perPage); render_footer(); ?>
