<?php
/**
 * Flames Connect Webhook
 * Called by HA REST commands to control the fireplace.
 *
 * GET/POST /flames/webhook.php?cmd=on|off|status&token=<webhook_token>
 */

define('WEBHOOK_TOKEN', 'flames_webhook_2026_friday');
define('PYTHON',        '/usr/bin/python3');
define('SCRIPT',        __DIR__ . '/flameconnect.py');
define('FIRE_ID',       '0701201A006E');

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
if ($token !== WEBHOOK_TOKEN) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Command ───────────────────────────────────────────────────────────────────
$cmd = strtolower(trim($_GET['cmd'] ?? $_POST['cmd'] ?? ''));
if (!in_array($cmd, ['on', 'off', 'status'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'cmd must be on, off, or status']);
    exit;
}

// ── Run script ────────────────────────────────────────────────────────────────
$output    = [];
$exit_code = 0;
exec(escapeshellcmd(PYTHON . ' ' . SCRIPT . ' ' . escapeshellarg($cmd)) . ' 2>&1', $output, $exit_code);

$output_str = implode("\n", $output);

if ($exit_code !== 0) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => 'Script error', 'output' => $output_str]);
    exit;
}

echo json_encode(['ok' => true, 'cmd' => $cmd, 'output' => $output_str]);
