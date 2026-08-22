<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * NATIVE SCORM READER — Package Upload Handler (Phase 1)
 *
 * Receives a SCORM .zip package, validates it, creates DB records,
 * spawns a background worker process, and immediately returns a job ID.
 * The heavy work (extraction + S3 upload + manifest parsing) runs in the
 * background worker to avoid nginx proxy_read_timeout (504) on large files.
 *
 * POST fields:
 *   csrf_token      — CSRF token
 *   package_title   — Override title (optional; falls back to manifest)
 *   package_desc    — Optional description
 *   organization_id — Org to assign (admin only; super admin can choose)
 *   scorm_file      — The .zip file
 *
 * Response (JSON):
 *   { ok: true, job_id: N, title: "...", scorm_version: "1.2" }
 *   { ok: false, error: "..." }
 *
 * @package  PP_LMS
 * @version  2.0.0
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../scorm-api/scorm-normalize.php';

requireLogin();
requireAdmin();
ensureOrganizationsTable();
ensureScormTables();
ensureUploadJobsTable();

if (!function_exists('spawnUploadWorker')) {
    /**
     * Try to start the SCORM CLI background worker as a detached process.
     * Returns true only when a worker process was successfully started; returns
     * false otherwise so the caller can fall back to inline processing.
     */
    function spawnUploadWorker(int $jobId, string $tmpPath, int $packageId, string $stripPrefix): bool
    {
        if (!function_exists('proc_open')) {
            error_log('[SCORM] proc_open unavailable — using inline processing');
            return false;
        }
        $worker = __DIR__ . '/scorm-upload-worker.php';
        if (!is_file($worker)) {
            error_log('[SCORM] Worker file missing: ' . $worker);
            return false;
        }

        // Resolve a usable PHP CLI binary. PHP_BINARY under FPM points at the FPM
        // binary (not usable for CLI scripts), so allow an explicit override and
        // fall back to standard CLI locations.
        $phpBin = SCORM_PHP_BIN !== '' ? SCORM_PHP_BIN : '';
        if ($phpBin === '' || !is_executable($phpBin)) {
            $phpBin = '';
            if (PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
                $base = strtolower(basename(PHP_BINARY));
                if (strpos($base, 'fpm') === false) $phpBin = PHP_BINARY;
            }
        }
        if ($phpBin === '') {
            foreach (['/usr/bin/php', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1', '/usr/bin/php8.0', '/usr/bin/php7.4'] as $cand) {
                if (is_executable($cand)) { $phpBin = $cand; break; }
            }
        }
        if ($phpBin === '') {
            error_log('[SCORM] No PHP CLI binary found — using inline processing');
            return false;
        }

        $cmd = [
            $phpBin, $worker,
            '--job-id=' . $jobId,
            '--tmp=' . $tmpPath,
            '--pkg=' . $packageId,
            '--strip=' . $stripPrefix,
        ];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            error_log('[SCORM] proc_open failed to start worker — using inline processing');
            return false;
        }
        // Fire-and-forget: do NOT proc_close() (it would wait for the child).
        error_log('[SCORM] Spawned background worker for job=' . $jobId . ' (php=' . $phpBin . ')');
        return true;
    }
}

// Remove time limit for the initial receive phase
set_time_limit(120);
if (ini_get('memory_limit') < 512) {
    ini_set('memory_limit', '512M');
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// —— Detect empty POST (post_max_size exceeded) ——
if (empty($_POST) && empty($_FILES)) {
    error_log('[SCORM] Empty POST and FILES — post_max_size likely exceeded. post_max_size=' . ini_get('post_max_size') . ' upload_max_filesize=' . ini_get('upload_max_filesize'));
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'File too large — it exceeds the server post_max_size (' . ini_get('post_max_size') . ') limit. Please reduce the file size or increase post_max_size in php.ini.']);
    exit;
}

