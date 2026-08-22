<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * SCORM Upload Job Status Endpoint
 *
 * GET ?job_id=<id>
 * Returns JSON with the current status of a background upload job.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

$jobId = (int)($_GET['job_id'] ?? 0);
if (!$jobId) {
    echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM scorm_upload_jobs WHERE id=?");
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }

    // Verify the job belongs to the current user (security)
    if ((int)$row['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    // Stale-job detection: a job stuck in 'queued' (worker never started) or a
    // 'running' job with no heartbeat is marked failed so the UI can surface it
    // instead of polling forever. Thresholds are generous to avoid false failures
    // for large packages with long single-file S3 uploads.
    if (($row['status'] === 'queued' || $row['status'] === 'running') && !empty($row['updated_at'])) {
        $nowTs = time();
        $updTs = strtotime((string)$row['updated_at']);
        $crTs  = strtotime((string)($row['created_at'] ?? ''));
        $stale = false;
        if ($row['status'] === 'queued') {
            $stale = ($crTs > 0 && ($nowTs - $crTs) > 30 * 60);
        } else {
            $stale = ($updTs > 0 && ($nowTs - $updTs) > 60 * 60);
        }
        if ($stale) {
            $failMsg = $row['status'] === 'queued'
                ? 'The upload worker never started. Job marked failed after 30 minutes — please retry.'
                : 'The upload worker stopped responding (no progress for over an hour). Job marked failed — please retry.';
            $pdo->prepare("UPDATE scorm_upload_jobs SET status='failed', message=?, updated_at=NOW() WHERE id=? AND status=?")
                ->execute([$failMsg, $jobId, $row['status']]);
            $row['status'] = 'failed';
            $row['message'] = $failMsg;
        }
    }

    echo json_encode([
        'ok'              => true,
        'status'          => $row['status'],
        'message'         => $row['message'],
        'progress_pct'    => (int)$row['progress_pct'],
        'package_id'      => $row['package_id'] ? (int)$row['package_id'] : null,
        'sco_count'       => $row['sco_count'] ? (int)$row['sco_count'] : null,
        'files_extracted' => $row['files_extracted'] ? (int)$row['files_extracted'] : null,
        'launch_sco_id'   => $row['launch_sco_id'] ? (int)$row['launch_sco_id'] : null,
        'title'           => $row['title'] ?? '',
        'scorm_version'   => $row['scorm_version'] ?? '',
        'updated_at'      => $row['updated_at'] ?? '',
        'created_at'      => $row['created_at'] ?? '',
    ]);
} catch (Throwable $e) {
    $correlationId = bin2hex(random_bytes(8));
    error_log('[STATUS][' . $correlationId . '] ' . $e->getMessage());
    // Never echo raw exception text to the browser.
    echo json_encode([
        'ok'             => false,
        'error'          => 'server_error',
        'correlation_id' => $correlationId,
    ]);
}
