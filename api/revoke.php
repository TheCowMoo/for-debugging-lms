<?php
/**
 * API: Full User Revocation Endpoint
 *
 * Deletes a user from the database AND cleans up all their
 * SCORM Cloud registrations.
 *
 * DELETE /api/revoke.php
 * Body: { "email": "demo-student@pursuitpathways.com" }
 */

require_once __DIR__ . '/../bootstrap.php';

// ── Authentication (API key or admin session required) ──
if (!apiRequireAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Provide a valid X-API-Key header or admin session.']);
    exit;
}

// ── Rate limiting ──
if (!checkRegistrationRateLimit('api-revoke', 5, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many revocation attempts. Please try again later.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = rtrim(BASE_URL, '/');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Log method for debugging
$method = $_SERVER['REQUEST_METHOD'];
error_log('[API REVOKE] Received method=' . $method . ' content_type=' . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

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
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    $userId = (int)$user['id'];

    // Get all SCORM Cloud registration IDs for this user
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

    // Delete user (CASCADE will handle user_registrations)
    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $delStmt->execute([$userId]);

    http_response_code(200);
    echo json_encode([
        'success'                  => true,
        'message'                  => "User $email fully revoked",
        'user_id'                  => $userId,
        'email'                    => $email,
        'scorm_registrations_deleted'  => $scormDeleted,
        'scorm_registrations_failed'   => $scormFailed,
    ]);

} catch (PDOException $e) {
    error_log('[API REVOKE] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
}