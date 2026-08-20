<?php
/**
 * Moodle API Connection Test
 * Tests connectivity and explores available data from your Moodle instance.
 */

require_once __DIR__ . '/bootstrap.php';

// Only allow in development or from specific IPs for security
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
if (APP_ENV !== 'development' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIPs)) {
    die("This test page is only available in development mode.\n");
}

echo "=== Moodle API Connection Test ===\n\n";

// Test 1: Check Moodle config
echo "--- Configuration ---\n";
echo "MOODLE_BASE_URL: " . (MOODLE_BASE_URL ?: 'NOT SET') . "\n";
echo "MOODLE_WSTOKEN: " . (MOODLE_WSTOKEN ? substr(MOODLE_WSTOKEN, 0, 8) . '...' : 'NOT SET') . "\n\n";

if (!MOODLE_BASE_URL || !MOODLE_WSTOKEN) {
    echo "ERROR: MOODLE_BASE_URL and MOODLE_WSTOKEN must be set in .env\n";
    exit(1);
}

// Test 2: Fetch courses
echo "--- Courses ---\n";
$courses = moodleApiCall('core_course_get_courses', []);
if (!empty($courses)) {
    echo "Found " . count($courses) . " courses:\n";
    foreach ($courses as $course) {
        if (!empty($course['id']) && $course['id'] > 1) { // Skip site course
            echo "  - [{$course['id']}] {$course['fullname']} ({$course['shortname']})\n";
        }
    }
} else {
    echo "No courses found or API error.\n";
}
echo "\n";

// Test 3: Fetch SCORM activities
echo "--- SCORM Activities ---\n";
$scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', ['courseids' => []]);
$scormList = $scorms['scorms'] ?? [];
if (!empty($scormList)) {
    echo "Found " . count($scormList) . " SCORM activities:\n";
    foreach ($scormList as $scorm) {
        echo "  - [{$scorm['id']}] {$scorm['name']} (course: {$scorm['course']}, cmid: {$scorm['coursemodule']})\n";
    }
} else {
    echo "No SCORM activities found.\n";
}
echo "\n";

// Test 4: Fetch users
echo "--- Users (first 5) ---\n";
$users = moodleApiCall('core_user_get_users_by_field', [
    'field' => 'email',
    'values' => ['admin@example.com'],  // This will likely fail silently, that's ok
]);
if (!empty($users)) {
    echo "Found " . count($users) . " matching users.\n";
} else {
    echo "No users matched (expected, just testing connectivity).\n";
}
echo "\n";

// Test 5: Try fetchScormCourses wrapper
echo "--- fetchScormCourses() ---\n";
$scormCourses = fetchScormCourses();
if (!empty($scormCourses)) {
    echo "Found " . count($scormCourses) . " courses via wrapper:\n";
    foreach ($scormCourses as $sc) {
        echo "  - id: {$sc['id']}, title: {$sc['title']}\n";
    }
} else {
    echo "No courses returned.\n";
}
echo "\n";

echo "=== Test Complete ===\n";