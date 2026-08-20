<?php
/**
 * Delete SCORM Registrations — now uses Moodle bridge.
 * This script removes all Moodle SCORM registrations/enrollments.
 *
 * WARNING: This will unenroll all users from all SCORM courses.
 * Only use this for cleanup during migration.
 */

require_once __DIR__ . '/bootstrap.php';

// Require super admin for safety
if (!isSuperAdmin()) {
    die("Super admin access required.\n");
}

echo "=== Moodle Registration Cleanup ===\n\n";

// Fetch all registrations
$registrations = fetchScormRegistrations();
echo "Found " . count($registrations) . " registrations.\n\n";

if (empty($registrations)) {
    echo "No registrations to clean up.\n";
    exit;
}

echo "Registrations:\n";
foreach ($registrations as $reg) {
    $courseTitle = $reg['course']['title'] ?? 'Unknown';
    $learnerEmail = $reg['learner']['id'] ?? $reg['learner']['email'] ?? 'Unknown';
    $regId = $reg['id'] ?? 'unknown';
    echo "  - $regId: $courseTitle ($learnerEmail)\n";
}

echo "\nTo delete these registrations, uncomment the deletion code in this script.\n";
echo "Currently running in dry-run mode for safety.\n\n";

// Uncomment the lines below to actually delete
/*
foreach ($registrations as $reg) {
    $regId = $reg['id'] ?? '';
    if ($regId) {
        deleteScormRegistration($regId);
        echo "Deleted: $regId\n";
    }
}
*/

echo "Done.\n";