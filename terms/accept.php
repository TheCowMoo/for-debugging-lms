<?php
/**
 * TERMS OF USE — Acceptance handler.
 * Records the timestamp when user agrees to terms.
 */

require_once __DIR__ . '/../bootstrap.php';

// Must be logged in and POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    redirectTo('login/');
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE users SET terms_accepted_at = NOW() WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
} catch (PDOException $e) {
    error_log('[TERMS] Failed to record acceptance: ' . $e->getMessage());
}

redirectTo('dashboard/');