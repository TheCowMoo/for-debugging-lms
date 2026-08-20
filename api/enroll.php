<?php
/**
 * API: Webhook Enrollment Endpoint
 *
 * Enrolls an existing user into a SCORM Cloud course by email.
 *
 * POST /api/enroll.php
 * Body: { "email": "demo-student@pursuitpathways.com", "course_id": "activeshooterhpcas" }
 */

require_once __DIR__ . '/../bootstrap.php';

// ── Authentication (API key or admin session required) ──
if (!apiRequireAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Provide a valid X-API-Key header or admin session.']);
    exit;
}

// ── Rate limiting ──
if (!checkRegistrationRateLimit('api-enroll', 10, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many enrollment attempts. Please try again later.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = rtrim(BASE_URL, '/');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Log method for debugging
$method = $_SERVER['REQUEST_METHOD'];
error_log('[API ENROLL] Received method=' . $method . ' content_type=' . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

// Parse JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Fallback to POST form fields
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No data received.', 'method' => $method]);
    exit;
}

// ── Validate required fields ──
$email    = strtolower(trim($data['email'] ?? ''));
$courseId = trim($data['course_id'] ?? '');

if ($email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'email is required.']);
    exit;
}

if ($courseId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'course_id is required.']);
    exit;
}

try {
    $pdo = getDbConnection();

    // Look up user by email
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found. Register first via /api/register.php']);
        exit;
    }

    $userId   = (int)$user['id'];
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Learner';
    $verified = (int)$user['is_verified'];

    // Optional: warn if user is not yet verified
    $warnings = [];
    if ($verified === 0) {
        $warnings[] = 'User email not yet verified. They must verify before logging in.';
    }

    // Build SCORM Cloud payload
    $regId = 'reg_' . substr(md5($email . $courseId . time()), 0, 12);

    $payload = [
        'courseId'       => $courseId,
        'registrationId' => $regId,
        'learnerId'      => $email,
        'learnerName'    => $userName,
    ];

    $debugResult = [];
    $scormResponse = createScormRegistration($payload, $debugResult);

    $scormRegId = $scormResponse['id'] ?? $regId;

    // Save registration to local database
    ensureUserRegistrationsTable();

    // Update users table with registration_id
    $updateUser = $pdo->prepare("UPDATE users SET registration_id = ? WHERE id = ?");
    $updateUser->execute([$scormRegId, $userId]);

    // Insert into user_registrations table
    $courseTitle = $scormResponse['course']['title'] ?? $courseId;
    $insertReg = $pdo->prepare("INSERT INTO user_registrations (user_id, course_id, registration_id, course_title) VALUES (?, ?, ?, ?)");
    $insertReg->execute([$userId, $courseId, $scormRegId, $courseTitle]);

    http_response_code(201);
    echo json_encode([
        'success'         => true,
        'message'         => 'User enrolled in course',
        'email'           => $email,
        'course_id'       => $courseId,
        'course_title'    => $courseTitle,
        'registration_id' => $scormRegId,
        'warnings'        => $warnings,
    ]);

} catch (PDOException $e) {
    error_log('[API ENROLL] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
}