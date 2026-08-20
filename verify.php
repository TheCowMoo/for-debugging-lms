<?php
/**
 * VERIFICATION HANDLER (verify.php)
 */
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = getDbConnection();
    
    $token = $_GET['token'] ?? '';

    if (!$token) {
        die("Invalid request.");
    }

    // Update user: set is_verified to 1 and clear the token
    $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE verification_token = ?");
    $stmt->execute([$token]);

    if ($stmt->rowCount() > 0) {
        // Redirect to login with a success flag
        redirectTo('login/?verified=1');
    } else {
        echo "Invalid or expired verification link.";
    }

} catch (PDOException $e) {
    die("Database error.");
}