<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * SCORM S3 Re-Sync — CLI version
 *
 * Run directly from the command line as the web user (or with sudo -u www-data).
 * Does NOT require a web session or HTTP request.
 *
 * Usage:
 *   php scorm-s3-resync-cli.php --pkg=26
 *   php scorm-s3-resync-cli.php --pkg=26 --pkg=27
 *   php scorm-s3-resync-cli.php --pkg=26 --force   (re-upload even if already in S3)
 *   php scorm-s3-resync-cli.php --all               (sync every package)
 *
 * Run from the public_html directory:
 *   cd /home/Nathan/web/edu.pursuitpathways.com/public_html
 *   sudo -u www-data php admin/scorm-s3-resync-cli.php --pkg=26 --pkg=27
 */

// Must be run from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

// Change to the directory containing bootstrap.php
$scriptDir = dirname(__DIR__); // public_html
chdir($scriptDir);

// Bootstrap without session/auth checks
define('CLI_MODE', true);
require_once $scriptDir . '/bootstrap.php';

set_time_limit(0);
ini_set('memory_limit', '256M');

// ── Parse CLI arguments ──
$opts = getopt('', ['pkg:', 'all', 'force', 'dry-run']);
$packageIds = [];
$forceAll   = isset($opts['force']);
$dryRun     = isset($opts['dry-run']);
$syncAll    = isset($opts['all']);

if ($syncAll) {
    // Fetch all package IDs from DB
    try {
        $pdo = getDbConnection();
        $rows = $pdo->query("SELECT id FROM scorm_packages ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $packageIds = array_map('intval', $rows);
    } catch (Throwable $e) {
        echo "Error fetching package list: " . $e->getMessage() . "\n";
        exit(1);
    }
} elseif (isset($opts['pkg'])) {
    // --pkg can be specified multiple times; getopt returns array or string
    $pkgArg = $opts['pkg'];
    $packageIds = is_array($pkgArg) ? array_map('intval', $pkgArg) : [(int)$pkgArg];
} else {
    echo "Usage:\n";
    echo "  php admin/scorm-s3-resync-cli.php --pkg=26\n";
    echo "  php admin/scorm-s3-resync-cli.php --pkg=26 --pkg=27\n";
    echo "  php admin/scorm-s3-resync-cli.php --all\n";
    echo "  php admin/scorm-s3-resync-cli.php --pkg=26 --force    (re-upload all, not just missing)\n";
    echo "  php admin/scorm-s3-resync-cli.php --pkg=26 --dry-run  (show missing, no upload)\n";
    exit(0);
}

if (!isS3Configured()) {
    echo "Error: S3 is not configured (S3_BUCKET/S3_KEY/S3_SECRET missing).\n";
    exit(1);
}

$grandTotal    = 0;
$grandUploaded = 0;
$grandFailed   = 0;

foreach ($packageIds as $packageId) {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Package $packageId\n";
    echo str_repeat('=', 60) . "\n";

    $packageDir = SCORM_STORAGE_PATH . '/' . $packageId;
    if (!is_dir($packageDir)) {
        echo "  ERROR: Local directory not found: $packageDir\n";
        echo "  The package files may have been deleted. Re-upload the SCORM zip.\n";
        continue;
    }

    $s3Prefix = S3_PREFIX . $packageId . '/';
    echo "  Local dir : $packageDir\n";
    echo "  S3 prefix : $s3Prefix\n";
    echo "  Mode      : " . ($dryRun ? "DRY RUN" : ($forceAll ? "FORCE RE-UPLOAD ALL" : "UPLOAD MISSING ONLY")) . "\n\n";

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS)
    );

    $totalFiles   = 0;
    $missingFiles = [];

    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $totalFiles++;
        $rel   = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($packageDir) + 1));
        $s3Key = $s3Prefix . $rel;

        if ($forceAll || !s3Exists($s3Key)) {
            $missingFiles[] = ['local' => $file->getPathname(), 'key' => $s3Key, 'rel' => $rel];
            if ($dryRun) {
                echo "  MISSING: $rel\n";
            }
        }
    }

    echo "  Total local files : $totalFiles\n";
    echo "  Missing from S3   : " . count($missingFiles) . "\n";
    $grandTotal += $totalFiles;

    if ($dryRun || count($missingFiles) === 0) {
        if (count($missingFiles) === 0) echo "  Nothing to upload.\n";
        continue;
    }

    echo "\n  Uploading " . count($missingFiles) . " files...\n";
    $uploaded = 0;
    $failed   = 0;

    foreach ($missingFiles as $item) {
        $mime = function_exists('mime_content_type')
            ? (mime_content_type($item['local']) ?: 'application/octet-stream')
            : 'application/octet-stream';

        $size = number_format(filesize($item['local']));
        echo "  [{$size}B] " . $item['rel'] . " ... ";

        if (s3PutFile($item['local'], $item['key'], $mime)) {
            echo "OK\n";
            $uploaded++;
        } else {
            echo "FAILED\n";
            $failed++;
        }
    }

    echo "\n  Result: $uploaded uploaded, $failed failed\n";
    $grandUploaded += $uploaded;
    $grandFailed   += $failed;
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY: $grandUploaded uploaded, $grandFailed failed (across " . count($packageIds) . " package(s))\n";

if ($grandFailed > 0) {
    echo "Some files failed. Re-run to retry.\n";
    exit(1);
} else {
    echo "Done. Clear the SCORM HTML cache and hard-refresh the course.\n";
    exit(0);
}
