<?php
/**
 * PURSUIT PATHWAYS LMS
 * NATIVE SCORM READER — Tracking Receiver (Phase 2)
 *
 * Receives persist calls from scorm-rte.js (running inside SCORM content)
 * and upserts tracking data into the analytics tables:
 *
 *   scorm_attempts     — one row per (user, sco, attempt)
 *   scorm_interactions — question-level answers
 *   scorm_objectives   — objective-level outcomes
 *   scorm_events       — fine-grained audit log
 *
 * Request body (JSON, POST):
 *   {
 *     "pkg":             scorm_packages.id,
 *     "sco":             sco_items.id (0 if unknown),
 *     "version":         "1.2" | "2004",
 *     "attempt":         existing attempt id (from prior response),
 *     "terminating":     bool,
 *     "session_delta_ms": incremental ms since previous persist,
 *     "values":          { "cmi.core.lesson_status": "completed", ... }
 *   }
 *
 * Response (JSON):
 *   { "ok": true, "attempt_id": <int>, "initial": { "values": [...] } }
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../bootstrap.php';
ensureScormTables();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Authenticate: session cookie OR stateless serve token ──
// The SCORM content runs inside an iframe; SameSite=Lax session cookies are
// not sent on requests originating there, so tracking POSTs often arrive with
// no session. Accept the short-lived HMAC serve token (`t=`) that serve.php
// injects into SCORM_PACKAGE_CONFIG (mirrors serve.php's own auth path).
if (!isset($_SESSION['user_id'])) {
    $serveToken = trim((string)($_GET['t'] ?? ''));
    $servePkgId = (int)($data['pkg'] ?? 0);
    if ($serveToken !== '' && $servePkgId > 0) {
        $tokenUserId = validateServeToken($serveToken, $servePkgId);
        if ($tokenUserId !== null) {
            $_SESSION['user_id'] = $tokenUserId;
            try {
                $tokPdo  = getDbConnection();
                $tokStmt = $tokPdo->prepare('SELECT role, organization_id FROM users WHERE id = ? LIMIT 1');
                $tokStmt->execute([$tokenUserId]);
                $tokUser = $tokStmt->fetch(PDO::FETCH_ASSOC);
                if ($tokUser) {
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

$pdo = getDbConnection();
$userId = (int)$_SESSION['user_id'];
$orgId = getOrgId();
$packageId = (int)($data['pkg'] ?? 0);
$scoId = (int)($data['sco'] ?? 0);
$version = ($data['version'] ?? '1.2') === '2004' ? '2004' : '1.2';
$terminating = !empty($data['terminating']);
// Incremental ms since previous persist (sent by RTE)
$sessionDeltaMs = (int)($data['session_delta_ms'] ?? ($data['session_ms'] ?? 0));
$values = $data['values'] ?? [];

if (!is_array($values)) {
    $values = [];
}

if ($packageId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing pkg.']);
    exit;
}

// ── Verify package access (active, org-scoped, or enrolled) ──
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

$pkgStmt = $pdo->prepare("SELECT id FROM scorm_packages sp WHERE sp.id = ? AND sp.status = 'active'" . $orgFilter);
$pkgStmt->execute($params);
if (!$pkgStmt->fetch()) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Package not found or inactive.']);
    exit;
}

// ── Resolve SCO ──
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

// ── Resolve user details (org + department snapshot) ──
$userStmt = $pdo->prepare("SELECT first_name, last_name, department, organization_id FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
$department = $userRow['department'] ?? null;
$userOrgId = $userRow['organization_id'] ?? null;
if ($userOrgId === null) {
    $userOrgId = $orgId !== null ? $orgId : null;
}

// ── Normalize values (case-insensitive) ──
$normalized = [];
foreach ($values as $el => $val) {
    $key = strtolower(trim((string)$el));
    $normalized[$key] = (string)$val;
}

// ── Extract statuses / scores ──
$lessonStatus  = $normalized['cmi.core.lesson_status'] ?? ($normalized['cmi.completion_status'] ?? ($normalized['cmi.success_status'] ?? ''));
$completion    = $normalized['cmi.completion_status'] ?? '';
$successStatus = $normalized['cmi.success_status'] ?? '';
$scoreRaw      = $normalized['cmi.core.score.raw'] ?? ($normalized['cmi.score.raw'] ?? null);
$scoreScaled   = $normalized['cmi.score.scaled'] ?? null;
$scoreMin      = $normalized['cmi.core.score.min'] ?? ($normalized['cmi.score.min'] ?? null);
$scoreMax      = $normalized['cmi.core.score.max'] ?? ($normalized['cmi.score.max'] ?? null);
$location      = $normalized['cmi.core.lesson_location'] ?? ($normalized['cmi.location'] ?? '');
$suspend       = $normalized['cmi.suspend_data'] ?? '';
$sessionTimeRaw= $normalized['cmi.core.session_time'] ?? ($normalized['cmi.session_time'] ?? '');
$progress      = $normalized['cmi.progress_measure'] ?? null;
$entry         = $normalized['cmi.core.entry'] ?? ($normalized['cmi.entry'] ?? 'ab-initio');
$exitVal       = $normalized['cmi.core.exit'] ?? ($normalized['cmi.exit'] ?? '');

/**
 * Convert SCORM duration (PT1H30M45S or HH:MM:SS) to seconds.
 */
