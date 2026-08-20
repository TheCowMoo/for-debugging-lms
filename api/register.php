<?php
/**
 * API: Webhook Registration Endpoint
 * 
 * Receives POST requests from external CRM/forms to register users.
 * Endpoint: POST https://edu.pursuitpathways.com/api/register.php
 * 
 * Expects JSON body:
 * {
 *   "first_name": "Demo",
 *   "last_name": "Student",
 *   "email": "demo-student@pursuitpathways.com",
 *   "password": "DemoStudent1!",
 *   "recaptcha_token": "03AGdBq...",  // required: reCAPTCHA v3 token from frontend
 *   "role": "student"                 // optional, default: student
 *   "course_id": "39"                 // optional: auto-enroll the user in this course
 * }
 * 
 * Returns JSON response.
 */

require_once __DIR__ . '/../bootstrap.php';

// ── Authentication (API key or admin session required) ──
if (!apiRequireAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Provide a valid X-API-Key header or admin session.']);
    exit;
}

// ── CORS Headers (restrict to known origins) ──
header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = rtrim(BASE_URL, '/');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Accept any method that carries a JSON body ──
// Log method for debugging
$method = $_SERVER['REQUEST_METHOD'];
error_log('[API REGISTER] Received method=' . $method . ' content_type=' . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

// Parse JSON body early — accept any method that sends data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// If no JSON body found, check POST form fields
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

// Accept JSON body on any method, or POST with form data
if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No data received. Send JSON body or form fields.', 'method' => $method]);
    exit;
}


// ── Validate required fields ──
$firstName = trim($data['first_name'] ?? '');
$lastName  = trim($data['last_name'] ?? '');
$email     = strtolower(trim($data['email'] ?? ''));
$password  = $data['password'] ?? '';
$role      = trim($data['role'] ?? 'student');

$errors = [];
if ($firstName === '') $errors[] = 'first_name is required';
if ($lastName === '')  $errors[] = 'last_name is required';
if ($email === '')     $errors[] = 'email is required';
if ($password === '')  $errors[] = 'password is required';

// Validate role — only student roles allowed via API for security
// Admin/super_admin accounts must be created manually or via database
$allowedRoles = ['student', 'demo_student'];
if (!in_array($role, $allowedRoles, true)) {
    $errors[] = "role must be one of: " . implode(', ', $allowedRoles);
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
    exit;
}

// ── Security checks (rate limit + reCAPTCHA) ──
if (!checkRegistrationRateLimit('api-register', 5, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many registration attempts. Please try again later.']);
    exit;
}

$recaptchaToken = $data['recaptcha_token'] ?? ($_POST['recaptcha_token'] ?? '');
if (!verifyRecaptcha($recaptchaToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'reCAPTCHA verification failed. Include recaptcha_token in your request.']);
    exit;
}

// ── Optional fields ──
$department   = trim($data['department'] ?? '');
$isTeamLead   = (int)($data['is_team_lead'] ?? 0);
$orgId        = !empty($data['organization_id']) ? (int)$data['organization_id'] : null;

// ── Register user ──
try {
    $pdo = getDbConnection();
    ensureUserColumns();

    // Check for duplicate email
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Email already registered.']);
        exit;
    }

    // Hash password
    $passHash = password_hash($password, PASSWORD_DEFAULT);

    // Generate verification token
    $vToken = bin2hex(random_bytes(32));

    // Insert user (verified = 0 — email verification required)
    $sql = "INSERT INTO users (email, password_hash, first_name, last_name, role, organization_id, 
            verification_token, is_verified, department, is_team_lead, created_at)
            VALUES (:email, :pass, :fname, :lname, :role, :orgid,
            :vtoken, 0, :department, :is_team_lead, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email'        => $email,
        ':pass'         => $passHash,
        ':fname'        => $firstName,
        ':lname'        => $lastName,
        ':role'         => $role,
        ':orgid'        => $orgId,
        ':vtoken'       => $vToken,
        ':department'   => $department,
        ':is_team_lead' => $isTeamLead,
    ]);

    $userId = (int)$pdo->lastInsertId();

    // ── Optional course enrollment (auto-enroll if course_id provided) ──
    $courseId = trim($data['course_id'] ?? '');
    $enrolled = false;
    if ($courseId !== '') {
        $regId = 'reg_' . substr(md5($email . $courseId), 0, 12);
        $regPayload = [
            'courseId'  => $courseId,
            'registrationId' => $regId,
            'learner'   => ['id' => $email, 'firstName' => $firstName, 'lastName' => $lastName],
        ];
        $regResponse = createScormRegistration($regPayload);
        if (!empty($regResponse)) {
            $enrolled = true;
            try {
                ensureUserRegistrationsTable();
                $pdo->prepare('INSERT INTO user_registrations (user_id, course_id, registration_id, course_title) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $courseId, $regId, $regResponse['course']['title'] ?? '']);
            } catch (PDOException $e) {
                error_log('[API REGISTER] user_registrations insert failed: ' . $e->getMessage());
            }
        }
    }

    // ── Send verification email via GHL ──
    $verifyLink = buildUrl('verify.php?token=' . urlencode($vToken));
    $siteName = getSiteName();
    $subject = "Verify your email address - $siteName";
    $verificationEmail = buildVerificationEmail($firstName, $verifyLink, $siteName);

    require_once __DIR__ . '/../signup/ghl_helper.php';
    $emailSent = sendGHLPortalEmail($email, $firstName, $subject, $verificationEmail['html'], $lastName, $verificationEmail['text']);

    http_response_code(201);
    echo json_encode([
        'success'     => true,
        'message'     => 'User registered. Verification email sent.',
        'user_id'     => $userId,
        'email'       => $email,
        'role'        => $role,
        'enrolled'    => $enrolled,
        'email_sent'  => $emailSent,
        'verify_link' => $verifyLink,
    ]);

} catch (PDOException $e) {
    error_log('[API REGISTER] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
}