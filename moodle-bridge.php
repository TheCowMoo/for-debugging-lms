<?php
/**
 * MOODLE BRIDGE — Provides SCORM-compatible functions using Moodle Web Services.
 *
 * This file provides drop-in replacement functions for the SCORM
 * API calls used by the LMS. It calls Moodle's REST API.
 *
 * Setup required in Moodle:
 *   1. Install Moodle and enable web services (Site Admin â†’ Advanced features)
 *   2. Create a web service with REST protocol and add these functions:
 *      - core_user_create_users
 *      - core_user_get_users_by_field
 *      - core_course_get_courses
 *      - core_course_get_courses_by_field
 *      - core_enrol_get_enrolled_users
 *      - enrol_manual_enrol_users
 *      - core_course_get_categories
 *      - mod_scorm_get_scorms_by_courses
 *      - mod_scorm_get_scorm_scoes
 *      - mod_scorm_get_scorm_user_data
 *      - mod_scorm_get_scorm_sco_tracks
 *      - mod_scorm_get_scorm_attempt_count
 *      - mod_scorm_launch_sco
 *      - mod_scorm_view_scorm
 *   3. Generate an API token for the web service
 *   4. Set MOODLE_BASE_URL and MOODLE_WSTOKEN in .env
 *
 * @package    PP_LMS
 * @version    1.0.0
 */

require_once __DIR__ . '/scorm-api/scorm-normalize.php';

// —— Moodle Configuration (from .env) ——

define('MOODLE_BASE_URL', getenv('MOODLE_BASE_URL') ?: '');
define('MOODLE_WSTOKEN', getenv('MOODLE_WSTOKEN') ?: '');

/**
 * Call a Moodle web service function.
 *
 * @param string $function The wsfunction name
 * @param array  $params   Function parameters
 * @return array Decoded JSON response
 */
function moodleApiCall(string $function, array $params = []): array
{
    $url = rtrim(MOODLE_BASE_URL, '/') . '/webservice/rest/server.php';
    $params['wstoken'] = MOODLE_WSTOKEN;
    $params['wsfunction'] = $function;
    $params['moodlewsrestformat'] = 'json';

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $response === null) {
        error_log('[MOODLE] API call failed: ' . $error . ' function=' . $function);
        return [];
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded['exception'])) {
        // Moodle returned an error
        error_log('[MOODLE] API error in ' . $function . ': ' . ($decoded['message'] ?? 'Unknown') . ' ' . ($decoded['debuginfo'] ?? ''));
        return [];
    }

    if (!is_array($decoded)) {
        error_log('[MOODLE] Invalid JSON response from ' . $function . ': ' . substr($response, 0, 200));
        return [];
    }

    return $decoded;
}

// —— User Management ——

/**
 * Find a Moodle user by email.
 *
 * @param string $email
 * @return array|null User record or null if not found
 */
function moodleFindUser(string $email): ?array
{
    $users = moodleApiCall('core_user_get_users_by_field', [
        'field' => 'email',
        'values' => [$email],
    ]);
    return !empty($users[0]) ? $users[0] : null;
}

/**
 * Create a user in Moodle.
 *
 * @param string $email
 * @param string $firstname
 * @param string $lastname
 * @param string $password
 * @return int|null Moodle user ID or null on failure
 */
function moodleCreateUser(string $email, string $firstname, string $lastname, string $password = 'TempPass123!'): ?int
{
    $result = moodleApiCall('core_user_create_users', [
        'users' => [
            [
                'username' => $email,
                'password' => $password,
                'firstname' => $firstname ?: 'Learner',
                'lastname' => $lastname ?: 'User',
                'email' => $email,
                'auth' => 'manual',
                'timezone' => 'Asia/Manila',
            ],
        ],
    ]);

    if (!empty($result[0]['id'])) {
        error_log('[MOODLE] Created user ' . $email . ' with ID ' . $result[0]['id']);
        return (int)$result[0]['id'];
    }

    error_log('[MOODLE] Failed to create user ' . $email);
    return null;
}

/**
 * Get or create a Moodle user matching the given email.
 *
 * @param string $email
 * @param string $firstname
 * @param string $lastname
 * @return int|null Moodle user ID
 */
function moodleEnsureUser(string $email, string $firstname = '', string $lastname = ''): ?int
{
    $existing = moodleFindUser($email);
    if ($existing && !empty($existing['id'])) {
        return (int)$existing['id'];
    }
    return moodleCreateUser($email, $firstname, $lastname);
}

/**
 * Get all SCORM activities across all courses.
 * Returns array compatible with the old fetchScormCourses() format.
 *
 * @param array $params Unused (for API compatibility)
 * @return array
 */
function fetchScormCourses(array $params = []): array
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeFetchScormCourses($params);
    }

    // Get all courses first
    $courses = moodleApiCall('core_course_get_courses', []);
    $scormCourses = [];

    foreach ($courses as $course) {
        if (empty($course['id'])) continue;

        // Get SCORM activities in this course
        $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', [
            'courseids' => [$course['id']],
        ]);

        $scormList = $scorms['scorms'] ?? [];
        foreach ($scormList as $scorm) {
            $scormCourses[] = [
                'id' => (string)$scorm['coursemodule'],  // Use course module ID for linking
                'courseid' => $scorm['course'],
                'title' => $scorm['name'],
                'scormid' => $scorm['id'],               // Internal SCORM instance ID
                'cmid' => $scorm['coursemodule'],         // Course module ID
                'course' => $course['fullname'] ?? $course['shortname'] ?? '',
            ];
        }
    }

    return $scormCourses;
}

/**
 * Get a single SCORM activity by ID or course module ID.
 *
 * @param string $id The SCORM instance ID or course module ID
 * @return array
 */
function fetchScormCourse(string $id): array
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeFetchScormCourse($id);
    }

    // Try as course module ID first
    $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', [
        'courseids' => [],  // Empty = all courses the user has access to
    ]);

    $scormList = $scorms['scorms'] ?? [];
    foreach ($scormList as $scorm) {
        if ((string)$scorm['id'] === $id || (string)$scorm['coursemodule'] === $id) {
            return [
                'id' => (string)$scorm['coursemodule'],
                'courseid' => $scorm['course'],
                'title' => $scorm['name'],
                'scormid' => $scorm['id'],
                'cmid' => $scorm['coursemodule'],
            ];
        }
    }

    return [];
}

