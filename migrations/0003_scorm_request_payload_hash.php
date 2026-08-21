<?php
/**
 * 0003 — scorm_request_idempotency.payload_hash.
 *
 * Stores a SHA-256 of the raw commit body so a replayed request_id with
 * different content can be detected and rejected instead of silently
 * returning the original response.
 *
 * @package  PP_LMS
 */

return function (PDO $pdo): void {
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scorm_request_idempotency' AND COLUMN_NAME = 'payload_hash'"
    );
    $stmt->execute([$schema]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE scorm_request_idempotency ADD COLUMN payload_hash CHAR(64) NOT NULL DEFAULT '' AFTER response");
    }
};
