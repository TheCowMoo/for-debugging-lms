<?php
/**
 * PURSUIT PATHWAYS LMS
 * SCORM Upload Background Worker (CLI only)
 *
 * Invoked by scorm-upload-handler.php as a background process.
 * Performs the slow work: ZIP extraction + S3 upload + manifest parsing.
 *
 * Arguments (all required):
 *   --job-id=<id>        Row ID in scorm_upload_jobs
 *   --tmp=<path>         Path to the uploaded .zip temp file
 *   --pkg=<id>           scorm_packages.id already inserted by the handler
 *   --strip=<prefix>     Manifest subfolder prefix to strip (may be empty)
 *
 * @package  PP_LMS
 */

// ── CLI guard ──
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Parse arguments
$opts = getopt('', ['job-id:', 'tmp:', 'pkg:', 'strip:']);
$jobId    = (int)($opts['job-id'] ?? 0);
$tmpPath  = $opts['tmp'] ?? '';
$pkgId    = (int)($opts['pkg'] ?? 0);
$stripPfx = $opts['strip'] ?? '';

if (!$jobId || !$tmpPath || !$pkgId) {
    fwrite(STDERR, "[WORKER] Missing required arguments\n");
    exit(1);
}

// Bootstrap (no HTTP session needed for CLI)
define('WORKER_CLI', true);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../scorm-api/scorm-normalize.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Helper: update job status ──
function jobUpdate(int $jobId, string $status, string $message = '', int $pct = 0, array $extra = []): void
{
    try {
        $pdo = getDbConnection();
        $pdo->prepare(
            "UPDATE scorm_upload_jobs SET status=?, message=?, progress_pct=?, updated_at=NOW() WHERE id=?"
        )->execute([$status, $message, $pct, $jobId]);
        if (!empty($extra)) {
            $sets = [];
            $vals = [];
            foreach ($extra as $col => $val) {
                $sets[] = "`$col`=?";
                $vals[] = $val;
            }
            $vals[] = $jobId;
            $pdo->prepare("UPDATE scorm_upload_jobs SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        }
    } catch (Throwable $e) {
        error_log('[WORKER] jobUpdate failed: ' . $e->getMessage());
    }
}

// ── Helper: rollback package row ──
function rollbackPkg(int $pkgId): void
{
    try {
        $pdo = getDbConnection();
        $pdo->prepare("DELETE FROM scorm_packages WHERE id=?")->execute([$pkgId]);
    } catch (Throwable $_) {}
}

// Replace mode: wipe the previous version of a package (keeps the package id).
function replaceCleanup(int $pkgId): void
{
    try {
        $pdo = getDbConnection();
        // Break the FK link so completed attempts survive the sco_items delete.
        $pdo->prepare("UPDATE scorm_attempts SET sco_item_id = NULL WHERE package_id = ?")->execute([$pkgId]);
        // Drop in-progress sessions so learners restart the new content cleanly.
        $pdo->prepare("DELETE FROM scorm_attempts WHERE package_id = ? AND is_complete = 0")->execute([$pkgId]);
        // Remove old SCO definitions.
        $pdo->prepare("DELETE FROM sco_items WHERE package_id = ?")->execute([$pkgId]);
    } catch (Throwable $e) {
        error_log('[REPLACE] DB cleanup error for pkg=' . $pkgId . ': ' . $e->getMessage());
    }

    // Local files.
    $dir = SCORM_STORAGE_PATH . '/' . $pkgId;
    if (is_dir($dir)) {
        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $f) {
            if ($f->isDir()) @rmdir($f->getRealPath());
            else @unlink($f->getRealPath());
        }
        @rmdir($dir);
    }

    // S3 objects.
    if (function_exists('isS3Configured') && isS3Configured()) {
        s3DeletePrefix(S3_PREFIX . $pkgId . '/');
    }

    // HTML rewrite cache for this package (per-user cache dirs).
    foreach (glob(rtrim(SCORM_CACHE_PATH, '/') . '/' . $pkgId . '_*') as $cDir) {
        if (!is_dir($cDir)) continue;
        $it2 = new RecursiveDirectoryIterator($cDir, FilesystemIterator::SKIP_DOTS);
        $f2 = new RecursiveIteratorIterator($it2, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($f2 as $cf) {
            if ($cf->isDir()) @rmdir($cf->getRealPath());
            else @unlink($cf->getRealPath());
        }
        @rmdir($cDir);
    }

    error_log('[REPLACE] Previous content removed for pkg=' . $pkgId);
}

// Determine whether this job is replacing an existing package.
$replaceFlag = 0;
try {
    $pdo = getDbConnection();
    $replaceFlag = (int)($pdo->query("SELECT replace_flag FROM scorm_upload_jobs WHERE id=$jobId")->fetchColumn() ?: 0);
} catch (Throwable $_) {}

error_log("[WORKER] Starting job=$jobId pkg=$pkgId tmp=$tmpPath strip='$stripPfx'");
jobUpdate($jobId, 'running', 'Starting extraction…', 5);

// ── Step 1: Open ZIP ──
$zip = new ZipArchive();
if ($zip->open($tmpPath) !== true) {
    jobUpdate($jobId, 'failed', 'Could not open ZIP file.', 0);
    if (!$replaceFlag) rollbackPkg($pkgId);
    exit(1);
}

// Replace mode: extract to a STAGING directory first. The previous version is
// only destroyed (replaceCleanup) after the new content is extracted, parsed,
// and validated — a failed replace never destroys the working course.
$stagingDir = '';
if ($replaceFlag) {
    $stagingDir = SCORM_STORAGE_PATH . '/.staging_' . $pkgId . '_' . $jobId;
    if (is_dir($stagingDir)) {
        $itX = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($itX as $fX) {
            $fX->isDir() ? @rmdir($fX->getRealPath()) : @unlink($fX->getRealPath());
        }
        @rmdir($stagingDir);
    }
}

// ── Step 2: Extract ──
$packageDir = SCORM_STORAGE_PATH . '/' . $pkgId;
// In replace mode we extract into staging so the live package is untouched
// until the new content is fully validated.
$extractTarget = ($replaceFlag && $stagingDir !== '') ? $stagingDir : $packageDir;
if (!is_dir($extractTarget)) {
    mkdir($extractTarget, 0755, true);
}

$extractedCount = 0;
try {
    jobUpdate($jobId, 'running', 'Extracting files…', 10);
    if ($stripPfx === '') {
        if (!$zip->extractTo($extractTarget)) {
            throw new RuntimeException('ZipArchive::extractTo() returned false');
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractTarget, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) { if ($f->isFile()) $extractedCount++; }
    } else {
        $tmpExtractDir = $extractTarget . '_extract_tmp';
        if (!is_dir($tmpExtractDir)) mkdir($tmpExtractDir, 0755, true);
        if (!$zip->extractTo($tmpExtractDir)) {
            throw new RuntimeException('ZipArchive::extractTo() returned false');
        }
        $srcBase = $tmpExtractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($stripPfx, '/'));
        if (is_dir($srcBase)) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcBase, FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                if (!$f->isFile()) continue;
                $rel  = substr($f->getPathname(), strlen($srcBase) + 1);
                $dest = $extractTarget . DIRECTORY_SEPARATOR . $rel;
                $ddir = dirname($dest);
                if (!is_dir($ddir)) mkdir($ddir, 0755, true);
                rename($f->getPathname(), $dest);
                $extractedCount++;
            }
        }
        // Clean up temp dir
        $rii3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpExtractDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii3 as $f3) { $f3->isFile() ? unlink($f3->getPathname()) : rmdir($f3->getPathname()); }
        @rmdir($tmpExtractDir);
    }
} catch (Throwable $e) {
    error_log('[WORKER] Extraction failed: ' . $e->getMessage());
    $zip->close();
    @unlink($tmpPath);
    // Remove partial staging so the previous version stays intact.
    if ($replaceFlag && $stagingDir !== '' && is_dir($stagingDir)) {
        $itY = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($itY as $fY) {
            $fY->isDir() ? @rmdir($fY->getRealPath()) : @unlink($fY->getRealPath());
        }
        @rmdir($stagingDir);
    }
    jobUpdate($jobId, 'failed', 'Extraction failed: ' . $e->getMessage(), 0);
    if (!$replaceFlag) rollbackPkg($pkgId);
    exit(1);
}
$zip->close();
@unlink($tmpPath); // Remove temp file after extraction

