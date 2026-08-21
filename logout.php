<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * Secure Logout Script
 */

require_once __DIR__ . '/bootstrap.php';

// 1. Initialize the session
session_start();

// 2. Clear the session array
$_SESSION = [];

// 3. Delete the session cookie (destroys it client-side)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the server-side session file
session_destroy();

// 5. Redirect to login
header('Location: login/');
exit;