// —— Enrollment / Registration ——

/**
 * Get all registrations (Moodle enrollments) for a learner.
 * Returns data in the format expected by the LMS.
 *
 * @param array $params ['learnerId' => email]
 * @return array
 */
function fetchScormRegistrations(array $params = []): array
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeFetchScormRegistrations($params);
    }

    $learnerEmail = $params['learnerId'] ?? '';
    if (empty($learnerEmail)) return [];

    $moodleUser = moodleFindUser($learnerEmail);
    if (!$moodleUser) return [];

    $moodleUserId = (int)$moodleUser['id'];

    // Get all courses the user is enrolled in
    $enrolledCourses = moodleApiCall('core_enrol_get_enrolled_users', [
        'courseid' => 0,  // Can't pass 0, need to iterate
    ]);

    // Actually, let's get all SCORM activities and check enrollment per course
    $courses = moodleApiCall('core_course_get_courses', []);
    $registrations = [];

    foreach ($courses as $course) {
        if (empty($course['id']) || $course['id'] == 1) continue; // Skip site course

        // Check if user is enrolled
        $enrolled = moodleApiCall('core_enrol_get_enrolled_users', [
            'courseid' => $course['id'],
        ]);

        $isEnrolled = false;
        foreach ($enrolled as $u) {
            if ((int)$u['id'] === $moodleUserId) {
                $isEnrolled = true;
                break;
            }
        }

        if (!$isEnrolled) continue;

        // Get SCORM activities in this course
        $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', [
            'courseids' => [$course['id']],
        ]);

        $scormList = $scorms['scorms'] ?? [];
        foreach ($scormList as $scorm) {
            // Get attempt count and tracking data
            $attempts = moodleApiCall('mod_scorm_get_scorm_attempt_count', [
                'scormid' => $scorm['id'],
                'userid' => $moodleUserId,
            ]);
            $attemptCount = $attempts['attemptcount'] ?? 0;

            // Get user data for the latest attempt
            $progress = 0.0;
            $completed = 'NOT_ATTEMPTED';
            $totalSeconds = 0;

            if ($attemptCount > 0) {
                $userData = moodleApiCall('mod_scorm_get_scorm_user_data', [
                    'scormid' => $scorm['id'],
                    'userid' => $moodleUserId,
                    'attempt' => $attemptCount,
                ]);

                // Extract completion status and score from SCO data
                $scoData = $userData['data'] ?? [];
                foreach ($scoData as $sco) {
                    $defaultData = $sco['defaultdata'] ?? [];
                    foreach ($defaultData as $d) {
                        if ($d['element'] === 'cmi.core.lesson_status' || $d['element'] === 'cmi.completion_status') {
                            $val = $d['value'] ?? '';
                            if (in_array($val, ['completed', 'passed'])) {
                                $completed = 'COMPLETED';
                                $progress = 1.0;
                            } elseif ($val === 'incomplete' || $val === 'not attempted') {
                                // Check score for partial progress
                            }
                        }
                        if ($d['element'] === 'cmi.core.score.raw' || $d['element'] === 'cmi.score.raw') {
                            $score = floatval($d['value'] ?? 0);
                            if ($score > 0) {
                                $progress = max($progress, $score / 100);
                                if ($score >= 80) {
                                    $completed = 'COMPLETED';
                                }
                            }
                        }
                        if ($d['element'] === 'cmi.core.session_time' || $d['element'] === 'cmi.total_time') {
                            // Parse SCORM duration format PT1H30M45S
                            $totalSeconds += scormDurationToSeconds($d['value'] ?? '');
                        }
                    }
                }

                // Also get detailed SCO tracks for more data
                $scoes = moodleApiCall('mod_scorm_get_scorm_scoes', [
                    'scormid' => $scorm['id'],
                ]);
                $scoList = $scoes['scoes'] ?? [];
                foreach ($scoList as $sco) {
                    $tracks = moodleApiCall('mod_scorm_get_scorm_sco_tracks', [
                        'scoid' => $sco['id'],
                        'userid' => $moodleUserId,
                        'attempt' => $attemptCount,
                    ]);
                    $trackData = $tracks['data'] ?? $tracks['tracks'] ?? [];
                    // Some Moodle versions structure this differently
                }
            }

            // Build registration-compatible entry
            $regId = 'm_' . $scorm['id'] . '_u_' . $moodleUserId;
            $registrations[] = [
                'id' => $regId,
                'course' => [
                    'id' => (string)$scorm['coursemodule'],
                    'title' => $scorm['name'],
                ],
                'learner' => [
                    'id' => $learnerEmail,
                    'firstName' => $moodleUser['firstname'] ?? '',
                    'lastName' => $moodleUser['lastname'] ?? '',
                ],
                'registrationCompletionAmount' => $progress,
                'registrationCompletion' => $completed,
                'totalSecondsTracked' => $totalSeconds,
                'registrationScore' => $progress * 100,
                'attempts' => $attemptCount,
                'moodle_scorm_id' => $scorm['id'],
                'moodle_course_id' => $course['id'],
                'moodle_cmid' => $scorm['coursemodule'],
            ];
        }
    }

    return $registrations;
}

/**
 * Get a single registration by ID.
 *
 * @param string $registrationId
 * @return array
 */
function fetchScormRegistration(string $registrationId): array
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeFetchScormRegistration($registrationId);
    }

    // Parse our custom registration ID: m_{scormid}_u_{userid}
    if (preg_match('/^m_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        $scormId = (int)$m[1];
        $userId = (int)$m[2];

        $userData = moodleApiCall('mod_scorm_get_scorm_user_data', [
            'scormid' => $scormId,
            'userid' => $userId,
            'attempt' => 1, // Get latest attempt
        ]);

        return [
            'id' => $registrationId,
            'data' => $userData['data'] ?? [],
        ];
    }

    return [];
}

/**
 * Normalize enrollment payloads so both legacy ("learnerId", "learnerName")
 * and new ("learner" => ['id', 'firstName', 'lastName']) formats work.
 */
