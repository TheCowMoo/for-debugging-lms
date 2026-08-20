<?php
/**
 * API: Generate Signed Invite Link
 *
 * Creates a one-time-use signed invite token linked to a specific email.
 * The resulting link cannot be shared — only the intended email can use it.
 *
 * POST /api/invite.php
 * Body: { "email": "jane@company.com", "course_id": "activeshooterhpcas", "department": "HR" }
 */

require_once __DIR__ . '/../bootstrap.php';

// ── Authentication (API key or admin session required) ──
if (!apiRequireAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Provide a valid X-API-Key header or admin session.']);
    exit;
}

// ── Rate limiting ──
if (!checkRegistrationRateLimit('api-invite', 10, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many invite requests. Please try again later.']);
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

// Parse body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}
if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No data received.']);
    exit;
}

// ── Validate ──
$isDemo       = !empty($data['is_demo']) ? 1 : 0;
$email = strtolower(trim($data['email'] ?? ''));

// For full invites, email is required. For multi-use demo links, email is optional.
if ($email === '' && !$isDemo) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'email is required for non-demo invites.']);
    exit;
}

$courseId     = trim($data['course_id'] ?? '');
$department   = trim($data['department'] ?? '');
$isTeamLead   = (int)($data['is_team_lead'] ?? 0);
$maxUses      = !empty($data['max_uses']) ? (int)$data['max_uses'] : null;
$orgId        = !empty($data['organization_id']) ? (int)$data['organization_id'] : null;

// Optional expiry (default: 7 days)
$expiresInDays = !empty($data['expires_in_days']) ? (int)$data['expires_in_days'] : 7;

try {
    $pdo = getDbConnection();
    ensureInviteTokensTable();

    // Generate a cryptographically random token
    $token = bin2hex(random_bytes(32));

    // For demo multi-use, don't lock to a single email — link is shareable
    $insertEmail = ($isDemo && !$email) ? null : $email;

    // Build token data payload — capture the authenticated user if session-based
    $createdBy = $_SESSION['user_id'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO invite_tokens (token, email, course_id, department, is_team_lead, is_demo, max_uses, use_count, created_by, organization_id, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    $stmt->execute([$token, $insertEmail, $courseId ?: null, $department ?: null, $isTeamLead, $isDemo, $maxUses, $createdBy, $orgId, $expiresInDays]);

    // Build the signed invite link
    $inviteLink = buildUrl('signup/?token=' . urlencode($token) . ($insertEmail ? '&email=' . urlencode($email) : ''));

    $typeLabel = $isDemo ? 'demo' : 'full';
    http_response_code(201);
    echo json_encode([
        'success'     => true,
        'message'     => ucfirst($typeLabel) . ' invite link generated. ' . ($isDemo ? 'This is a demo — no SCORM seat consumed.' : 'User will need to verify email before logging in.'),
        'type'        => $typeLabel,
        'email'       => $email,
        'invite_link' => $inviteLink,
        'expires_in'  => "$expiresInDays days",
    ]);

} catch (PDOException $e) {
    error_log('[API INVITE] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
}