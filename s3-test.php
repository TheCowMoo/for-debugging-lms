<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin();
header('Content-Type: text/plain');
$f = __DIR__ . '/.env';
if (file_exists($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || strpos($l, '#') === 0) continue;
        $p = explode('=', $l, 2);
        if (count($p) === 2) putenv(trim($p[0]) . '=' . trim($p[1]));
    }
}
define('S3_BUCKET', getenv('S3_BUCKET') ?: '');
define('S3_REGION', getenv('S3_REGION') ?: 'us-east-1');
define('S3_KEY', getenv('S3_KEY') ?: '');
define('S3_SECRET', getenv('S3_SECRET') ?: '');
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: '');
define('S3_DEBUG', getenv('S3_DEBUG') === '1');
require_once __DIR__ . '/s3-helpers.php';
echo "Bucket=" . S3_BUCKET . " Region=" . S3_REGION . " KeySet=" . (S3_KEY !== '' ? 'Y' : 'N') . "\n";
if (!isS3Configured()) { echo "FAIL not configured\n"; exit; }
$ok = s3Put('test-write.txt', 'hello', 'text/plain');
echo $ok ? "SUCCESS write test-write.txt to s3://" . S3_BUCKET . "/test-write.txt\n" : "FAILED (check error log for [S3] DEBUG)\n";