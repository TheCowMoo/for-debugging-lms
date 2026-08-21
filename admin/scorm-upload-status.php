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
