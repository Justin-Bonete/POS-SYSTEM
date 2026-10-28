<?php
// tools/reset_database.php
// DANGEROUS: Truncates selected tables in the configured database.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!function_exists('isAdmin') || !isAdmin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$allowAnywhere = getenv('POS_ALLOW_DB_RESET_ANYWHERE');
$isLocal = ($remote === '127.0.0.1' || $remote === '::1');
if (!$isLocal && !($allowAnywhere !== false && $allowAnywhere !== '' && $allowAnywhere !== '0')) {
    http_response_code(403);
    echo 'Forbidden (local requests only)';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function new_token() {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return md5(uniqid('', true));
    }
}

$db = getDB();
if (!$db) {
    http_response_code(500);
    echo 'Database connection failed';
    exit;
}

// Fetch table list from current database connection.
$tables = [];
try {
    $stmt = $db->query('SHOW TABLES');
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $r) {
        if (!empty($r[0])) $tables[] = (string)$r[0];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to list tables';
    exit;
}

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    $expected = $_SESSION['db_reset_csrf'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !hash_equals((string)$expected, (string)$csrf)) {
        $message = 'Invalid security token. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $selected = $_POST['tables'] ?? [];
        if (!is_array($selected)) $selected = [];

        // Keep only valid known tables.
        $validSet = array_flip($tables);
        $selected = array_values(array_filter(array_map('strval', $selected), fn($t) => isset($validSet[$t])));

        $confirm = trim((string)($_POST['confirm'] ?? ''));
        $confirmUsers = trim((string)($_POST['confirm_users'] ?? ''));

        if (count($selected) === 0) {
            $message = 'Select at least one table.';
            $messageType = 'error';
        } elseif ($confirm !== 'DELETE ALL DATA') {
            $message = 'Type DELETE ALL DATA to confirm.';
            $messageType = 'error';
        } elseif (in_array('users', $selected, true) && $confirmUsers !== 'DELETE USERS') {
            $message = 'You selected users. Type DELETE USERS to confirm deleting user accounts.';
            $messageType = 'error';
        } else {
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($selected as $t) {
                    $db->exec('TRUNCATE TABLE `' . str_replace('`', '``', $t) . '`');
                }
                $db->exec('SET FOREIGN_KEY_CHECKS=1');
                $message = 'Truncated: ' . implode(', ', $selected);
                $messageType = 'success';
            } catch (Throwable $e) {
                try { $db->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_e) {}
                $message = 'Reset failed: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// New token per page load.
$_SESSION['db_reset_csrf'] = new_token();
$token = $_SESSION['db_reset_csrf'];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Reset (Danger)</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f6f7fb; margin:0; }
        .wrap { max-width: 900px; margin: 32px auto; padding: 0 16px; }
        .card { background:#fff; border-radius: 14px; padding: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.06); }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .danger { color:#b00020; font-weight: 700; }
        .note { color:#555; font-size: 13px; margin: 0 0 14px; }
        .msg { padding: 10px 12px; border-radius: 10px; margin: 10px 0 14px; font-size: 13px; }
        .msg.info { background:#eef3ff; color:#1b3a8a; }
        .msg.success { background:#e9f7ef; color:#1e7a3a; }
        .msg.error { background:#fdecee; color:#8a1b2b; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
        .tables { border:1px solid #eee; border-radius: 12px; padding: 12px; max-height: 360px; overflow:auto; }
        .row { display:flex; align-items:center; justify-content:space-between; gap: 10px; padding: 6px 2px; border-bottom: 1px solid #f3f3f3; }
        .row:last-child { border-bottom: 0; }
        .row label { display:flex; align-items:center; gap: 10px; cursor:pointer; }
        .row code { color:#333; }
        .controls { display:flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
        button { border: 1px solid #ddd; background:#fff; padding: 8px 10px; border-radius: 10px; cursor:pointer; }
        button.primary { background:#b00020; color:#fff; border-color:#b00020; }
        input[type="text"] { width:100%; padding: 10px; border-radius: 10px; border:1px solid #ddd; }
        .hint { font-size: 12px; color:#666; margin-top: 6px; }
        .section-title { font-weight: 700; margin: 8px 0; font-size: 13px; color:#222; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1><span class="danger">Danger:</span> Database Reset</h1>
        <p class="note">This will permanently delete data by truncating the selected tables. This page is admin-only and local-only by default.</p>

        <?php if ($message): ?>
            <div class="msg <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php else: ?>
            <div class="msg info">Tip: leave <code>users</code> unchecked unless you really want to delete login accounts.</div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">

            <div class="grid">
                <div>
                    <div class="section-title">Tables</div>
                    <div class="controls">
                        <button type="button" onclick="setAll(true)">Select all</button>
                        <button type="button" onclick="setAll(false)">Select none</button>
                        <button type="button" onclick="selectRecommended()">Select recommended</button>
                    </div>
                    <div class="tables">
                        <?php foreach ($tables as $t): ?>
                            <div class="row">
                                <label>
                                    <input class="tbl" type="checkbox" name="tables[]" value="<?= h($t) ?>" <?= $t === 'users' ? '' : 'checked' ?>>
                                    <code><?= h($t) ?></code>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hint">Recommended keeps <code>users</code> so you don’t lock yourself out.</div>
                </div>

                <div>
                    <div class="section-title">Confirmation</div>
                    <div class="hint">Type exactly: <code>DELETE ALL DATA</code></div>
                    <input type="text" name="confirm" placeholder="DELETE ALL DATA" required>

                    <div style="height:12px"></div>
                    <div class="hint">If you selected <code>users</code>, also type: <code>DELETE USERS</code></div>
                    <input type="text" name="confirm_users" placeholder="DELETE USERS (only if users selected)">

                    <div style="height:14px"></div>
                    <button class="primary" type="submit">Truncate selected tables</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function setAll(checked) {
  document.querySelectorAll('.tbl').forEach(cb => cb.checked = checked);
}
function selectRecommended() {
  document.querySelectorAll('.tbl').forEach(cb => {
    cb.checked = cb.value !== 'users';
  });
}
</script>
</body>
</html>

