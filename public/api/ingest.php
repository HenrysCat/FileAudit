<?php

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function bearer_authorization_header(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($authorization !== '') {
        return $authorization;
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                return (string)$value;
            }
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['POST required']], 405);
}

$trustedIps = config('TRUSTED_COLLECTOR_IPS', []);
if (is_array($trustedIps) && $trustedIps !== []) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $trustedIps, true)) {
        json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['Collector IP not trusted']], 403);
    }
}

$authorization = bearer_authorization_header();
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['Bearer token required']], 401);
}

$expectedToken = (string)config('API_TOKEN', '');
if ($expectedToken === '' || !hash_equals($expectedToken, trim($matches[1]))) {
    json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['Invalid API token']], 401);
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['Invalid JSON: ' . json_last_error_msg()]], 400);
}

if (!is_array($decoded)) {
    json_response(['ok' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => ['Expected a JSON event object or array of event objects']], 400);
}

$events = array_is_list($decoded) ? $decoded : [$decoded];
$inserted = 0;
$duplicates = 0;
$errors = [];

$sql = "
    INSERT IGNORE INTO audit_events (
        server_name, computer_name, event_id, record_id, time_created, username,
        domain_name, user_sid, source_ip, source_port, object_name, object_type,
        share_name, relative_target_name, action, access_mask, access_list,
        handle_id, logon_id, transaction_id, task_category, keywords_text,
        process_name, status, raw_json
    ) VALUES (
        :server_name, :computer_name, :event_id, :record_id, :time_created, :username,
        :domain_name, :user_sid, :source_ip, :source_port, :object_name, :object_type,
        :share_name, :relative_target_name, :action, :access_mask, :access_list,
        :handle_id, :logon_id, :transaction_id, :task_category, :keywords_text,
        :process_name, :status, :raw_json
    )
";
$stmt = db()->prepare($sql);

foreach ($events as $index => $event) {
    if (!is_array($event)) {
        $errors[] = 'Event ' . $index . ': expected JSON object';
        continue;
    }

    $serverName = nullable_string($event, 'server_name');
    $eventId = filter_var($event['event_id'] ?? null, FILTER_VALIDATE_INT);
    $recordId = filter_var($event['record_id'] ?? null, FILTER_VALIDATE_INT);
    $timeCreated = normalize_time_created($event['time_created'] ?? null);
    $action = nullable_string($event, 'action');

    if ($serverName === null || $eventId === false || $recordId === false || $timeCreated === null || $action === null) {
        $errors[] = 'Event ' . $index . ': server_name, event_id, record_id, time_created, and action are required';
        continue;
    }

    $stmt->execute([
        ':server_name' => $serverName,
        ':computer_name' => nullable_string($event, 'computer_name'),
        ':event_id' => $eventId,
        ':record_id' => $recordId,
        ':time_created' => $timeCreated,
        ':username' => nullable_string($event, 'username'),
        ':domain_name' => nullable_string($event, 'domain_name'),
        ':user_sid' => nullable_string($event, 'user_sid'),
        ':source_ip' => nullable_string($event, 'source_ip'),
        ':source_port' => nullable_string($event, 'source_port'),
        ':object_name' => nullable_string($event, 'object_name'),
        ':object_type' => nullable_string($event, 'object_type'),
        ':share_name' => nullable_string($event, 'share_name'),
        ':relative_target_name' => nullable_string($event, 'relative_target_name'),
        ':action' => $action,
        ':access_mask' => nullable_string($event, 'access_mask'),
        ':access_list' => nullable_string($event, 'access_list'),
        ':handle_id' => nullable_string($event, 'handle_id'),
        ':logon_id' => nullable_string($event, 'logon_id'),
        ':transaction_id' => nullable_string($event, 'transaction_id'),
        ':task_category' => nullable_string($event, 'task_category'),
        ':keywords_text' => nullable_string($event, 'keywords_text'),
        ':process_name' => nullable_string($event, 'process_name'),
        ':status' => nullable_string($event, 'status'),
        ':raw_json' => json_encode($event, JSON_UNESCAPED_SLASHES),
    ]);

    if ($stmt->rowCount() === 1) {
        $inserted++;
    } else {
        $duplicates++;
    }
}

json_response([
    'ok' => $errors === [],
    'inserted' => $inserted,
    'duplicates' => $duplicates,
    'errors' => $errors,
], $errors === [] ? 200 : 400);
