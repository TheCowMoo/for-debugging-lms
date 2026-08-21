<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * CROSS-VERSION SCORM TRACKING RECEIVER (v3)
 *
 * Receives persist calls from scorm-rte.js (inside SCORM content) and persists
 * tracking data into the analytics tables:
 *
 *   scorm_attempts              — one row per (user, sco, attempt)
 *   scorm_interactions          — question-level answers (upserted, never fully deleted)
 *   scorm_objectives            — objective-level outcomes (upserted)
 *   scorm_interaction_objectives — cmi.interactions.n.objectives.m.id links
 *   scorm_comments_from_learner — SCORM 2004 learner comments
 *   scorm_events                — bounded audit log (request_id + changed fields)
 *   scorm_request_idempotency   — exact-once handling for retries/beacons
 *
 * Processing order (contract):
 *   1.  Validate HTTP method, body size, JSON shape, request limits
 *   2.  Authenticate (session cookie OR SCORM serve token)
 *   3.  Validate package, user, organization, SCO, attempt ownership
 *   4.  Check idempotency key
 *   5.  Begin DB transaction
 *   6.  Create/update attempt with concurrency-safe numbering
 *   7.  Normalize statuses/score while retaining raw values
 *   8.  Upsert interactions + objectives (no full delete of prior set)
 *   9.  Upsert interaction-objective links + learner comments
 *   10. Insert bounded audit event (request_id + changed-field metadata)
 *   11. Commit
 *   12. Return complete resume state + attempt ID
 *
 * On any failure the transaction rolls back and a retryable error is returned.
 * The endpoint NEVER returns ok:true for a partially persisted payload.
 *
 * Request body (JSON, POST):
 *   {
 *     "pkg":             scorm_packages.id (required)
 *     "sco":             sco_items.id (0 if unknown)
 *     "version":         "1.2" | "2004"
 *     "attempt":         existing attempt id (from prior response)
 *     "request_id":      client-generated idempotency key (every commit)
 *     "terminating":     bool
 *     "session_delta_ms": incremental ms since previous persist
 *     "values":          { "cmi.core.lesson_status": "...", ... }  (flat scalars)
 *     "interactions":    [ { index, id, type, learner_response, result,
 *                            weighting, latency, timestamp, description,
 *                            objectives: ["objA"], correct_responses: [{id, pattern}] } ]
 *     "objectives":      [ { index, id, score:{...}, completion_status, ... } ]
 *     "comments":        [ { index, comment, location, timestamp } ]
 *   }
 *
 * @package  PP_LMS
 * @version  3.0.0
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/scorm-normalize.php';

ensureScormTables();
ensureScormMigrations();

define('SCORM_STORE_MAX_BODY_BYTES', 8388608);   // 8 MB — generous for large interaction sets
define('SCORM_STORE_MAX_VALUES', 10000);         // hard cap on scalar elements
define('SCORM_STORE_MAX_INTERACTIONS', 2000);    // hard cap on interactions per commit
define('SCORM_STORE_MAX_OBJECTIVES', 2000);
define('SCORM_STORE_MAX_COMMENTS', 500);

function scormStoreFail(int $status, string $error, bool $retryable, array $extra = []): void
{
    // Bounded monitoring log: rejected payloads (4xx) and failed persistence (5xx).
    $monitorType = $status >= 500 ? 'failed' : 'rejected';
    scormMonitorLog($monitorType, $error, $status, ['retryable' => $retryable]);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $error, 'retryable' => $retryable], $extra));
    exit;
}

/**
 * Best-effort, bounded monitoring log for rejected/duplicate/failed requests.
 */
function scormMonitorLog(string $type, string $reason, int $status = 0, array $detail = []): void
{
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO scorm_monitor (monitor_type, reason, request_id, user_id, package_id, http_status, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $type,
            mb_substr($reason, 0, 250),
            (string)($GLOBALS['scorm_monitor_req'] ?? ''),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            isset($GLOBALS['scorm_monitor_pkg']) ? (int)$GLOBALS['scorm_monitor_pkg'] : null,
            $status,
            !empty($detail) ? json_encode($detail) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[SCORM-MONITOR] log failed: ' . $e->getMessage());
    }
}

// ── Step 1: method / body / shape / limits ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    scormStoreFail(405, 'Method not allowed.', false);
}

$rawBody = file_get_contents('php://input');
if (strlen($rawBody) > SCORM_STORE_MAX_BODY_BYTES) {
    scormStoreFail(413, 'Payload too large.', false);
}
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    scormStoreFail(400, 'Invalid JSON body.', false);
}
$GLOBALS['scorm_monitor_req'] = (string)($data['request_id'] ?? '');
$data['values']      = is_array($data['values'] ?? null) ? $data['values'] : [];
$data['interactions'] = is_array($data['interactions'] ?? null) ? $data['interactions'] : [];
$data['objectives']  = is_array($data['objectives'] ?? null) ? $data['objectives'] : [];
$data['comments']    = is_array($data['comments'] ?? null) ? $data['comments'] : [];
if (count($data['values']) > SCORM_STORE_MAX_VALUES) {
    scormStoreFail(413, 'Too many data elements.', false);
}
if (count($data['interactions']) > SCORM_STORE_MAX_INTERACTIONS) {
    scormStoreFail(413, 'Too many interactions.', false);
}
if (count($data['objectives']) > SCORM_STORE_MAX_OBJECTIVES) {
    scormStoreFail(413, 'Too many objectives.', false);
}
if (count($data['comments']) > SCORM_STORE_MAX_COMMENTS) {
    scormStoreFail(413, 'Too many comments.', false);
}
$packageId = (int)($data['pkg'] ?? 0);
$GLOBALS['scorm_monitor_pkg'] = $packageId;
if ($packageId <= 0) {
    scormStoreFail(400, 'Missing pkg.', false);
}

