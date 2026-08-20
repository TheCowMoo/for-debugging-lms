<?php
/**
 * PURSUIT PATHWAYS LMS
 * ENROLLMENT HANDLER - Creates course registrations
 */

require_once __DIR__ . '/bootstrap.php';

requireLogin();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed.";
    exit;
}

$courseId = trim($_POST['course_id'] ?? '');
$learnerId = '';
$learnerName = '';

try {
    $pdo = getDbConnection();
    
    if (isTestUser()) {
        $learnerId = TEST_USER_EMAIL;
        $learnerName = 'Test Learner';
    } else {
        $stmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (empty($user)) {
            redirectTo('login/');
        }
        
        $learnerId = $user['email'];
        $learnerName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($learnerName)) {
            $learnerName = 'Learner';
        }
    }
} catch (PDOException $e) {
    error_log('[ENROLL] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo "Database error.";
    exit;
}

if (empty($courseId)) {
    http_response_code(400);
    echo "No course ID specified.";
    exit;
}

// Create registration
$payload = [
    'courseId' => $courseId,
    'learnerId' => $learnerId,
    'learnerName' => $learnerName,
];

$result = createScormRegistration($payload);

if (!empty($result['id'])) {
    // Save registration_id to user record
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET registration_id = ? WHERE email = ?");
        $stmt->execute([$result['id'], $learnerId]);
    } catch (PDOException $e) {
        error_log('[ENROLL] Failed to save registration ID: ' . $e->getMessage());
    }

    // Redirect to the course viewer
    $launchLink = getScormLaunchLink($result['id'], buildUrl('dashboard/'));
    if (!empty($launchLink)) {
        $_SESSION['course_url'] = $launchLink;
        redirectTo('course-viewer/');
    }

    echo "Registration created. You can now access the course from your dashboard.";
} else {
    $errorMsg = 'Course registration failed.';
    if (!empty($result['raw'])) {
        $errorMsg .= ' Response: ' . substr($result['raw'], 0, 200);
    }
    error_log('[ENROLL] ' . $errorMsg);
    http_response_code(500);
    echo "Failed to create enrollment. Please contact support.";
}