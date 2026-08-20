<?php
/**
 * PURSUIT PATHWAYS LMS
 * SCORM S3 Re-Sync Tool (JSON API)
 *
 * Scans the local extracted directory for one or all SCORM packages and
 * uploads any files that are present on disk but missing from S3.
 *
 * Called by the "Repair" / "Repair All" buttons in the admin UI (scorm-upload.php).
 *
 * GET  ?pkg=N          — dry-run: returns JSON with missing file count, no uploads
 * POST ?pkg=N          — upload missing files for package N, returns JSON
 * POST ?all=1          — upload missing files for ALL packages, returns JSON
 *
 * @package  PP_LMS
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();
requireAdmin();

set_time_limit(0);
ini_set('memory_limit', '256M'); // Low — we stream files, not load them

header('Content-Type: application/json; charset=utf-8');

if (!isS3Configured()) {
    echo json_encode(['ok' => false, 'error' => 'S3 is not configured on this server.']);
    exit;
}

$isDryRun = ($_SERVER['REQUEST_METHOD'] !== 'POST');
$allPkgs  = !$isDryRun && (isset($_GET['all']) || isset($_POST['all']));
$pkgId    = (int)($_GET['pkg'] ?? $_POST['pkg'] ?? 0);

if (!$allPkgs && $pkgId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '?pkg=N or ?all=1 required']);
    exit;
}

// ── Helper: sync one package directory to S3 ──
function syncPackage(int $packageId, bool $dryRun): array {
    $packageDir = SCORM_STORAGE_PATH . '/' . $packageId;
    if (!is_dir($packageDir)) {
        return [
            'pkg'      => $packageId,
            'ok'       => false,
            'error'    => 'Local directory not found: ' . $packageDir,
            'uploaded' => 0,
            'missing'  => 0,
            'failed'   => 0,
        ];
    }

    $s3Prefix     = S3_PREFIX . $packageId . '/';
    $missingFiles = [];
    $uploadedCount = 0;
    $failedCount   = 0;

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($rii as $file) {
        if (!$file->isFile()) continue;
        $rel   = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($packageDir) + 1));
        $s3Key = $s3Prefix . $rel;
        if (!s3Exists($s3Key)) {
            $missingFiles[] = ['local' => $file->getPathname(), 'key' => $s3Key, 'rel' => $rel];
        }
    }

    if (!$dryRun) {
        foreach ($missingFiles as $item) {
            $mime = function_exists('mime_content_type')
                ? (mime_content_type($item['local']) ?: 'application/octet-stream')
                : 'application/octet-stream';
            if (s3PutFile($item['local'], $item['key'], $mime)) {
                $uploadedCount++;
            } else {
                $failedCount++;
                error_log('[S3-RESYNC] Upload failed: pkg=' . $packageId . ' key=' . $item['key']);
            }
        }
    }

    return [
        'pkg'      => $packageId,
        'ok'       => true,
        'uploaded' => $uploadedCount,
        'missing'  => count($missingFiles),
        'failed'   => $failedCount,
        'dry_run'  => $dryRun,
    ];
}

// ── Single package ──
if (!$allPkgs) {
    $result = syncPackage($pkgId, $isDryRun);
    echo json_encode($result);
    exit;
}

// ── All packages ──
$pdo = getDbConnection();
$pkgIds = $pdo->query("SELECT id FROM scorm_packages ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$totalUploaded = 0;
$totalFailed   = 0;
$details       = [];

foreach ($pkgIds as $id) {
    $r = syncPackage((int)$id, false);
    $totalUploaded += $r['uploaded'];
    $totalFailed   += $r['failed'];
    if ($r['ok'] && ($r['uploaded'] > 0 || $r['missing'] > 0)) {
        $details[] = $r;
    }
    if (!$r['ok']) {
        $details[] = $r; // include errors too
    }
}

echo json_encode([
    'ok'             => true,
    'packages'       => count($pkgIds),
    'total_uploaded' => $totalUploaded,
    'total_failed'   => $totalFailed,
    'details'        => $details,
]);
