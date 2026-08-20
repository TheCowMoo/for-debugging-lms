<?php
/**
 * PURSUIT PATHWAYS LMS — SCORM Schema Migration Runner
 *
 * Applies pending versioned schema migrations in filename order and records
 * them in `schema_migrations` so each runs exactly once.
 *
 * Usage:
 *   CLI:      php migrations/run.php
 *   Web:      GET /migrations/run.php?token=<migration-run-token>
 *             (token = hash_hmac('sha256', 'migrations:' . APP_CSRF_SECRET, APP_CSRF_SECRET))
 *   Web (UI): POST with csrf_token + logged-in admin/super-admin session
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../bootstrap.php';

$isCli = PHP_SAPI === 'cli';

// ── Authorisation ──
if (!$isCli) {
    $allowed = false;
    // Option A: HMAC run token (non-interactive / scheduled)
    $token = trim((string)($_GET['token'] ?? ($_REQUEST['token'] ?? '')));
    $expected = hash_hmac('sha256', 'migrations:' . APP_CSRF_SECRET, APP_CSRF_SECRET);
    if ($token !== '' && hash_equals($expected, $token)) {
        $allowed = true;
    }
    // Option B: logged-in admin + CSRF token (browser)
    if (!$allowed) {
        requireLogin();
        requireAdmin();
        if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $allowed = true;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
        exit;
    }
    header('Content-Type: application/json');
}

set_time_limit(0);

$pdo = getDbConnection();

// ── Record table ──
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        result VARCHAR(20) DEFAULT 'applied'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$applied = [];
foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
    $applied[$row['version']] = true;
}

$files = glob(__DIR__ . '/[0-9]*_*.php');
sort($files, SORT_STRING);

// Serialise concurrent runners so the same migration is never applied twice.
$lockOk = (bool)$pdo->query("SELECT GET_LOCK('pp_scorm_migrations', 10)")->fetchColumn();
if (!$lockOk) {
    $msg = 'Could not acquire migration lock (another runner is active).';
    error_log('[MIGRATE] ' . $msg);
    if ($isCli) { fwrite(STDERR, $msg . "\n"); exit(1); }
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$results = [];
try {
    foreach ($files as $file) {
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        try {
            $up = require $file;
            if (!is_callable($up)) {
                throw new RuntimeException("Migration $version does not return a callable.");
            }
            $up($pdo);
            $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, result) VALUES (?, 'applied')");
            $stmt->execute([$version]);
            $results[] = ['version' => $version, 'status' => 'applied'];
            error_log("[MIGRATE] applied $version");
        } catch (Throwable $e) {
            $results[] = ['version' => $version, 'status' => 'failed', 'error' => $e->getMessage()];
            error_log('[MIGRATE] ' . $version . ' failed: ' . $e->getMessage());
            break; // stop at first failure — retry after fixing
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('pp_scorm_migrations')");
}

if ($isCli) {
    if (empty($results)) {
        echo "Nothing to migrate — all versions applied.\n";
        exit(0);
    }
    foreach ($results as $r) {
        echo sprintf("%s  %-30s  %s\n", strtoupper($r['status']), $r['version'], $r['error'] ?? '');
    }
    foreach ($results as $r) {
        if ($r['status'] === 'failed') { exit(1); }
    }
    exit(0);
}

echo json_encode(['ok' => true, 'results' => $results]);
