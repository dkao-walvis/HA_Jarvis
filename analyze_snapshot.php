<?php
/**
 * analyze_snapshot.php — Analyze a camera snapshot using jarvis-client (Claude vision).
 *
 * POST /HA_Jarvis/analyze_snapshot.php
 * Body: {"image_url": "http://...", "type": "person|car"}
 *
 * Returns: {"ok": true, "analysis": "..."}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// Auth
$given_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if (!$given_key) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Missing API key']); exit; }
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ? AND enabled = 1");
$stmt->execute([$given_key]);
if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Invalid API key']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$image_url = $body['image_url'] ?? '';
$type = $body['type'] ?? 'person';

if (!$image_url) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'image_url required']); exit; }

if ($type === 'car') {
    $prompt = "Analyze this security camera image. First, is there actually a car in the image? Start your response with CONFIRMED: or FALSE_POSITIVE: on the first line. If confirmed, read the license plate if visible and describe the vehicle. Format:\nCONFIRMED: Plate: [PLATE or 'Not visible']. Vehicle: [description].\nOr if no car: FALSE_POSITIVE: No car detected — [what you see instead].";
} else {
    $prompt = "Analyze this security camera image. First, is there actually a person in the image? Start your response with CONFIRMED: or FALSE_POSITIVE: on the first line. If confirmed, describe the person (gender, age, clothing). Format:\nCONFIRMED: [person description].\nOr if no person: FALSE_POSITIVE: No person detected — [what triggered the alert, e.g., shadow, animal, object].";
}

// Download image to a world-readable temp file
$img_data = @file_get_contents($image_url);
if (!$img_data) { echo json_encode(['ok' => false, 'error' => 'Could not fetch image']); exit; }
// Use a shared dir (Apache's PrivateTmp isolates /tmp and /var/tmp)
$tmp_img = '/var/www/html/HA_Jarvis/tmp/claude_img_' . uniqid() . '.jpg';
file_put_contents($tmp_img, $img_data);
chmod($tmp_img, 0644);

// Call jarvis listener /ask endpoint directly with image_path
// The listener passes image_path to codex CLI which supports vision
$system = "You analyze security camera images for a home security system. Be concise and factual.";
$full_prompt = "$system\n\n$prompt";
$payload = json_encode([
    'prompt'     => $full_prompt,
    'image_path' => $tmp_img,
]);
$ctx = stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => 'Content-Type: application/json',
    'content' => $payload,
    'timeout' => 30,
]]);

// The /ask endpoint returns SSE (streaming). Collect all chunks.
$resp = @file_get_contents('http://localhost:3000/ask_claude_stream', false, $ctx);
$result = '';
if ($resp) {
    foreach (explode("\n", $resp) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'data: ')) continue;
        $evt = json_decode(substr($line, 6), true);
        if (isset($evt['chunk'])) $result .= $evt['chunk'];
    }
}
$result = trim($result);
@unlink($tmp_img);

if (!$result) {
    $result = $type === 'car' ? 'Plate: Not visible. Vehicle: Analysis unavailable.' : 'Person description unavailable.';
}

$confirmed = !str_starts_with(strtoupper(trim($result)), 'FALSE_POSITIVE');

// ── Double Take face match (person events only) ─────────────────────────────
$face_match = null;
if ($type === 'person' && $confirmed) {
    $camera = $body['camera'] ?? 'Driveway';
    $dt_url = "http://100.72.30.111:3000/api/latest?camera=" . urlencode($camera);
    $dt_ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $dt_resp = @file_get_contents($dt_url, false, $dt_ctx);
    $dt_data = $dt_resp ? json_decode($dt_resp, true) : null;
    if ($dt_data && !empty($dt_data['match'])) {
        $face_match = [
            'name' => $dt_data['match']['name'] ?? 'unknown',
            'confidence' => round($dt_data['match']['confidence'] ?? 0, 1),
        ];
    }
}

echo json_encode([
    'ok' => true,
    'analysis' => $result,
    'confirmed' => $confirmed,
    'type' => $type,
    'face_match' => $face_match,
]);