if ($extractedCount === 0) {
    jobUpdate($jobId, 'failed', 'Package contained no readable files.', 0);
    if (!$replaceFlag) rollbackPkg($pkgId);
    exit(1);
}

// Replace mode: new content extracted successfully — now swap it into place.
if ($replaceFlag && $stagingDir !== '') {
    $stagingCount = 0;
    $itC = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS));
    foreach ($itC as $fc) { if ($fc->isFile()) $stagingCount++; }
    if ($stagingCount === 0) {
        error_log("[WORKER] Staging dir empty for pkg=$pkgId — aborting replace.");
        if (is_dir($stagingDir)) { @rmdir($stagingDir); }
        jobUpdate($jobId, 'failed', 'Replace aborted: staging directory was empty.', 0);
        exit(1);
    }
    jobUpdate($jobId, 'running', 'Validated new content — replacing previous version…', 60);
    replaceCleanup($pkgId);
    if (!is_dir($packageDir)) mkdir($packageDir, 0755, true);
    $itM = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itM as $fm) {
        $relM  = substr($fm->getPathname(), strlen($stagingDir) + 1);
        $destM = $packageDir . DIRECTORY_SEPARATOR . $relM;
        if ($fm->isDir()) {
            if (!is_dir($destM)) mkdir($destM, 0755, true);
        } else {
            $ddM = dirname($destM);
            if (!is_dir($ddM)) mkdir($ddM, 0755, true);
            rename($fm->getPathname(), $destM);
        }
    }
    @rmdir($stagingDir);
    error_log("[WORKER] Replaced pkg=$pkgId with validated content ($extractedCount files).");
}

