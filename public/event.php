<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    render_header('Invalid Event');
    echo '<p class="empty">Invalid event id.</p>';
    render_footer();
    exit;
}

$stmt = db()->prepare('SELECT * FROM audit_events WHERE id = :id');
$stmt->execute([':id' => $id]);
$event = $stmt->fetch();

if (!$event) {
    http_response_code(404);
    render_header('Event Not Found');
    echo '<p class="empty">Event not found.</p>';
    render_footer();
    exit;
}

$rawJson = '';
if (!empty($event['raw_json'])) {
    $decoded = json_decode((string)$event['raw_json'], true);
    $rawJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

$showDeletionCorrelationNote = in_array((int)$event['event_id'], [4659, 4660], true)
    && empty($event['object_name'])
    && !empty($event['handle_id']);

$confirmedByRelatedDelete = false;
$relatedEvents = [];
$relatedWhere = [];
$relatedParams = [
    ':id' => $event['id'],
    ':server_name' => $event['server_name'],
    ':time_from' => (new DateTimeImmutable((string)$event['time_created']))->modify('-2 minutes')->format('Y-m-d H:i:s'),
    ':time_to' => (new DateTimeImmutable((string)$event['time_created']))->modify('+2 minutes')->format('Y-m-d H:i:s'),
];

if (!empty($event['handle_id'])) {
    $relatedWhere[] = 'handle_id = :handle_id';
    $relatedParams[':handle_id'] = $event['handle_id'];
}

if (!empty($event['logon_id']) && !empty($event['username'])) {
    $relatedWhere[] = '(logon_id = :logon_id AND username = :username)';
    $relatedParams[':logon_id'] = $event['logon_id'];
    $relatedParams[':username'] = $event['username'];
}

if ($relatedWhere) {
    $relatedSql = "
        SELECT id, time_created, event_id, action, username, object_name, relative_target_name,
               share_name, access_mask, access_list, handle_id, logon_id
        FROM audit_events
        WHERE id <> :id
          AND server_name = :server_name
          AND time_created BETWEEN :time_from AND :time_to
          AND (object_name IS NULL OR object_name NOT LIKE '%.tmp')
          AND (relative_target_name IS NULL OR relative_target_name NOT LIKE '%.tmp')
          AND (" . implode(' OR ', $relatedWhere) . ")
        ORDER BY time_created ASC, id ASC
        LIMIT 50
    ";
    $relatedStmt = db()->prepare($relatedSql);
    $relatedStmt->execute($relatedParams);
    $relatedEvents = $relatedStmt->fetchAll();

    foreach ($relatedEvents as $relatedEvent) {
        if (in_array((int)$relatedEvent['event_id'], [4659, 4660], true)) {
            $confirmedByRelatedDelete = true;
            break;
        }
    }
}

$fieldLabels = [
    'handle_id' => 'Handle ID',
    'logon_id' => 'Logon ID',
    'transaction_id' => 'Transaction ID',
    'task_category' => 'Task Category',
    'keywords_text' => 'Keywords',
];

render_header('Event #' . $event['id']);
?>
<?php if ($showDeletionCorrelationNote): ?>
    <div class="notice">
        This deletion confirmation or delete-intent event does not include the object path. Look for related 4656 or 4663 events with the same Handle ID and Logon ID.
    </div>
<?php endif; ?>

<?php if (($event['action'] ?? '') === 'DeleteRequested' && $confirmedByRelatedDelete): ?>
    <div class="notice">
        This path-bearing delete access event is confirmed by a related 4659 or 4660 event with the same Handle ID and Logon ID.
    </div>
<?php endif; ?>

<section class="detail-grid">
    <?php foreach ($event as $key => $value): ?>
        <?php if ($key === 'raw_json') {
            continue;
        } ?>
        <div class="detail-key"><?= h($fieldLabels[$key] ?? $key) ?></div>
        <div class="detail-value"><?= h(in_array($key, ['time_created', 'received_at'], true) ? format_event_time($value) : ($value ?? '')) ?></div>
    <?php endforeach; ?>
</section>

<h2>Related Events</h2>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Time</th>
            <th>Event ID</th>
            <th>Action</th>
            <th>User</th>
            <th>Object</th>
            <th>Access Mask</th>
            <th>Access List</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$relatedEvents): ?>
            <tr><td colspan="8" class="empty">No related events found.</td></tr>
        <?php endif; ?>
        <?php foreach ($relatedEvents as $related): ?>
            <tr>
                <td><?= h(format_event_time($related['time_created'] ?? null)) ?></td>
                <td><?= h($related['event_id']) ?></td>
                <td><?= action_badge((string)$related['action']) ?></td>
                <td><?= h($related['username'] ?? '') ?></td>
                <td class="path-cell"><?= h(display_event_path($related)) ?></td>
                <td><?= h($related['access_mask'] ?? '') ?></td>
                <td class="path-cell"><?= h($related['access_list'] ?? '') ?></td>
                <td><a class="button small" href="/event.php?id=<?= h($related['id']) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2>Raw JSON</h2>
<pre class="json-block"><?= h($rawJson) ?></pre>
<?php render_footer(); ?>
