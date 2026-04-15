<?php
// Legion Vault
define('VAULT_URL',   'http://100.69.36.45:8800');
define('VAULT_TOKEN', '184e36fd5df9423621dd3179a0a93d59dba3e168512aa1950f70310d688f21b1');

// Home Assistant
define('HA_URL',   'http://homeassistant:8123');
define('HA_TOKEN', _vault_secret('openclaw_to_ha'));

// MySQL database
define('DB_HOST', 'localhost');
define('DB_NAME', 'jarvis_ha');
define('DB_USER', 'admin');
define('DB_PASS', _vault_secret('mysql_admin_password'));

// ── Vault helper ─────────────────────────────────────────────────────────────
function _vault_secret(string $name): string {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];

    $ch = curl_init(VAULT_URL . '/api/secrets/' . urlencode($name));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . VAULT_TOKEN],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        error_log("HA Jarvis: vault secret '$name' fetch failed (HTTP $code)");
        return '';
    }
    $data = json_decode($body, true);
    $cache[$name] = $data['value'] ?? '';
    return $cache[$name];
}