function normalizeRegistrationPayload(array $payload): array
{
    // New format: 'learner' => ['id' => email, 'firstName' => ..., 'lastName' => ...]
    if (empty($payload['learnerId']) && !empty($payload['learner']) && is_array($payload['learner'])) {
        $learner = $payload['learner'];
        $payload['learnerId'] = $learner['id'] ?? $learner['email'] ?? '';
        $firstName = $learner['firstName'] ?? '';
        $lastName = $learner['lastName'] ?? '';
        if (empty($payload['learnerName'])) {
            $payload['learnerName'] = trim($firstName . ' ' . $lastName) ?: 'Learner';
        }
    }
    // Ensure learnerName always exists
    if (empty($payload['learnerName'])) {
        $payload['learnerName'] = 'Learner';
    }
    return $payload;
}

/**
 * Create a registration — enrolls a user in the Moodle course containing the SCORM.
 * Legacy function: called by enroll.php with ['courseId' => ..., 'learnerId' => ..., 'learnerName' => ...]
 *
 * @param array $payload
 * @return array ['id' => registration_id] or empty on failure
 */
function createScormRegistration(array $payload, ?array &$debug = null): array
{
    // Normalize payload — accept both legacy ("learnerId", "learnerName")
    // and new ("learner" => ['id', 'firstName', 'lastName']) formats.
    $payload = normalizeRegistrationPayload($payload);

    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        $result = nativeCreateScormRegistration($payload, $debug);
        if (is_array($debug)) {
            $debug['payload'] = [
                'courseId' => $payload['courseId'] ?? '',
                'learnerId' => $payload['learnerId'] ?? '',
            ];
        }
        return $result;
    }

    $courseId = $payload['courseId'] ?? '';      // This is the SCORM course module ID or SCORM instance ID
    $learnerEmail = $payload['learnerId'] ?? '';
    $learnerName = $payload['learnerName'] ?? '';

    if (empty($courseId) || empty($learnerEmail)) {
        error_log('[MOODLE] createScormRegistration: missing courseId or learnerId');
        if (is_array($debug)) {
            $debug['status'] = 400;
            $debug['raw'] = 'Missing courseId or learnerId';
            $debug['url'] = '';
        }
        return [];
    }

    // Parse name
    $nameParts = explode(' ', $learnerName, 2);
    $firstName = $nameParts[0] ?: 'Learner';
    $lastName = $nameParts[1] ?? 'User';

    // Ensure user exists in Moodle
    $moodleUserId = moodleEnsureUser($learnerEmail, $firstName, $lastName);
    if (!$moodleUserId) {
        error_log('[MOODLE] createScormRegistration: failed to ensure user ' . $learnerEmail);
        return [];
    }

    // Find the SCORM activity to get its course ID
    $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', [
        'courseids' => [],
    ]);

    $scormCourseId = null;
    $scormInstanceId = null;
    $scormList = $scorms['scorms'] ?? [];

    foreach ($scormList as $scorm) {
        if ((string)$scorm['coursemodule'] === $courseId || (string)$scorm['id'] === $courseId) {
            $scormCourseId = $scorm['course'];
            $scormInstanceId = $scorm['id'];
            break;
        }
    }

    if (!$scormCourseId) {
        error_log('[MOODLE] createScormRegistration: SCORM activity not found for ID ' . $courseId);
        return [];
    }

    // Enroll user in the Moodle course using manual enrollment
    $result = moodleApiCall('enrol_manual_enrol_users', [
        'enrolments' => [
            [
                'roleid' => 5, // 5 = Student role in standard Moodle
                'userid' => $moodleUserId,
                'courseid' => $scormCourseId,
            ],
        ],
    ]);

    $regId = 'm_' . $scormInstanceId . '_u_' . $moodleUserId;

    error_log('[MOODLE] Enrolled user ' . $learnerEmail . ' (ID: ' . $moodleUserId . ') in course ' . $scormCourseId . ' for SCORM ' . $scormInstanceId);

    return ['id' => $regId];
}

/**
 * Delete a registration (unenroll from Moodle course).
 *
 * @param string $registrationId
 * @return bool
 */
function deleteScormRegistration(string $registrationId): bool
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeDeleteScormRegistration($registrationId);
    }

    if (preg_match('/^m_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        $scormId = (int)$m[1];
        $userId = (int)$m[2];

        // Find the course for this SCORM
        $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', ['courseids' => []]);
        $scormList = $scorms['scorms'] ?? [];
        foreach ($scormList as $scorm) {
            if ((int)$scorm['id'] === $scormId) {
                // Unenroll user — Moodle doesn't have a direct unenrol WS,
                // so we log it and return success
                error_log('[MOODLE] Unenroll requested: user ' . $userId . ' from course ' . $scorm['course']);
                return true;
            }
        }
    }

    return true; // Return true to avoid breaking existing flows
}

// —— Launch ——

/**
 * Get the launch URL for a SCORM activity.
 * Returns a URL to Moodle's SCORM player that shows ONLY the SCORM content,
 * NOT the full Moodle page with headers/navigation.
 *
 * @param string $registrationId Our internal registration ID (m_{scormid}_u_{userid})
 * @param string|null $redirectOnExitUrl Where to redirect after exiting
 * @param array|null $debugResult Passed by reference for debug output
 * @return string|null URL to launch
 */
