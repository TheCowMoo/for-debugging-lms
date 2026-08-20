<?php
/**
 * API: SCORM Unenroll Endpoint (Keep User)
 *
 * Deletes only the SCORM Cloud registrations for a user,
 * but keeps their account in the database intact.
 *
 * DELETE /api/unenroll.php
 * Body: { "email": "demo-student@pursuitpathways.com" }
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Log method for debugging
$method = $_SERVER['REQUEST_METHOD'];
error_log('[API UNENROLL] Received method=' . $method . ' content_type=' . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

// Parse JSON body early — accept any method that sends data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// If no JSON body found, check POST/GET fields
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

if (empty($data['email'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email is required.']);
    exit;
}

$email = strtolower(trim($data['email']));

try {
    $pdo = getDbConnection();

    // Look up user
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    $userId = (int)$user['id'];

    // Get SCORM Cloud registration IDs for this user
    $regStmt = $pdo->prepare("SELECT registration_id FROM user_registrations WHERE user_id = ?");
    $regStmt->execute([$userId]);
    $registrations = $regStmt->fetchAll(PDO::FETCH_COLUMN);

    $scormDeleted = 0;
    $scormFailed = 0;

    foreach ($registrations as $regId) {
        if (!empty($regId)) {
            $success = deleteScormRegistration($regId);
            if ($success) {
                $scormDeleted++;
            } else {
                $scormFailed++;
            }
        }
    }

    // Keep user record but clear local registration tracking
    $clearStmt = $pdo->prepare("DELETE FROM user_registrations WHERE user_id = ?");
    $clearStmt->execute([$userId]);

    // Also clear registration_id from users table
    $updateStmt = $pdo->prepare("UPDATE users SET registration_id = NULL WHERE id = ?");
    $updateStmt->execute([$userId]);

    http_response_code(200);
    echo json_encode([
        'success'                => true,
        'message'                => "SCORM registrations cleared for $email (user account kept)",
        'user_id'                => $userId,
        'email'                  => $email,
        'registrations_deleted'  => $scormDeleted,
        'registrations_failed'   => $scormFailed,
    ]);

} catch (PDOException $e) {
    error_log('[API UNENROLL] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
}