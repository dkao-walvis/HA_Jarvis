<?php
/**
 * HA Jarvis — Voice Proxy
 * Called by Siri Shortcut: POST with {text: "..."} or raw body
 * Forwards to OpenClaw gateway (jarvis agent) and returns plain text response
 */

header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo 'POST required'; exit; }

// ── Auth ──────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

$body_early = file_get_contents('php://input');
$json_early = json_decode($body_early, true);

$given_key = $_SERVER['HTTP_X_API_KEY']
          ?? $_GET['api_key']
          ?? $_POST['api_key']
          ?? $json_early['api_key']
          ?? '';
if (empty($given_key)) {
    http_response_code(401);
    echo 'Missing API key';
    exit;
}

$db  = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ? AND enabled = 1");
$stmt->execute([$given_key]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo 'Invalid or disabled API key';
    exit;
}

// ── Kill switch ───────────────────────────────────────────────────────────────
$ks = $db->query("SELECT value FROM settings WHERE `key` = 'kill_switch'")->fetchColumn();
if ($ks === '1') {
    http_response_code(503);
    echo 'Jarvis is currently offline';
    exit;
}

// ── Parse input ───────────────────────────────────────────────────────────────
$body = $body_early;
$json = $json_early;

$text = $_GET['text']
     ?? $_POST['text']
     ?? $_POST['message']
     ?? $json['text']
     ?? $json['message']
     ?? $json['query']
     ?? trim($body);

if (empty($text)) {
    http_response_code(400);
    echo 'No text provided';
    exit;
}

// ── Mirror user command to Telegram ──────────────────────────────────────────
// Makes the conversation visible in Telegram as "Darren said → Jarvis replied"
define('TG_BOT_TOKEN', '7861413317:AAHCNDyiOrMxwoBjh8Ql2p49hsDd6WM8_O0');
define('TG_CHAT_ID',   '8433492739');

function tg_send(string $msg): void {
    $ch = curl_init('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['chat_id' => TG_CHAT_ID, 'text' => $msg]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Pre-fetch current home state from HA_Jarvis API ──────────────────────────
// The LLM has no tool access via this path, so we fetch entity states ourselves
// and inject them as context so Jarvis can answer state questions.
$ha_api_key = 'edcad79dff035e1531518bed409b31eb25ef843b71ec20d9';
$ha_api_url = 'http://localhost/HA_Jarvis/api.php';

$ch_ha = curl_init($ha_api_url . '?action=list');
curl_setopt_array($ch_ha, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["X-API-Key: $ha_api_key"],
    CURLOPT_TIMEOUT        => 5,
]);
$ha_resp = curl_exec($ch_ha);
curl_close($ch_ha);

$home_state = '';
$ha_data = json_decode($ha_resp, true);
if (!empty($ha_data['entities'])) {
    $lines = [];
    foreach ($ha_data['entities'] as $e) {
        $name  = $e['friendly_name'] ?? $e['entity_id'];
        $state = $e['state'] ?? 'unknown';
        $lines[] = "- $name: $state";
    }
    $home_state = "\n\n## Current Home State\n" . implode("\n", $lines);
}

// ── Build system prompt from Jarvis's workspace files ────────────────────────
$workspace = '/home/darren/.openclaw/workspace-jarvis';
$sections  = [];
foreach (['IDENTITY.md', 'SOUL.md', 'TOOLS.md', 'MEMORY.md'] as $f) {
    $path = $workspace . '/' . $f;
    if (file_exists($path)) {
        $sections[] = "## $f\n" . trim(file_get_contents($path));
    }
}
$system_prompt = implode("\n\n", $sections)
    . $home_state
    . "\n\n## Voice mode\nYou are replying via voice. Be concise. No markdown, no bullet points."
    . " The home state above is current — use it to answer state questions directly without running any commands.";

// ── Call jarvis_listener (real Claude CLI) ────────────────────────────────────
$payload = json_encode([
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $text],
    ],
    'stream' => false,
]);

$ch = curl_init('http://localhost:3000/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);

$resp      = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err       = curl_error($ch);
curl_close($ch);

if ($err || $http_code !== 200) {
    http_response_code(502);
    echo 'Jarvis did not respond. Please try again.';
    exit;
}

$data  = json_decode($resp, true);
$reply = $data['choices'][0]['message']['content'] ?? '';

if (empty($reply)) {
    http_response_code(502);
    echo 'Jarvis returned an empty response.';
    exit;
}

// ── Mirror to Telegram: show what was said + Jarvis reply ────────────────────
tg_send("🎙️ " . $text . "\n\n🤖 " . $reply);

// ── Log to audit ──────────────────────────────────────────────────────────────
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$db->prepare("INSERT INTO audit_log (api_key_id, entity_id, action, payload, result, status, ip)
              VALUES (NULL, 'voice', 'voice_proxy', ?, ?, 'ok', ?)")
   ->execute([substr($text, 0, 500), substr($reply, 0, 500), $ip]);

echo $reply;