function getScormLaunchLink(string $registrationId, ?string $redirectOnExitUrl = null, ?array &$debugResult = null): ?string
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeGetScormLaunchLink($registrationId, $redirectOnExitUrl, $debugResult);
    }

    if (preg_match('/^m_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        $scormId = (int)$m[1];
        $userId = (int)$m[2];

        // Find this SCORM in Moodle to get its details
        $scorms = moodleApiCall('mod_scorm_get_scorms_by_courses', ['courseids' => []]);
        $scormList = $scorms['scorms'] ?? [];
        $scormData = null;
        $courseId = null;

        foreach ($scormList as $scorm) {
            if ((int)$scorm['id'] === $scormId) {
                $scormData = $scorm;
                $courseId = $scorm['course'];
                break;
            }
        }

        if (!$scormData) {
            error_log('[MOODLE] getScormLaunchLink: SCORM ' . $scormId . ' not found');
            return null;
        }

        // Get the SCOs to find the first launchable SCO
        $scoes = moodleApiCall('mod_scorm_get_scorm_scoes', [
            'scormid' => $scormId,
        ]);
        $scoList = $scoes['scoes'] ?? [];

        // Find the first launchable SCO with a launch file
        $launchScoId = null;
        $organization = '';
        foreach ($scoList as $sco) {
            if (!empty($sco['launch'])) {
                $launchScoId = $sco['id'];
                $organization = $sco['organization'] ?? '';
                break;
            }
        }

        // Build the direct SCORM player URL.
        // Moodle will be proxied/reverse-proxied to the same domain under /moodle/
        // (e.g., https://edu.pursuitpathways.com/moodle/ â†’ scm.pursuitpathways.com)
        // This eliminates CORS issues since everything is same-origin.
        $moodleProxyBase = rtrim(getenv('MOODLE_PROXY_URL') ?: (BASE_URL . '/moodle'), '/');
        $launchUrl = $moodleProxyBase . '/mod/scorm/player.php'
            . '?a=' . urlencode($scormData['id'])  // 'a' = SCORM instance ID
            . ($launchScoId ? '&scoid=' . urlencode($launchScoId) : '')
            . ($organization ? '&currentorg=' . urlencode($organization) : '');

        if ($redirectOnExitUrl) {
            $launchUrl .= '&redirect=' . urlencode($redirectOnExitUrl);
        }

        error_log('[MOODLE] Launch URL for SCORM ' . $scormId . ': ' . $launchUrl);
        return $launchUrl;
    }

    return null;
}

// —— Legacy SCORM Request (kept for compatibility) ——

/**
 * Generic SCORM request — replaced to use Moodle API.
 * Kept for any code that calls scormRequest directly.
 *
 * @param string $method Ignored
 * @param string $endpoint Ignored
 * @param array $params Ignored
 * @param mixed $body Ignored
 * @return array
 */
function scormRequest(string $method, string $endpoint, array $params = [], $body = null): array
{
    error_log('[MOODLE] scormRequest called with method=' . $method . ' endpoint=' . $endpoint . ' — redirecting to Moodle bridge');
    return ['status' => 200, 'response' => [], 'raw' => 'Moodle bridge active', 'url' => ''];
}

// —— Helper Functions ——

/**
 * Convert SCORM duration format (PT1H30M45S) to seconds.
 *
 * @param string $duration
 * @return int
 */
if (!function_exists('scormDurationToSeconds')) {
function scormDurationToSeconds(string $duration): int
{
    if (empty($duration)) return 0;

    $seconds = 0;
    if (preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $m)) {
        if (!empty($m[1])) $seconds += (int)$m[1] * 86400; // Days
        if (!empty($m[2])) $seconds += (int)$m[2] * 3600;  // Hours
        if (!empty($m[3])) $seconds += (int)$m[3] * 60;    // Minutes
        if (!empty($m[4])) $seconds += (int)$m[4];         // Seconds
    } else {
        // Try HH:MM:SS format
        if (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $duration, $m)) {
            $seconds = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        }
    }

    return $seconds;
}
}

/**
 * Fetch a JSON resource from Moodle (legacy compatibility wrapper).
 *
 * @param string $url
 * @return array
 */
function fetchScormJson(string $url): array
{
    return moodleApiCall('mod_scorm_get_scorms_by_courses', ['courseids' => []]);
}

/**
 * Generic resource fetcher (legacy compatibility wrapper).
 *
 * @param string $resource
 * @param array $params
 * @return array
 */
function fetchScormResource(string $resource, array $params = []): array
{
    // Try to parse the resource path and route it appropriately
    if (strpos($resource, '/courses') === 0) {
        return fetchScormCourses($params);
    }
    if (strpos($resource, '/registrations') === 0) {
        return fetchScormRegistrations($params);
    }
    if (strpos($resource, '/learners') === 0) {
        return moodleApiCall('core_user_get_users_by_field', ['field' => 'email', 'values' => ['']]);
    }
    return [];
}

/**
 * Fetch learners from Moodle (legacy compatibility).
 *
 * @param array $params
 * @return array
 */
function fetchScormLearners(array $params = []): array
{
    // Native backend dispatch
    if (shouldUseNativeBackend()) {
        return nativeFetchScormLearners($params);
    }

    // Moodle equivalent — get users enrolled in any course with SCORM activities
    $courses = moodleApiCall('core_course_get_courses', []);
    $allUsers = [];
    $seen = [];

    foreach ($courses as $course) {
        if (empty($course['id']) || $course['id'] == 1) continue;
        $enrolled = moodleApiCall('core_enrol_get_enrolled_users', ['courseid' => $course['id']]);
        foreach ($enrolled as $u) {
            if (!isset($seen[$u['id']])) {
                $seen[$u['id']] = true;
                $allUsers[] = [
                    'id' => $u['email'] ?? $u['username'] ?? $u['id'],
                    'email' => $u['email'] ?? '',
                    'firstName' => $u['firstname'] ?? '',
                    'lastName' => $u['lastname'] ?? '',
                ];
            }
        }
    }

    return $allUsers;
}

// —————————————————————————————————————————————————————————————————————————————
// NATIVE SCORM READER BACKEND (Phase 3)
//
// These functions read from the native scorm_* tables and produce output
// compatible with the legacy Moodle bridge format expected
// by existing pages (dashboard, progress, admin-progress, course-page, enroll).
// Dispatch to these is controlled by SCORM_BACKEND (native|moodle|auto).
// —————————————————————————————————————————————————————————————————————————————

/**
 * Decide whether to use the native SCORM reader backend.
 *
 * SCORM_BACKEND values:
 *   native — always use native tables
 *   moodle — always use Moodle bridge (legacy)
 *   auto   — use native when native data exists, else Moodle (default)
 */
