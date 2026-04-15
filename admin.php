<?php
require_once __DIR__ . '/db.php';

require_once '/var/www/html/Legion_SSO/sso_check.php';
$authed = true; // sso_check.php already exited if not authenticated

// ── AJAX / POST actions (require auth) ───────────────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $db  = get_db();
    $act = $_POST['act'] ?? '';

    switch ($act) {

        case 'kill_switch':
            $val = $_POST['value'] === '1' ? '1' : '0';
            set_setting('kill_switch', $val);
            echo json_encode(['ok' => true, 'kill_switch' => $val]);
            exit;

        case 'save_ha':
            set_setting('ha_url',   trim($_POST['ha_url']));
            set_setting('ha_token', trim($_POST['ha_token']));
            echo json_encode(['ok' => true]);
            exit;

        case 'test_ha':
            $url   = get_setting('ha_url');
            $token = get_setting('ha_token');
            $ch    = curl_init("$url/api/");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_TIMEOUT        => 5,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $ok = ($code === 200);
            echo json_encode(['ok' => $ok, 'code' => $code, 'message' => $ok ? 'Connected' : 'Failed (HTTP '.$code.')']);
            exit;

        case 'add_key':
            $label = trim($_POST['label']);
            if (!$label) { echo json_encode(['ok'=>false,'message'=>'Label required']); exit; }
            $key = bin2hex(random_bytes(24));
            $db->prepare("INSERT INTO api_keys (label, api_key) VALUES (?,?)")->execute([$label, $key]);
            echo json_encode(['ok' => true, 'key' => $key, 'id' => $db->lastInsertId()]);
            exit;

        case 'toggle_key':
            $db->prepare("UPDATE api_keys SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")
               ->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
            exit;

        case 'delete_key':
            $db->prepare("DELETE FROM api_keys WHERE id=?")->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
            exit;

        case 'add_entity':
            $eid     = trim($_POST['entity_id']);
            $fname   = trim($_POST['friendly_name']);
            $actions = implode(',', array_filter(array_map('trim', explode(',', $_POST['allowed_actions']))));
            $notes   = trim($_POST['notes'] ?? '');
            if (!$eid) { echo json_encode(['ok'=>false,'message'=>'entity_id required']); exit; }
            try {
                $db->prepare("INSERT INTO entities (entity_id, friendly_name, allowed_actions, notes) VALUES (?,?,?,?)")
                   ->execute([$eid, $fname ?: $eid, $actions ?: 'get', $notes]);
                echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'message' => 'entity_id already exists']);
            }
            exit;

        case 'update_entity':
            $fname   = trim($_POST['friendly_name']);
            $actions = implode(',', array_filter(array_map('trim', explode(',', $_POST['allowed_actions']))));
            $notes   = trim($_POST['notes'] ?? '');
            $db->prepare("UPDATE entities SET friendly_name=?, allowed_actions=?, notes=? WHERE id=?")
               ->execute([$fname, $actions ?: 'get', $notes, $_POST['id']]);
            echo json_encode(['ok' => true]);
            exit;

        case 'toggle_entity':
            $db->prepare("UPDATE entities SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")
               ->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
            exit;

        case 'delete_entity':
            $db->prepare("DELETE FROM entities WHERE id=?")->execute([$_POST['id']]);
            echo json_encode(['ok' => true]);
            exit;

        case 'clear_audit':
            $db->exec("DELETE FROM audit_log");
            echo json_encode(['ok' => true]);
            exit;

        case 'fetch_ha_entities':
            $url   = get_setting('ha_url');
            $token = get_setting('ha_token');
            $ch    = curl_init("$url/api/states");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) { echo json_encode(['ok' => false, 'message' => "HA returned HTTP $code"]); exit; }
            $states = json_decode($res, true) ?? [];
            // Get already-whitelisted entity IDs
            $existing = $db->query("SELECT entity_id FROM entities")->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_flip($existing);
            $entities = [];
            foreach ($states as $s) {
                $entities[] = [
                    'entity_id'     => $s['entity_id'],
                    'friendly_name' => $s['attributes']['friendly_name'] ?? $s['entity_id'],
                    'domain'        => explode('.', $s['entity_id'])[0],
                    'state'         => $s['state'],
                    'whitelisted'   => isset($existing[$s['entity_id']]),
                ];
            }
            usort($entities, fn($a,$b) => strcmp($a['entity_id'], $b['entity_id']));
            echo json_encode(['ok' => true, 'entities' => $entities]);
            exit;

        case 'bulk_add_entities':
            $items   = json_decode($_POST['entities'], true) ?? [];
            $actions = trim($_POST['allowed_actions'] ?? 'get');
            $added   = 0; $skipped = 0;
            $stmt    = $db->prepare("INSERT IGNORE INTO entities (entity_id, friendly_name, allowed_actions) VALUES (?,?,?)");
            foreach ($items as $item) {
                $stmt->execute([$item['entity_id'], $item['friendly_name'], $actions]);
                $stmt->rowCount() ? $added++ : $skipped++;
            }
            echo json_encode(['ok' => true, 'added' => $added, 'skipped' => $skipped]);
            exit;
    }
}

