<?php
/**
 * play_game_room.php — Generate an AI music query via local Claude and play on game room speaker.
 *
 * POST /HA_Jarvis/play_game_room.php
 * Optional body: {"preference": "something upbeat"}
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

// ── Build prompt ────────────────────────────────────────────────────────────
$base_prompt = "Suggest one YouTube Music search query for upbeat pop music for a game room — high-energy, sing-along friendly.";
if ($preference) {
    $base_prompt = "Suggest one YouTube Music search query for: $preference. Must be upbeat, high-energy, good for a game room.";
}
$prompt = "$base_prompt Reply with ONLY the search query, nothing else.";

// ── Call local Claude ────────────────────────────────────────────────────────
$claude_url = 'http://100.69.36.45:3000/ask_claude';
$claude_payload = json_encode([
    'prompt'  => $prompt,
    'context' => 'You generate concise YouTube playlist search queries. Reply with only the query, no explanation.',
]);
$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => 'Content-Type: application/json',
    'content' => $claude_payload,
    'timeout' => 20,
]]);
$resp = @file_get_contents($claude_url, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;
$query = trim($data['reply'] ?? '') ?: 'upbeat pop game room playlist';

// ── Play via HA_Jarvis API ──────────────────────────────────────────────────
// Use the same key that authenticated this request (guaranteed valid)
$api_key = $given_key;
$play_payload = json_encode([
    'action' => 'play',
    'entity_id' => 'Game room speaker',
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
    'speaker' => $play_data['on'] ?? 'Game room speaker',
]);
