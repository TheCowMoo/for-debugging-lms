<?php
/**
 * 0002 — User security version for serve-token revocation.
 *
 * Adds users.security_version, a monotonic counter bumped whenever a user's
 * entitlements change (password reset, self-service password change, email
 * change, role/org change, disable). Serve tokens embed the current value;
 * validation rejects any token issued before a bump, so a changed or disabled
 * account loses token authority immediately instead of waiting for expiry.
 *
 * @package  PP_LMS
 */

return function (PDO $pdo): void {
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'security_version'"
    );
    $stmt->execute([$schema]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN security_version INT NOT NULL DEFAULT 0 AFTER invite_token_id");
    }
};