// ── Load data for page ────────────────────────────────────────────────────────
$db          = get_db();
$kill_switch = get_setting('kill_switch') === '1';
$ha_url      = get_setting('ha_url');
$ha_token    = get_setting('ha_token');
$api_keys    = $db->query("SELECT * FROM api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$entities    = $db->query("SELECT * FROM entities ORDER BY entity_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$audit_logs  = $db->query("SELECT * FROM audit_log ORDER BY ts DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HA Jarvis — Admin Console</title>
<style>
  :root {
    --bg:       #f1f5f9;
    --surface:  #ffffff;
    --surface2: #f8fafc;
    --border:   #e2e8f0;
    --accent:   #2563eb;
    --green:    #16a34a;
    --red:      #dc2626;
    --yellow:   #d97706;
    --text:     #1e293b;
    --muted:    #64748b;
    --font:     'Segoe UI', system-ui, sans-serif;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 15px; }

  /* Login */
  .login-wrap { display:flex; align-items:center; justify-content:center; height:100vh; }
  .login-box { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 40px; width: 360px; }
  .login-box h1 { font-size: 22px; margin-bottom: 6px; }
  .login-box p  { color: var(--muted); margin-bottom: 24px; font-size: 13px; }
  .login-error  { color: var(--red); font-size: 13px; margin-bottom: 12px; }

  /* Layout */
  .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 14px 28px; display:flex; align-items:center; justify-content:space-between; }
  .header h1 { font-size: 20px; display:flex; align-items:center; gap:10px; }
  .header h1 span.dot { width:10px; height:10px; border-radius:50%; background:var(--green); display:inline-block; }
  .header h1 span.dot.red { background:var(--red); }
  .nav { display:flex; gap:4px; }
  .nav button { background:none; border:none; color:var(--muted); cursor:pointer; padding:6px 14px; border-radius:6px; font-size:14px; }
  .nav button.active, .nav button:hover { background:var(--surface2); color:var(--text); }
  .main { padding: 28px; max-width: 1200px; margin: 0 auto; }

  /* Kill Switch Banner */
  .kill-banner { background: #fee2e2; border: 1px solid var(--red); border-radius: 10px; padding: 14px 20px;
                 display:flex; align-items:center; justify-content:space-between; margin-bottom: 24px; }
  .kill-banner.off { background: #dcfce7; border-color: var(--green); }
  .kill-banner .info { display:flex; align-items:center; gap:12px; }
  .kill-banner .info svg { flex-shrink:0; }

  /* Cards */
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
  .card-title { font-size: 16px; font-weight: 600; margin-bottom: 16px; display:flex; align-items:center; gap:8px; }

  /* Forms */
  label { font-size: 13px; color: var(--muted); display:block; margin-bottom: 4px; }
  input[type=text], input[type=password], input[type=url], select, textarea {
    width:100%; background: var(--surface); border: 1px solid var(--border); color: var(--text);
    border-radius: 6px; padding: 8px 12px; font-size: 14px; outline:none;
  }
  input:focus, select:focus, textarea:focus { border-color: var(--accent); }
  .form-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:12px; }
  .form-actions { display:flex; gap:8px; margin-top:8px; }

  /* Buttons */
  .btn { padding: 8px 16px; border-radius: 6px; border: none; cursor:pointer; font-size:14px; font-weight:500; transition: opacity .15s; }
  .btn:hover { opacity: .85; }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-danger  { background: var(--red);   color: #fff; }
  .btn-success { background: var(--green); color: #fff; }
  .btn-ghost   { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-sm      { padding: 4px 10px; font-size: 12px; }
  .btn-kill    { background: var(--red);   color: #fff; padding: 10px 24px; font-size: 15px; font-weight: 600; border-radius: 8px; border: none; cursor:pointer; }
  .btn-kill.off { background: var(--green); }

  /* Tables */
  table { width:100%; border-collapse: collapse; font-size: 14px; }
  th { text-align:left; padding: 8px 12px; font-size: 12px; color: var(--muted); border-bottom: 1px solid var(--border); font-weight:500; text-transform:uppercase; letter-spacing:.05em; }
  td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: var(--surface2); }

  /* Badges */
  .badge { display:inline-block; padding: 2px 8px; border-radius:4px; font-size:12px; font-weight:500; }
  .badge-green  { background: #dcfce7; color: var(--green); }
  .badge-red    { background: #fee2e2; color: var(--red); }
  .badge-yellow { background: #fef3c7; color: var(--yellow); }
  .badge-blue   { background: #dbeafe; color: var(--accent); }

  /* Action chips */
  .action-chip { display:inline-block; padding:1px 7px; border-radius:4px; font-size:11px; margin:1px;
                 background: var(--surface2); border: 1px solid var(--border); }

  /* Domain filter chips */
  .domain-chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; cursor:pointer;
                 background: var(--surface2); border: 1px solid var(--border); color:var(--muted);
                 transition: all .15s; user-select:none; }
  .domain-chip.active { background: var(--accent); border-color: var(--accent); color: #fff; }

  /* Sortable headers */
  .sortable { cursor:pointer; user-select:none; white-space:nowrap; }
  .sortable:hover { color: var(--accent); }

  /* Tab sections */
  .tab-section { display:none; }
  .tab-section.active { display:block; }

  /* Audit log */
  .audit-ok     { color: var(--green); }
  .audit-denied { color: var(--red); }
  .audit-killed { color: var(--yellow); }

  /* Key reveal */
  .key-text { font-family: monospace; font-size: 12px; color: var(--muted); cursor:pointer; }
  .key-text:hover { color: var(--text); }

  /* Toast */
  #toast { position:fixed; bottom:24px; right:24px; background:var(--surface); border:1px solid var(--border);
           border-radius:8px; padding:12px 20px; font-size:14px; display:none; z-index:999; box-shadow:0 4px 12px rgba(0,0,0,.12); }
  #toast.show { display:block; animation: fadeIn .2s; }
  @keyframes fadeIn { from { opacity:0; transform:translateY(6px) } to { opacity:1; transform:none } }

  /* Modal */
  .modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:100; align-items:center; justify-content:center; }
  .modal-backdrop.open { display:flex; }
  .modal { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:24px; width:min(95vw,1100px); }
  .modal.expanded { width:98vw; max-width:98vw; max-height:96vh; }
  .import-table { width:100%; table-layout:fixed; border-collapse:collapse; }
  .import-table th { overflow:hidden; white-space:nowrap; position:relative; }
  .import-table th .resize-handle {
    position:absolute; right:0; top:0; bottom:0; width:5px; cursor:col-resize;
    background:transparent; user-select:none;
  }
  .import-table th .resize-handle:hover, .import-table th .resize-handle.dragging { background:var(--accent); opacity:.4; }
  .import-domain-row td { background:var(--surface2); font-weight:600; font-size:13px; padding:6px 10px; cursor:pointer; border-top:2px solid var(--border); user-select:none; }
  .import-domain-row td:hover { background:var(--border); }
  .import-domain-row .domain-toggle { font-size:11px; margin-right:6px; display:inline-block; width:12px; }
  .import-entity-row.hidden { display:none; }
  .modal h3 { margin-bottom:16px; }

  hr.divider { border:none; border-top:1px solid var(--border); margin:20px 0; }
  .action-checks { display:flex; flex-wrap:wrap; gap:6px 14px; }
  .action-checks label { display:flex; align-items:center; gap:5px; font-size:13px; color:var(--text); cursor:pointer; white-space:nowrap; }
  .action-checks input[type=checkbox] { width:auto; cursor:pointer; }
</style>
</head>
<body>

<!-- ── App ── -->
<div class="header">
  <h1>
    <span class="dot <?= $kill_switch ? 'red' : '' ?>"></span>
    HA Jarvis
    <span style="font-size:13px;color:var(--muted);font-weight:400">Admin Console</span>
  </h1>
  <div style="display:flex;align-items:center;gap:16px">
    <nav class="nav">
      <button class="nav-btn active" data-tab="overview">Overview</button>
      <button class="nav-btn" data-tab="entities">Entities</button>
      <button class="nav-btn" data-tab="keys">API Keys</button>
      <button class="nav-btn" data-tab="settings">Settings</button>
      <button class="nav-btn" data-tab="audit">Audit Log</button>
    </nav>
    <form method="POST" style="margin:0">
      <button class="btn btn-ghost btn-sm" name="logout" value="1">Logout</button>
    </form>
  </div>
</div>

<div class="main">

  <!-- Kill Switch Banner -->
  <div class="kill-banner <?= $kill_switch ? '' : 'off' ?>" id="killBanner">
    <div class="info">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".5" fill="currentColor"/>
      </svg>
      <div>
        <strong><?= $kill_switch ? 'KILL SWITCH ACTIVE — All OpenClaw access is BLOCKED' : 'Kill Switch OFF — API access is ENABLED' ?></strong>
        <div style="font-size:12px;opacity:.7;margin-top:2px">
          <?= $kill_switch ? 'No requests will reach Home Assistant until you disable the kill switch.' : 'OpenClaw agents can access whitelisted entities.' ?>
        </div>
      </div>
    </div>
    <button class="btn-kill <?= $kill_switch ? 'off' : '' ?>" id="killBtn" data-active="<?= $kill_switch ? '1' : '0' ?>" onclick="toggleKill()">
      <?= $kill_switch ? 'DISABLE Kill Switch' : 'ENGAGE Kill Switch' ?>
    </button>
  </div>

  <!-- ── Overview Tab ── -->
  <div class="tab-section active" id="tab-overview">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
      <?php
        $active_entities = array_filter($entities, fn($e) => $e['enabled']);
        $active_keys     = array_filter($api_keys, fn($k) => $k['enabled']);
        $denied_today    = $db->query("SELECT COUNT(*) FROM audit_log WHERE status='denied' AND date(ts)=date('now')")->fetchColumn();
        $calls_today     = $db->query("SELECT COUNT(*) FROM audit_log WHERE date(ts)=date('now')")->fetchColumn();
        $stats = [
          ['Whitelisted Entities', count($active_entities) . ' / ' . count($entities), 'var(--accent)'],
          ['Active API Keys',      count($active_keys) . ' / ' . count($api_keys),     'var(--green)'],
          ['Calls Today',          $calls_today,                                        'var(--yellow)'],
          ['Denied Today',         $denied_today,                                       'var(--red)'],
        ];
        foreach ($stats as [$label, $val, $color]):
      ?>
      <div class="card" style="margin:0">
        <div style="font-size:13px;color:var(--muted);margin-bottom:6px"><?= $label ?></div>
        <div style="font-size:28px;font-weight:700;color:<?= $color ?>"><?= $val ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-title">Recent Activity</div>
      <table>
        <thead><tr><th>Time</th><th>Key</th><th>Entity</th><th>Action</th><th>Result</th><th>Status</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($audit_logs, 0, 20) as $log): ?>
          <tr>
            <td style="white-space:nowrap;color:var(--muted);font-size:12px"><?= htmlspecialchars($log['ts']) ?></td>
            <td><?= htmlspecialchars($log['api_label'] ?? '—') ?></td>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($log['entity_id'] ?? '—') ?></td>
            <td><span class="action-chip"><?= htmlspecialchars($log['action'] ?? '—') ?></span></td>
            <td style="font-size:12px;color:var(--muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['result'] ?? '') ?></td>
            <td><span class="badge badge-<?= $log['status']==='ok'?'green':($log['status']==='denied'?'red':'yellow') ?>"><?= htmlspecialchars($log['status']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($log['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$audit_logs): ?><tr><td colspan="7" style="text-align:center;color:var(--muted)">No activity yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Entities Tab ── -->
  <div class="tab-section" id="tab-entities">
    <div class="card" style="margin-bottom:20px">
      <div class="card-title">Import from Home Assistant</div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Pull all current entities from HA and select which ones to whitelist.</p>
      <button class="btn btn-primary" onclick="openImportModal()">Load HA Entities</button>
    </div>

    <div class="card">
      <div class="card-title">Add Entity Manually</div>
      <div class="form-row">
        <div>
          <label>Entity ID</label>
          <input type="text" id="new_eid" placeholder="light.living_room">
        </div>
        <div>
          <label>Friendly Name</label>
          <input type="text" id="new_fname" placeholder="Living Room Light">
        </div>
        <div>
          <label>Allowed Actions</label>
          <div class="action-checks" id="new_actions">
            <label><input type="checkbox" value="get" checked> get</label>
            <label><input type="checkbox" value="call" checked> call</label>
            <label><input type="checkbox" value="set"> set</label>
            <label><input type="checkbox" value="snapshot"> snapshot</label>
            <label><input type="checkbox" value="delete"> delete</label>
          </div>
        </div>
        <div>
          <label>Notes</label>
          <input type="text" id="new_notes" placeholder="Optional notes">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" onclick="addEntity()">Add Entity</button>
      </div>
    </div>

    <div class="card">
      <div class="card-title" style="justify-content:space-between">
        <span>Whitelisted Entities (<span id="entityCount"><?= count($entities) ?></span>)</span>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" id="entitySearch" placeholder="Search..." style="width:180px;font-size:13px" oninput="renderEntityTable()">
          <button class="btn btn-ghost btn-sm" onclick="toggleAllDomains(true)">Expand All</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleAllDomains(false)">Collapse All</button>
        </div>
      </div>

      <!-- Domain filter chips -->
      <div id="domainFilters" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px"></div>

      <!-- Entity table -->
      <table id="entityTable" style="width:100%">
        <thead>
          <tr>
            <th class="sortable" data-col="domain"    onclick="sortEntity('domain')">Domain <span class="sort-icon" id="si-domain"></span></th>
            <th class="sortable" data-col="entity_id" onclick="sortEntity('entity_id')">Entity ID <span class="sort-icon" id="si-entity_id"></span></th>
            <th class="sortable" data-col="friendly_name" onclick="sortEntity('friendly_name')">Friendly Name <span class="sort-icon" id="si-friendly_name"></span></th>
            <th>Allowed Actions</th>
            <th class="sortable" data-col="notes" onclick="sortEntity('notes')">Notes <span class="sort-icon" id="si-notes"></span></th>
            <th class="sortable" data-col="enabled" onclick="sortEntity('enabled')">Status <span class="sort-icon" id="si-enabled"></span></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="entityTbody"></tbody>
      </table>
      <?php if (!$entities): ?><div style="text-align:center;color:var(--muted);padding:20px">No entities whitelisted yet</div><?php endif; ?>
    </div>
  </div>

  <!-- ── API Keys Tab ── -->
  <div class="tab-section" id="tab-keys">
    <div class="card">
      <div class="card-title">Create API Key</div>
      <div class="form-row">
        <div>
          <label>Label</label>
          <input type="text" id="new_key_label" placeholder="e.g. OpenClaw Agent #1">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" onclick="addKey()">Generate Key</button>
      </div>
    </div>

    <div class="card">
      <div class="card-title">API Keys (<?= count($api_keys) ?>)</div>
      <table id="keyTable">
        <thead><tr><th>Label</th><th>API Key</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($api_keys as $k): ?>
          <tr id="krow-<?= $k['id'] ?>">
            <td><?= htmlspecialchars($k['label']) ?></td>
            <td><span class="key-text" title="Click to reveal" onclick="this.textContent=this.dataset.key;this.style.color='var(--text)'" data-key="<?= htmlspecialchars($k['api_key']) ?>">••••••••••••••••••••••••</span></td>
            <td><span class="badge badge-<?= $k['enabled']?'green':'red' ?>"><?= $k['enabled']?'Active':'Disabled' ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($k['created_at']) ?></td>
            <td style="display:flex;gap:6px">
              <button class="btn btn-ghost btn-sm" onclick="toggleKey(<?= $k['id'] ?>)"><?= $k['enabled']?'Disable':'Enable' ?></button>
              <button class="btn btn-danger btn-sm" onclick="deleteKey(<?= $k['id'] ?>, '<?= htmlspecialchars($k['label']) ?>')">Revoke</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$api_keys): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">No API keys yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="card-title">API Usage</div>
      <div style="font-size:13px;color:var(--muted);line-height:1.8">
        <strong style="color:var(--text)">Header:</strong> <code>X-API-Key: &lt;your_key&gt;</code><br>
        <strong style="color:var(--text)">Get state:</strong> <code>GET /api.php?action=get&entity_id=light.living_room&api_key=&lt;key&gt;</code><br>
        <strong style="color:var(--text)">Call service:</strong> <code>POST /api.php</code> body: <code>{"action":"call","entity_id":"light.living_room","service":"turn_on","data":{},"api_key":"&lt;key&gt;"}</code><br>
        <strong style="color:var(--text)">List entities:</strong> <code>GET /api.php?action=list&api_key=&lt;key&gt;</code>
      </div>
    </div>
  </div>

  <!-- ── Settings Tab ── -->
  <div class="tab-section" id="tab-settings">
    <div class="card">
      <div class="card-title">Home Assistant Connection</div>
      <div class="form-row">
        <div>
          <label>HA URL</label>
          <input type="text" id="ha_url" value="<?= htmlspecialchars($ha_url) ?>" placeholder="http://homeassistant:8123">
        </div>
        <div>
          <label>Long-Lived Access Token</label>
          <input type="text" id="ha_token" value="<?= htmlspecialchars($ha_token) ?>" placeholder="eyJ...">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" onclick="saveHA()">Save</button>
        <button class="btn btn-ghost" onclick="testHA()">Test Connection</button>
      </div>
      <div id="ha_test_result" style="margin-top:12px;font-size:13px;display:none"></div>
    </div>

    <div class="card">
      <div class="card-title">Admin Password</div>
      <p style="font-size:13px;color:var(--muted)">Edit <code>config.php</code> and change <code>ADMIN_PASSWORD</code> to update the password.</p>
    </div>

    <div class="card">
      <div class="card-title">Database</div>
      <p style="font-size:13px;color:var(--muted);margin-bottom:12px">MySQL database: <code><?= DB_NAME ?>@<?= DB_HOST ?></code></p>
      <button class="btn btn-danger" onclick="if(confirm('Clear all audit logs?')) clearAudit()">Clear Audit Log</button>
    </div>
  </div>

  <!-- ── Audit Log Tab ── -->
  <div class="tab-section" id="tab-audit">
    <div class="card">
      <div class="card-title" style="justify-content:space-between">
        <span>Audit Log (last 200)</span>
        <button class="btn btn-danger btn-sm" onclick="if(confirm('Clear all audit logs?')) clearAudit()">Clear All</button>
      </div>
      <table>
        <thead><tr><th>Time</th><th>Key</th><th>Entity</th><th>Action</th><th>Payload</th><th>Result</th><th>Status</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($audit_logs as $log): ?>
          <tr>
            <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= htmlspecialchars($log['ts']) ?></td>
            <td style="font-size:13px"><?= htmlspecialchars($log['api_label'] ?? '—') ?></td>
            <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($log['entity_id'] ?? '—') ?></td>
            <td><span class="action-chip"><?= htmlspecialchars($log['action'] ?? '—') ?></span></td>
            <td style="font-size:11px;color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['payload'] ?? '') ?></td>
            <td style="font-size:12px;color:var(--muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['result'] ?? '') ?></td>
            <td><span class="badge badge-<?= $log['status']==='ok'?'green':($log['status']==='denied'?'red':'yellow') ?>"><?= htmlspecialchars($log['status']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($log['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$audit_logs): ?><tr><td colspan="8" style="text-align:center;color:var(--muted)">No logs yet</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main -->

<!-- Import from HA Modal -->
<div class="modal-backdrop" id="importModal">
  <div class="modal" style="max-height:85vh;display:flex;flex-direction:column">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h3>Import HA Entities</h3>
      <div style="display:flex;gap:6px">
        <button class="btn btn-ghost btn-sm" id="importExpandBtn" onclick="toggleImportExpand()" title="Expand / Collapse">⤢</button>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('importModal').classList.remove('open')">Close</button>
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
      <input type="text" id="importSearch" placeholder="Search entity..." style="flex:1;min-width:160px" oninput="filterImport()">
      <select id="importDomain" onchange="filterImport()" style="width:160px">
        <option value="">All domains</option>
      </select>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text);white-space:nowrap">
        <input type="checkbox" id="hideWhitelisted" onchange="filterImport()"> Hide already added
      </label>
    </div>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;flex-wrap:wrap">
      <span id="importCount" style="color:var(--muted)"></span>
      <button class="btn btn-ghost btn-sm" onclick="selectAllImport(true)">Select All</button>
      <button class="btn btn-ghost btn-sm" onclick="selectAllImport(false)">Deselect All</button>
      <button class="btn btn-ghost btn-sm" onclick="expandAllDomains(true)">Expand All</button>
      <button class="btn btn-ghost btn-sm" onclick="expandAllDomains(false)">Collapse All</button>
      <span style="margin-left:auto;color:var(--muted)">Default actions:</span>
      <div class="action-checks" id="importActions">
        <label><input type="checkbox" value="get" checked> get</label>
        <label><input type="checkbox" value="call" checked> call</label>
        <label><input type="checkbox" value="set"> set</label>
        <label><input type="checkbox" value="snapshot"> snapshot</label>
        <label><input type="checkbox" value="delete"> delete</label>
      </div>
    </div>

    <div style="overflow-y:auto;flex:1;border:1px solid var(--border);border-radius:6px" id="importList">
      <table class="import-table" id="importTable">
        <colgroup>
          <col style="width:40px">
          <col style="width:280px">
          <col style="width:220px">
          <col style="width:110px">
          <col style="width:90px">
          <col style="width:100px">
        </colgroup>
        <thead style="position:sticky;top:0;background:var(--surface);z-index:1">
          <tr>
            <th style="width:40px"></th>
            <th>Entity ID<span class="resize-handle"></span></th>
            <th>Friendly Name<span class="resize-handle"></span></th>
            <th>Domain<span class="resize-handle"></span></th>
            <th>State<span class="resize-handle"></span></th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="importTbody"></tbody>
      </table>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
      <span id="importSelectedCount" style="align-self:center;font-size:13px;color:var(--muted)"></span>
      <button class="btn btn-primary" onclick="bulkImport()">Add Selected to Whitelist</button>
    </div>
  </div>
</div>

<!-- Edit Entity Modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <h3>Edit Entity</h3>
    <input type="hidden" id="edit_id">
    <div style="margin-bottom:12px">
      <label>Entity ID</label>
      <input type="text" id="edit_eid" readonly style="opacity:.5">
    </div>
    <div style="margin-bottom:12px">
      <label>Friendly Name</label>
      <input type="text" id="edit_fname">
    </div>
    <div style="margin-bottom:12px">
      <label>Allowed Actions</label>
      <div class="action-checks" id="edit_actions">
        <label><input type="checkbox" value="get"> get</label>
        <label><input type="checkbox" value="call"> call</label>
        <label><input type="checkbox" value="set"> set</label>
        <label><input type="checkbox" value="snapshot"> snapshot</label>
        <label><input type="checkbox" value="delete"> delete</label>
      </div>
    </div>
    <div style="margin-bottom:16px">
      <label>Notes</label>
      <input type="text" id="edit_notes">
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveEntity()">Save</button>
    </div>
  </div>
</div>

<!-- New Key Modal -->
<div class="modal-backdrop" id="newKeyModal">
  <div class="modal">
    <h3>API Key Created</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Copy this key now — it will not be shown again in full.</p>
    <div style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:12px;font-family:monospace;font-size:13px;word-break:break-all;margin-bottom:16px" id="newKeyDisplay"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button class="btn btn-ghost" onclick="copyKey()">Copy</button>
      <button class="btn btn-primary" onclick="document.getElementById('newKeyModal').classList.remove('open');location.reload()">Done</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ── Entity table data ────────────────────────────────────────────────────────
const _allEntities = <?= json_encode(array_map(function($e) {
  $parts = explode('.', $e['entity_id'], 2);
  $e['domain'] = $parts[0];
  return $e;
}, $entities)) ?>;

let _entitySortCol = 'domain';
let _entitySortAsc = true;
let _activeDomains = new Set(); // empty = show all

function initEntityTable() {
  // Build domain filter chips
  const domains = [...new Set(_allEntities.map(e => e.domain))].sort();
  const filtersDiv = document.getElementById('domainFilters');
  if (!filtersDiv) return;
  filtersDiv.innerHTML = domains.map(d =>
    `<span class="domain-chip active" data-domain="${d}" onclick="toggleDomainChip(this,'${d}')">${d}</span>`
  ).join('');
  renderEntityTable();
}

function toggleDomainChip(el, domain) {
  if (_activeDomains.size === 0) {
    // all active → switch to exclusive mode with only this one OFF
    const domains = [...new Set(_allEntities.map(e => e.domain))];
    domains.forEach(d => { if (d !== domain) _activeDomains.add(d); });
  } else if (_activeDomains.has(domain)) {
    _activeDomains.delete(domain);
    if (_activeDomains.size === 0) _activeDomains = new Set(); // back to all
  } else {
    _activeDomains.add(domain);
  }
  document.querySelectorAll('.domain-chip').forEach(chip => {
    const d = chip.dataset.domain;
    const on = _activeDomains.size === 0 || _activeDomains.has(d);
    chip.classList.toggle('active', on);
  });
  renderEntityTable();
}

function toggleAllDomains(open) {
  document.querySelectorAll('.domain-section').forEach(sec => {
    sec.style.display = open ? '' : 'none';
    const hdr = document.querySelector(`.domain-section-header[data-domain="${sec.dataset.domain}"]`);
    if (hdr) hdr.classList.toggle('collapsed', !open);
  });
}

function sortEntity(col) {
  if (_entitySortCol === col) { _entitySortAsc = !_entitySortAsc; }
  else { _entitySortCol = col; _entitySortAsc = true; }
  document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '');
  const icon = document.getElementById('si-' + col);
  if (icon) icon.textContent = _entitySortAsc ? ' ▲' : ' ▼';
  renderEntityTable();
}

function renderEntityTable() {
  const tbody   = document.getElementById('entityTbody');
  const search  = (document.getElementById('entitySearch')?.value || '').toLowerCase();
  if (!tbody) return;

  let rows = _allEntities.filter(e => {
    const domainOk = _activeDomains.size === 0 || _activeDomains.has(e.domain);
    const searchOk = !search ||
      e.entity_id.toLowerCase().includes(search) ||
      (e.friendly_name||'').toLowerCase().includes(search) ||
      (e.notes||'').toLowerCase().includes(search);
    return domainOk && searchOk;
  });

  rows.sort((a, b) => {
    const av = (a[_entitySortCol] ?? '').toString().toLowerCase();
    const bv = (b[_entitySortCol] ?? '').toString().toLowerCase();
    return _entitySortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
  });

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">No entities match</td></tr>';
    return;
  }

  // Group by domain
  const groups = {};
  rows.forEach(e => { (groups[e.domain] = groups[e.domain] || []).push(e); });

  let html = '';
  Object.keys(groups).sort().forEach(domain => {
    const items = groups[domain];
    html += `<tr class="domain-section-header" data-domain="${domain}" onclick="toggleDomainSection('${domain}')" style="cursor:pointer;background:var(--surface2)">
      <td colspan="7" style="padding:6px 12px;font-weight:600;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">
        <span class="domain-toggle">▼</span> ${domain} <span style="font-weight:400">(${items.length})</span>
      </td>
    </tr>`;
    items.forEach(e => {
      const actions = (e.allowed_actions||'').split(',').map(a=>a.trim()).filter(Boolean)
        .map(a=>`<span class="action-chip">${a}</span>`).join(' ');
      const badge = e.enabled == 1
        ? '<span class="badge badge-green">Active</span>'
        : '<span class="badge" style="background:var(--surface2);color:var(--muted)">Disabled</span>';
      html += `<tr class="domain-section" data-domain="${domain}">
        <td style="font-family:monospace;font-size:11px;color:var(--muted)">${e.domain}</td>
        <td style="font-family:monospace;font-size:12px">${e.entity_id}</td>
        <td style="font-size:13px">${e.friendly_name||''}</td>
        <td>${actions}</td>
        <td style="font-size:12px;color:var(--muted)">${e.notes||''}</td>
        <td>${badge}</td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick='editEntity(${JSON.stringify(e)})'>Edit</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleEntityStatus(${e.id},${e.enabled})">${e.enabled==1?'Disable':'Enable'}</button>
          <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="deleteEntity(${e.id},'${e.entity_id}')">Del</button>
        </td>
      </tr>`;
    });
  });
  tbody.innerHTML = html;
  document.getElementById('entityCount').textContent = rows.length;
}

function toggleDomainSection(domain) {
  const rows = document.querySelectorAll(`.domain-section[data-domain="${domain}"]`);
  const hdr  = document.querySelector(`.domain-section-header[data-domain="${domain}"]`);
  const open = rows[0]?.style.display !== 'none';
  rows.forEach(r => r.style.display = open ? 'none' : '');
  if (hdr) {
    const arrow = hdr.querySelector('.domain-toggle');
    if (arrow) arrow.textContent = open ? '▶' : '▼';
  }
}

// Tab navigation
document.querySelectorAll('.nav-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});

function toast(msg, ok=true) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.borderColor = ok ? 'var(--green)' : 'var(--red)';
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

async function post(data) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) fd.append(k, v);
  const r = await fetch('admin.php', {method:'POST', body:fd});
  return r.json();
}

// Kill switch — read current state from PHP-rendered attribute
async function toggleKill() {
  const btn      = document.getElementById('killBtn');
  const isActive = btn.dataset.active === '1';
  const r = await post({act:'kill_switch', value: isActive ? '0' : '1'});
  if (r.ok) location.reload();
}

// HA Settings
async function saveHA() {
  const r = await post({act:'save_ha', ha_url:document.getElementById('ha_url').value, ha_token:document.getElementById('ha_token').value});
  toast(r.ok ? 'Saved' : 'Error');
}
async function testHA() {
  const el = document.getElementById('ha_test_result');
  el.style.display='block'; el.textContent='Testing...'; el.style.color='var(--muted)';
  const r = await post({act:'test_ha'});
  el.textContent = r.message;
  el.style.color = r.ok ? 'var(--green)' : 'var(--red)';
}

// Action checkbox helpers
function getCheckedActions(groupId) {
  return [...document.querySelectorAll(`#${groupId} input[type=checkbox]:checked`)].map(cb => cb.value).join(',');
}
function setCheckedActions(groupId, actionsStr) {
  const active = (actionsStr || '').split(/[,;\s]+/).map(s => s.trim()).filter(Boolean);
  document.querySelectorAll(`#${groupId} input[type=checkbox]`).forEach(cb => {
    cb.checked = active.includes(cb.value);
  });
}

// Entities
async function addEntity() {
  const r = await post({act:'add_entity',entity_id:document.getElementById('new_eid').value,friendly_name:document.getElementById('new_fname').value,allowed_actions:getCheckedActions('new_actions'),notes:document.getElementById('new_notes').value});
  r.ok ? (toast('Entity added'), location.reload()) : toast(r.message||'Error', false);
}
function editEntity(e) {
  document.getElementById('edit_id').value    = e.id;
  document.getElementById('edit_eid').value   = e.entity_id;
  document.getElementById('edit_fname').value = e.friendly_name;
  document.getElementById('edit_notes').value = e.notes;
  setCheckedActions('edit_actions', e.allowed_actions);
  document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
async function saveEntity() {
  const r = await post({act:'update_entity',id:document.getElementById('edit_id').value,friendly_name:document.getElementById('edit_fname').value,allowed_actions:getCheckedActions('edit_actions'),notes:document.getElementById('edit_notes').value});
  r.ok ? (toast('Saved'), location.reload()) : toast('Error', false);
}
async function toggleEntity(id) {
  const r = await post({act:'toggle_entity',id});
  r.ok ? location.reload() : toast('Error', false);
}
function toggleEntityStatus(id) { toggleEntity(id); }
async function deleteEntity(id, name) {
  if (!confirm(`Remove ${name} from whitelist?`)) return;
  const r = await post({act:'delete_entity',id});
  r.ok ? (toast('Removed'), location.reload()) : toast('Error', false);
}

// API Keys
let _newKey = '';
async function addKey() {
  const label = document.getElementById('new_key_label').value;
  if (!label) { toast('Enter a label', false); return; }
  const r = await post({act:'add_key',label});
  if (r.ok) {
    _newKey = r.key;
    document.getElementById('newKeyDisplay').textContent = r.key;
    document.getElementById('newKeyModal').classList.add('open');
  } else { toast(r.message||'Error', false); }
}
function copyKey() {
  navigator.clipboard.writeText(_newKey).then(() => toast('Copied!'));
}
async function toggleKey(id) {
  const r = await post({act:'toggle_key',id});
  r.ok ? location.reload() : toast('Error', false);
}
async function deleteKey(id, label) {
  if (!confirm(`Revoke key "${label}"?`)) return;
  const r = await post({act:'delete_key',id});
  r.ok ? (toast('Revoked'), location.reload()) : toast('Error', false);
}

// ── Import modal expand/collapse ─────────────────────────────────────────────
function toggleImportExpand() {
  const modal = document.querySelector('#importModal .modal');
  const btn   = document.getElementById('importExpandBtn');
  const expanded = modal.classList.toggle('expanded');
  btn.textContent = expanded ? '⤡' : '⤢';
}

// ── Column resize for import table ────────────────────────────────────────────
(function() {
  let dragging = null, startX = 0, startW = 0, colEl = null;

  document.addEventListener('mousedown', e => {
    if (!e.target.classList.contains('resize-handle')) return;
    e.preventDefault();
    dragging    = e.target;
    dragging.classList.add('dragging');
    startX      = e.clientX;
    const th    = dragging.closest('th');
    const table = th.closest('table');
    const idx   = [...th.parentElement.children].indexOf(th);
    colEl       = table.querySelectorAll('col')[idx];
    startW      = th.offsetWidth;
  });

  document.addEventListener('mousemove', e => {
    if (!dragging) return;
    const newW = Math.max(60, startW + (e.clientX - startX));
    colEl.style.width = newW + 'px';
  });

  document.addEventListener('mouseup', () => {
    if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
  });
})();

// ── Import from HA ───────────────────────────────────────────────────────────
let _haEntities = [];

async function openImportModal() {
  document.getElementById('importTbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">Loading...</td></tr>';
  document.getElementById('importModal').classList.add('open');

  const r = await post({act: 'fetch_ha_entities'});
  if (!r.ok) { toast(r.message || 'Failed to load', false); return; }

  _haEntities = r.entities;
  _collapsedDomains.clear();

  // Populate domain filter
  const domains = [...new Set(r.entities.map(e => e.domain))].sort();
  const domSel  = document.getElementById('importDomain');
  domSel.innerHTML = '<option value="">All domains</option>';
  domains.forEach(d => { const o = document.createElement('option'); o.value = d; o.textContent = d; domSel.appendChild(o); });

  renderImport(_haEntities);
}

function filterImport() {
  const search      = document.getElementById('importSearch').value.toLowerCase();
  const domain      = document.getElementById('importDomain').value;
  const hideAdded   = document.getElementById('hideWhitelisted').checked;
  const filtered    = _haEntities.filter(e =>
    (!search || e.entity_id.includes(search) || e.friendly_name.toLowerCase().includes(search)) &&
    (!domain || e.domain === domain) &&
    (!hideAdded || !e.whitelisted)
  );
  // When searching/filtering, expand everything so results are visible
  if (search || domain || hideAdded) _collapsedDomains.clear();
  renderImport(filtered);
}

// Track which domains are collapsed (collapsed by default when >10 entities in domain)
const _collapsedDomains = new Set();

function renderImport(list) {
  const tbody = document.getElementById('importTbody');
  document.getElementById('importCount').textContent = list.length + ' entities';
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">No entities found</td></tr>';
    updateImportCount();
    return;
  }

  // Group by domain
  const groups = {};
  list.forEach(e => { (groups[e.domain] = groups[e.domain] || []).push(e); });
  const domains = Object.keys(groups).sort();

  // Auto-collapse large domains on first render (only if not already tracking them)
  if (_collapsedDomains.size === 0) {
    domains.forEach(d => { if (groups[d].length > 8) _collapsedDomains.add(d); });
  }

  let html = '';
  domains.forEach(d => {
    const items    = groups[d];
    const total    = items.length;
    const added    = items.filter(e => e.whitelisted).length;
    const collapsed = _collapsedDomains.has(d);
    const arrow    = collapsed ? '▶' : '▼';
    html += `<tr class="import-domain-row" data-domain="${d}" onclick="toggleDomain('${d}')">
      <td colspan="6">
        <span class="domain-toggle">${arrow}</span>
        <strong>${d}</strong>
        <span style="font-weight:400;color:var(--muted);margin-left:8px">${total} entities${added ? ` · ${added} already added` : ''}</span>
        <button class="btn btn-ghost btn-sm" style="margin-left:12px;font-size:11px" onclick="event.stopPropagation();selectDomain('${d}',true)">Select all</button>
        <button class="btn btn-ghost btn-sm" style="margin-left:4px;font-size:11px" onclick="event.stopPropagation();selectDomain('${d}',false)">None</button>
      </td>
    </tr>`;
    items.forEach(e => {
      html += `<tr class="import-entity-row${collapsed ? ' hidden' : ''}" data-domain="${d}">
        <td style="text-align:center"><input type="checkbox" class="import-cb" data-id="${e.entity_id}" data-name="${e.friendly_name.replace(/"/g,'&quot;')}" ${e.whitelisted ? 'disabled checked' : ''} onchange="updateImportCount()"></td>
        <td style="font-family:monospace;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${e.entity_id}">${e.entity_id}</td>
        <td style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${e.friendly_name}">${e.friendly_name}</td>
        <td style="overflow:hidden"><span class="action-chip">${e.domain}</span></td>
        <td style="font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${e.state}</td>
        <td>${e.whitelisted ? '<span class="badge badge-green">Added</span>' : '<span class="badge" style="background:var(--surface2);color:var(--muted)">New</span>'}</td>
      </tr>`;
    });
  });

  tbody.innerHTML = html;
  updateImportCount();
}

function toggleDomain(domain) {
  const collapsed = _collapsedDomains.has(domain);
  if (collapsed) _collapsedDomains.delete(domain); else _collapsedDomains.add(domain);
  const rows  = document.querySelectorAll(`.import-entity-row[data-domain="${domain}"]`);
  const hdr   = document.querySelector(`.import-domain-row[data-domain="${domain}"] .domain-toggle`);
  rows.forEach(r => r.classList.toggle('hidden', !collapsed));
  if (hdr) hdr.textContent = collapsed ? '▼' : '▶';
}

function expandAllDomains(expand) {
  document.querySelectorAll('.import-domain-row').forEach(hdr => {
    const d = hdr.dataset.domain;
    if (expand) _collapsedDomains.delete(d); else _collapsedDomains.add(d);
    document.querySelectorAll(`.import-entity-row[data-domain="${d}"]`).forEach(r => r.classList.toggle('hidden', !expand));
    const arrow = hdr.querySelector('.domain-toggle');
    if (arrow) arrow.textContent = expand ? '▼' : '▶';
  });
}

function selectDomain(domain, checked) {
  document.querySelectorAll(`.import-entity-row[data-domain="${domain}"] .import-cb:not(:disabled)`)
    .forEach(cb => cb.checked = checked);
  updateImportCount();
}

function selectAllImport(checked) {
  document.querySelectorAll('.import-cb:not(:disabled)').forEach(cb => cb.checked = checked);
  updateImportCount();
}

function updateImportCount() {
  const n = document.querySelectorAll('.import-cb:checked:not(:disabled)').length;
  document.getElementById('importSelectedCount').textContent = n + ' selected';
}

async function bulkImport() {
  const selected = [...document.querySelectorAll('.import-cb:checked')].map(cb => ({
    entity_id: cb.dataset.id, friendly_name: cb.dataset.name
  }));
  if (!selected.length) { toast('Nothing selected', false); return; }
  const actions = getCheckedActions('importActions') || 'get';
  const r = await post({act: 'bulk_add_entities', entities: JSON.stringify(selected), allowed_actions: actions});
  if (r.ok) {
    toast(`Added ${r.added}, skipped ${r.skipped} already existing`);
    document.getElementById('importModal').classList.remove('open');
    location.reload();
  } else { toast('Error importing', false); }
}

// Init
document.addEventListener('DOMContentLoaded', initEntityTable);

// Audit
async function clearAudit() {
  const r = await post({act:'clear_audit'});
  r.ok ? (toast('Cleared'), location.reload()) : toast('Error', false);
}
</script>

</body>
</html>