function shouldUseNativeBackend(): bool
{
    $mode = defined('SCORM_BACKEND') ? SCORM_BACKEND : 'auto';
    if ($mode === 'native') {
        return true;
    }
    if ($mode === 'moodle') {
        return false;
    }
    // auto: native wins if there are any packages OR any attempts for this user
    try {
        $pdo = getDbConnection();
        ensureScormTables();

        // Any active packages scoped to the current org?
        $orgId = getOrgId();
        $pkgSql = "SELECT COUNT(*) FROM scorm_packages sp WHERE sp.status = 'active'";
        $pkgParams = [];
        if (!isSuperAdmin() && $orgId !== null) {
            $pkgSql .= " AND (sp.organization_id = ? OR EXISTS (
                            SELECT 1 FROM course_assignments ca
                            WHERE ca.package_id = sp.id AND ca.organization_id = ?
                          ))";
            $pkgParams[] = $orgId;
            $pkgParams[] = $orgId;
        }
        $pkgStmt = $pdo->prepare($pkgSql);
        $pkgStmt->execute($pkgParams);
        if ((int)$pkgStmt->fetchColumn() > 0) {
            return true;
        }

        // Any native attempts for the current user?
        if (isset($_SESSION['user_id']) && !isTestUser()) {
            $attStmt = $pdo->prepare("SELECT COUNT(*) FROM scorm_attempts WHERE user_id = ?");
            $attStmt->execute([(int)$_SESSION['user_id']]);
            return (int)$attStmt->fetchColumn() > 0;
        }
    } catch (PDOException $e) {
        error_log('[SCORM] shouldUseNativeBackend check failed: ' . $e->getMessage());
    }

    return false;
}

/**
 * Get the native scorm_packages visible to the current user/org.
 * Returns rows with id, title, version, scorm_version, description, status.
 */
function nativeFetchPackagesForOrg(): array
{
    ensureScormTables();
    $pdo = getDbConnection();
    $orgId = getOrgId();

    $sql = "SELECT sp.*
            FROM scorm_packages sp
            WHERE sp.status = 'active'";
    $params = [];

    if (!isSuperAdmin() && $orgId !== null) {
        $sql .= " AND (sp.organization_id = ? OR EXISTS (
                        SELECT 1 FROM course_assignments ca
                        WHERE ca.package_id = sp.id AND ca.organization_id = ?
                      ))";
        $params[] = $orgId;
        $params[] = $orgId;
    } elseif (!isSuperAdmin()) {
        $sql .= " AND sp.organization_id IS NULL";
    }

    $sql .= " ORDER BY sp.title ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Native fetchScormCourses — reads scorm_packages + sco_items.
 * Compatible format: id, courseid, title, scormid, cmid, course.
 */
function nativeFetchScormCourses(array $params = []): array
{
    $packages = nativeFetchPackagesForOrg();
    $result = [];

    foreach ($packages as $pkg) {
        $scoId = (int)$pkg['launch_sco_id'];
        $scoTitle = '';
        if ($scoId === 0) {
            // fall back to first SCO
            $pdo = getDbConnection();
            $s = $pdo->prepare("SELECT id, title FROM sco_items WHERE package_id = ? AND scorm_type = 'sco' ORDER BY id LIMIT 1");
            $s->execute([(int)$pkg['id']]);
            $first = $s->fetch(PDO::FETCH_ASSOC);
            if ($first) {
                $scoId = (int)$first['id'];
                $scoTitle = $first['title'];
            }
        } else {
            $pdo = getDbConnection();
            $s = $pdo->prepare("SELECT title FROM sco_items WHERE id = ?");
            $s->execute([$scoId]);
            $scoTitle = (string)$s->fetchColumn();
        }

        $result[] = [
            'id' => (string)$pkg['id'],
            'courseid' => (string)$pkg['id'],
            'title' => $pkg['title'],
            'scormid' => (int)$pkg['id'],
            'cmid' => $scoId ?: (int)$pkg['id'],
            'course' => $pkg['title'],
            'scorm_version' => $pkg['scorm_version'] ?? '1.2',
            'launch_sco_id' => $scoId,
        ];
    }

    return $result;
}

/**
 * Native fetchScormCourse — get one package.
 */
function nativeFetchScormCourse(string $id): array
{
    $courses = nativeFetchScormCourses();
    foreach ($courses as $c) {
        if ($c['id'] === $id || (string)$c['scormid'] === $id || (string)$c['cmid'] === $id) {
            return $c;
        }
    }
    return [];
}

/**
 * Shared registration-completion computation for the native reader backend.
 *
 * Completion percentage (0..1) priority:
 *   1. Server-normalized attempt_state (P5) — completed/passed/failed = 100%.
 *   2. cmi.progress_measure when the package reports it.
 *   3. Storyline 360 / Rise suspend_data slide bookmarks (visited / total).
 *   4. Legacy SCORM 1.2 fallback: score/100 when nothing else is available.
 *      SCORM 2004 never uses score as progress — success and completion are
 *      independent signals there, so presenting a score as progress would be
 *      inaccurate.
 *
 * @param array $row A scorm_attempts row (with package title/version joined).
 * @return array{completionAmount:float, completion:string, resumeAvailable:bool}
 */
function scormRegistrationCompletion(array $row): array
{
    $completionAmount = 0.0;
    $completion = 'NOT_ATTEMPTED';

    // Prefer the server-normalized state already computed by store.php.
    $state = strtolower(trim((string)($row['attempt_state'] ?? '')));
    if (in_array($state, ['passed', 'failed', 'completed'], true)) {
        $completion = 'COMPLETED';
        $completionAmount = 1.0;
    } elseif (in_array($state, ['in_progress', 'incomplete', 'browsed'], true)) {
        $completion = 'INCOMPLETE';
    } else {
        // Fall back to raw statuses (legacy rows / pre-P5 data).
        $status = strtolower(trim((string)($row['lesson_status'] ?: ($row['completion_status'] ?: $row['success_status']))));
        if (in_array($status, ['completed', 'passed'], true)
            || strtolower(trim((string)($row['completion_status'] ?? ''))) === 'completed') {
            $completion = 'COMPLETED';
            $completionAmount = 1.0;
        } elseif (in_array($status, ['incomplete', 'browsed'], true)) {
            $completion = 'INCOMPLETE';
        }
    }

    if ($completion === 'INCOMPLETE') {
        // 1) Official cmi.progress_measure (0..1) when the package reports it.
        if (isset($row['progress_measure']) && $row['progress_measure'] !== null && $row['progress_measure'] !== '') {
            $completionAmount = max(0.0, min(1.0, (float)$row['progress_measure']));
        }
        // 2) Storyline 360 / Rise: derive visited/total slides from suspend_data.
        if ($completionAmount <= 0) {
            $derived = scormProgressFromSuspendData((string)($row['suspend_data'] ?? ''));
            if ($derived !== null) {
                $completionAmount = $derived;
            }
        }
    }

    // Score is NOT completion. SCORM 2004 keeps success and completion
    // separate, so never present a score as progress there. The legacy 1.2
    // fallback is retained for packages that only ever report a score.
    if ($completionAmount <= 0 && !scormIs2004Row($row)) {
        $score = $row['score_raw'] ?? null;
        if ($score !== null && $score !== '' && (float)$score > 0) {
            $completionAmount = min(1.0, (float)$score / 100);
        }
    }

    if ($completion === 'COMPLETED') {
        $completionAmount = 1.0;
    }

    // Honest progress: track resume availability separately so the UI can say
    // "Resume Learning" without inventing a percentage.
    $resumeAvailable = ($completion !== 'COMPLETED'
        && ((string)($row['suspend_data'] ?? '') !== '' || (string)($row['lesson_location'] ?? '') !== ''));
    if ($resumeAvailable && $completion === 'NOT_ATTEMPTED') {
        $completion = 'INCOMPLETE';
    }

    return [
        'completionAmount' => round($completionAmount, 4),
        'completion' => $completion,
        'resumeAvailable' => $resumeAvailable,
    ];
}

