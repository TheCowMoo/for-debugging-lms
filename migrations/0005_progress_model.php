<?php
/**
 * 0005 — Progress model (reported vs estimated).
 *
 * Adds explicit progress-tracking fields to scorm_attempts:
 *   - reported_progress_measure  — official cmi.progress_measure value
 *   - estimated_progress_measure — LMS-derived value from a validated adapter
 *   - progress_source            — scorm_reported | <adapter> | completed_status | none
 *   - progress_confidence        — 0..1 adapter confidence
 *   - progress_parser            — adapter/parser version
 *   - progress_calculated_at     — last calculation time
 *   - progress_raw_hash          — SHA-256 of the source state (idempotent recalc)
 *
 * The existing progress_measure column remains the raw reported value for
 * backward compatibility while consumers migrate.
 *
 * @package  PP_LMS
 */

return function (PDO $pdo): void {
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $hasCol = function (string $column) use ($pdo, $schema): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scorm_attempts' AND COLUMN_NAME = ?"
        );
        $stmt->execute([$schema, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $add = function (string $column, string $ddl) use ($pdo, $hasCol): void {
        if ($hasCol($column)) {
            return;
        }
        $pdo->exec("ALTER TABLE scorm_attempts ADD COLUMN $ddl");
    };

    $add('reported_progress_measure', "`reported_progress_measure` DECIMAL(5,4) NULL AFTER `progress_measure`");
    $add('estimated_progress_measure', "`estimated_progress_measure` DECIMAL(5,4) NULL AFTER `reported_progress_measure`");
    $add('progress_source', "`progress_source` VARCHAR(40) NOT NULL DEFAULT '' AFTER `estimated_progress_measure`");
    $add('progress_confidence', "`progress_confidence` DECIMAL(5,4) NULL AFTER `progress_source`");
    $add('progress_parser', "`progress_parser` VARCHAR(100) NOT NULL DEFAULT '' AFTER `progress_confidence`");
    $add('progress_calculated_at', "`progress_calculated_at` TIMESTAMP NULL AFTER `progress_parser`");
    $add('progress_raw_hash', "`progress_raw_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `progress_calculated_at`");
};
