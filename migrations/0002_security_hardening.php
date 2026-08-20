<?php
/**
 * 0002 — Security hardening foundation.
 *
 * Account lockout & throttling, security audit logging, and email MFA support:
 *
 *   security_events  — audit log for logins, admin actions, MFA, lockouts
 *   auth_attempts    — per-account + per-IP login attempt tracker
 *   mfa_challenges   — email MFA 6-digit challenges (10-minute expiry)
 *   users            — failed_login_count, failed_login_started_at, locked_until
 *
 * Every statement is guarded against information_schema / SHOW COLUMNS so this
 * migration is safe on fresh installs AND existing databases.
 *
 * @package  PP_LMS
 */

return function (PDO $pdo): void {
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    $hasTable = function (string $table) use ($pdo, $schema): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
        );
        $stmt->execute([$schema, $table]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $hasColumn = function (string $table, string $column) use ($pdo, $schema): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$schema, $table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    // ─────────────────────────────────────────────────────────────────────
    // security_events — audit log
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(60) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            actor_user_id INT NULL,
            actor_email VARCHAR(255) NOT NULL DEFAULT '',
            actor_ip VARCHAR(45) NOT NULL DEFAULT '',
            target_user_id INT NULL,
            target_email VARCHAR(255) NOT NULL DEFAULT '',
            detail JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_created (event_type, created_at),
            INDEX idx_severity (severity),
            INDEX idx_actor (actor_user_id),
            INDEX idx_actor_ip (actor_ip),
            INDEX idx_target (target_user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ─────────────────────────────────────────────────────────────────────
    // auth_attempts — per-account + per-IP login attempt tracker
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS auth_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL DEFAULT '',
            user_id INT NULL,
            ip_hash CHAR(32) NOT NULL DEFAULT '',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            lockout_triggered TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_user_time (user_id, attempted_at),
            INDEX idx_email_time (email, attempted_at),
            INDEX idx_ip_time (ip_hash, attempted_at),
            INDEX idx_success (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ─────────────────────────────────────────────────────────────────────
    // mfa_challenges — email MFA 6-digit challenges
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mfa_challenges (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ─────────────────────────────────────────────────────────────────────
    // users — per-account lockout state
    // ─────────────────────────────────────────────────────────────────────
    if ($hasTable('users')) {
        $addCol = function (string $column, string $ddl) use ($pdo, $hasColumn): void {
            if ($hasColumn('users', $column)) {
                return;
            }
            $pdo->exec("ALTER TABLE users ADD COLUMN $ddl");
        };
        $addCol('failed_login_count', '`failed_login_count` INT NOT NULL DEFAULT 0 AFTER `is_verified`');
        $addCol('failed_login_started_at', '`failed_login_started_at` DATETIME NULL AFTER `failed_login_count`');
        $addCol('locked_until', '`locked_until` DATETIME NULL AFTER `failed_login_started_at`');
    }
};