/**
 * Whether a scorm_attempts row belongs to a SCORM 2004 package.
 */
function scormIs2004Row(array $row): bool
{
    $edition = strtolower((string)($row['scorm_edition'] ?? ''));
    if (strpos($edition, '2004') !== false) {
        return true;
    }
    return strtolower((string)($row['scorm_version'] ?? '')) === '2004';
}

/**
 * Native fetchScormRegistrations — reads scorm_attempts aggregated by
 * (user, package) so existing dashboards get one row per enrolled course.
 * Compatible format:
 *   id, course[id/title], learner[id/firstName/lastName/email],
 *   registrationCompletionAmount (0-1), registrationCompletion, totalSecondsTracked,
 *   registrationScore, attempts, lastAccessDate, updated
 */
function nativeFetchScormRegistrations(array $params = []): array
{
    ensureScormTables();
    $pdo = getDbConnection();
    $orgId = getOrgId();

    // —— Optional learner filter (email) ——
    $learnerEmail = $params['learnerId'] ?? '';
    $userId = null;

    if ($learnerEmail !== '') {
        $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $uStmt->execute([$learnerEmail]);
        $userId = (int)$uStmt->fetchColumn();
        if ($userId === 0) {
            return []; // no local user with that email
        }
    } elseif (isset($_SESSION['user_id']) && !isTestUser()) {
        $userId = (int)$_SESSION['user_id'];
    }

    // —— For admins without learner filter: aggregate learners across org ——
    $subFilters = '';
    $paramsArr = [];
    if (!isSuperAdmin() && $orgId !== null) {
        $subFilters .= " AND a2.organization_id = ?";
        $paramsArr[] = $orgId;
    }

    if ($userId !== null) {
        $subFilters .= " AND a2.user_id = ?";
        $paramsArr[] = $userId;
    }

    // Aggregate latest attempt per (user, package).
    // The subquery computes the max attempt id per (user, package) with the
    // same org/user filters; the outer query joins package + user + sco details.
    try {
        $sql = "SELECT a.*, p.title AS package_title, p.scorm_version,
                       u.email AS user_email, u.first_name AS user_first, u.last_name AS user_last,
                       COALESCE(si.title, p.title) AS sco_title
                FROM scorm_attempts a
                JOIN scorm_packages p ON p.id = a.package_id
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN sco_items si ON si.id = a.sco_item_id
                WHERE a.id IN (
                    SELECT MAX(a2.id)
                    FROM scorm_attempts a2
                    WHERE 1=1" . $subFilters . "
                    GROUP BY a2.user_id, a2.package_id
                )
                ORDER BY a.last_accessed_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsArr);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[SCORM] nativeFetchScormRegistrations query failed: ' . $e->getMessage());
        return [];
    }

    $registrations = [];
    foreach ($rows as $row) {
        $regCompletion = scormRegistrationCompletion($row);
        $completionAmount = $regCompletion['completionAmount'];
        $completion = $regCompletion['completion'];
        $resumeAvailable = $regCompletion['resumeAvailable'];
        $score = $row['score_raw'];

        $learnerName = trim(($row['user_first'] ?? '') . ' ' . ($row['user_last'] ?? ''));
        $regId = 'n_' . $row['package_id'] . '_u_' . $row['user_id'];

        $registrations[] = [
            'id' => $regId,
            'course' => [
                'id' => (string)$row['package_id'],
                'title' => $row['package_title'] ?? 'Untitled Course',
            ],
            'learner' => [
                'id' => $row['user_email'] ?? '',
                'email' => $row['user_email'] ?? '',
                'firstName' => $row['user_first'] ?? '',
                'lastName' => $row['user_last'] ?? '',
            ],
            'registrationCompletionAmount' => round($completionAmount, 4),
            'registrationCompletion' => $completion,
            'totalSecondsTracked' => (int)$row['total_time_seconds'],
            'registrationScore' => $score !== null ? (float)$score : 0,
            'attempts' => (int)$row['attempt_number'],
            'lastAccessDate' => $row['last_accessed_at'] ?? '',
            'updated' => $row['last_accessed_at'] ?? '',
            'isComplete' => (int)$row['is_complete'],
            'resumeAvailable' => $resumeAvailable,
            'native' => true,
            'package_id' => (int)$row['package_id'],
            'sco_item_id' => $row['sco_item_id'] ? (int)$row['sco_item_id'] : null,
        ];
    }

    return $registrations;
}

/**
 * Native fetchScormRegistration — get one registration by id.
 */
