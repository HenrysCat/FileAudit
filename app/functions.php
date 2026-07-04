<?php

declare(strict_types=1);

function config(?string $key = null, mixed $default = null): mixed
{
    global $config;

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_name(): string
{
    return (string)config('APP_NAME', 'FileAudit');
}

function app_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone((string)config('APP_TIMEZONE', 'UTC'));
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

function format_event_time(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(app_timezone())->format('Y-m-d H:i:s');
    } catch (Exception) {
        return $value;
    }
}

function render_header(string $title): void
{
    $fullTitle = $title . ' - ' . app_name();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($fullTitle) ?></title>
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="/"><?= h(app_name()) ?></a>
        <nav class="nav">
            <a href="/">Dashboard</a>
            <a href="/search.php">Search</a>
            <a href="/deletions.php">Deletions</a>
            <a href="/modifications.php">Modifications</a>
            <a href="/permissions.php">Permissions</a>
            <a href="/failed.php">Failed</a>
            <a href="/logout.php">Logout</a>
        </nav>
    </header>
    <main class="container">
    <h1><?= h($title) ?></h1>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}

function action_badge(string $action): string
{
    $class = 'badge';
    $normalized = strtolower($action);

    if ($normalized === 'deleted') {
        $class .= ' badge-danger';
    } elseif ($normalized === 'deleterequested') {
        $class .= ' badge-warning';
    } elseif (in_array($normalized, ['created', 'modified', 'written'], true)) {
        $class .= ' badge-info';
    } elseif ($normalized === 'permissionchanged') {
        $class .= ' badge-warning';
    } elseif ($normalized === 'failedaccess') {
        $class .= ' badge-muted';
    } else {
        $class .= ' badge-default';
    }

    return '<span class="' . h($class) . '">' . h($action) . '</span>';
}

function fetch_recent_events(?string $where = null, array $params = [], int $limit = 100, int $offset = 0): array
{
    $sql = 'SELECT * FROM audit_events';
    $where = visible_events_where($where);

    if ($where) {
        $sql .= ' WHERE ' . $where;
    }

    $sql .= ' ORDER BY time_created DESC, id DESC LIMIT :limit OFFSET :offset';
    $stmt = db()->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function count_events(?string $where = null, array $params = []): int
{
    $sql = 'SELECT COUNT(*) FROM audit_events';
    $where = visible_events_where($where);

    if ($where) {
        $sql .= ' WHERE ' . $where;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function visible_events_where(?string $where = null): string
{
    $visible = "(
        (object_name IS NULL OR object_name NOT LIKE '%.tmp')
        AND (relative_target_name IS NULL OR relative_target_name NOT LIKE '%.tmp')
    )";

    if (!$where) {
        return $visible;
    }

    return '(' . $where . ') AND ' . $visible;
}

function ignored_tmp_path(array $event): bool
{
    foreach (['object_name', 'relative_target_name'] as $field) {
        if (!empty($event[$field]) && preg_match('/\.tmp$/i', (string)$event[$field])) {
            return true;
        }
    }

    return false;
}

function current_page(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

function render_pagination(int $page, int $total, int $perPage): void
{
    $totalPages = max(1, (int)ceil($total / $perPage));

    if ($totalPages <= 1) {
        return;
    }
    ?>
    <nav class="pagination">
        <?php if ($page > 1): ?>
            <a class="button" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= h($page) ?> of <?= h($totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a class="button" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a>
        <?php endif; ?>
    </nav>
    <?php
}

function render_events_table(array $events): void
{
    $events = apply_display_actions($events);
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Time</th>
                <th>Event ID</th>
                <th>Action</th>
                <th>User</th>
                <th>Server</th>
                <th>Object</th>
                <th>Source IP</th>
                <th>Handle ID</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$events): ?>
                <tr><td colspan="9" class="empty">No events found.</td></tr>
            <?php endif; ?>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= h(format_event_time($event['time_created'] ?? null)) ?></td>
                    <td><?= h($event['event_id'] ?? '') ?></td>
                    <td><?= action_badge((string)($event['display_action'] ?? $event['action'])) ?></td>
                    <td><?= h($event['username'] ?? '') ?></td>
                    <td><?= h($event['server_name']) ?></td>
                    <td class="path-cell"><?= h(display_event_path($event)) ?></td>
                    <td><?= h($event['source_ip'] ?? '') ?></td>
                    <td><?= h($event['handle_id'] ?? '') ?></td>
                    <td><a class="button small" href="/event.php?id=<?= h($event['id']) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function apply_display_actions(array $events): array
{
    $candidates = [];

    foreach ($events as $index => $event) {
        if (($event['action'] ?? '') !== 'DeleteRequested') {
            continue;
        }

        if (empty($event['server_name']) || empty($event['handle_id']) || empty($event['time_created'])) {
            continue;
        }

        $candidates[$index] = $event;
    }

    if (!$candidates) {
        return $events;
    }

    foreach ($candidates as $index => $event) {
        if ((int)($event['event_id'] ?? 0) === 4659) {
            $events[$index]['display_action'] = 'Deleted';
            continue;
        }

        $timeCreated = new DateTimeImmutable((string)$event['time_created']);
        $params = [
            ':server_name' => $event['server_name'],
            ':handle_id' => $event['handle_id'],
            ':time_from' => $timeCreated->modify('-2 minutes')->format('Y-m-d H:i:s'),
            ':time_to' => $timeCreated->modify('+2 minutes')->format('Y-m-d H:i:s'),
        ];

        $logonSql = '';
        if (!empty($event['logon_id'])) {
            $logonSql = ' AND logon_id = :logon_id';
            $params[':logon_id'] = $event['logon_id'];
        }

        $stmt = db()->prepare("
            SELECT 1
            FROM audit_events
            WHERE event_id IN (4659, 4660)
              AND server_name = :server_name
              AND handle_id = :handle_id
              $logonSql
              AND time_created BETWEEN :time_from AND :time_to
            LIMIT 1
        ");
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            $events[$index]['display_action'] = 'Deleted';
        }
    }

    return $events;
}

function display_event_path(array $event): string
{
    foreach (['object_name', 'relative_target_name', 'share_name'] as $field) {
        if (!empty($event[$field])) {
            return (string)$event[$field];
        }
    }

    return '';
}

function nullable_string(array $data, string $key): ?string
{
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return null;
    }

    return (string)$data[$key];
}

function normalize_time_created(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}
