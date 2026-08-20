<?php
/**
 * UPLOAD DIAGNOSTIC — Shows PHP upload limits as actually enforced.
 * Run: https://edu.pursuitpathways.com/upload-diag.php
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin();

header('Content-Type: text/plain');
echo "=== UPLOAD LIMIT DIAGNOSTICS ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

$limits = [
    'upload_max_filesize' => 'Max single file upload',
    'post_max_size' => 'Max entire POST body',
    'memory_limit' => 'PHP memory limit',
    'max_execution_time' => 'Max script runtime (seconds)',
    'max_input_time' => 'Max input parsing time',
    'upload_tmp_dir' => 'Temp upload directory',
    'max_file_uploads' => 'Max simultaneous file uploads',
];

foreach ($limits as $key => $label) {
    $value = ini_get($key);
    echo str_pad($label . ':', 40) . "$key = $value\n";
}

echo "\n--- Nginx reverse proxy check ---\n";
if (!empty($_SERVER['HTTP_X_REAL_IP']) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    echo "Behind nginx/reverse proxy: YES\n";
    echo "If uploads fail with 413 or timeout, check:\n";
    echo "  - nginx: client_max_body_size (should be >= post_max_size)\n";
    echo "  - nginx: proxy_read_timeout (should be >= max_execution_time)\n";
    echo "  - nginx: proxy_send_timeout (should be >= max_input_time)\n";
} else {
    echo "Direct PHP access (no reverse proxy detected)\n";
}

echo "\n--- Memory available ---\n";
echo "memory_get_usage(): " . number_format(memory_get_usage(true)) . " bytes\n";
echo "memory_get_peak_usage(): " . number_format(memory_get_peak_usage(true)) . " bytes\n";

echo "\n--- .user.ini check ---\n";
$userIni = __DIR__ . '/.user.ini';
if (file_exists($userIni)) {
    echo "File exists: YES\n";
    echo "Content:\n";
    echo file_get_contents($userIni);
} else {
    echo "File does NOT exist at $userIni\n";
}

echo "\n--- Test file write ---\n";
$testDir = __DIR__ . '/content/scorm';
if (is_dir($testDir)) {
    $testFile = $testDir . '/_write_test.tmp';
    $result = file_put_contents($testFile, 'hello');
    if ($result !== false) {
        echo "Write test OK ($result bytes) to $testFile\n";
        unlink($testFile);
    } else {
        echo "Write test FAILED to $testFile\n";
    }
} else {
    echo "Directory $testDir does not exist\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";