function scormDurationToSeconds(string $duration): int
{
    if (empty($duration)) return 0;
    $seconds = 0;
    if (preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $m)) {
        if (!empty($m[1])) $seconds += (int)$m[1] * 86400;
        if (!empty($m[2])) $seconds += (int)$m[2] * 3600;
        if (!empty($m[3])) $seconds += (int)$m[3] * 60;
        if (!empty($m[4])) $seconds += (int)$m[4];
    } elseif (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $duration, $m)) {
        $seconds = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
    }
    return $seconds;
}

$sessionSeconds = $sessionDeltaMs > 0
    ? (int)floor($sessionDeltaMs / 1000)
    : scormDurationToSeconds($sessionTimeRaw);

// ── Determine passed status ──
$passed = 0;
if (in_array($lessonStatus, ['passed'], true)) {
    $passed = 1;
} elseif (in_array($completion, ['completed'], true)) {
    $mastery = ($sco['mastery_score'] ?? null);
    if ($scoreRaw !== null) {
        if ($mastery !== null && (float)$scoreRaw >= (float)$mastery) {
            $passed = 1;
        } elseif ($mastery === null && (float)$scoreRaw >= 80) {
            $passed = 1; // sensible default when no mastery defined
        }
    }
} elseif (in_array($successStatus, ['passed'], true)) {
    $passed = 1;
}

// ── Completion logic ──
$isComplete = $terminating ? 1 : 0;
if (in_array($lessonStatus, ['completed', 'passed'], true)) $isComplete = 1;
if (in_array($completion, ['completed'], true)) $isComplete = 1;
if (in_array($successStatus, ['passed', 'failed'], true)) $isComplete = 1;

$now = date('Y-m-d H:i:s');

// ── Load existing attempt or create a new one ──
$attemptId = (int)($data['attempt'] ?? 0);
if ($attemptId > 0) {
    $check = $pdo->prepare("SELECT id FROM scorm_attempts WHERE id = ? AND user_id = ?");
    $check->execute([$attemptId, $userId]);
    if (!$check->fetch()) {
        $attemptId = 0;
    }
}

$isNewAttempt = ($attemptId === 0);
$responseTotalSeconds = $sessionSeconds;

