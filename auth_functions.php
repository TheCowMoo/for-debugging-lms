<?php
/**
 * Legacy auth functions.
 * Database connection and session now handled by bootstrap.php.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Checks if the logged-in user is an admin
 */
function is_admin() {
    return isAdmin();
}

/**
 * Security Gate: Use this at the top of admin-only pages
 */
function require_admin() {
    requireAdmin();
}

function sendSystemEmail($to, $subject, $body) {
    $fromEmail = getenv('MAIL_FROM_EMAIL') ?: 'noreply@' . (getenv('APP_DOMAIN') ?: 'localhost');
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Learning Portal';
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: $fromName <$fromEmail>" . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}