<?php
/**
 * 0001 — Cross-version SCORM compatibility foundation.
 *
 * Adds the columns, tables, and indexes required for reliable SCORM 1.2 and
 * SCORM 2004 2nd/3rd/4th Edition tracking:
 *
 *   scorm_packages  — scorm_edition, manifest_id, package_version, sco_count,
 *                     activity_tree, resource_metadata, content_hash, fingerprint
 *   scorm_attempts  — scorm_edition, mode, credit, completion_threshold,
 *                     scaled_passing_score, normalized_completion,
 *                     normalized_success, attempt_state, status_source,
 *                     uniqueness on (user, package, sco, attempt_number), indexes
 *   scorm_interactions  — uniqueness on (attempt_id, interaction_index) + index
 *   scorm_objectives    — uniqueness on (attempt_id, objective_index) + index
 *   scorm_interaction_objectives — new junction table (cmi.interactions.n.objectives.m.id)
 *   scorm_comments_from_learner  — new table (SCORM 2004 comments)
 *   scorm_request_idempotency    — new table (browser commit/beacon dedupe)
 *   scorm_events     — request_id + changed_fields columns
 *   scorm_monitor    — rejected/duplicate/failed-persistence log
 *
 * Every statement is guarded against information_schema so this migration is
 * safe to run on fresh installs AND databases already created by the old
 * runtime CREATE TABLE IF NOT EXISTS bootstrap.
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
    $hasIndex = function (string $table, string $index) use ($pdo, $schema): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$schema, $table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $addColumn = function (string $table, string $ddl) use ($pdo, $hasColumn): void {
        // $ddl = "`scorm_edition` VARCHAR(20) ... AFTER `scorm_version`"
        if (preg_match('/^`?(\w+)`? /', $ddl, $m)) {
            $name = $m[1];
            if ($hasColumn($table, $name)) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    };
    $addIndex = function (string $table, string $name, string $definition) use ($pdo, $hasIndex): void {
        if ($hasIndex($table, $name)) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD $definition");
    };
    $addUnique = function (string $table, string $name, string $columns) use ($pdo, $hasIndex): void {
        if ($hasIndex($table, $name)) {
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$name` ($columns)");
    };
    // ─────────────────────────────────────────────────────────────────────
    // scorm_packages — edition, manifest metadata, fingerprint
    // ─────────────────────────────────────────────────────────────────────
    $addColumn('scorm_packages', "`scorm_edition` VARCHAR(20) NOT NULL DEFAULT '' AFTER `scorm_version`");
    $addColumn('scorm_packages', "`manifest_id` VARCHAR(255) NOT NULL DEFAULT '' AFTER `scorm_version`");
    $addColumn('scorm_packages', "`package_version` VARCHAR(100) NOT NULL DEFAULT '' AFTER `manifest_id`");
    $addColumn('scorm_packages', "`sco_count` INT NOT NULL DEFAULT 0 AFTER `package_version`");
    $addColumn('scorm_packages', "`activity_tree` JSON NULL AFTER `sco_count`");
    $addColumn('scorm_packages', "`resource_metadata` JSON NULL AFTER `activity_tree`");
    $addColumn('scorm_packages', "`content_hash` VARCHAR(64) NOT NULL DEFAULT '' AFTER `resource_metadata`");
    $addColumn('scorm_packages', "`fingerprint` JSON NULL AFTER `content_hash`");

    // ─────────────────────────────────────────────────────────────────────
    // scorm_attempts — edition, mode/credit, thresholds, normalized fields, indexes
    // ─────────────────────────────────────────────────────────────────────
    if ($hasTable('scorm_attempts')) {
        $addColumn('scorm_attempts', "`scorm_edition` VARCHAR(20) NOT NULL DEFAULT '' AFTER `package_id`");
        $addColumn('scorm_attempts', "`mode` VARCHAR(20) NOT NULL DEFAULT '' AFTER `entry`");
        $addColumn('scorm_attempts', "`credit` VARCHAR(20) NOT NULL DEFAULT '' AFTER `mode`");
        $addColumn('scorm_attempts', "`completion_threshold` DECIMAL(5,4) NULL AFTER `progress_measure`");
        $addColumn('scorm_attempts', "`scaled_passing_score` DECIMAL(5,4) NULL AFTER `completion_threshold`");
        $addColumn('scorm_attempts', "`normalized_completion` VARCHAR(20) NOT NULL DEFAULT '' AFTER `scaled_passing_score`");
        $addColumn('scorm_attempts', "`normalized_success` VARCHAR(20) NOT NULL DEFAULT '' AFTER `normalized_completion`");
        $addColumn('scorm_attempts', "`status_source` VARCHAR(20) NOT NULL DEFAULT '' AFTER `normalized_success`");
        $addColumn('scorm_attempts', "`attempt_state` VARCHAR(30) NOT NULL DEFAULT '' AFTER `status_source`");
        $addColumn('scorm_attempts', "`last_request_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `attempt_state`");

        // Stable uniqueness for (user, package, sco, attempt_number).
        // sco_item_id may be NULL (the legacy fallback insert); MySQL treats
        // NULLs as distinct in unique keys, so those rows never collide.
        $addUnique('scorm_attempts', 'uq_attempt', 'user_id, package_id, sco_item_id, attempt_number');

        $addIndex('scorm_attempts', 'idx_user_pkg_sco', 'INDEX `idx_user_pkg_sco` (user_id, package_id, sco_item_id)');
        $addIndex('scorm_attempts', 'idx_completion_status', 'INDEX `idx_completion_status` (completion_status)');
        $addIndex('scorm_attempts', 'idx_success_status', 'INDEX `idx_success_status` (success_status)');
        $addIndex('scorm_attempts', 'idx_attempt_state', 'INDEX `idx_attempt_state` (attempt_state)');
        $addIndex('scorm_attempts', 'idx_started_at', 'INDEX `idx_started_at` (started_at)');
        $addIndex('scorm_attempts', 'idx_last_accessed', 'INDEX `idx_last_accessed` (last_accessed_at)');
    }
    // ─────────────────────────────────────────────────────────────────────
    // scorm_interactions / scorm_objectives — stable uniqueness + indexes
    // ─────────────────────────────────────────────────────────────────────
    if ($hasTable('scorm_interactions')) {
        $addColumn('scorm_interactions', "`correct_response_ids` JSON NULL AFTER `correct_responses`");
        $addUnique('scorm_interactions', 'uq_interaction', 'attempt_id, interaction_index');
        $addIndex('scorm_interactions', 'idx_interaction_id', 'INDEX `idx_interaction_id` (interaction_id)');
    }
    if ($hasTable('scorm_objectives')) {
        $addUnique('scorm_objectives', 'uq_objective', 'attempt_id, objective_index');
        $addIndex('scorm_objectives', 'idx_objective_id', 'INDEX `idx_objective_id` (objective_id)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // scorm_events — request_id + bounded changed-field metadata
    // ─────────────────────────────────────────────────────────────────────
    if ($hasTable('scorm_events')) {
        $addColumn('scorm_events', "`request_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `slide_id`");
        $addColumn('scorm_events', "`changed_fields` JSON NULL AFTER `request_id`");
        $addIndex('scorm_events', 'idx_request_id', 'INDEX `idx_request_id` (request_id)');
    }

    // ─────────────────────────────────────────────────────────────────────
    // scorm_interaction_objectives — cmi.interactions.n.objectives.m.id
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scorm_interaction_objectives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            interaction_index INT NOT NULL,
            objective_index INT NOT NULL,
            objective_id VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_link (attempt_id, interaction_index, objective_index),
            FOREIGN KEY (attempt_id) REFERENCES scorm_attempts(id) ON DELETE CASCADE,
            INDEX idx_attempt (attempt_id),
            INDEX idx_objective_id (objective_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // ─────────────────────────────────────────────────────────────────────
    // scorm_comments_from_learner — SCORM 2004 cmi.comments_from_learner.n.*
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scorm_comments_from_learner (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            user_id INT NOT NULL,
            comment_index INT NOT NULL,
            comment_text TEXT,
            location VARCHAR(500) NOT NULL DEFAULT '',
            timestamp TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comment (attempt_id, comment_index),
            FOREIGN KEY (attempt_id) REFERENCES scorm_attempts(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_attempt (attempt_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ─────────────────────────────────────────────────────────────────────
    // scorm_request_idempotency — browser commit/beacon request dedupe
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scorm_request_idempotency (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            attempt_id INT NULL,
            response JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_request_id (request_id),
            INDEX idx_attempt (attempt_id),
            INDEX idx_user (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ─────────────────────────────────────────────────────────────────────
    // scorm_monitor — rejected-payload / duplicate-request / failed-persistence log
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scorm_monitor (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            monitor_type VARCHAR(30) NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            request_id VARCHAR(64) NOT NULL DEFAULT '',
            user_id INT NULL,
            package_id INT NULL,
            http_status INT NOT NULL DEFAULT 0,
            detail JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_created (monitor_type, created_at),
            INDEX idx_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
};