if ($isNewAttempt) {
    // Next attempt number for (user, package [+ sco])
    $attemptNumSql = "SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM scorm_attempts WHERE user_id = ? AND package_id = ?";
    $attemptNumParams = [$userId, $packageId];
    if ($scoId > 0) {
        $attemptNumSql .= " AND sco_item_id = ?";
        $attemptNumParams[] = $scoId;
    }
    $numStmt = $pdo->prepare($attemptNumSql);
    $numStmt->execute($attemptNumParams);
    $attemptNumber = (int)$numStmt->fetchColumn();

    // Total time carried from previous attempts (same package/SCO)
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

    try {
        $insert = $pdo->prepare("INSERT INTO scorm_attempts
            (user_id, organization_id, department, sco_item_id, package_id, attempt_number,
             lesson_status, completion_status, success_status,
             score_raw, score_scaled, score_min, score_max,
             mastery_score, passed, session_time_seconds, total_time_seconds,
             lesson_location, suspend_data, progress_measure, entry, `exit`,
             browser_info, is_complete, started_at, last_accessed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");

        $insert->execute([
            $userId,
            $userOrgId,
            $department,
            $scoId > 0 ? $scoId : null,
            $packageId,
            $attemptNumber,
            $lessonStatus ?: 'not attempted',
            $completion,
            $successStatus,
            $scoreRaw !== null && $scoreRaw !== '' ? (float)$scoreRaw : null,
            $scoreScaled !== null && $scoreScaled !== '' ? (float)$scoreScaled : null,
            $scoreMin !== null && $scoreMin !== '' ? (float)$scoreMin : null,
            $scoreMax !== null && $scoreMax !== '' ? (float)$scoreMax : null,
            $sco['mastery_score'] ?? null,
            $passed,
            $sessionSeconds,
            $responseTotalSeconds,
            $location,
            $suspend,
            $progress !== null && $progress !== '' ? (float)$progress : null,
            $entry,
            $exitVal,
            $browserInfo,
            $now,
            $now,
        ]);

        $attemptId = (int)$pdo->lastInsertId();
    } catch (PDOException $insertErr) {
        error_log('[SCORM-STORE] INSERT failed: ' . $insertErr->getMessage() . ' userId=' . $userId . ' pkg=' . $packageId . ' sco=' . $scoId);
        // If the INSERT failed (e.g., FK violation for sco_item_id), try
        // inserting with sco_item_id = NULL so the attempt is still recorded.
        try {
            $fallbackInsert = $pdo->prepare("INSERT INTO scorm_attempts
                (user_id, organization_id, department, sco_item_id, package_id, attempt_number,
                 lesson_status, completion_status, success_status,
                 score_raw, score_scaled, score_min, score_max,
                 mastery_score, passed, session_time_seconds, total_time_seconds,
                 lesson_location, suspend_data, progress_measure, entry, `exit`,
                 browser_info, is_complete, started_at, last_accessed_at)
    VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
            $fallbackInsert->execute([
                $userId, $userOrgId, $department, $packageId, $attemptNumber,
                $lessonStatus ?: 'not attempted', $completion, $successStatus,
                $scoreRaw !== null && $scoreRaw !== '' ? (float)$scoreRaw : null,
                $scoreScaled !== null && $scoreScaled !== '' ? (float)$scoreScaled : null,
                $scoreMin !== null && $scoreMin !== '' ? (float)$scoreMin : null,
                $scoreMax !== null && $scoreMax !== '' ? (float)$scoreMax : null,
                $sco['mastery_score'] ?? null,
                $passed, $sessionSeconds, $responseTotalSeconds,
                $location, $suspend,
                $progress !== null && $progress !== '' ? (float)$progress : null,
                $entry, $exitVal, $browserInfo, $now, $now,
            ]);
            $attemptId = (int)$pdo->lastInsertId();
        } catch (PDOException $fallbackErr) {
            error_log('[SCORM-STORE] Fallback INSERT also failed: ' . $fallbackErr->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Failed to create attempt record.']);
            exit;
        }
    }
} else {
    // ── Update existing attempt ──
    $prevTotal = 0;
    $totalStmt = $pdo->prepare("SELECT total_time_seconds FROM scorm_attempts WHERE id = ?");
    $totalStmt->execute([$attemptId]);
    if ($row = $totalStmt->fetch(PDO::FETCH_ASSOC)) {
        $prevTotal = (int)$row['total_time_seconds'];
    }

    // Accumulate incremental delta (no double-counting — RTE sends deltas)
    $responseTotalSeconds = $prevTotal + $sessionSeconds;

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
        entry = ?,
        `exit` = ?,
        is_complete = ?,
        completed_at = IF(? = 1, COALESCE(completed_at, ?), completed_at),
        last_accessed_at = ?
        WHERE id = ? AND user_id = ?");

    $update->execute([
        $lessonStatus ?: 'not attempted',
        $completion,
        $successStatus,
        $scoreRaw !== null && $scoreRaw !== '' ? (float)$scoreRaw : null,
        $scoreScaled !== null && $scoreScaled !== '' ? (float)$scoreScaled : null,
        $scoreMin !== null && $scoreMin !== '' ? (float)$scoreMin : null,
        $scoreMax !== null && $scoreMax !== '' ? (float)$scoreMax : null,
        $passed,
        $sessionSeconds,
        $responseTotalSeconds,
        $location,
        $suspend,
        $progress !== null && $progress !== '' ? (float)$progress : null,
        $entry,
        $exitVal,
        $isComplete,
        $isComplete,
        $now,
        $now,
        $attemptId,
        $userId,
    ]);
}

// ── Sync interactions ──
$interactionData = [];
foreach ($normalized as $el => $val) {
    if (preg_match('/^cmi\.interactions\.(\d+)\.(.*)$/', $el, $m)) {
        $idx = (int)$m[1];
        $field = $m[2];
        if (!isset($interactionData[$idx])) {
            $interactionData[$idx] = [];
        }
        $interactionData[$idx][$field] = $val;
    }
}

if (!empty($interactionData)) {
    $pdo->prepare("DELETE FROM scorm_interactions WHERE attempt_id = ?")->execute([$attemptId]);

    $insInter = $pdo->prepare("INSERT INTO scorm_interactions
        (attempt_id, user_id, interaction_index, interaction_id, interaction_type,
         learner_response, correct_responses, result, weighting, latency_seconds,
         description, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($interactionData as $idx => $fields) {
        $latencyRaw = $fields['latency'] ?? '';
        $latencySec = $latencyRaw ? scormDurationToSeconds($latencyRaw) : null;

        $correctList = [];
        foreach ($fields as $f => $v) {
            if (preg_match('/^correct_responses\.\d+\.pattern$/', $f)) {
                $correctList[] = $v;
            }
        }
        $correctResponses = !empty($correctList) ? json_encode($correctList) : null;

        $insInter->execute([
            $attemptId,
            $userId,
            $idx,
            $fields['id'] ?? '',
            $fields['type'] ?? '',
            $fields['learner_response'] ?? null,
            $correctResponses,
            $fields['result'] ?? '',
            $fields['weighting'] !== '' && $fields['weighting'] !== null ? (float)$fields['weighting'] : null,
            $latencySec,
            $fields['description'] ?? null,
            !empty($fields['timestamp']) ? date('Y-m-d H:i:s', strtotime($fields['timestamp'])) : null,
        ]);
    }
}

// ── Sync objectives ──
$objectiveData = [];
foreach ($normalized as $el => $val) {
    if (preg_match('/^cmi\.objectives\.(\d+)\.(.*)$/', $el, $m)) {
        $idx = (int)$m[1];
        $field = $m[2];
        if (!isset($objectiveData[$idx])) {
            $objectiveData[$idx] = [];
        }
        $objectiveData[$idx][$field] = $val;
    }
}

if (!empty($objectiveData)) {
    $pdo->prepare("DELETE FROM scorm_objectives WHERE attempt_id = ?")->execute([$attemptId]);

    $insObj = $pdo->prepare("INSERT INTO scorm_objectives
        (attempt_id, user_id, objective_index, objective_id,
         score_raw, score_scaled, score_min, score_max,
         completion_status, success_status, progress_measure, description)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($objectiveData as $idx => $fields) {
        $insObj->execute([
            $attemptId,
            $userId,
            $idx,
            $fields['id'] ?? '',
            isset($fields['score.raw']) && $fields['score.raw'] !== '' ? (float)$fields['score.raw'] : null,
            isset($fields['score.scaled']) && $fields['score.scaled'] !== '' ? (float)$fields['score.scaled'] : null,
            isset($fields['score.min']) && $fields['score.min'] !== '' ? (float)$fields['score.min'] : null,
            isset($fields['score.max']) && $fields['score.max'] !== '' ? (float)$fields['score.max'] : null,
            $fields['completion_status'] ?? '',
            $fields['success_status'] ?? '',
            isset($fields['progress_measure']) && $fields['progress_measure'] !== '' ? (float)$fields['progress_measure'] : null,
            $fields['description'] ?? null,
        ]);
    }
}

// ── Log audit event ──
$eventType = $terminating ? 'terminate' : 'commit';
$insEvent = $pdo->prepare("INSERT INTO scorm_events (attempt_id, user_id, event_type, data_element, old_value, new_value, slide_id)
                           VALUES (?, ?, ?, ?, '', ?, ?)");
$insEvent->execute([
    $attemptId,
    $userId,
    $eventType,
    'full_state',
    json_encode($normalized),
    $location,
]);

// ── Build resume data for the RTE ──
function buildInitialState(PDO $pdo, int $attemptId, string $version): ?array
{
    $stmt = $pdo->prepare("SELECT lesson_location, suspend_data, score_raw, score_scaled,
                                  lesson_status, completion_status, success_status, progress_measure
                           FROM scorm_attempts WHERE id = ?");
    $stmt->execute([$attemptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $initial = ['values' => []];
    $is2004 = $version === '2004';
    $map = [
        ($is2004 ? 'cmi.location' : 'cmi.core.lesson_location') => $row['lesson_location'],
        'cmi.suspend_data' => $row['suspend_data'],
        ($is2004 ? 'cmi.score.raw' : 'cmi.core.score.raw') => $row['score_raw'],
        ($is2004 ? 'cmi.score.scaled' : '') => $row['score_scaled'],
        ($is2004 ? 'cmi.completion_status' : 'cmi.core.lesson_status') => $row['completion_status'] ?: $row['lesson_status'],
        ($is2004 ? 'cmi.success_status' : '') => $row['success_status'],
        ($is2004 ? 'cmi.progress_measure' : '') => $row['progress_measure'],
    ];
    foreach ($map as $el => $val) {
        if ($el !== '' && $val !== null && $val !== '') {
            $initial['values'][] = ['element' => $el, 'value' => (string)$val];
        }
    }
    return $initial;
}

$initial = null;
if (!$isNewAttempt) {
    // Existing attempt: return stored values so the SCO can resume
    $initial = buildInitialState($pdo, $attemptId, $version);
} else {
    // New attempt: always attempt to resume from the most recent incomplete
    // previous attempt so returning users pick up where they left off.
    if ($scoId > 0) {
        $prevStmt = $pdo->prepare("SELECT id FROM scorm_attempts WHERE user_id = ? AND package_id = ? AND sco_item_id = ? AND is_complete = 0 AND id <> ? ORDER BY id DESC LIMIT 1");
        $prevStmt->execute([$userId, $packageId, $scoId, $attemptId]);
    } else {
        $prevStmt = $pdo->prepare("SELECT id FROM scorm_attempts WHERE user_id = ? AND package_id = ? AND is_complete = 0 AND id <> ? ORDER BY id DESC LIMIT 1");
        $prevStmt->execute([$userId, $packageId, $attemptId]);
    }
    $prevId = (int)$prevStmt->fetchColumn();
    if ($prevId > 0) {
        $initial = buildInitialState($pdo, $prevId, $version);
        // Force resume entry so Rise 360 / Storyline restore the saved state
        // (lesson_location + suspend_data) instead of starting fresh.
        if ($initial && isset($initial['values']) && is_array($initial['values'])) {
            $entryElement = $version === '2004' ? 'cmi.entry' : 'cmi.core.entry';
            $entryFound = false;
            foreach ($initial['values'] as &$iv) {
                if (isset($iv['element']) && strtolower($iv['element']) === strtolower($entryElement)) {
                    $iv['value'] = 'resume';
                    $entryFound = true;
                    break;
                }
            }
            unset($iv);
            if (!$entryFound) {
                $initial['values'][] = ['element' => $entryElement, 'value' => 'resume'];
            }
        }
    }
}

// ── Response ──
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'attempt_id' => $attemptId,
    'initial' => $initial,
    'saved' => [
        'status' => $lessonStatus ?: ($completion ?: $successStatus),
        'passed' => $passed,
        'score_raw' => $scoreRaw,
        'total_seconds' => $responseTotalSeconds,
    ],
]);