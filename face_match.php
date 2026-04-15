<?php
/**
 * face_match.php — Query Double Take for the latest face match on a camera.
 * GET /HA_Jarvis/face_match.php?camera=Driveway
 * Returns: {"name":"Darren","confidence":95.2,"camera":"Driveway"} or {"name":"unknown"}
 */
header('Content-Type: application/json');

$camera = $_GET['camera'] ?? 'Driveway';
$dt_url = "http://100.72.30.111:3000/api/latest?camera=" . urlencode($camera);

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$resp = @file_get_contents($dt_url, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;

if ($data && !empty($data['match'])) {
    echo json_encode([
        'name' => $data['match']['name'] ?? 'unknown',
        'confidence' => round($data['match']['confidence'] ?? 0, 1),
        'camera' => $camera,
    ]);
} elseif ($data && !empty($data['results'])) {
    // Alternative response format
    $best = null;
    foreach ($data['results'] as $r) {
        if (!$best || ($r['confidence'] ?? 0) > ($best['confidence'] ?? 0)) {
            $best = $r;
        }
    }
    echo json_encode([
        'name' => $best['name'] ?? 'unknown',
        'confidence' => round($best['confidence'] ?? 0, 1),
        'camera' => $camera,
    ]);
} else {
    echo json_encode(['name' => 'no_face', 'confidence' => 0, 'camera' => $camera]);
}