// —— Validate upload presence ——
if (empty($_FILES['scorm_file']) || $_FILES['scorm_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE form limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    $code = $_FILES['scorm_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $uploadErrors[$code] ?? 'Unknown upload error.';
    if ($code === UPLOAD_ERR_INI_SIZE) {
        $msg .= ' Server limits: post_max_size=' . ini_get('post_max_size') . ', upload_max_filesize=' . ini_get('upload_max_filesize') . '.';
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// —— CSRF validation ——
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

// —— Replace mode: overwrite an existing package's content (same package id) ——
$replacePkgId = (int)($_POST['replace_package_id'] ?? 0);
if ($replacePkgId > 0) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, organization_id FROM scorm_packages WHERE id = ?");
    $stmt->execute([$replacePkgId]);
    $pkgRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pkgRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Package not found.']);
        exit;
    }
    if (!isSuperAdmin() && (int)$pkgRow['organization_id'] !== (int)getOrgId()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to replace this package.']);
        exit;
    }
}

$tmpPath = $_FILES['scorm_file']['tmp_name'];

// —— Validate file size ——
if (filesize($tmpPath) > SCORM_MAX_UPLOAD_SIZE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File exceeds the maximum allowed size of 512 MB.']);
    exit;
}

try {
    // —— Validate it's a real ZIP ——
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Uploaded file is not a valid ZIP archive.']);
        exit;
    }

    // —— Validate imsmanifest.xml exists (any depth) ——
    $manifestIndex = $zip->locateName('imsmanifest.xml');
    if ($manifestIndex === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strtolower(basename($name)) === 'imsmanifest.xml') {
                $manifestIndex = $i;
                break;
            }
        }
    }
    if ($manifestIndex === false) {
        $sample = [];
        for ($i = 0; $i < min(15, $zip->numFiles); $i++) {
            $sample[] = $zip->getNameIndex($i);
        }
        error_log('[SCORM] No imsmanifest.xml found. First ' . count($sample) . ' ZIP entries: ' . implode(', ', $sample));
        $zip->close();
        $joined = strtolower(implode(' ', $sample));
        $hint = '';
        if (strpos($joined, 'story_content') !== false && strpos($joined, 'frame.xml') !== false) {
            $hint = ' This appears to be an Articulate Storyline HTML5/Web export. Please re-publish as SCORM 1.2 or SCORM 2004 (LMS format) from Storyline, not "Web" format.';
        } elseif (strpos($joined, 'captivate') !== false) {
            $hint = ' This appears to be an Adobe Captivate export. Please re-publish as SCORM 1.2 or SCORM 2004 (LMS format).';
        } elseif (strpos($joined, 'tincan') !== false || strpos($joined, 'xapi') !== false) {
            $hint = ' This appears to be a Tin Can/xAPI package. Please export as SCORM 1.2 or SCORM 2004 instead.';
        } elseif (strpos($joined, 'lms') !== false || strpos($joined, 'scorm') !== false) {
            $hint = ' This ZIP references SCORM-related files but is missing imsmanifest.xml. The package may be malformed or exported incorrectly.';
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Package is missing imsmanifest.xml at its root. This is not a valid SCORM package.' . $hint]);
        exit;
    }

    $manifestXml = $zip->getFromIndex($manifestIndex);
    if (empty($manifestXml)) {
        $zip->close();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not read imsmanifest.xml from the package.']);
        exit;
    }

    // —— Parse manifest for title/version ——
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($manifestXml)) {
        $zip->close();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'imsmanifest.xml is malformed XML.']);
        exit;
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('adlcp', 'http://www.adlnet.org/xsd/adlcp_rootv1p2');
    $xp->registerNamespace('adlcp2004', 'http://www.adlnet.org/xsd/adlcp_v1p3');

    // Determine SCORM version
    $schemaVersion = '1.2';
    $rootEl = $dom->documentElement;
    if (strpos($rootEl->getAttribute('xmlns'), 'adlcp_v1p3') !== false || strpos($manifestXml, 'adlcp_v1p3') !== false) {
        $schemaVersion = '2004';
    }
    // Edition detection ('1.2', '2004 2nd/3rd/4th Edition') — stored on the
    // package row and used for suspend-data limits and field semantics.
    $scormEdition = scormDetectEdition($manifestXml);

    // Extract title
    $title = trim($_POST['package_title'] ?? '') ?: '';
    if ($title === '') {
        $titleNode = $xp->query('//*[local-name()="title"]')->item(0);
        $title = trim($titleNode ? $titleNode->textContent : '');
    }
    if ($title === '') {
        $title = preg_replace('/\.zip$/i', '', basename($_FILES['scorm_file']['name']));
    }

    $description = trim($_POST['package_desc'] ?? '');
    if ($description === '') {
        $descNode = $xp->query('//*[local-name()="description"]')->item(0);
        if ($descNode) $description = trim($descNode->textContent);
    }

    // Determine strip prefix
    $manifestName = $zip->getNameIndex($manifestIndex);
    $stripPrefix  = (dirname($manifestName) !== '.') ? dirname($manifestName) . '/' : '';

    $zip->close();

    // —— Organization scoping ——
    $orgId = (int)($_POST['organization_id'] ?? 0);
    $currentOrgId = getOrgId();
    if (!isSuperAdmin()) {
        $orgId = $currentOrgId;
    } elseif ($orgId === 0) {
        $orgId = null;
    }

    // Replace mode never changes assignments — keep them exactly as they are.
    if ($replacePkgId > 0) {
        $orgId = null;
    }

    // —— Move temp file to a persistent location ——
    // PHP's temp file is deleted when the request ends; we need it to survive
    // until the background worker finishes extracting it.
    $persistTmp = SCORM_STORAGE_PATH . '/_upload_tmp_' . uniqid('', true) . '.zip';
    if (!is_dir(SCORM_STORAGE_PATH)) {
        mkdir(SCORM_STORAGE_PATH, 0755, true);
    }
    if (!move_uploaded_file($tmpPath, $persistTmp)) {
        // Fallback: copy (some PHP configs disallow move_uploaded_file to non-tmp dirs)
        if (!copy($tmpPath, $persistTmp)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded file to persistent storage.']);
            exit;
        }
    }

    // —— Create (upload) or update (replace) package DB row ——
    $pdo = getDbConnection();
    if ($replacePkgId > 0) {
        // Replace keeps the same package id so assignments, links and history survive.
        $pdo->prepare("UPDATE scorm_packages SET title = ?, description = ?, scorm_version = ?, scorm_edition = ?, manifest_xml = ?, status = 'draft' WHERE id = ?")
            ->execute([$title, $description, $schemaVersion, $scormEdition, $manifestXml, $replacePkgId]);
        $packageId = $replacePkgId;
    } else {
        $insert = $pdo->prepare("INSERT INTO scorm_packages (organization_id, title, description, scorm_version, scorm_edition, manifest_xml, upload_path, status)
                                 VALUES (?, ?, ?, ?, ?, ?, '', 'draft')");
        $insert->execute([
            $orgId !== 0 ? $orgId : null,
            $title,
            $description,
            $schemaVersion,
            $scormEdition,
            $manifestXml,
        ]);
        $packageId = (int)$pdo->lastInsertId();
    }

    // —— Create upload job row ——
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $pdo->prepare("INSERT INTO scorm_upload_jobs (user_id, package_id, org_id, title, scorm_version, tmp_path, strip_prefix, replace_flag, status, message, progress_pct)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', 'Queued for processing…', 0)")
        ->execute([$userId, $packageId, $orgId, $title, $schemaVersion, $persistTmp, $stripPrefix, $replacePkgId > 0 ? 1 : 0]);
    $jobId = (int)$pdo->lastInsertId();

    // Respond immediately, then process the job inline (no background spawn).
    // Background spawning via curl/fsockopen/exec is unreliable on shared hosts and
    // can silently fail, leaving the job stuck in "queued". Instead we send the JSON
    // response now (fastcgi_finish_request) and run the slow work (extract + S3 upload
    // + manifest parse) in this same process by delegating to scorm-upload-run.php,
    // which validates the token from $_REQUEST and calls exit() when finished.
    $runToken = hash_hmac('sha256', 'run:' . $jobId . ':' . $packageId, APP_CSRF_SECRET);

    $respJson = json_encode([
        'ok'            => true,
        'job_id'        => $jobId,
        'package_id'    => $packageId,
        'title'         => $title,
        'scorm_version' => $schemaVersion,
    ]);
    header('Content-Length: ' . strlen($respJson));
    echo $respJson;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) { while (ob_get_level() > 0) { ob_end_flush(); } }
        flush();
    }

    set_time_limit(0);
    ignore_user_abort(true);

    // Preferred path: a detached CLI worker (enabled via SCORM_BACKGROUND_WORKER=1
    // in .env). Falls back to inline processing in this process when spawning is
    // unavailable — inline still works on PHP-FPM where fastcgi_finish_request()
    // returns the response to the browser before the slow work runs.
    $spawned = false;
    if ((defined('SCORM_BACKGROUND_WORKER') ? SCORM_BACKGROUND_WORKER : '0') === '1') {
        $spawned = spawnUploadWorker($jobId, $persistTmp, $packageId, $stripPrefix);
    }
    if (!$spawned) {
        $_REQUEST['job_id'] = $jobId;
        $_REQUEST['token']  = $runToken;
        $GLOBALS['_SCORM_INLINE'] = true;
        require __DIR__ . '/scorm-upload-run.php';
        exit;
    }


} catch (Throwable $globalErr) {
    error_log('[SCORM] UPLOAD CRASH: ' . $globalErr->getMessage() . ' (class: ' . get_class($globalErr) . ')');
    if (isset($zip) && $zip instanceof ZipArchive) {
        try { $zip->close(); } catch (Throwable $_) {}
    }
    if (!empty($packageId) && !$replacePkgId) {
        try {
            $pdo = getDbConnection();
            $pdo->prepare("DELETE FROM scorm_packages WHERE id=?")->execute([$packageId]);
        } catch (Throwable $_) {}
    }
    if (!empty($persistTmp) && file_exists($persistTmp)) {
        @unlink($persistTmp);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error during upload: ' . $globalErr->getMessage()]);
}
