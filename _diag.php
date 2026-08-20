<?php
/**
 * DIAGNOSTIC SCRIPT — TEMPORARY. DELETE AFTER USE.
 * Run: https://edu.pursuitpathways.com/_diag.php
 * Safe: does not modify any data, no auth required, plain text output.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin();

header('Content-Type: text/plain');
echo "=== PP LMS DIAGNOSTICS ===\n\n";

echo "PHP Version: " . PHP_VERSION . " (PHP_VERSION_ID=" . PHP_VERSION_ID . ")\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max execution: " . ini_get('max_execution_time') . "s\n\n";

echo "--- Extensions ---\n";
$required = ['pdo', 'pdo_mysql', 'curl', 'json', 'session', 'openssl'];
foreach ($required as $ext) {
    echo (extension_loaded($ext) ? "[OK] " : "[MISSING] ") . $ext . "\n";
}

echo "\n--- Errors in last request would appear below ---\n";
$err = error_get_last();
if ($err) {
    echo "Last error: " . json_encode($err) . "\n";
} else {
    echo "No stored PHP error in this request.\n";
}

echo "\n--- Testing session_set_cookie_params (array form) ---\n";
try {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        echo "[OK] Array form works (PHP " . PHP_VERSION . ")\n";
    } else {
        // Legacy form
        session_set_cookie_params(0, '/', '', false, true);
        echo "[OK] Legacy form works (PHP " . PHP_VERSION . ")\n";
    }
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
}

echo "\n--- DB connectivity ---\n";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
if (count($parts) === 2 && in_array(trim($parts[0]), ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'S3_BUCKET', 'S3_REGION', 'S3_KEY', 'S3_SECRET', 'S3_ENDPOINT', 'S3_DEBUG'])) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}
$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
echo "DB_HOST=$host DB_NAME=$name DB_USER=$user DB_PASS=" . (strlen($pass) > 0 ? str_repeat('*', strlen($pass)) : '(empty)') . "\n";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "[OK] DB connection works\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables (" . count($tables) . "): " . implode(', ', $tables) . "\n";
    $requiredTables = ['users', 'organizations', 'scorm_packages', 'sco_items', 'course_assignments', 'scorm_attempts', 'scorm_interactions', 'scorm_objectives', 'scorm_events'];
    foreach ($requiredTables as $t) {
        echo (in_array($t, $tables) ? "[OK] " : "[MISSING] ") . $t . "\n";
    }
} catch (Throwable $e) {
    echo "[FAIL] DB connection: " . $e->getMessage() . "\n";
}

if (file_exists(__DIR__ . "/s3-helpers.php")) { require_once __DIR__ . "/s3-helpers.php"; }
if (!defined('S3_BUCKET') && getenv('S3_BUCKET')) { define('S3_BUCKET', getenv('S3_BUCKET')); define('S3_REGION', getenv('S3_REGION') ?: 'us-east-1'); define('S3_KEY', getenv('S3_KEY') ?: ''); define('S3_SECRET', getenv('S3_SECRET') ?: ''); define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: ''); }
if (!defined('S3_BUCKET') && getenv('S3_BUCKET')) { define('S3_BUCKET', getenv('S3_BUCKET')); define('S3_REGION', getenv('S3_REGION') ?: 'us-east-1'); define('S3_KEY', getenv('S3_KEY') ?: ''); define('S3_SECRET', getenv('S3_SECRET') ?: ''); define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: ''); }
echo "\n--- S3 Storage ---\n";
if (function_exists('isS3Configured') && isS3Configured()) {
    echo "[OK]   S3 configured: bucket=" . S3_BUCKET . " region=" . S3_REGION . "\n";
    if (function_exists('s3Exists') && s3Exists(S3_PREFIX . '1/index.html')) {
        echo "[OK]   Object exists in S3\n";
    } else {
        echo "[INFO] Object not found in S3 (may not be uploaded yet)\n";
    }
} elseif (function_exists('isS3Configured')) {
    echo "[INFO] S3 not configured - using local disk storage\n";
    echo "       Set S3_BUCKET, S3_KEY, S3_SECRET in .env to enable\n";
} else {
    echo "[FAIL] s3-helpers.php not loaded - check bootstrap.php\n";
}

echo "\n--- Server route check (which URLs exist?) ---\n";
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$routeTests = [
    'scorm-player/index.php'       => $base . '/scorm-player/index.php?pkg=1',
    'scorm-content/1/ (rewrite)'   => $base . '/scorm-content/1/',
    'scorm-content/serve.php'      => $base . '/scorm-content/serve.php?pkg=1',
    'course-viewer/index.php'      => $base . '/course-viewer/index.php',
    'course-runner/index.php'      => $base . '/course-runner/index.php',
    'scorm-api/store.php'          => $base . '/scorm-api/store.php',
    'course-page/'                 => $base . '/course-page/',
    'login/'                       => $base . '/login/',
];
foreach ($routeTests as $label => $url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $snippet = '';
    if ($code >= 200 && $code < 300) {
        preg_match('/<title>(.*?)<\/title>/i', (string)$resp, $m);
        $snippet = ' (title: ' . htmlspecialchars($m[1] ?? '') . ')';
    }
    echo str_pad($label, 30) . ' => HTTP ' . $code . $snippet . "\n";
}

echo "\n--- Module files on disk ---\n";
$filesToCheck = [
    'scorm-player/index.php',
    'scorm-content/serve.php',
    'scorm-content/.htaccess',
    'scorm-api/scorm-rte.js',
    'scorm-api/store.php',
    'course-viewer/index.php',
    'course-runner/index.php',
    'course-runner/proxy.php',
];
foreach ($filesToCheck as $f) {
    $full = __DIR__ . '/' . $f;
    echo (file_exists($full) ? "[OK]   " : "[MISS] ") . $f . "\n";
}
// Check if package 1 has extracted files on disk
if (is_dir(__DIR__ . '/content/scorm/1')) {
    echo "[OK]   content/scorm/1/ directory exists (with files)\n";
    $entries = glob(__DIR__ . '/content/scorm/1/*');
    echo "       Entries in package 1: " . count($entries ?: []) . "\n";
} else {
    echo "[MISS] content/scorm/1/ directory does NOT exist\n";
    echo "       => SCORM package files were never extracted, or extracted elsewhere\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";