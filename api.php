<?php
/**
 * HA Jarvis - OpenClaw API Endpoint
 *
 * Endpoints:
 *   GET  /api.php?action=get&entity_id=Office Light
 *   POST /api.php  {"action":"call","entity_id":"Office Light","service":"turn_on","data":{}}
 *   GET  /api.php?action=list  (list allowed entities)
 *
 * entity_id accepts friendly name (e.g. "Office Light") or raw entity ID (e.g. "light.dk_office")
 * Auth: header  X-API-Key: <key>
 *       or param api_key=<key>
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// ── Auth ────────────────────────────────────────────────────────────────────
$api_key = $_SERVER['HTTP_X_API_KEY']
    ?? $_GET['api_key']
    ?? (json_decode(file_get_contents('php://input'), true)['api_key'] ?? null);

if (!$api_key) {
    respond(401, 'error', 'Missing API key');
}

$db  = get_db();
$row = $db->prepare("SELECT * FROM api_keys WHERE api_key = ?");
$row->execute([$api_key]);
$key_row = $row->fetch(PDO::FETCH_ASSOC);

if (!$key_row) {
    audit(null, null, null, 'auth', null, 'Unknown API key', 'denied');
    respond(401, 'error', 'Invalid API key');
}
if (!$key_row['enabled']) {
    audit($key_row['id'], $key_row['label'], null, 'auth', null, 'Key disabled', 'denied');
    respond(403, 'error', 'API key is disabled');
}

// ── Kill Switch ──────────────────────────────────────────────────────────────
if (get_setting('kill_switch') === '1') {
    audit($key_row['id'], $key_row['label'], null, 'killed', null, 'Kill switch active', 'denied');
    respond(503, 'error', 'All access blocked — kill switch is active');
}

// ── Parse Request ────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$body   = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
}

$action    = $body['action']    ?? $_GET['action']    ?? null;
$entity_id = $body['entity_id'] ?? $_GET['entity_id'] ?? null;
$service   = $body['service']   ?? $_GET['service']   ?? null;
$query     = $body['query']     ?? $_GET['query']     ?? null;
$data      = $body['data']      ?? [];
$return_response = !empty($body['return_response']);

// ── List allowed entities ────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->query("SELECT friendly_name, entity_id, allowed_actions FROM entities WHERE enabled = 1 ORDER BY friendly_name")
               ->fetchAll(PDO::FETCH_ASSOC);
    audit($key_row['id'], $key_row['label'], null, 'list', null, count($rows) . ' entities', 'ok');
    echo json_encode(['status' => 'ok', 'note' => 'Use friendly_name when calling get/call actions', 'entities' => $rows]);
    exit;
}

// ── Require entity_id for everything else ────────────────────────────────────
if (!$entity_id) {
    respond(400, 'error', 'Missing entity_id');
}

// ── Resolve friendly name → entity_id ────────────────────────────────────────
// Accept friendly name (case-insensitive) or raw entity_id
$stmt = $db->prepare("SELECT * FROM entities WHERE (entity_id = ? OR LOWER(friendly_name) = LOWER(?)) AND enabled = 1");
$stmt->execute([$entity_id, $entity_id]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    audit($key_row['id'], $key_row['label'], $entity_id, $action, null, 'Entity not whitelisted', 'denied');
    respond(403, 'error', "Entity '$entity_id' is not allowed or not found");
}

// Use the canonical entity_id from DB from here on
$entity_id = $entity['entity_id'];

$allowed = array_map('trim', explode(',', $entity['allowed_actions']));

// ── Route actions ────────────────────────────────────────────────────────────
$ha_url   = get_setting('ha_url');
$ha_token = get_setting('ha_token');

switch ($action) {

    case 'get':
        if (!in_array('get', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'get', null, 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'get' not allowed for '$entity_id'");
        }
        // Read from cache first
        $cached = $db->prepare("SELECT * FROM entity_cache WHERE entity_id = ?");
        $cached->execute([$entity_id]);
        $cache_row = $cached->fetch(PDO::FETCH_ASSOC);
        if ($cache_row) {
            $result = [
                'entity_id'   => $entity_id,
                'state'       => $cache_row['state'],
                'attributes'  => json_decode($cache_row['attributes'], true) ?? [],
                'last_changed'=> $cache_row['last_changed'],
                'cached_at'   => $cache_row['cached_at'],
                'source'      => 'cache',
            ];
            audit($key_row['id'], $key_row['label'], $entity_id, 'get', null, 'state: ' . $cache_row['state'] . ' (cache)', 'ok');
        } else {
            // Fallback to live HA if not in cache yet
            $result = ha_request('GET', "$ha_url/api/states/$entity_id", $ha_token);
            $result['source'] = 'live';
            audit($key_row['id'], $key_row['label'], $entity_id, 'get', null, 'state: ' . ($result['state'] ?? '?') . ' (live)', 'ok');
        }
        echo json_encode(['status' => 'ok', 'entity' => $result]);
        break;

    case 'call':
        if (!in_array('call', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'call', json_encode($body), 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'call' not allowed for '$entity_id'");
        }
        if (!$service) respond(400, 'error', 'Missing service (e.g. turn_on, turn_off)');
        [$domain] = explode('.', $entity_id);
        $payload  = array_merge(['entity_id' => $entity_id], $data);
        $svc_url  = "$ha_url/api/services/$domain/$service" . ($return_response ? '?return_response' : '');
        $result   = ha_request('POST', $svc_url, $ha_token, $payload);
        audit($key_row['id'], $key_row['label'], $entity_id, "call:$service", json_encode($payload), 'ok', 'ok');
        echo json_encode(['status' => 'ok', 'result' => $result]);
        break;

    case 'set':
        // Alias for call — pass service in body
        if (!in_array('set', $allowed) && !in_array('call', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'set', json_encode($body), 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'set' not allowed for '$entity_id'");
        }
        if (!$service) respond(400, 'error', 'Missing service');
        [$domain] = explode('.', $entity_id);
        $payload  = array_merge(['entity_id' => $entity_id], $data);
        $result   = ha_request('POST', "$ha_url/api/services/$domain/$service", $ha_token, $payload);
        audit($key_row['id'], $key_row['label'], $entity_id, "set:$service", json_encode($payload), 'ok', 'ok');
        echo json_encode(['status' => 'ok', 'result' => $result]);
        break;

    case 'play':
        if (!in_array('play', $allowed) && !in_array('call', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'play', $query, 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'play' not allowed for '$entity_id'");
        }
        if (!$query) respond(400, 'error', 'Missing query (song name or YouTube URL)');

        // Resolve query to audio stream URL via yt-dlp service
        $ytdlp = ytdlp_resolve($query);
        if (!$ytdlp['ok']) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'play', $query, $ytdlp['message'], 'error');
            respond(502, 'error', 'Could not resolve audio: ' . $ytdlp['message']);
        }

        // Cast to HA media player
        $payload = [
            'entity_id'          => $entity_id,
            'media_content_id'   => $ytdlp['url'],
            'media_content_type' => 'music',
            'extra'              => [
                'metadata' => [
                    'metadataType' => 3,
                    'title'        => $ytdlp['title'],
                    'artist'       => 'Jarvis',
                ],
            ],
        ];
        ha_request('POST', "$ha_url/api/services/media_player/play_media", $ha_token, $payload);
        audit($key_row['id'], $key_row['label'], $entity_id, 'play', $query, $ytdlp['title'], 'ok');
        echo json_encode([
            'status'  => 'ok',
            'playing' => $ytdlp['title'],
            'on'      => $entity['friendly_name'],
        ]);
        break;

    case 'snapshot':
        if (!in_array('snapshot', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'snapshot', null, 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'snapshot' not allowed for '$entity_id'");
        }
        $img_url = "$ha_url/api/camera_proxy/$entity_id";
        $ch = curl_init($img_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $ha_token"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $img_bytes = curl_exec($ch);
        $img_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $img_type  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($img_code !== 200 || !$img_bytes) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'snapshot', null, "HA returned HTTP $img_code", 'error');
            respond(502, 'error', "Camera snapshot failed (HTTP $img_code)");
        }
        $format = $body['format'] ?? $_GET['format'] ?? 'base64';
        if ($format === 'raw') {
            header('Content-Type: ' . ($img_type ?: 'image/jpeg'));
            audit($key_row['id'], $key_row['label'], $entity_id, 'snapshot', null, 'raw image', 'ok');
            echo $img_bytes;
        } else {
            audit($key_row['id'], $key_row['label'], $entity_id, 'snapshot', null, 'base64 image', 'ok');
            echo json_encode([
                'status'      => 'ok',
                'entity_id'   => $entity_id,
                'format'      => 'base64',
                'content_type'=> $img_type ?: 'image/jpeg',
                'image'       => base64_encode($img_bytes),
            ]);
        }
        break;

    case 'stream':
        if (!in_array('snapshot', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'stream', null, 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'snapshot' (required for stream) not allowed for '$entity_id'");
        }
        audit($key_row['id'], $key_row['label'], $entity_id, 'stream', null, 'stream url returned', 'ok');
        echo json_encode([
            'status'     => 'ok',
            'entity_id'  => $entity_id,
            'stream_url' => "$ha_url/api/camera_proxy_stream/$entity_id",
            'note'       => 'Open stream_url in a browser or VLC. Requires HA session cookie or direct network access.',
        ]);
        break;

    case 'delete':
        if (!in_array('delete', $allowed)) {
            audit($key_row['id'], $key_row['label'], $entity_id, 'delete', null, 'Action not permitted', 'denied');
            respond(403, 'error', "Action 'delete' not allowed for '$entity_id'");
        }
        $result = ha_request('DELETE', "$ha_url/api/states/$entity_id", $ha_token);
        audit($key_row['id'], $key_row['label'], $entity_id, 'delete', null, 'deleted', 'ok');
        echo json_encode(['status' => 'ok', 'result' => $result]);
        break;

    default:
        respond(400, 'error', "Unknown action '$action'. Use: get, call, set, snapshot, stream, delete, list");
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function ytdlp_resolve(string $query): array {
    $ch = curl_init('http://127.0.0.1:18790/resolve');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res) return ['ok' => false, 'message' => 'yt-dlp service unreachable'];
    return json_decode($res, true) ?? ['ok' => false, 'message' => 'Invalid response'];
}

function ha_request(string $method, string $url, string $token, ?array $body = null): array {
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: application/json",
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        respond(502, 'error', 'Could not reach Home Assistant');
    }
    $decoded = json_decode($response, true);
    if ($http_code >= 400) {
        respond($http_code, 'error', 'HA error: ' . ($decoded['message'] ?? $response));
    }
    return $decoded ?? [];
}

function audit(?int $key_id, ?string $label, ?string $entity, ?string $action, ?string $payload, ?string $result, string $status): void {
    $db = get_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $db->prepare("INSERT INTO audit_log (api_key_id, api_label, entity_id, action, payload, result, status, ip) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$key_id, $label, $entity, $action, $payload, $result, $status, $ip]);
}

function respond(int $code, string $status, string $message): never {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}
