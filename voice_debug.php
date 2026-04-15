<?php
$log = date('Y-m-d H:i:s') . "\n"
  . "METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'cli') . "\n"
  . "CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'none') . "\n"
  . "GET: " . print_r($_GET, true)
  . "POST: " . print_r($_POST, true)
  . "BODY: " . file_get_contents('php://input') . "\n---\n";

file_put_contents('/var/www/html/HA_Jarvis/voice_debug.log', $log, FILE_APPEND);
echo "ok";
