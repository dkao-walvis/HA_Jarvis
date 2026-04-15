<?php
/**
 * play_office.php — Seed-pool rotation music player for the office speaker.
 *
 * POST /HA_Jarvis/play_office.php
 * Optional body: {"preference": "something jazzy"}   (logged, not yet used for seed selection)
 *
 * Returns: {"ok": true, "seed": "...", "query": "...", "speaker": "..."}
 */
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Auth (jarvis_ha.api_keys) ────────────────────────────────────────────────
$given_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
if (!$given_key) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing API key']);
    exit;
}

$haDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$haDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $haDb->prepare("SELECT id FROM api_keys WHERE api_key = ? AND enabled = 1");
$stmt->execute([$given_key]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$preference = trim($body['preference'] ?? '');   // v1: logged only, not wired

// ── Connect to jarvis_brain ──────────────────────────────────────────────────
$brainDb = new PDO(
    'mysql:host=localhost;dbname=jarvis_brain;charset=utf8mb4',
    'admin', 'StrongPassword123!'
);
$brainDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$context  = 'office';
$speaker  = 'Darren office speaker';
$fallback = 'instrumental focus work playlist';

// ── Fetch enabled seeds ──────────────────────────────────────────────────────
$seedStmt = $brainDb->prepare("SELECT * FROM music_seeds WHERE context = ? AND enabled = 1");
$seedStmt->execute([$context]);
$seeds = $seedStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$seeds) {
    // No seeds — fall back to a plain Claude prompt
    $query = _claude_fallback($fallback);
    _do_play($speaker, $query, $given_key, $context, null, $fallback, $query, $brainDb);
    echo json_encode(['ok' => true, 'seed' => $fallback, 'query' => $query, 'speaker' => $speaker]);
    exit;
}

// ── Cooldown filter (7d → 3d → 1d → 0d) ─────────────────────────────────────
$eligible = [];
foreach ([7, 3, 1, 0] as $days) {
    $recentStmt = $brainDb->prepare("
        SELECT DISTINCT seed_id FROM music_history
        WHERE context = ? AND seed_id IS NOT NULL AND ts >= NOW() - INTERVAL ? DAY
    ");
    $recentStmt->execute([$context, $days]);
    $recent = array_column($recentStmt->fetchAll(PDO::FETCH_ASSOC), 'seed_id');
    $recent = array_flip($recent);
    $eligible = array_filter($seeds, fn($s) => !isset($recent[$s['id']]));
    if ($eligible || $days === 0) break;
}
if (!$eligible) {
    $eligible = $seeds;
}
$eligible = array_values($eligible);

// ── Weighted random pick ──────────────────────────────────────────────────────
$total = array_sum(array_column($eligible, 'weight')) ?: 1;
$roll  = mt_rand(0, $total * 1000) / 1000;
$acc   = 0;
$chosen = $eligible[0];
foreach ($eligible as $s) {
    $acc += $s['weight'];
    if ($roll <= $acc) {
        $chosen = $s;
        break;
    }
}

// ── Claude refinement ─────────────────────────────────────────────────────────
$refined = _ask_claude_refine($chosen['seed_text'], $chosen['genre'] ?? null, $context);
$query   = $refined ?: $chosen['seed_text'];

// ── Play via api.php ──────────────────────────────────────────────────────────
_do_play($speaker, $query, $given_key, $context, $chosen, $chosen['seed_text'], $query, $brainDb);

echo json_encode([
    'ok'         => true,
    'seed'       => $chosen['seed_text'],
    'query'      => $query,
    'speaker'    => $speaker,
]);

// ── Helpers ───────────────────────────────────────────────────────────────────

function _ask_claude_refine(string $seed_text, ?string $genre, string $context): ?string {
    $system = "You are a music curator. Generate concise YouTube search queries that return long playlist/radio variants of the seed. One line only.";
    $prompt = "Seed: {$seed_text}\nGenre: " . ($genre ?: 'unspecified') . "\nContext: {$context}\n\nEmit a single YouTube search query that returns a LONG playlist or radio mix of this seed. Return only the query, no explanation, no quotes.";
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode(['prompt' => $prompt, 'context' => $system]),
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents('http://100.69.36.45:3000/ask_claude', false, $ctx);
    if (!$resp) return null;
    $data  = json_decode($resp, true);
    $reply = trim($data['reply'] ?? '');
    if (!_is_sane_query($reply)) return null;
    return $reply;
}

/**
 * Reject Claude replies that look like upstream errors or non-query content.
 * Real queries are short, plain text, no JSON, no error keywords.
 */
function _is_sane_query(string $r): bool {
    if ($r === '') return false;
    if (strlen($r) > 200) return false;            // queries should be short
    if (strpos($r, '{') !== false) return false;   // any JSON-looking content = not a query
    if (preg_match('/\b(api error|http \d{3}|internal server error|rate limit|status\.claude\.com|503|502|429)\b/i', $r)) return false;
    if (preg_match('/^\s*error\b/i', $r)) return false;
    return true;
}

function _claude_fallback(string $fallback): string {
    $refined = _ask_claude_refine($fallback, null, 'office');
    return $refined ?: $fallback;
}

function _do_play(
    string  $speaker,
    string  $query,
    string  $api_key,
    string  $context,
    ?array  $seed,
    string  $seed_text,
    string  $query_sent,
    PDO     $brainDb
): void {
    $trigger_source = 'legacy_automation';

    // Play
    $play_ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode([
            'action'         => 'play',
            'entity_id'      => $speaker,
            'query'          => $query,
            'api_key'        => $api_key,
            'trigger_source' => $trigger_source,
        ]),
        'timeout' => 15,
    ]]);
    @file_get_contents('http://localhost/HA_Jarvis/api.php', false, $play_ctx);

    // Log history + bump counters (non-fatal)
    try {
        $brainDb->prepare("
            INSERT INTO music_history (context, seed_id, seed_text, speaker, trigger_source, query_sent)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$context, $seed['id'] ?? null, $seed_text, $speaker, $trigger_source, $query_sent]);

        if ($seed) {
            $brainDb->prepare("
                UPDATE music_seeds SET last_played_at = NOW(), play_count = play_count + 1 WHERE id = ?
            ")->execute([$seed['id']]);
        }
    } catch (Exception $e) {
        error_log("[play_office] history/seed update failed: " . $e->getMessage());
    }
}