// ── Step 2: authenticate (session OR stateless serve token) ──
// SCORM content runs inside an iframe; SameSite=Lax session cookies are not
// sent on requests originating there, so tracking POSTs usually arrive with no
// session. Accept the short-lived HMAC serve token (`t=`) that serve.php
// injects into SCORM_PACKAGE_CONFIG.
if (!isset($_SESSION['user_id'])) {
    $serveToken = trim((string)($_GET['t'] ?? ''));
    if ($serveToken !== '' && $packageId > 0) {
        $tokenUserId = validateServeToken($serveToken, $packageId);
        if ($tokenUserId !== null) {
            try {
                $tokPdo  = getDbConnection();
                $tokStmt = $tokPdo->prepare('SELECT role, organization_id FROM users WHERE id = ? LIMIT 1');
                $tokStmt->execute([$tokenUserId]);
                $tokUser = $tokStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tokUser) {
                    // Deleted user — the serve token must not grant access.
                    error_log('[SCORM-STORE] serve token rejected: user ' . $tokenUserId . ' no longer exists.');
                    $tokenUserId = null;
                } else {
                    $_SESSION['user_id']       = $tokenUserId;
                    $_SESSION['user_role']       = $tokUser['role'] ?? 'student';
                    $_SESSION['organization_id'] = $tokUser['organization_id'] ?? null;
                }
            } catch (PDOException $e) {
                error_log('[SCORM-STORE] token session bootstrap failed: ' . $e->getMessage());
            }
        }
    }
}
requireLogin();

// Token refresh for long sessions: if the RTE's serve token is near expiry,
// return a fresh token so tracking continues past the token lifetime.
$serveToken = trim((string)($_GET['t'] ?? ''));
$refreshToken = null;
if ($serveToken !== '') {
    $serveTokenExp = serveTokenExpiry($serveToken);
    if ($serveTokenExp !== null && time() > $serveTokenExp - 900) {
        $refreshToken = generateServeToken((int)$_SESSION['user_id'], $packageId);
    }
}

$pdo = getDbConnection();
$userId = (int)$_SESSION['user_id'];
$orgId = getOrgId();

// ── Step 3: validate package / user / org / SCO / attempt ownership ──
$orgFilter = '';
$params = [$packageId];
if (!isSuperAdmin() && $orgId !== null) {
    $orgFilter = " AND (sp.organization_id = ? OR EXISTS (
                    SELECT 1 FROM course_assignments ca
                    WHERE ca.package_id = sp.id AND ca.organization_id = ?
                  ) OR EXISTS (
                    SELECT 1 FROM scorm_attempts a
                    WHERE a.package_id = sp.id AND a.user_id = ?
                  ))";
    $params[] = $orgId;
    $params[] = $orgId;
    $params[] = $userId;
} elseif (!isSuperAdmin()) {
    $orgFilter = " AND (sp.organization_id IS NULL OR EXISTS (
                    SELECT 1 FROM scorm_attempts a
                    WHERE a.package_id = sp.id AND a.user_id = ?
                  ))";
    $params[] = $userId;
}

$pkgStmt = $pdo->prepare(
    "SELECT id, scorm_version, scorm_edition, manifest_xml
     FROM scorm_packages sp WHERE sp.id = ? AND sp.status = 'active'" . $orgFilter
);
$pkgStmt->execute($params);
$pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
if (!$pkg) {
    scormStoreFail(404, 'Package not found or inactive.', false);
}

$edition = (string)($pkg['scorm_edition'] ?? '');
if ($edition === '') {
    $edition = scormDetectEdition((string)($pkg['manifest_xml'] ?? ''));
}
$version = (($data['version'] ?? '') === '2004') ? '2004' : (($pkg['scorm_version'] ?? '1.2') === '2004' ? '2004' : '1.2');

$scoId = (int)($data['sco'] ?? 0);
$sco = null;
if ($scoId > 0) {
    $scoStmt = $pdo->prepare("SELECT id, launch_url, mastery_score FROM sco_items WHERE id = ? AND package_id = ?");
    $scoStmt->execute([$scoId, $packageId]);
    $sco = $scoStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sco) {
        $scoId = 0;
        $sco = null;
    }
}

$userStmt = $pdo->prepare("SELECT first_name, last_name, department, organization_id FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    scormStoreFail(401, 'User not found.', false);
}
$department = $userRow['department'] ?? null;
$userOrgId  = $userRow['organization_id'] ?? ($orgId ?? null);

$attemptId = (int)($data['attempt'] ?? 0);
if ($attemptId > 0) {
    $check = $pdo->prepare("SELECT id, sco_item_id FROM scorm_attempts WHERE id = ? AND user_id = ? AND package_id = ?");
    $check->execute([$attemptId, $userId, $packageId]);
    $attemptRow = $check->fetch(PDO::FETCH_ASSOC);
    if (!$attemptRow) {
        // Not this user's attempt (or wrong package) — treat as new.
        $attemptId = 0;
    } elseif ($scoId > 0 && (int)$attemptRow['sco_item_id'] > 0 && (int)$attemptRow['sco_item_id'] !== $scoId) {
        // Attempt belongs to a different SCO — never mix state across SCOs.
        $attemptId = 0;
    }
}