error_log("[WORKER] Extracted $extractedCount files for pkg=$pkgId");
jobUpdate($jobId, 'running', "Extracted $extractedCount files. Uploading to S3…", 30);

// ── Step 3: S3 Upload ──
$s3Failed = 0;
if (isS3Configured()) {
    $s3Count  = 0;
    $s3Failed = 0;
    $s3Retry  = [];
    $totalFiles = $extractedCount;
    $doneFiles  = 0;

    try {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile()) continue;
            $r    = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($packageDir) + 1));
            $mime = function_exists('mime_content_type') ? (mime_content_type($file->getPathname()) ?: 'application/octet-stream') : 'application/octet-stream';
            $key  = S3_PREFIX . $pkgId . '/' . $r;
            if (s3PutFile($file->getPathname(), $key, $mime)) {
                $s3Count++;
            } else {
                $s3Retry[] = ['path' => $file->getPathname(), 'key' => $key, 'mime' => $mime, 'rel' => $r];
                error_log('[WORKER] S3 upload failed (will retry): ' . $r);
            }
            $doneFiles++;
            // Update progress every 25 files
            if ($doneFiles % 25 === 0) {
                $pct = 30 + (int)(($doneFiles / max(1, $totalFiles)) * 50);
                jobUpdate($jobId, 'running', "Uploading to S3: $doneFiles / $totalFiles files…", $pct);
            }
        }
    } catch (Throwable $s3Err) {
        error_log('[WORKER] S3 upload error: ' . $s3Err->getMessage());
    }

    // Retry pass
    if (!empty($s3Retry)) {
        sleep(2);
        error_log('[WORKER] Retrying ' . count($s3Retry) . ' failed S3 uploads…');
        $stillFailed = [];
        foreach ($s3Retry as $item) {
            if (s3PutFile($item['path'], $item['key'], $item['mime'])) {
                $s3Count++;
            } else {
                $stillFailed[] = $item;
                error_log('[WORKER] Retry FAILED: ' . $item['rel']);
            }
        }
        $s3Failed = count($stillFailed);
    }

    // Verification pass
    $s3Gaps = [];
    try {
        $rii2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii2 as $file2) {
            if (!$file2->isFile()) continue;
            $r2   = str_replace(DIRECTORY_SEPARATOR, '/', substr($file2->getPathname(), strlen($packageDir) + 1));
            $key2 = S3_PREFIX . $pkgId . '/' . $r2;
            if (!s3Exists($key2)) {
                $s3Gaps[] = ['path' => $file2->getPathname(), 'key' => $key2, 'rel' => $r2];
            }
        }
    } catch (Throwable $verifyErr) {
        error_log('[WORKER] S3 verification scan error: ' . $verifyErr->getMessage());
    }

    if (!empty($s3Gaps)) {
        error_log('[WORKER] Verification found ' . count($s3Gaps) . ' gaps — uploading…');
        foreach ($s3Gaps as $gap) {
            $mime = function_exists('mime_content_type') ? (mime_content_type($gap['path']) ?: 'application/octet-stream') : 'application/octet-stream';
            if (s3PutFile($gap['path'], $gap['key'], $mime)) {
                $s3Count++;
            } else {
                $s3Failed++;
                error_log('[WORKER] Gap fill FAILED: ' . $gap['rel']);
            }
        }
    }

    error_log("[WORKER] S3 complete: $s3Count uploaded, $s3Failed failed, " . count($s3Gaps) . " gaps filled");
}

