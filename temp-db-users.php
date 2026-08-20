<?php
/**
 * Temporary utility: list users from DB.
 * Uses bootstrap.php so .env credentials are respected.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin();

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query('SELECT id, email, first_name, last_name, role, is_verified FROM users LIMIT 10');
    echo "<pre>";
    foreach ($stmt as $row) {
        echo implode(' | ', $row) . PHP_EOL;
    }
    echo "</pre>";
} catch (Throwable $e) {
    echo 'ERROR: ' . htmlspecialchars($e->getMessage()) . PHP_EOL;
}