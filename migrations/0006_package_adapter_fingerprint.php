<?php
/**
 * 0006 — Package adapter fingerprint.
 *
 * Adds authoring-tool adapter fields to scorm_packages so progress adapters
 * (estimated progress) can be gated by an exact package fingerprint:
 *   - adapter_family   — storyline | rise | captivate | ispring | lectora | generic
 *   - adapter_version  — detected runtime/version when available
 *   - runtime_hash     — SHA-256 of the detected runtime/driver file
 *   - manifest_hash    — SHA-256 of imsmanifest.xml
 *   - parser_version   — adapter implementation version
 *
 * Unknown packages are 'generic' and are never parsed opaquely.
 *
 * @package  PP_LMS
 */

return function (PDO $pdo): void {
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $hasCol = function (string $column) use ($pdo, $schema): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scorm_packages' AND COLUMN_NAME = ?"
        );
        $stmt->execute([$schema, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $add = function (string $column, string $ddl) use ($pdo, $hasCol): void {
        if ($hasCol($column)) {
            return;
        }
        $pdo->exec("ALTER TABLE scorm_packages ADD COLUMN $ddl");
    };

    $add('adapter_family', "`adapter_family` VARCHAR(20) NOT NULL DEFAULT 'generic' AFTER `fingerprint`");
    $add('adapter_version', "`adapter_version` VARCHAR(50) NOT NULL DEFAULT '' AFTER `adapter_family`");
    $add('runtime_hash', "`runtime_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `adapter_version`");
    $add('manifest_hash', "`manifest_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `runtime_hash`");
    $add('parser_version', "`parser_version` VARCHAR(50) NOT NULL DEFAULT '' AFTER `manifest_hash`");
};