if ($s3Failed > 0) {
    error_log("[WORKER] S3 upload incomplete: $s3Failed files missing from S3 — failing job.");
    jobUpdate($jobId, 'failed', "S3 upload incomplete: $s3Failed files missing from S3. Use the admin Repair action after resolving the cause.", 0);
    exit(1);
}


jobUpdate($jobId, 'running', 'S3 upload complete. Parsing manifest…', 85);

// ── Step 4: Parse manifest → sco_items ──
try {
    $pdo = getDbConnection();

    // Read manifest from DB (already stored by handler)
    $manifestXml = $pdo->query("SELECT manifest_xml FROM scorm_packages WHERE id=$pkgId")->fetchColumn();
    if (!$manifestXml) {
        throw new RuntimeException('manifest_xml missing from scorm_packages row');
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($manifestXml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('adlcp', 'http://www.adlnet.org/xsd/adlcp_rootv1p2');
    $xp->registerNamespace('adlcp2004', 'http://www.adlnet.org/xsd/adlcp_v1p3');
    $xp->registerNamespace('imsss', 'http://www.imsproject.org/xsd/imsss');
    $xp->registerNamespace('sco', 'http://www.imsproject.org/xsd/imsss');

    $resourceNodes = $xp->query('//*[local-name()="resource"]');
    $scoCount = 0;
    $firstLaunchScoId = null;
    $firstLaunchHref = '';
    $activityTree = [];
    $resourceMetadata = [];

    foreach ($resourceNodes as $resNode) {
        $type          = $resNode->getAttribute('type') ?: 'asset';
        $identifier    = $resNode->getAttribute('identifier') ?: '';
        $identifierref = $resNode->getAttribute('identifierref') ?: '';
        $launchHref    = $resNode->getAttribute('href') ?: '';

        if ($stripPfx !== '' && strpos($launchHref, $stripPfx) === 0) {
            $launchHref = substr($launchHref, strlen($stripPfx));
        }

        $scormType = 'asset';
        if (stripos($type, 'sco') !== false) {
            $scormType = 'sco';
        } else {
            $scoTypeAttr = $resNode->getAttribute('adlcp:scormType');
            if ($scoTypeAttr === '') $scoTypeAttr = $resNode->getAttribute('sco:scormType');
            if (stripos($scoTypeAttr, 'sco') !== false) $scormType = 'sco';
        }
        if ($scormType !== 'sco' && $launchHref !== '') {
            $scormType = 'sco';
        }
        if ($launchHref === '' && $scormType === 'sco') {
            $fileNodes = $xp->query('*[local-name()="file"]', $resNode);
            if ($fileNodes->length > 0) {
                $launchHref = $fileNodes->item(0)->getAttribute('href') ?: '';
            }
        }

        $itemTitle = '';
        $itemNodes = $xp->query('//*[local-name()="item" and @identifierref="' . htmlspecialchars($identifier, ENT_QUOTES) . '"]');
        if ($itemNodes->length > 0) {
            $titleNode = $xp->query('.//*[local-name()="title"]', $itemNodes->item(0))->item(0);
            $itemTitle = $titleNode ? trim($titleNode->textContent) : '';
        }
        if ($itemTitle === '') $itemTitle = $identifier ?: 'SCO';

        $dataFromLms = $resNode->getAttribute('adlcp:datafromlms') ?: $resNode->getAttribute('datafromlms');
        $prereq      = $resNode->getAttribute('adlcp:prerequisites') ?: $resNode->getAttribute('prerequisites');
        $maxTime     = $resNode->getAttribute('adlcp:maxtimeallowed') ?: $resNode->getAttribute('maxtimeallowed');
        $timeLimitAct = $resNode->getAttribute('adlcp:timelimitaction') ?: $resNode->getAttribute('timelimitaction');
        $ms          = $resNode->getAttribute('adlcp:masteryscore') ?: $resNode->getAttribute('masteryscore');
        $mastery     = ($ms !== '') ? (float)$ms : null;

        // Manifest-derived metadata for the package fingerprint.
        $activityTree[] = ['identifier' => $identifier, 'identifierref' => $identifierref, 'title' => $itemTitle, 'type' => $scormType];
        $resourceMetadata[] = ['identifier' => $identifier, 'type' => $type, 'href' => $launchHref, 'scormType' => $scormType];

        $pdo->prepare("INSERT INTO sco_items
            (package_id, identifier, identifierref, title, launch_url, scorm_type,
             data_from_lms, prerequisites, max_time_allowed, time_limit_action, mastery_score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$pkgId, $identifier, $identifierref, $itemTitle, $launchHref, $scormType,
                       $dataFromLms, $prereq, $maxTime, $timeLimitAct, $mastery]);

        if ($scormType === 'sco' && $firstLaunchScoId === null) {
            $firstLaunchScoId = (int)$pdo->lastInsertId();
            $firstLaunchHref = $launchHref;
        }
        $scoCount++;
    }

    if ($firstLaunchScoId !== null) {
        // ── Edition detection + package fingerprint (manifest-driven) ──
        $edition = scormDetectEdition($manifestXml);
        $schemaVersion = strpos($edition, '2004') === 0 ? '2004' : '1.2';
        $rootEl = $dom->documentElement;
        $manifestId = $rootEl->getAttribute('identifier') ?: '';
        $svNode = $xp->query('//*[local-name()="schemaversion"]')->item(0);
        $packageVersion = $svNode ? trim($svNode->textContent) : '';

        $hashParts = [];
        $riiH = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS));
        foreach ($riiH as $hf) {
            if (!$hf->isFile()) continue;
            $hashParts[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($hf->getPathname(), strlen($packageDir) + 1)) . ':' . $hf->getSize();
        }
        sort($hashParts, SORT_STRING);
        $contentHash = hash('sha256', implode("\n", $hashParts));

        $fingerprint = json_encode([
            'standard'     => $schemaVersion,
            'edition'      => $edition,
            'launch_href'  => $firstLaunchHref,
            'manifest_id'  => $manifestId,
            'sco_count'    => $scoCount,
            'content_hash' => $contentHash,
            'asset_count'  => $extractedCount,
        ]);

        $pdo->prepare("UPDATE scorm_packages SET
                launch_sco_id = ?, upload_path = ?,
                scorm_edition = ?, manifest_id = ?, package_version = ?, sco_count = ?,
                activity_tree = ?, resource_metadata = ?, content_hash = ?, fingerprint = ?
                WHERE id = ?")
            ->execute([
                $firstLaunchScoId,
                'content/scorm/' . $pkgId,
                $edition,
                $manifestId,
                $packageVersion,
                $scoCount,
                json_encode($activityTree),
                json_encode($resourceMetadata),
                $contentHash,
                $fingerprint,
                $pkgId,
            ]);
    } else {
        $pdo->prepare("UPDATE scorm_packages SET upload_path=? WHERE id=?")
            ->execute(['content/scorm/' . $pkgId, $pkgId]);
    }

    error_log("[WORKER] Manifest parsed: $scoCount SCOs, firstLaunchScoId=$firstLaunchScoId");

    // ── Assign to org if stored in job ──
    $jobRow = $pdo->query("SELECT org_id FROM scorm_upload_jobs WHERE id=$jobId")->fetch(PDO::FETCH_ASSOC);
    $orgId  = $jobRow['org_id'] ?? null;
    if ($orgId) {
        try {
            $pdo->prepare("INSERT IGNORE INTO course_assignments (package_id, organization_id, assigned_by) VALUES (?, ?, ?)")
                ->execute([$pkgId, $orgId, $jobRow['user_id'] ?? 0]);
        } catch (Throwable $_) {}
    }

    // Mark job complete
    jobUpdate($jobId, 'done', 'Upload complete.', 100, [
        'package_id'      => $pkgId,
        'sco_count'       => $scoCount,
        'files_extracted' => $extractedCount,
        'launch_sco_id'   => $firstLaunchScoId,
    ]);
    error_log("[WORKER] Job $jobId done. pkg=$pkgId scos=$scoCount");

} catch (Throwable $e) {
    error_log('[WORKER] Manifest parse failed: ' . $e->getMessage());
    jobUpdate($jobId, 'failed', 'Manifest parse failed: ' . $e->getMessage(), 0);
    exit(1);
}