// ── Step 4: idempotency key ──
// Every RTE commit carries a client-generated request_id. The claim row is
// inserted BEFORE any write. Duplicates replay the stored response (exactly
// once), report "already committed" if the response write was lost, or return a
// retryable 409 while the original request is still in flight.
$requestId = '';
if (isset($data['request_id'])) {
    $requestId = (string)$data['request_id'];
    $requestId = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $requestId), 0, 64);
}
if ($requestId !== '') {
    // Payload fingerprint: a replayed request_id must carry the SAME body.
    // (The payload_hash column may be absent until migration 0003 runs.)
    static $hasPayloadHash = null;
    if ($hasPayloadHash === null) {
        $hasPayloadHash = false;
        try {
            $hashCol = $pdo->query("SHOW COLUMNS FROM scorm_request_idempotency LIKE 'payload_hash'")->fetchColumn();
            $hasPayloadHash = (bool)$hashCol;
        } catch (Throwable $e) {
            $hasPayloadHash = false;
        }
    }
    $payloadHash = $hasPayloadHash ? hash('sha256', $rawBody) : '';

    if ($hasPayloadHash) {
        $claim = $pdo->prepare("INSERT IGNORE INTO scorm_request_idempotency (request_id, user_id, payload_hash) VALUES (?, ?, ?)");
        $claim->execute([$requestId, $userId, $payloadHash]);
    } else {
        $claim = $pdo->prepare("INSERT IGNORE INTO scorm_request_idempotency (request_id, user_id) VALUES (?, ?)");
        $claim->execute([$requestId, $userId]);
    }
    if ($claim->rowCount() === 0) {
        $dup = $pdo->prepare(
            "SELECT attempt_id, response, payload_hash FROM scorm_request_idempotency WHERE request_id = ? AND user_id = ?"
        );
        $dup->execute([$requestId, $userId]);
        $dupRow = $dup->fetch(PDO::FETCH_ASSOC);
        scormMonitorLog('duplicate', 'Duplicate request_id detected.', 409, [
            'replayed' => $dupRow && $dupRow['response'] !== null ? true : false,
            'committed' => $dupRow && (int)$dupRow['attempt_id'] > 0 ? true : false,
        ]);
        // Replaying the SAME request_id with a DIFFERENT body is an abuse /
        // tamper signal — never honor it.
        if ($dupRow && $hasPayloadHash && $dupRow['payload_hash'] !== '' && $dupRow['payload_hash'] !== $payloadHash) {
            scormStoreFail(409, 'Payload does not match request_id.', false);
        }
        if ($dupRow && $dupRow['response'] !== null) {
            header('Content-Type: application/json');
            echo $dupRow['response'];
            exit;
        }
        if ($dupRow && (int)$dupRow['attempt_id'] > 0) {
            // Original committed but its response write never completed.
            scormStoreFail(409, 'Request already committed.', false, ['committed' => true]);
        }
        scormStoreFail(409, 'Request already in progress.', true);
    }
}

// ── Extract raw scalar values (retain verbatim for storage) ──
$normalized = [];
foreach ($data['values'] as $el => $val) {
    $key = strtolower(trim((string)$el));
    $normalized[$key] = (string)$val;
}

$rawLessonStatus = $normalized['cmi.core.lesson_status'] ?? '';
$rawCompletion   = $normalized['cmi.completion_status'] ?? '';
$rawSuccess      = $normalized['cmi.success_status'] ?? '';
$scoreRaw        = $normalized['cmi.core.score.raw'] ?? ($normalized['cmi.score.raw'] ?? null);
$scoreScaled     = $normalized['cmi.score.scaled'] ?? null;
$scoreMin        = $normalized['cmi.core.score.min'] ?? ($normalized['cmi.score.min'] ?? null);
$scoreMax        = $normalized['cmi.core.score.max'] ?? ($normalized['cmi.score.max'] ?? null);
$location        = $normalized['cmi.core.lesson_location'] ?? ($normalized['cmi.location'] ?? '');
$suspend         = $normalized['cmi.suspend_data'] ?? '';
$sessionTimeRaw  = $normalized['cmi.core.session_time'] ?? ($normalized['cmi.session_time'] ?? '');
$progress        = $normalized['cmi.progress_measure'] ?? null;
$entry           = $normalized['cmi.core.entry'] ?? ($normalized['cmi.entry'] ?? 'ab-initio');
$exitVal         = $normalized['cmi.core.exit'] ?? ($normalized['cmi.exit'] ?? '');
$mode            = $normalized['cmi.core.lesson_mode'] ?? ($normalized['cmi.mode'] ?? '');
$credit          = $normalized['cmi.core.credit'] ?? ($normalized['cmi.credit'] ?? '');
$completionThreshold = $normalized['cmi.completion_threshold'] ?? null;
$scaledPassingScore  = $normalized['cmi.scaled_passing_score'] ?? null;
// 1.2 exporters put mastery in cmi.student_data.mastery_score
$masteryScore = $sco['mastery_score'] ?? null;
if ($masteryScore === null && isset($normalized['cmi.student_data.mastery_score']) && $normalized['cmi.student_data.mastery_score'] !== '') {
    $masteryScore = (float)$normalized['cmi.student_data.mastery_score'];
}

// Edition-aware suspend_data limit (4096 / 4000 / 64000).
$suspendInfo = scormSanitizeSuspendData($suspend, $edition);
$suspend = $suspendInfo['value'];

$sessionDeltaMs = (int)($data['session_delta_ms'] ?? ($data['session_ms'] ?? 0));
$sessionSeconds = $sessionDeltaMs > 0
    ? (int)floor($sessionDeltaMs / 1000)
    : scormDurationToSeconds($sessionTimeRaw);

$terminating = !empty($data['terminating']);
$norm = scormNormalizeStatuses(
    $normalized,
    $version,
    $masteryScore !== null ? (float)$masteryScore : null,
    $scaledPassingScore !== null && $scaledPassingScore !== '' ? (float)$scaledPassingScore : null,
    $terminating
);

// Completion is STATUS-driven only. A terminating request (browser close,
// unload beacon, LMSFinish) is a transport event, NOT course completion.
$isComplete = 0;
if ($version === '2004') {
    $isComplete = (in_array($rawCompletion, ['completed'], true) || in_array($rawSuccess, ['passed', 'failed'], true)) ? 1 : 0;
} else {
    $isComplete = (in_array($rawLessonStatus, ['completed', 'passed', 'failed'], true)) ? 1 : 0;
}

$now = date('Y-m-d H:i:s');
$committed = false;

// Progress source bookkeeping (reported vs derived — P3). The reported value is
// the official cmi.progress_measure (0..1) when the package sends one.
$reportedProgress = ($progress !== null && $progress !== '') ? (float)$progress : null;
if ($reportedProgress !== null) {
    $progressSource = 'scorm_reported';
} elseif ($isComplete === 1) {
    $progressSource = 'completed_status';
} else {
    $progressSource = 'none';
}
// SCORM 2004 accuracy: when the package does not report cmi.progress_measure
// but its Storyline/Rise suspend_data carries slide bookmarks (visited/total),
// derive a defensible progress percentage so dashboards and analytics show real
// progress for in-progress courses instead of 0%. Provenance is recorded in
// progress_source; reported_progress_measure stays NULL (never claimed as
// package-reported).
if ($reportedProgress === null && $progressSource === 'none' && $suspend !== '') {
    $derivedProgress = scormProgressFromSuspendData($suspend);
    if ($derivedProgress !== null && $derivedProgress > 0) {
        $progress = $derivedProgress;
        $progressSource = 'storyline_suspend';
    }
}
$progressCalcAt = $now;

