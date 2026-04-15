<?php
/**
 * play_office.php — Generate an AI music query via local-codex and play on office speaker.
 *
 * POST /HA_Jarvis/play_office.php
 * Optional body: {"preference": "something jazzy"}
 *
 * Returns: {"ok": true, "query": "...", "playing": "..."}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Auth ────────────────────────────────────────────────────────────────────
$given_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if (!$given_key) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Missing API key']); exit; }

$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ? AND enabled = 1");
$stmt->execute([$given_key]);
if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Invalid API key']); exit; }

// ── Parse preference ────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$preference = trim($body['preference'] ?? '');

// ── Get weather from entity_cache ───────────────────────────────────────────
$weather = '';
$ws = $db->query("SELECT ec.state, ec.attributes FROM entity_cache ec JOIN entities e ON e.entity_id = ec.entity_id WHERE e.entity_id LIKE 'weather.%' AND e.enabled = 1 LIMIT 1");
$wr = $ws->fetch(PDO::FETCH_ASSOC);
if ($wr) {
    $attrs = json_decode($wr['attributes'], true) ?: [];
    $weather = "Weather: {$wr['state']}, {$attrs['temperature']}°C";
}

// ── Build prompt ────────────────────────────────────────────────────────────
$base_prompt = "Suggest one YouTube Music search query for instrumental focus music for work.";
if ($preference) {
    $base_prompt = "Suggest one YouTube Music search query for: $preference. Must be instrumental, good for focus/work.";
}
$prompt = "$base_prompt $weather Reply with ONLY the search query, nothing else.";

// ── Call local-codex ────────────────────────────────────────────────────────
$codex_url = 'http://100.69.36.45:3310/ask_codex';
$codex_payload = json_encode(['prompt' => $prompt, 'system' => 'You generate concise YouTube playlist search queries. Reply with only the query, no explanation.']);
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/json',
    'content' => $codex_payload,
    'timeout' => 20,
]]);
$resp = @file_get_contents($codex_url, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;
$query = trim($data['reply'] ?? '') ?: 'focus instrumental work playlist';

// ── Play via HA_Jarvis API ──────────────────────────────────────────────────
// Use the same key that authenticated this request (guaranteed valid)
$api_key = $given_key;
$play_payload = json_encode([
    'action' => 'play',
    'entity_id' => 'Darren office speaker',
    'query' => $query,
    'api_key' => $api_key,
]);
$ctx2 = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/json',
    'content' => $play_payload,
    'timeout' => 15,
]]);
$play_resp = @file_get_contents('http://localhost/HA_Jarvis/api.php', false, $ctx2);
$play_data = $play_resp ? json_decode($play_resp, true) : null;

echo json_encode([
    'ok' => true,
    'query' => $query,
    'playing' => $play_data['playing'] ?? $query,
    'speaker' => $play_data['on'] ?? 'Darren office speaker',
]);