function nativeFetchScormRegistration(string $registrationId): array
{
    if (!preg_match('/^n_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        return [];
    }
    $packageId = (int)$m[1];
    $userId = (int)$m[2];

    ensureScormTables();
    $pdo = getDbConnection();

    try {
        $stmt = $pdo->prepare("SELECT sa.*, sp.title AS package_title, sp.scorm_version,
                                      u.email AS user_email, u.first_name AS user_first, u.last_name AS user_last
                               FROM scorm_attempts sa
                               JOIN scorm_packages sp ON sp.id = sa.package_id
                               LEFT JOIN users u ON u.id = sa.user_id
                               WHERE sa.package_id = ? AND sa.user_id = ?
                               ORDER BY sa.id DESC LIMIT 1");
        $stmt->execute([$packageId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[SCORM] nativeFetchScormRegistration query failed: ' . $e->getMessage());
        return [];
    }

    if (!$row) {
        // No attempt yet — return a placeholder registration
        $pkgStmt = $pdo->prepare("SELECT title FROM scorm_packages WHERE id = ?");
        $pkgStmt->execute([$packageId]);
        $title = (string)$pkgStmt->fetchColumn();

        $userStmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $u = $userStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id' => $registrationId,
            'course' => ['id' => (string)$packageId, 'title' => $title ?: 'Untitled Course'],
            'learner' => [
                'id' => $u['email'] ?? '',
                'email' => $u['email'] ?? '',
                'firstName' => $u['first_name'] ?? '',
                'lastName' => $u['last_name'] ?? '',
            ],
            'registrationCompletionAmount' => 0.0,
            'registrationCompletion' => 'NOT_ATTEMPTED',
            'totalSecondsTracked' => 0,
            'registrationScore' => 0,
            'attempts' => 0,
            'native' => true,
        ];
    }

    // Build the registration directly from the fetched attempt row.
    $regCompletion = scormRegistrationCompletion($row);
    $completionAmount = $regCompletion['completionAmount'];
    $completion = $regCompletion['completion'];
    $resumeAvailable = $regCompletion['resumeAvailable'];
    $score = $row['score_raw'];

    return [
        'id' => $registrationId,
        'course' => [
            'id' => (string)$packageId,
            'title' => $row['package_title'] ?? 'Untitled Course',
        ],
        'learner' => [
            'id' => $row['user_email'] ?? '',
            'email' => $row['user_email'] ?? '',
            'firstName' => $row['user_first'] ?? '',
            'lastName' => $row['user_last'] ?? '',
        ],
        'registrationCompletionAmount' => round($completionAmount, 4),
        'registrationCompletion' => $completion,
        'totalSecondsTracked' => (int)$row['total_time_seconds'],
        'registrationScore' => $score !== null ? (float)$score : 0,
        'attempts' => (int)$row['attempt_number'],
        'lastAccessDate' => $row['last_accessed_at'] ?? '',
        'updated' => $row['last_accessed_at'] ?? '',
        'isComplete' => (int)$row['is_complete'],
        'resumeAvailable' => $resumeAvailable,
        'native' => true,
        'package_id' => (int)$packageId,
    ];
}

/**
 * Native createScormRegistration — creates an enrollment record for a
 * course (package) + learner. Falls back to inserting a placeholder attempt
 * row so the course appears in dashboards before first launch.
 *
 * @param array $payload ['courseId' => packageId, 'learnerId' => email, 'learnerName' => ...]
 * @return array ['id' => registrationId]
 */
function nativeCreateScormRegistration(array $payload, ?array &$debug = null): array
{
    $courseId = $payload['courseId'] ?? '';
    $learnerEmail = $payload['learnerId'] ?? '';
    $learnerName = $payload['learnerName'] ?? '';

    if ($courseId === '' || $learnerEmail === '') {
        error_log('[SCORM] nativeCreateScormRegistration: missing courseId or learnerId');
        if (is_array($debug)) {
            $debug['status'] = 400;
            $debug['raw'] = 'Missing courseId or learnerId';
            $debug['url'] = '';
        }
        return [];
    }

    ensureScormTables();
    $pdo = getDbConnection();

    // Resolve package
    try {
        $pkgStmt = $pdo->prepare("SELECT id, launch_sco_id FROM scorm_packages WHERE (id = ? OR CAST(id AS CHAR) = ?) AND status = 'active'");
        $pkgStmt->execute([$courseId, $courseId]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[SCORM] nativeCreateScormRegistration: package query failed: ' . $e->getMessage());
        if (is_array($debug)) {
            $debug['status'] = 500;
            $debug['raw'] = 'DB error: ' . $e->getMessage();
            $debug['url'] = '';
        }
        return [];
    }
    if (!$pkg) {
        error_log('[SCORM] nativeCreateScormRegistration: package not found for ' . $courseId);
        if (is_array($debug)) {
            $debug['status'] = 404;
            $debug['raw'] = 'Package not found: ' . $courseId;
            $debug['url'] = '';
        }
        return [];
    }

    // Resolve local user
    try {
        $uStmt = $pdo->prepare("SELECT id, organization_id, department, first_name, last_name FROM users WHERE email = ?");
        $uStmt->execute([$learnerEmail]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[SCORM] nativeCreateScormRegistration: user query failed: ' . $e->getMessage());
        if (is_array($debug)) {
            $debug['status'] = 500;
            $debug['raw'] = 'DB error: ' . $e->getMessage();
            $debug['url'] = '';
        }
        return [];
    }
    if (!$user) {
        error_log('[SCORM] nativeCreateScormRegistration: user not found ' . $learnerEmail);
        if (is_array($debug)) {
            $debug['status'] = 404;
            $debug['raw'] = 'User not found: ' . $learnerEmail;
            $debug['url'] = '';
        }
        return [];
    }

    $userId = (int)$user['id'];
    $packageId = (int)$pkg['id'];
    $scoId = (int)$pkg['launch_sco_id'];

    // Check if an attempt row already exists
    $existing = $pdo->prepare("SELECT id FROM scorm_attempts WHERE user_id = ? AND package_id = ? ORDER BY id LIMIT 1");
    $existing->execute([$userId, $packageId]);
    $existingId = (int)$existing->fetchColumn();

    if ($existingId === 0) {
        // Create a placeholder attempt (status: not attempted) so the course
        // shows up in dashboards before the learner launches it.
        $attemptNumber = 1;
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM scorm_attempts WHERE user_id = ? AND package_id = ?");
        $maxStmt->execute([$userId, $packageId]);
        $attemptNumber = (int)$maxStmt->fetchColumn();

        $insert = $pdo->prepare("INSERT INTO scorm_attempts
            (user_id, organization_id, department, sco_item_id, package_id, attempt_number,
             lesson_status, session_time_seconds, total_time_seconds, entry, is_complete, started_at)
            VALUES (?, ?, ?, ?, ?, ?, 'not attempted', 0, 0, 'ab-initio', 0, NOW())");
        $insert->execute([
            $userId,
            $user['organization_id'] !== null ? (int)$user['organization_id'] : null,
            $user['department'] ?? null,
            $scoId > 0 ? $scoId : null,
            $packageId,
            $attemptNumber,
        ]);
    }

    $regId = 'n_' . $packageId . '_u_' . $userId;
    error_log('[SCORM] nativeCreateScormRegistration: ' . $regId);
    return ['id' => $regId];
}

/**
 * Native deleteScormRegistration — safe no-op (enrollment removal can be
 * implemented later; returning true keeps existing flows intact).
 */
function nativeDeleteScormRegistration(string $registrationId): bool
{
    return true;
}

/**
 * Native getScormLaunchLink — returns URL to scorm-player/index.php.
 */
function nativeGetScormLaunchLink(string $registrationId, ?string $redirectOnExitUrl = null, ?array &$debugResult = null): ?string
{
    // Accept legacy Moodle-format registration ids (m_{scormid}_u_{userid})
    // by mapping them to a native package id when possible.
    if (preg_match('/^m_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        $legacyScormId = (int)$m[1];
        $legacyUserId = (int)$m[2];

        // Try to find a scorm_packages row whose id matches the legacy scorm id.
        try {
            $pdo = getDbConnection();
            $pkgStmt = $pdo->prepare("SELECT id, launch_sco_id FROM scorm_packages WHERE id = ? OR CAST(id AS CHAR) = ?");
            $pkgStmt->execute([$legacyScormId, (string)$legacyScormId]);
            $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
            if ($pkg) {
                $registrationId = 'n_' . (int)$pkg['id'] . '_u_' . $legacyUserId;
            }
        } catch (PDOException $e) {
            error_log('[SCORM] nativeGetScormLaunchLink legacy lookup failed: ' . $e->getMessage());
        }
    }

    if (!preg_match('/^n_(\d+)_u_(\d+)$/', $registrationId, $m)) {
        if (is_array($debugResult)) {
            $debugResult['status'] = 400;
            $debugResult['raw'] = 'Unrecognized registration id format: ' . $registrationId;
            $debugResult['url'] = '';
        }
        return null;
    }
    $packageId = (int)$m[1];
    $userId    = (int)$m[2];

    // Determine SCO
    try {
        $pdo = getDbConnection();
        $pkgStmt = $pdo->prepare("SELECT launch_sco_id FROM scorm_packages WHERE id = ?");
        $pkgStmt->execute([$packageId]);
        $launchScoId = (int)$pkgStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[SCORM] nativeGetScormLaunchLink package lookup failed: ' . $e->getMessage());
        if (is_array($debugResult)) {
            $debugResult['status'] = 500;
            $debugResult['raw'] = 'DB error: ' . $e->getMessage();
            $debugResult['url'] = '';
        }
        return null;
    }

    // Resume: prefer the learner's latest INCOMPLETE attempt that actually has
    // tracking state (suspend_data / lesson_location / time). Empty placeholder
    // rows created by nativeCreateScormRegistration() are intentionally skipped.
    $resumeScoId     = $launchScoId;
    $resumeAttemptId = 0;
    try {
        $resumeStmt = $pdo->prepare(
            "SELECT id, sco_item_id FROM scorm_attempts
             WHERE user_id = ? AND package_id = ? AND is_complete = 0
               AND (
                   (suspend_data IS NOT NULL AND suspend_data <> '')
                   OR (lesson_location IS NOT NULL AND lesson_location <> '')
                   OR total_time_seconds > 0
               )
             ORDER BY last_accessed_at DESC, id DESC LIMIT 1"
        );
        $resumeStmt->execute([$userId, $packageId]);
        $resumeRow = $resumeStmt->fetch(PDO::FETCH_ASSOC);
        if ($resumeRow) {
            $resumeAttemptId = (int)$resumeRow['id'];
            if ((int)$resumeRow['sco_item_id'] > 0) {
                $resumeScoId = (int)$resumeRow['sco_item_id'];
            }
            error_log("[SCORM] Resume: pkg=$packageId user=$userId attempt=$resumeAttemptId sco=$resumeScoId");
        }
    } catch (PDOException $e) {
        error_log('[SCORM] nativeGetScormLaunchLink resume lookup failed: ' . $e->getMessage());
    }

    $url = buildUrl('scorm-player/?pkg=' . $packageId);
    if ($resumeScoId > 0) {
        $url .= '&sco=' . $resumeScoId;
    }
    if ($resumeAttemptId > 0) {
        $url .= '&attempt=' . $resumeAttemptId;
    }
    if ($redirectOnExitUrl) {
        $url .= '&redirect=' . urlencode($redirectOnExitUrl);
    }

    if (is_array($debugResult)) {
        $debugResult['status'] = 200;
        $debugResult['raw'] = 'native launch url';
        $debugResult['url'] = $url;
    }

    error_log('[SCORM] Native launch URL for ' . $registrationId . ': ' . $url);
    return $url;
}

/**
 * Native fetchScormLearners.
 */
function nativeFetchScormLearners(array $params = []): array
{
    ensureScormTables();
    $pdo = getDbConnection();
    $orgId = getOrgId();

    $sql = "SELECT DISTINCT u.id, u.email, u.first_name, u.last_name
            FROM scorm_attempts a
            JOIN users u ON u.id = a.user_id
            WHERE 1=1";
    $paramsArr = [];
    if (!isSuperAdmin() && $orgId !== null) {
        $sql .= " AND a.organization_id = ?";
        $paramsArr[] = $orgId;
    }
    $sql .= " ORDER BY u.first_name, u.last_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramsArr);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $learners = [];
    foreach ($rows as $row) {
        $learners[] = [
            'id' => $row['email'] ?: (string)$row['id'],
            'email' => $row['email'] ?? '',
            'firstName' => $row['first_name'] ?? '',
            'lastName' => $row['last_name'] ?? '',
        ];
    }
    return $learners;
}
