<?php

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function dns_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function dns_bearer_authorization_header(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization !== '') {
        return $authorization;
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                return (string)$value;
            }
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dns_json_response(['ok' => false, 'errors' => ['POST required']], 405);
}

$trustedIps = config('TRUSTED_COLLECTOR_IPS', []);
if (is_array($trustedIps) && $trustedIps !== [] && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $trustedIps, true)) {
    dns_json_response(['ok' => false, 'errors' => ['Collector IP not trusted']], 403);
}

if (!preg_match('/^Bearer\s+(.+)$/i', dns_bearer_authorization_header(), $matches)) {
    dns_json_response(['ok' => false, 'errors' => ['Bearer token required']], 401);
}

$expectedToken = (string)config('API_TOKEN', '');
if ($expectedToken === '' || !hash_equals($expectedToken, trim($matches[1]))) {
    dns_json_response(['ok' => false, 'errors' => ['Invalid API token']], 401);
}

$decoded = json_decode((string)file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    dns_json_response(['ok' => false, 'errors' => ['Expected a JSON query object or array of query objects']], 400);
}

$queries = array_is_list($decoded) ? $decoded : [$decoded];
$stmt = db()->prepare('INSERT IGNORE INTO dns_queries (dns_server, time_created, client_ip, query_name, query_type, response_code, entry_hash, raw_line) VALUES (:dns_server, :time_created, :client_ip, :query_name, :query_type, :response_code, :entry_hash, :raw_line)');
$dedupeStmt = db()->prepare('SELECT 1 FROM dns_queries WHERE client_ip = :client_ip AND query_name = :query_name AND time_created BETWEEN :time_from AND :time_to LIMIT 1');
$inserted = 0;
$duplicates = 0;
$suppressed = 0;
$errors = [];
$deduplicateMinutes = max(0, (int)config('DNS_LOG_DEDUPLICATE_MINUTES', 1));

foreach ($queries as $index => $query) {
    if (!is_array($query)) {
        $errors[] = "Query $index: expected JSON object";
        continue;
    }

    $dnsServer = nullable_string($query, 'dns_server');
    $timeCreated = normalize_time_created($query['time_created'] ?? null);
    $clientIp = nullable_string($query, 'client_ip');
    $queryName = nullable_string($query, 'query_name');
    $entryHash = nullable_string($query, 'entry_hash');

    if ($dnsServer === null || $timeCreated === null || $clientIp === null || $queryName === null || $entryHash === null || !preg_match('/^[a-f0-9]{64}$/i', $entryHash)) {
        $errors[] = "Query $index: dns_server, time_created, client_ip, query_name, and a SHA-256 entry_hash are required";
        continue;
    }

    if ($deduplicateMinutes > 0) {
        $queryTime = new DateTimeImmutable($timeCreated, new DateTimeZone('UTC'));
        $dedupeStmt->execute([
            ':client_ip' => $clientIp,
            ':query_name' => $queryName,
            ':time_from' => $queryTime->modify("-$deduplicateMinutes minutes")->format('Y-m-d H:i:s'),
            ':time_to' => $queryTime->modify("+$deduplicateMinutes minutes")->format('Y-m-d H:i:s'),
        ]);
        if ($dedupeStmt->fetchColumn() !== false) {
            $suppressed++;
            continue;
        }
    }

    $stmt->execute([
        ':dns_server' => $dnsServer,
        ':time_created' => $timeCreated,
        ':client_ip' => $clientIp,
        ':query_name' => $queryName,
        ':query_type' => nullable_string($query, 'query_type'),
        ':response_code' => nullable_string($query, 'response_code'),
        ':entry_hash' => strtolower($entryHash),
        ':raw_line' => nullable_string($query, 'raw_line'),
    ]);

    if ($stmt->rowCount() === 1) {
        $inserted++;
    } else {
        $duplicates++;
    }
}

if ($errors === []) {
    $retentionDays = max(1, (int)config('DNS_LOG_RETENTION_DAYS', 7));
    db()->exec("DELETE FROM dns_queries WHERE time_created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $retentionDays DAY)");
}

dns_json_response([
    'ok' => $errors === [],
    'inserted' => $inserted,
    'duplicates' => $duplicates,
    'suppressed' => $suppressed,
    'errors' => $errors,
], $errors === [] ? 200 : 400);