// ── Step 5: begin transaction ──
try {
    $pdo->beginTransaction();

    // ── Step 6: create or update attempt (concurrency-safe numbering) ──
    $isNewAttempt = ($attemptId === 0);
    $responseTotalSeconds = $sessionSeconds;
    $priorState = null;
    // Snapshot of the STORED attempt state (before this commit's values are
    // applied) so the first Initialize (empty values) returns real resume data.
    $resumeState = null;

    if ($isNewAttempt) {
        // Load total time carried from previous attempts (same package/SCO).
        $prevTimeSql = "SELECT COALESCE(SUM(total_time_seconds), 0) FROM scorm_attempts WHERE user_id = ? AND package_id = ?";
        $prevTimeParams = [$userId, $packageId];
        if ($scoId > 0) {
            $prevTimeSql .= " AND sco_item_id = ?";
            $prevTimeParams[] = $scoId;
        }
        $prevTimeStmt = $pdo->prepare($prevTimeSql);
        $prevTimeStmt->execute($prevTimeParams);
        $carriedTime = (int)$prevTimeStmt->fetchColumn();
        $responseTotalSeconds = $carriedTime + $sessionSeconds;

        $browserInfo = null;
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $browserInfo = json_encode(['ua' => $_SERVER['HTTP_USER_AGENT'], 'viewport' => null]);
        }

        // Concurrency-safe attempt numbering: compute MAX+1 under a row lock,
        // insert under the uq_attempt unique key, and retry on a duplicate
        // (another request won the race) — bounded, so a stuck duplicate
        // surfaces as a normal DB error instead of an infinite loop.
        $attemptNumber = 1;
        $inserted = false;
        $retries = 0;
        $numSql = "SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM scorm_attempts
                   WHERE user_id = ? AND package_id = ?";
        $numParams = [$userId, $packageId];
        if ($scoId > 0) {
            $numSql .= " AND sco_item_id = ?";
            $numParams[] = $scoId;
        }
        while (!$inserted && $retries < 5) {
            $numStmt = $pdo->prepare($numSql . " FOR UPDATE");
            $numStmt->execute($numParams);
            $attemptNumber = (int)$numStmt->fetchColumn();

            try {
                $insert = $pdo->prepare("INSERT INTO scorm_attempts
                    (user_id, organization_id, department, sco_item_id, package_id, scorm_edition, attempt_number,
                     lesson_status, completion_status, success_status,
                     score_raw, score_scaled, score_min, score_max,
                     mastery_score, passed, session_time_seconds, total_time_seconds,
                     lesson_location, suspend_data, progress_measure, reported_progress_measure, progress_source, progress_calculated_at, entry, mode, credit, `exit`,
                     completion_threshold, scaled_passing_score,
                     normalized_completion, normalized_success, status_source, attempt_state,
                     last_request_id, browser_info, is_complete, started_at, last_accessed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([
                    $userId,
                    $userOrgId,
                    $department,
                    $scoId > 0 ? $scoId : null,
                    $packageId,
                    $edition,
                    $attemptNumber,
                    $rawLessonStatus !== '' ? $rawLessonStatus : 'not attempted',
                    $rawCompletion,
                    $rawSuccess,
                    $scoreRaw !== null && $scoreRaw !== '' ? (float)$scoreRaw : null,
                    $scoreScaled !== null && $scoreScaled !== '' ? (float)$scoreScaled : null,
                    $scoreMin !== null && $scoreMin !== '' ? (float)$scoreMin : null,
                    $scoreMax !== null && $scoreMax !== '' ? (float)$scoreMax : null,
                    $masteryScore !== null ? (float)$masteryScore : null,
                    $norm['success'] === 'passed' ? 1 : 0,
                    $sessionSeconds,
                    $responseTotalSeconds,
                    $location,
                    $suspend,
                    $progress !== null && $progress !== '' ? (float)$progress : null,
                    $reportedProgress,
                    $progressSource,
                    $progressCalcAt,
                    $entry,
                    $mode,
                    $credit,
                    $exitVal,
                    $completionThreshold !== null && $completionThreshold !== '' ? (float)$completionThreshold : null,
                    $scaledPassingScore !== null && $scaledPassingScore !== '' ? (float)$scaledPassingScore : null,
                    $norm['completion'],
                    $norm['success'],
                    $norm['source'],
                    $norm['state'],
                    $requestId,
                    $browserInfo,
                    $isComplete,
                    $now,
                    $now,
                ]);
                $attemptId = (int)$pdo->lastInsertId();
                $inserted = true;
            } catch (PDOException $insertErr) {
                $isDuplicate = ($insertErr->getCode() == 23000 || $insertErr->getCode() == 1062);
                if ($isDuplicate && $retries < 4) {
                    $retries++;
                    continue;
                }
                throw $insertErr;
            }
        }
        if (!$inserted) {
            throw new RuntimeException('Could not allocate a unique attempt number after retries.');
        }
    }

    else {
        $prevTotal = 0;
        $totalStmt = $pdo->prepare(
            "SELECT total_time_seconds, lesson_status, completion_status, success_status,
                    score_raw, score_scaled, lesson_location, progress_measure, attempt_state
             FROM scorm_attempts WHERE id = ? FOR UPDATE"
        );
        $totalStmt->execute([$attemptId]);
        if ($row = $totalStmt->fetch(PDO::FETCH_ASSOC)) {
            $prevTotal = (int)$row['total_time_seconds'];
            $priorState = $row;
        }
        // Snapshot resume state BEFORE this request's (possibly empty) values
        // overwrite the stored suspend_data/lesson_location on first Initialize.
        $resumeState = scormStoreBuildResume($pdo, $attemptId, $version, $edition);
        // Accumulate incremental delta (no double-counting — RTE sends deltas).
        $responseTotalSeconds = $prevTotal + $sessionSeconds;

        // Preserve stored resume state on a values-less Initialize. The RTE's
        // first request on resume carries no scalars (nothing SetValue'd yet);
        // applying them blindly would wipe suspend_data / lesson_location /
        // progress / statuses from the attempt row.
        if ($priorState !== null) {
            if (!array_key_exists('cmi.suspend_data', $normalized)) {
                $suspend = (string)($priorState['suspend_data'] ?? $suspend);
            }
            if (!array_key_exists('cmi.core.lesson_location', $normalized)
                && !array_key_exists('cmi.location', $normalized)) {
                $location = (string)($priorState['lesson_location'] ?? $location);
            }
            if (!array_key_exists('cmi.core.entry', $normalized)
                && !array_key_exists('cmi.entry', $normalized)) {
                $entry = (string)($priorState['entry'] ?? $entry);
            }
            if (!array_key_exists('cmi.progress_measure', $normalized)
                && ($progress === null || $progress === '')
                && !empty($priorState['progress_measure'])) {
                $progress = $priorState['progress_measure'];
                $reportedProgress = null;
                $progressSource = (string)($priorState['progress_source'] ?? $progressSource);
            }
            if (!array_key_exists('cmi.core.score.raw', $normalized) && !array_key_exists('cmi.score.raw', $normalized)) {
                $scoreRaw = $priorState['score_raw'] ?? $scoreRaw;
            }
            if (!array_key_exists('cmi.score.scaled', $normalized)) {
                $scoreScaled = $priorState['score_scaled'] ?? $scoreScaled;
            }
            if (!array_key_exists('cmi.core.score.min', $normalized) && !array_key_exists('cmi.score.min', $normalized)) {
                $scoreMin = $priorState['score_min'] ?? $scoreMin;
            }
            if (!array_key_exists('cmi.core.score.max', $normalized) && !array_key_exists('cmi.score.max', $normalized)) {
                $scoreMax = $priorState['score_max'] ?? $scoreMax;
            }
            if (!array_key_exists('cmi.core.lesson_status', $normalized)
                && !array_key_exists('cmi.completion_status', $normalized)
                && !array_key_exists('cmi.success_status', $normalized)) {
                $rawLessonStatus = (string)($priorState['lesson_status'] ?? $rawLessonStatus);
                $rawCompletion = (string)($priorState['completion_status'] ?? $rawCompletion);
                $rawSuccess = (string)($priorState['success_status'] ?? $rawSuccess);
                $norm = [
                    'completion' => (string)($priorState['normalized_completion'] ?? $norm['completion']),
                    'success'    => (string)($priorState['normalized_success'] ?? $norm['success']),
                    'source'     => (string)($priorState['status_source'] ?? $norm['source']),
                    'state'      => (string)($priorState['attempt_state'] ?? $norm['state']),
                ];
                $isComplete = (int)($priorState['is_complete'] ?? $isComplete);
            }
        }

        $update = $pdo->prepare("UPDATE scorm_attempts SET
            lesson_status = ?,
            completion_status = ?,
            success_status = ?,
            score_raw = ?,
            score_scaled = ?,
            score_min = ?,
            score_max = ?,
            passed = ?,
            session_time_seconds = session_time_seconds + ?,
            total_time_seconds = ?,
            lesson_location = ?,
            suspend_data = ?,
            progress_measure = ?,
            reported_progress_measure = ?,
            progress_source = ?,
            progress_calculated_at = ?,
            entry = ?,
            mode = ?,
            credit = ?,
            `exit` = ?,
            completion_threshold = ?,
            scaled_passing_score = ?,
            normalized_completion = ?,
            normalized_success = ?,
            status_source = ?,
            attempt_state = ?,
            last_request_id = ?,
            is_complete = ?,
            completed_at = IF(? = 1, COALESCE(completed_at, ?), completed_at),
            last_accessed_at = ?
            WHERE id = ? AND user_id = ?");

        // is_complete is computed above (status-driven only); a terminating
        // unload/close is NOT completion.
        $update->execute([
            $rawLessonStatus !== '' ? $rawLessonStatus : 'not attempted',
            $rawCompletion,
            $rawSuccess,
            $scoreRaw !== null && $scoreRaw !== '' ? (float)$scoreRaw : null,
            $scoreScaled !== null && $scoreScaled !== '' ? (float)$scoreScaled : null,
            $scoreMin !== null && $scoreMin !== '' ? (float)$scoreMin : null,
            $scoreMax !== null && $scoreMax !== '' ? (float)$scoreMax : null,
            $norm['success'] === 'passed' ? 1 : 0,
            $sessionSeconds,
            $responseTotalSeconds,
            $location,
            $suspend,
            $progress !== null && $progress !== '' ? (float)$progress : null,
            $reportedProgress,
            $progressSource,
            $progressCalcAt,
            $entry,
            $mode,
            $credit,
            $exitVal,
            $completionThreshold !== null && $completionThreshold !== '' ? (float)$completionThreshold : null,
            $scaledPassingScore !== null && $scaledPassingScore !== '' ? (float)$scaledPassingScore : null,
            $norm['completion'],
            $norm['success'],
            $norm['source'],
            $norm['state'],
            $requestId,
            $isComplete,
            $isComplete,
            $now,
            $now,
            $attemptId,
            $userId,
        ]);
    }

    // ── Step 8: upsert interactions + objectives (no full delete) ──
    // Prefer the structured payload; fall back to flat cmi.interactions.n.*
    // values for older/custom RTE clients.
    $interactionData = [];
    if (!empty($data['interactions'])) {
        foreach ($data['interactions'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $idx = (int)($item['index'] ?? 0);
            $interactionData[$idx] = $item;
        }
    } else {
        foreach ($normalized as $el => $val) {
            if (preg_match('/^cmi\.interactions\.(\d+)\.(.*)$/', $el, $m)) {
                $idx = (int)$m[1];
                if (!isset($interactionData[$idx])) {
                    $interactionData[$idx] = [];
                }
                $interactionData[$idx][$m[2]] = $val;
            }
        }
    }

    if (!empty($interactionData)) {
        $insInter = $pdo->prepare("INSERT INTO scorm_interactions
            (attempt_id, user_id, interaction_index, interaction_id, interaction_type,
             learner_response, correct_responses, correct_response_ids, result, weighting,
             latency_seconds, description, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                interaction_id = VALUES(interaction_id),
                interaction_type = VALUES(interaction_type),
                learner_response = VALUES(learner_response),
                correct_responses = VALUES(correct_responses),
                correct_response_ids = VALUES(correct_response_ids),
                result = VALUES(result),
                weighting = VALUES(weighting),
                latency_seconds = VALUES(latency_seconds),
                description = VALUES(description),
                timestamp = VALUES(timestamp)");

        $linkRows = []; // [interaction_index => [objective_index => objective_id]]
        foreach ($interactionData as $idx => $fields) {
            $latencyRaw = $fields['latency'] ?? '';
            $latencySec = $latencyRaw !== '' && $latencyRaw !== null ? scormDurationToSeconds((string)$latencyRaw) : null;

            // Correct responses: structured [{id, pattern}] or flat patterns.
            $correctPatterns = [];
            $correctIds = [];
            if (isset($fields['correct_responses']) && is_array($fields['correct_responses'])) {
                foreach ($fields['correct_responses'] as $cr) {
                    if (is_array($cr)) {
                        if (isset($cr['pattern'])) {
                            $correctPatterns[] = (string)$cr['pattern'];
                        }
                        if (isset($cr['id']) && $cr['id'] !== '') {
                            $correctIds[] = (string)$cr['id'];
                        }
                    }
                }
            }
            foreach ($fields as $f => $v) {
                if (preg_match('/^correct_responses\.(\d+)\.pattern$/', $f)) {
                    $correctPatterns[] = (string)$v;
                }
                if (preg_match('/^correct_responses\.(\d+)\.id$/', $f)) {
                    $correctIds[] = (string)$v;
                }
            }
            $correctResponses = !empty($correctPatterns) ? json_encode(array_values(array_unique($correctPatterns))) : null;
            $correctResponseIds = !empty($correctIds) ? json_encode(array_values(array_unique($correctIds))) : null;

            // Interaction → objective links (cmi.interactions.n.objectives.m.id)
            $objectives = $fields['objectives'] ?? null;
            if (is_array($objectives)) {
                $objIndex = 0;
                foreach ($objectives as $objLink) {
                    $oid = '';
                    if (is_array($objLink)) {
                        $oid = (string)($objLink['id'] ?? '');
                    } else {
                        $oid = (string)$objLink;
                    }
                    if ($oid !== '') {
                        $linkRows[$idx][$objIndex] = $oid;
                    }
                    $objIndex++;
                }
            }

            $insInter->execute([
                $attemptId,
                $userId,
                $idx,
                $fields['id'] ?? '',
                $fields['type'] ?? '',
                isset($fields['learner_response']) ? (is_array($fields['learner_response']) ? json_encode($fields['learner_response']) : (string)$fields['learner_response']) : null,
                $correctResponses,
                $correctResponseIds,
                $fields['result'] ?? '',
                isset($fields['weighting']) && $fields['weighting'] !== '' && $fields['weighting'] !== null ? (float)$fields['weighting'] : null,
                $latencySec,
                $fields['description'] ?? null,
                !empty($fields['timestamp']) ? date('Y-m-d H:i:s', strtotime((string)$fields['timestamp'])) : null,
            ]);
        }

        // ── Step 9a: interaction-objective links ──
        if (!empty($linkRows)) {
            $insLink = $pdo->prepare("INSERT INTO scorm_interaction_objectives
                (attempt_id, interaction_index, objective_index, objective_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE objective_id = VALUES(objective_id)");
            foreach ($linkRows as $iIdx => $objMap) {
                foreach ($objMap as $oIdx => $oid) {
                    $insLink->execute([$attemptId, $iIdx, $oIdx, $oid]);
                }
            }
        }
    }

    // ── Objectives: structured payload or flat cmi.objectives.n.* values ──
    $objectiveData = [];
    if (!empty($data['objectives'])) {
        foreach ($data['objectives'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $idx = (int)($item['index'] ?? 0);
            $objectiveData[$idx] = $item;
        }
    } else {
        foreach ($normalized as $el => $val) {
            if (preg_match('/^cmi\.objectives\.(\d+)\.(.*)$/', $el, $m)) {
                $idx = (int)$m[1];
                if (!isset($objectiveData[$idx])) {
                    $objectiveData[$idx] = [];
                }
                $objectiveData[$idx][$m[2]] = $val;
            }
        }
    }

    if (!empty($objectiveData)) {
        $insObj = $pdo->prepare("INSERT INTO scorm_objectives
            (attempt_id, user_id, objective_index, objective_id,
             score_raw, score_scaled, score_min, score_max,
             completion_status, success_status, progress_measure, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                objective_id = VALUES(objective_id),
                score_raw = VALUES(score_raw),
                score_scaled = VALUES(score_scaled),
                score_min = VALUES(score_min),
                score_max = VALUES(score_max),
                completion_status = VALUES(completion_status),
                success_status = VALUES(success_status),
                progress_measure = VALUES(progress_measure),
                description = VALUES(description)");

        foreach ($objectiveData as $idx => $fields) {
            $oScore = $fields['score'] ?? null;
            $scoreRawV = null;
            $scoreScaledV = null;
            $scoreMinV = null;
            $scoreMaxV = null;
            if (is_array($oScore)) {
                $scoreRawV = $oScore['raw'] ?? null;
                $scoreScaledV = $oScore['scaled'] ?? null;
                $scoreMinV = $oScore['min'] ?? null;
                $scoreMaxV = $oScore['max'] ?? null;
            } else {
                $scoreRawV = $fields['score.raw'] ?? null;
                $scoreScaledV = $fields['score.scaled'] ?? null;
                $scoreMinV = $fields['score.min'] ?? null;
                $scoreMaxV = $fields['score.max'] ?? null;
            }

            $insObj->execute([
                $attemptId,
                $userId,
                $idx,
                $fields['id'] ?? '',
                $scoreRawV !== null && $scoreRawV !== '' ? (float)$scoreRawV : null,
                $scoreScaledV !== null && $scoreScaledV !== '' ? (float)$scoreScaledV : null,
                $scoreMinV !== null && $scoreMinV !== '' ? (float)$scoreMinV : null,
                $scoreMaxV !== null && $scoreMaxV !== '' ? (float)$scoreMaxV : null,
                $fields['completion_status'] ?? '',
                $fields['success_status'] ?? '',
                isset($fields['progress_measure']) && $fields['progress_measure'] !== '' ? (float)$fields['progress_measure'] : null,
                $fields['description'] ?? null,
            ]);
        }
    }

    // ── Step 9b: learner comments (SCORM 2004) ──
    if (!empty($data['comments'])) {
        $insComment = $pdo->prepare("INSERT INTO scorm_comments_from_learner
            (attempt_id, user_id, comment_index, comment_text, location, timestamp)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                comment_text = VALUES(comment_text),
                location = VALUES(location),
                timestamp = VALUES(timestamp)");
        foreach ($data['comments'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $idx = (int)($item['index'] ?? 0);
            $insComment->execute([
                $attemptId,
                $userId,
                $idx,
                isset($item['comment']) ? mb_substr((string)$item['comment'], 0, 4000) : '',
                mb_substr((string)($item['location'] ?? ''), 0, 500),
                !empty($item['timestamp']) ? date('Y-m-d H:i:s', strtotime((string)$item['timestamp'])) : null,
            ]);
        }
    }

    // ── Step 10: bounded audit event (request_id + changed-field metadata) ──
    $changedFields = [];
    if ($priorState) {
        $candidates = [
            'lesson_status'     => $rawLessonStatus !== '' ? $rawLessonStatus : 'not attempted',
            'completion_status' => $rawCompletion,
            'success_status'    => $rawSuccess,
            'score_raw'         => $scoreRaw,
            'score_scaled'      => $scoreScaled,
            'lesson_location'   => $location,
            'progress_measure'  => $progress,
        ];
        foreach ($candidates as $k => $v) {
            if ((string)($priorState[$k] ?? '') !== (string)($v ?? '')) {
                $changedFields[] = $k;
            }
        }
        if (($priorState['attempt_state'] ?? '') !== $norm['state']) {
            $changedFields[] = 'attempt_state';
        }
    } else {
        $changedFields = ['lesson_status', 'completion_status', 'success_status', 'score_raw', 'score_scaled', 'lesson_location', 'progress_measure', 'attempt_state'];
    }
    $changedFields = array_slice(array_values(array_unique($changedFields)), 0, 100);

    // Bounded new_value: scalar state only, capped at 8 KB.
    $scalarSnapshot = [
        'lesson_status' => $rawLessonStatus,
        'completion_status' => $rawCompletion,
        'success_status' => $rawSuccess,
        'score_raw' => $scoreRaw,
        'score_scaled' => $scoreScaled,
        'location' => $location,
        'progress_measure' => $progress,
        'attempt_state' => $norm['state'],
    ];
    $eventValue = json_encode($scalarSnapshot);
    if (strlen($eventValue) > 8000) {
        $eventValue = substr($eventValue, 0, 8000);
    }

    $eventType = $terminating ? 'terminate' : 'commit';
    $insEvent = $pdo->prepare("INSERT INTO scorm_events
        (attempt_id, user_id, event_type, data_element, old_value, new_value, slide_id, request_id, changed_fields)
        VALUES (?, ?, ?, ?, '', ?, ?, ?, ?)");
    $insEvent->execute([
        $attemptId,
        $userId,
        $eventType,
        'full_state',
        $eventValue,
        $location,
        $requestId,
        json_encode($changedFields),
    ]);

    // ── Step 11: commit ──
    $pdo->commit();
    $committed = true;

    // ── Step 12: complete resume state + attempt id ──
    // For an existing attempt, prefer the pre-update snapshot (real stored
    // state); for a new attempt, build from what was just inserted (empty).
    $resume = $resumeState !== null
        ? $resumeState
        : scormStoreBuildResume($pdo, $attemptId, $version, $edition);

    $response = [
        'ok'         => true,
        'attempt_id' => $attemptId,
        'initial'    => $resume,
        'edition'    => $edition,
        // Fresh serve token when the RTE's token was near expiry (long courses).
        'refresh_token' => $refreshToken,
        // Reported vs derived progress (P3). estimated/confidence/parser stay
        // null until a validated package adapter exists (spec Phase 3).
        'progress'   => [
            'reported'        => $reportedProgress,
            'estimated'       => null,
            'display_percent' => $reportedProgress !== null ? (int)round($reportedProgress * 100) : null,
            'source'          => $progressSource,
            'confidence'      => null,
            'parser_version'  => '',
            'position'        => $location !== '' ? $location : null,
        ],
        'completion_status' => $rawCompletion,
        'success_status'    => $rawSuccess,
        'saved'      => [
            'status'           => $rawLessonStatus ?: ($rawCompletion ?: $rawSuccess),
            'normalized_state' => $norm['state'],
            'passed'           => $norm['success'] === 'passed' ? 1 : 0,
            'score_raw'        => $scoreRaw,
            'total_seconds'    => $responseTotalSeconds,
        ],
    ];

    // Persist the idempotent response for exact-once replay of retries.
    if ($requestId !== '') {
        try {
            $respJson = json_encode($response);
            $upd = $pdo->prepare(
                "UPDATE scorm_request_idempotency SET attempt_id = ?, response = ? WHERE request_id = ? AND user_id = ?"
            );
            $upd->execute([$attemptId, $respJson, $requestId, $userId]);
        } catch (Throwable $e) {
            error_log('[SCORM-STORE] idempotency response write failed: ' . $e->getMessage());
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
    }
    if (!$committed && $requestId !== '') {
        // Claim never produced a durable result — free it so the client can retry.
        try {
            $pdo->prepare("DELETE FROM scorm_request_idempotency WHERE request_id = ? AND user_id = ? AND response IS NULL")
                ->execute([$requestId, $userId]);
        } catch (Throwable $_) {}
    }
    error_log('[SCORM-STORE] persist failed: ' . $e->getMessage());
    if ($committed) {
        // Data is persisted; never report partial-success as failure.
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'attempt_id' => $attemptId, 'error_note' => 'Committed, resume build degraded.']);
        exit;
    }
    scormStoreFail(500, 'Failed to persist SCORM data.', true);
}
/**
 * Build the full resume state for the RTE: scalars, interactions (with
 * correct-response ids and objective links), objectives, and comments.
 */
function scormStoreBuildResume(PDO $pdo, int $attemptId, string $version, string $edition): array
{
    $is2004 = $version === '2004';
    $values = [];

    $stmt = $pdo->prepare(
        "SELECT lesson_location, suspend_data, score_raw, score_scaled,
                lesson_status, completion_status, success_status, progress_measure
         FROM scorm_attempts WHERE id = ?"
    );
    $stmt->execute([$attemptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $map = [
            ($is2004 ? 'cmi.location' : 'cmi.core.lesson_location') => $row['lesson_location'],
            'cmi.suspend_data' => $row['suspend_data'],
            ($is2004 ? 'cmi.score.raw' : 'cmi.core.score.raw') => $row['score_raw'],
            ($is2004 ? 'cmi.score.scaled' : '') => $row['score_scaled'],
            ($is2004 ? 'cmi.completion_status' : 'cmi.core.lesson_status') => $is2004 ? ($row['completion_status'] ?: $row['lesson_status']) : $row['lesson_status'],
            ($is2004 ? 'cmi.success_status' : '') => $row['success_status'],
            ($is2004 ? 'cmi.progress_measure' : '') => $row['progress_measure'],
        ];
        foreach ($map as $el => $val) {
            if ($el !== '' && $val !== null && $val !== '') {
                $values[] = ['element' => $el, 'value' => (string)$val];
            }
        }
    }

    $interactions = [];
    $iStmt = $pdo->prepare(
        "SELECT interaction_index, interaction_id, interaction_type, learner_response,
                correct_responses, correct_response_ids, result, weighting, latency_seconds,
                description, timestamp
         FROM scorm_interactions WHERE attempt_id = ? ORDER BY interaction_index"
    );
    $iStmt->execute([$attemptId]);
    foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $patterns = [];
        if ($r['correct_responses']) {
            $decoded = json_decode((string)$r['correct_responses'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    $patterns[] = (string)$p;
                }
            }
        }
        $ids = [];
        if ($r['correct_response_ids']) {
            $decoded = json_decode((string)$r['correct_response_ids'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    $ids[] = (string)$id;
                }
            }
        }
        $correctResponses = [];
        $count = max(count($patterns), count($ids));
        for ($i = 0; $i < $count; $i++) {
            $correctResponses[] = [
                'id'      => $ids[$i] ?? '',
                'pattern' => $patterns[$i] ?? '',
            ];
        }

        $objectives = [];
        $oStmt = $pdo->prepare(
            "SELECT objective_index, objective_id FROM scorm_interaction_objectives
             WHERE attempt_id = ? AND interaction_index = ? ORDER BY objective_index"
        );
        $oStmt->execute([$attemptId, (int)$r['interaction_index']]);
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $objectives[] = ['id' => $o['objective_id']];
        }

        $interactions[] = [
            'index'            => (int)$r['interaction_index'],
            'id'               => (string)$r['interaction_id'],
            'type'             => (string)$r['interaction_type'],
            'learner_response' => $r['learner_response'] !== null ? (string)$r['learner_response'] : '',
            'result'           => (string)$r['result'],
            'weighting'        => $r['weighting'] !== null ? (float)$r['weighting'] : null,
            'latency'          => $r['latency_seconds'] !== null ? scormSecondsToDuration((int)$r['latency_seconds']) : null,
            'timestamp'        => $r['timestamp'] !== null ? (string)$r['timestamp'] : null,
            'description'      => $r['description'] !== null ? (string)$r['description'] : '',
            'correct_responses'=> $correctResponses,
            'objectives'       => $objectives,
        ];
    }

    $objectives = [];
    $objStmt = $pdo->prepare(
        "SELECT objective_index, objective_id, score_raw, score_scaled, score_min, score_max,
                completion_status, success_status, progress_measure, description
         FROM scorm_objectives WHERE attempt_id = ? ORDER BY objective_index"
    );
    $objStmt->execute([$attemptId]);
    foreach ($objStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $objectives[] = [
            'index' => (int)$r['objective_index'],
            'id'    => (string)$r['objective_id'],
            'score' => [
                'scaled' => $r['score_scaled'] !== null ? (float)$r['score_scaled'] : null,
                'raw'    => $r['score_raw'] !== null ? (float)$r['score_raw'] : null,
                'min'    => $r['score_min'] !== null ? (float)$r['score_min'] : null,
                'max'    => $r['score_max'] !== null ? (float)$r['score_max'] : null,
            ],
            'completion_status' => (string)$r['completion_status'],
            'success_status'    => (string)$r['success_status'],
            'progress_measure'  => $r['progress_measure'] !== null ? (float)$r['progress_measure'] : null,
            'description'       => $r['description'] !== null ? (string)$r['description'] : '',
        ];
    }

    $comments = [];
    $cStmt = $pdo->prepare(
        "SELECT comment_index, comment_text, location, timestamp
         FROM scorm_comments_from_learner WHERE attempt_id = ? ORDER BY comment_index"
    );
    $cStmt->execute([$attemptId]);
    foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $comments[] = [
            'index'     => (int)$r['comment_index'],
            'comment'   => (string)$r['comment_text'],
            'location'  => (string)$r['location'],
            'timestamp' => $r['timestamp'] !== null ? (string)$r['timestamp'] : null,
        ];
    }

    return [
        'values'       => $values,
        'interactions' => $interactions,
        'objectives'   => $objectives,
        'comments'     => $comments,
    ];
}
