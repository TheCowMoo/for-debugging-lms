<?php
/**
 * Application bootstrap and shared configuration.
 * Centralizes environment detection, session handling, and database access.
 * Uses Moodle + native SCORM reader as the backend.
 */

// Environment detection — proxy-aware (nginx, Cloudflare, etc.)
$host = $_SERVER['HTTP_HOST'] ?? getenv('APP_DOMAIN') ?: 'localhost';
$localhostPattern = '/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/';
$IS_LOCALHOST = preg_match($localhostPattern, $host) === 1;

// Detect HTTPS correctly even behind reverse proxies
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $SCHEME = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $SCHEME = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    $SCHEME = 'https';
} elseif (!empty($_SERVER['HTTP_CF_VISITOR'])) {
    $cfVisitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
    $SCHEME = ($cfVisitor['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
} else {
    $SCHEME = 'http';
}

$BASE_URL = "$SCHEME://$host";
$COOKIE_DOMAIN = '';
$COOKIE_SECURE = ($SCHEME === 'https');

// On localhost, never set secure cookie — regardless of headers
if ($IS_LOCALHOST) {
    $COOKIE_SECURE = false;
}

// Session cookie configuration
// PHP >= 7.3 supports the array form; older versions need the legacy form.
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $COOKIE_DOMAIN,
        'secure' => $COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(0, '/', $COOKIE_DOMAIN, $COOKIE_SECURE, true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security headers (skip for course-viewer, course-runner, scorm-content, and
// scorm-player which need to be embedded in iframes)
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (
    strpos($uri, 'course-viewer') !== false ||
    strpos($uri, 'course-runner') !== false ||
    strpos($uri, 'scorm-content') !== false ||
    strpos($uri, 'scorm-player') !== false
) {
    header("X-Frame-Options: SAMEORIGIN");
} else {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}
header("X-XSS-Protection: 1; mode=block");

// —— Premium security headers ——
// HSTS: force HTTPS for all future requests (only when already on HTTPS).
if ($SCHEME === 'https') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
// Suppress the PHP version in response headers (removes "X-Powered-By").
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}
// Disallow Adobe/PDF cross-domain policy files.
header("X-Permitted-Cross-Domain-Policies: none");

// ---- Load .env file ----
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Application environment
define('APP_ENV', getenv('APP_ENV') ?: 'production');
$isDev = APP_ENV === 'development';

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: ($isDev ? '127.0.0.1' : die('DB_HOST not set')));
define('DB_NAME', getenv('DB_NAME') ?: ($isDev ? 'Nathan_scorm_system' : die('DB_NAME not set')));
define('DB_USER', getenv('DB_USER') ?: ($isDev ? 'Nathan_scorm_admin' : die('DB_USER not set')));
define('DB_PASS', getenv('DB_PASS') ?: ($isDev ? 'Marketingcow1!' : die('DB_PASS not set')));

// —— Native SCORM Reader (Phase 1) ——
// Storage location for uploaded, extracted SCORM packages.
// Files are stored under public_html/content/scorm and protected by .htaccess
// (deny all). Public delivery is handled exclusively through scorm-content/serve.php
define('SCORM_STORAGE_PATH', getenv('SCORM_STORAGE_PATH') ?: (__DIR__ . '/content/scorm'));
define('SCORM_MAX_UPLOAD_SIZE', 512 * 1024 * 1024); // 512 MB max package size

// SCORM HTML rewrite cache — stores fully injected/rewritten HTML pages so the
// expensive regex rewriting in serve.php runs once per (package_id, path, user)
// instead of on every request. SCORM packages are immutable once uploaded
// (re-uploads create a NEW package_id), so cached entries never go stale.
// The directory lives under content/ which is protected by .htaccess (deny all).
define('SCORM_CACHE_PATH', getenv('SCORM_CACHE_PATH') ?: (__DIR__ . '/content/cache/scorm'));

// SCORM runtime compatibility mode:
//   '1' (default) — RTE accepts cross-version spellings (1.2 elements on 2004
//                   packages and vice versa) for maximum Storyline/Rise compatibility.
//   '0'           — strict mode: content must use exactly the declared SCORM API.
define('SCORM_COMPAT_MODE', getenv('SCORM_COMPAT_MODE') ?: '1');

// SCORM backend selection:
//   native — use the native SCORM reader tables only
//   moodle — use the Moodle API bridge only (legacy)
//   auto   — use native when data exists for the learner, else Moodle (default)
define('SCORM_BACKEND', getenv('SCORM_BACKEND') ?: 'auto');
define('S3_BUCKET', getenv('S3_BUCKET') ?: '');
define('S3_REGION', getenv('S3_REGION') ?: 'us-east-1');
define('S3_KEY', getenv('S3_KEY') ?: '');
define('S3_SECRET', getenv('S3_SECRET') ?: '');
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: '');
define('S3_DEBUG', getenv('S3_DEBUG') === '1');
define('S3_PREFIX', getenv('S3_PREFIX') ?: 'scorm-content/');
require_once __DIR__ . '/s3-helpers.php';

// —— reCAPTCHA (Anti-bot) ——
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '6Le9sXgtAAAAAE5KUjvUpYAcpVPCx9ncgAEyAEyt');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '6Le9sXgtAAAAAOv68fmyvK5--NrsljT98IgI26B_');
define('RECAPTCHA_SCORE_THRESHOLD', (float)(getenv('RECAPTCHA_SCORE_THRESHOLD') ?: '0.5'));

// —— API Key (for authenticating webhook/API requests) ——
// Set this in your .env file. Requests to /api/* must include either:
//   - Header: X-API-Key: <your-key>
//   - Header: Authorization: Bearer <your-key>
//   - A valid admin/super_admin session cookie
define('API_KEY', getenv('API_KEY') ?: '');

// Demo signup notifications ("New Demo Signup" email) are sent to this address
// when someone signs up via a demo invite link.
define('DEMO_SIGNUP_NOTIFY_EMAIL', getenv('DEMO_SIGNUP_NOTIFY_EMAIL') ?: '');

/**
 * Verify reCAPTCHA v3 (or v2) token with Google's API.
 * For v3: returns true if success && score >= threshold.
 * For v2: returns true if success.
 *
 * @param  string $token   The g-recaptcha-response token from the client
 * @param  string $action  Optional v3 action name to validate (e.g. 'login', 'signup')
 * @return bool
 */
function verifyRecaptcha(string $token, string $action = ''): bool
{
    if ($token === '') {
        error_log('[RECAPTCHA] Empty token submitted');
        return false;
    }

    $postFields = [
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://www.google.com/recaptcha/api/siteverify',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpStatus !== 200) {
        error_log('[RECAPTCHA] Verify request failed: HTTP ' . $httpStatus);
        return false; // Fail closed
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        error_log('[RECAPTCHA] Invalid JSON response from Google');
        return false;
    }

    $success = (bool)($data['success'] ?? false);
    if (!$success) {
        $errorCodes = implode(', ', $data['error-codes'] ?? ['unknown']);
        error_log('[RECAPTCHA] Verification failed: ' . $errorCodes);
        return false;
    }

    // —— v3 score check ——
    $score = $data['score'] ?? null;
    if ($score !== null) {
        // This is a v3 token (score is only present for v3)
        if ($score < RECAPTCHA_SCORE_THRESHOLD) {
            error_log("[RECAPTCHA] Score too low: {$score} < " . RECAPTCHA_SCORE_THRESHOLD);
            return false;
        }

        // Validate action if specified
        if ($action !== '' && ($data['action'] ?? '') !== $action) {
            error_log("[RECAPTCHA] Action mismatch: expected '{$action}', got '" . ($data['action'] ?? '') . "'");
            // Don't fail on action mismatch — some edge cases with async loading
        }

        error_log("[RECAPTCHA] v3 OK — score: {$score}, action: " . ($data['action'] ?? 'none'));
    }

    return true;
}

/**
 * Simple IP-based rate limiting for registration endpoints.
 * Uses a flat JSON file to track attempts per IP per hour window.
 *
 * @param  string $endpoint  Identifier (e.g. 'signup', 'api-register')
 * @param  int    $maxAttempts  Max allowed in window (default 5)
 * @param  int    $windowSeconds Window length in seconds (default 3600 = 1 hour)
 * @return bool  True if request is allowed, false if rate-limited
 */
function checkRegistrationRateLimit(string $endpoint = 'signup', int $maxAttempts = 5, int $windowSeconds = 3600): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ipKey = md5($ip); // Hash IP so the file isn't a plaintext IP list

    $rateFile = __DIR__ . '/content/cache/ratelimit.json';

    $data = [];
    if (file_exists($rateFile)) {
        $raw = @file_get_contents($rateFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    $now = time();
    $windowKey = $endpoint . ':' . $ipKey;

    // Clean up old entries (> 2x window to prevent file growth)
    $cutoff = $now - ($windowSeconds * 2);
    foreach ($data as $key => $entry) {
        if (($entry['ts'] ?? 0) < $cutoff) {
            unset($data[$key]);
        }
    }

    // Get attempts in current window
    $attempts = 0;
    if (isset($data[$windowKey])) {
        $entries = $data[$windowKey]['entries'] ?? [];
        // Filter to only entries within the window
        $recent = array_filter($entries, function ($ts) use ($now, $windowSeconds) {
            return $ts > ($now - $windowSeconds);
        });
        $attempts = count($recent);
        $data[$windowKey]['entries'] = array_values($recent);
    }

    if ($attempts >= $maxAttempts) {
        error_log('[RATELIMIT] Blocked IP ' . $ip . ' on ' . $endpoint . ' (' . $attempts . ' attempts)');
        return false;
    }

    // Record this attempt
    if (!isset($data[$windowKey])) {
        $data[$windowKey] = ['ts' => $now, 'entries' => []];
    }
    $data[$windowKey]['entries'][] = $now;
    $data[$windowKey]['ts'] = $now;

    // Write back atomically
    $tmpFile = $rateFile . '.tmp';
    if (@file_put_contents($tmpFile, json_encode($data), LOCK_EX) !== false) {
        @rename($tmpFile, $rateFile);
    }
    // If writing fails, allow the request (don't block legitimate users because of disk issues)

    return true;
}

// —— Moodle SCORM Backend ——
// Load the Moodle bridge which provides all SCORM replacement functions
require_once __DIR__ . '/moodle-bridge.php';

define('TEST_USER_EMAIL', getenv('TEST_USER_EMAIL') ?: ($isDev ? 'test@example.local' : ''));
define('TEST_USER_PASSWORD', getenv('TEST_USER_PASSWORD') ?: ($isDev ? 'Test1234!' : ''));
define('TEST_USER_ID', getenv('TEST_USER_ID') ?: ($isDev ? 'test-user' : ''));

define('BASE_URL', $BASE_URL);
define('IS_LOCALHOST', $IS_LOCALHOST);

// —— Branding helpers ——

function getSiteName(): string
{
    return getenv('SITE_NAME') ?: 'Pursuit Pathways';
}

function getLogoUrl(): string
{
    $filename = getenv('LOGO_FILENAME') ?: 'PPlogo-C-450x1200px-T (1).png';
    return buildUrl('content/' . $filename);
}

function getFaviconUrl(): string
{
    $filename = getenv('FAVICON_FILENAME') ?: 'PPicon-C.svg';
    return buildUrl('content/' . $filename);
}

// —— CSRF token management ——

// —— Stateless CSRF secret ——
// Derived from stable env values — no new .env config needed.
define('APP_CSRF_SECRET', hash('sha256', (DB_PASS ?: 'dev') . '|' . (getenv('APP_DOMAIN') ?: 'local')));

function csrfBase64Encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function csrfBase64Decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'));
    return $decoded === false ? '' : $decoded;
}

function generateCsrfToken(): string
{
    // Stateless HMAC token: session_id.timestamp.signature
    // Self-validating — no session write/read required on the server.
    // Additionally stored in the session so the legacy fallback in
    // validateCsrfToken() works as a safety net.
    static $token = null;
    if ($token === null) {
        $sid = session_id() ?: '';
        $ts  = time();
        $payload = $sid . '.' . $ts;
        $sig = hash_hmac('sha256', $payload, APP_CSRF_SECRET);
        $token = csrfBase64Encode($payload) . '.' . csrfBase64Encode($sig);
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function validateCsrfToken(?string $token): bool
{
    if (empty($token)) {
        return false;
    }

    // —— Try stateless HMAC format: base64(sid.ts).base64(hmac) ——
    $parts = explode('.', (string)$token);
    if (count($parts) === 2) {
        $payload = csrfBase64Decode($parts[0]);
        if ($payload !== '') {
            $pieces = explode('.', $payload, 2);
            if (count($pieces) === 2) {
                $sid = $pieces[0];
                $ts  = (int)$pieces[1];

                // Verify signature
                $expected = hash_hmac('sha256', $payload, APP_CSRF_SECRET);
                $sig = csrfBase64Decode($parts[1]);
                if (hash_equals($expected, $sig)) {
                    // Token is cryptographically genuine (HMAC verified).
                    // Check age (12h max) — replay of stale tokens is prevented
                    // by the timestamp + expiry. No session_id match required.
                    if (time() - $ts <= 12 * 3600) {
                        return true;
                    }
                }
            }
        }
    }

    // —— Fallback: legacy session-based token (still accepted) ——
    if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token)) {
        return true;
    }

    // —— Diagnostics ——
    error_log(sprintf(
        '[CSRF] mismatch — session=%s post_len=%d sess_len=%d post_empty=%d files_empty=%d post_max=%s upload_max=%s',
        substr(session_id(), 0, 8),
        strlen((string)$token),
        strlen((string)($_SESSION['csrf_token'] ?? '')),
        empty($_POST) ? 1 : 0,
        empty($_FILES) ? 1 : 0,
        ini_get('post_max_size'),
        ini_get('upload_max_filesize')
    ));

    return false;
}

function csrfHiddenField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

// —— User helpers ——

function getCurrentUser(): array
{
    if (!isset($_SESSION['user_id'])) {
        redirectTo('login/');
    }

    if (isTestUser()) {
        return [
            'first_name' => 'Test',
            'email' => TEST_USER_EMAIL,
            'role' => 'student',
        ];
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT first_name, email, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[DB] getCurrentUser failed: ' . $e->getMessage());
        return [
            'first_name' => 'Learner',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['user_role'] ?? 'student',
        ];
    }

    if (empty($user)) {
        error_log('[SESSION] User ID ' . ($_SESSION['user_id'] ?? 'null') . ' not found in database. Redirecting to login.');
        session_destroy();
        redirectTo('login/');
    }

    return [
        'first_name' => htmlspecialchars($user['first_name'] ?? 'Learner', ENT_QUOTES, 'UTF-8'),
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'student',
    ];
}

function getCurrentFirstName(): string { $u = getCurrentUser(); return $u['first_name']; }
function getCurrentEmail(): string { $u = getCurrentUser(); return $u['email']; }
function getCurrentRole(): string { $u = getCurrentUser(); return $u['role']; }

function buildUrl(string $path = ''): string
{
    $path = trim($path, '/');
    return rtrim(BASE_URL, '/') . ($path !== '' ? '/' . $path : '');
}

function redirectTo(string $path): void
{
    session_write_close();
    header('Location: ' . buildUrl($path));
    exit;
}

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        try {
            // Heartbeat — returns 1 if the connection is still alive.
            // If the connection has gone away (MySQL timeout, TCP drop),
            // this throws and we create a fresh connection below.
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (\PDOException $e) {
            error_log('[DB] Connection lost during heartbeat — reconnecting. Error: ' . $e->getMessage());
            $pdo = null;
        }
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT            => 5,                               // fail fast on connect
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=600",  // 10 min keepalive
    ]);

    return $pdo;
}

function ensureSegmentOptionsTable(): void
{
    $pdo = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS segment_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        option_value VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_category_value (category, option_value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

function ensureUserRegistrationsTable(): void
{
    $pdo = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS user_registrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        course_id VARCHAR(255) NOT NULL,
        registration_id VARCHAR(255) NOT NULL,
        course_title VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_registration_id (registration_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

function ensureTermsAcceptedColumn(): void
{
    $pdo = getDbConnection();
    try {
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = [];
        while ($row = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        if (!in_array('terms_accepted_at', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN terms_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER is_team_lead");
        }
    } catch (PDOException $e) {
        error_log('[DB] ensureTermsAcceptedColumn failed: ' . $e->getMessage());
    }
}

function ensureUserColumns(): void
{
    $pdo = getDbConnection();
    try {
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = [];
        while ($row = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }

        if (!in_array('department', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN department VARCHAR(255) NULL AFTER last_name");
        }

        if (!in_array('is_team_lead', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_team_lead TINYINT(1) NOT NULL DEFAULT 0 AFTER department");
        }

        if (!in_array('reset_token', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL');
        }

        if (!in_array('reset_expiry', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN reset_expiry DATETIME NULL');
        }

        if (!in_array('invite_token_id', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN invite_token_id INT NULL');
        }

        if (!in_array('security_version', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN security_version INT NOT NULL DEFAULT 0 AFTER invite_token_id');
        }
    } catch (PDOException $e) {
        error_log('[DB] ensureUserColumns failed: ' . $e->getMessage());
    }
}
/**
 * Ensure the users.preferences column exists (safe ALTER, no-op if present).
 * Stores per-user appearance settings as JSON (theme, font scale).
 */
function ensureUserPreferencesColumn(): void
{
    $pdo = getDbConnection();
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('preferences', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN preferences TEXT NULL AFTER is_team_lead");
        }
    } catch (PDOException $e) {
        error_log('[DB] ensureUserPreferencesColumn failed: ' . $e->getMessage());
    }
}


// —— Shared functions (used by both the moodle bridge and standard UI) ——

function isTestUser(): bool
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] === TEST_USER_ID;
}

function loginLocalTestUser(): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = TEST_USER_ID;
    $_SESSION['email'] = TEST_USER_EMAIL;
    $_SESSION['user_role'] = 'student';
    $_SESSION['organization_id'] = null;
    session_write_close();
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        redirectTo('login/');
    }
}
function getUserPreferences(): array
{
    $defaults = ['theme' => 'light', 'font_scale' => 'normal'];

    // Local test user has no database row - keep preferences in the session only.
    if (isTestUser()) {
        $sessionPrefs = $_SESSION['preferences'] ?? [];
        return array_merge($defaults, array_intersect_key($sessionPrefs, $defaults));
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return $defaults;
    }

    $readPrefs = function () use ($userId): ?array {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT preferences FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && !empty($row['preferences'])) ? json_decode($row['preferences'], true) : [];
    };

    try {
        $prefs = $readPrefs();
    } catch (PDOException $e) {
        try {
            ensureUserPreferencesColumn();
            $prefs = $readPrefs();
        } catch (PDOException $e2) {
            error_log('[DB] getUserPreferences failed: ' . $e2->getMessage());
            $prefs = [];
        }
    }

    if (!is_array($prefs)) {
        $prefs = [];
    }
    return array_merge($defaults, array_intersect_key($prefs, $defaults));
}

function saveUserPreferences(array $prefs): bool
{
    $allowed = [
        'theme'      => ['light', 'dark'],
        'font_scale' => ['small', 'normal', 'large'],
    ];

    $clean = [];
    foreach ($allowed as $key => $values) {
        if (isset($prefs[$key]) && in_array($prefs[$key], $values, true)) {
            $clean[$key] = $prefs[$key];
        }
    }
    if (empty($clean)) {
        return false;
    }

    if (isTestUser()) {
        $_SESSION['preferences'] = array_merge(getUserPreferences(), $clean);
        return true;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return false;
    }

    try {
        ensureUserPreferencesColumn();
        $merged = array_merge(getUserPreferences(), $clean);
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET preferences = ? WHERE id = ?");
        return $stmt->execute([json_encode($merged), $userId]);
    } catch (PDOException $e) {
        error_log('[DB] saveUserPreferences failed: ' . $e->getMessage());
        return false;
    }
}


// —— Stateless SCORM Serve Token ——
// A short-lived HMAC token that authorises serve.php asset requests from
// inside the SCORM iframe. The browser's SameSite=Lax session cookie is
// NOT sent on sub-resource requests that originate from inside an iframe,
// so every JS/CSS/HTML asset fetch hits requireLogin() and gets redirected
// to the login page — which the browser then injects as the JS content,
// causing SyntaxError in the console.
//
// Fix: scorm-player/index.php generates a short-lived token and appends it
// as `t=` to the iframe src URL. serve.php validates it BEFORE calling
// requireLogin(), so asset requests are authenticated without cookies.
//
// Token format:  base64url(uid.pkgId.issue.expiry.secVer.nonce) . "." . base64url(hmac-sha256)
// Validity:      SCORM_TOKEN_TTL (default 1 hour); refreshed by store.php and serve.php
// Secret:        APP_CSRF_SECRET (reuses the existing stable HMAC key)
function generateServeToken(int $userId, int $packageId): string
{
    $ttl      = max(300, (int)(getenv('SCORM_TOKEN_TTL') ?: '3600'));
    $issue    = time();
    $expiry   = $issue + $ttl;
    $secVer   = getUserSecurityVersion($userId);
    $nonce    = csrfBase64Encode(random_bytes(8));
    $payload  = $userId . '.' . $packageId . '.' . $issue . '.' . $expiry . '.' . $secVer . '.' . $nonce;
    $sig      = hash_hmac('sha256', $payload, APP_CSRF_SECRET);
    return csrfBase64Encode($payload) . '.' . csrfBase64Encode($sig);
}

/**
 * Current security version for a user. Bumped whenever the user's entitlements
 * change (password reset, role/org change, disable), which invalidates every
 * outstanding serve token.
 */
function getUserSecurityVersion(int $userId): int
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("SELECT security_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $cache[$userId] = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $cache[$userId] = 0;
    }
    return $cache[$userId];
}

/**
 * Invalidate all outstanding serve tokens for a user by bumping the security
 * version. Call after password reset, role/org change, or account disable.
 */
function bumpUserSecurityVersion(int $userId): void
{
    try {
        $pdo = getDbConnection();
        $pdo->prepare("UPDATE users SET security_version = security_version + 1 WHERE id = ?")->execute([$userId]);
    } catch (PDOException $e) {
        error_log('[AUTH] bumpUserSecurityVersion failed: ' . $e->getMessage());
    }
}

/**
 * Parse the expiry timestamp from a serve token payload without full
 * validation. Returns null if the payload is malformed.
 */
function serveTokenExpiry(string $token): ?int
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $payload = csrfBase64Decode($parts[0]);
    if ($payload === '') {
        return null;
    }
    $pieces = explode('.', $payload, 6);
    if (count($pieces) !== 6) {
        return null;
    }
    return (int)$pieces[3];
}

/**
 * Validate a serve token and return the authenticated user ID on success,
 * or null on failure / expiry.
 *
 * Validates: constant-time signature, exact package binding, issue/expiry
 * window, user existence, and the user's current security version (revocation).
 *
 * @param  string $token  The `t=` value from the query string
 * @param  int    $pkgId  The `pkg=` value from the query string (must match)
 * @return int|null       Authenticated user ID, or null if invalid
 */
function validateServeToken(string $token, int $pkgId): ?int
{
    static $cache = [];
    if (array_key_exists($token, $cache)) {
        return $cache[$token];
    }
    $result = null;
    $parts = explode('.', $token, 2);
    if (count($parts) === 2) {
        $payload = csrfBase64Decode($parts[0]);
        $sig     = csrfBase64Decode($parts[1]);
        $expected = hash_hmac('sha256', $payload, APP_CSRF_SECRET);
        if ($payload !== '' && $sig !== '' && hash_equals($expected, $sig)) {
            $pieces = explode('.', $payload, 6);
            if (count($pieces) === 6) {
                $uid      = (int)$pieces[0];
                $tokenPkg = (int)$pieces[1];
                $issue    = (int)$pieces[2];
                $expiry   = (int)$pieces[3];
                $secVer   = (int)$pieces[4];
                if ($tokenPkg === $pkgId && $issue <= time() && time() <= $expiry && $uid > 0) {
                    // Revocation check: the user's current security version must
                    // match the one embedded in the token.
                    if (getUserSecurityVersion($uid) === $secVer) {
                        $result = $uid;
                    }
                }
            }
        }
    }
    $cache[$token] = $result;
    return $result;
}

// —— Multi-Organization (Multi-Tenant) Helpers ——

function getOrgId(): ?int
{
    return isset($_SESSION['organization_id']) ? (int)$_SESSION['organization_id'] : null;
}

function isSuperAdmin(): bool
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
}

function isOrgAdmin(): bool
{
    return isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin');
}

function isAdmin(): bool
{
    return isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin');
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        redirectTo('dashboard/?error=unauthorized');
    }
}

/**
 * Authenticate API requests via API key (header) OR admin session.
 * Returns true if the request is authorized, false otherwise.
 * Logs failures for audit trail.
 *
 * Supports:
 *   - X-API-Key: <key>        (recommended for external systems)
 *   - Authorization: Bearer <key>
 *   - Valid admin/super_admin session
 */
function apiRequireAuth(): bool
{
    // 1. Check session-based auth (admin already logged in via browser)
    if (isAdmin()) {
        return true;
    }

    // 2. Check API key from headers
    $apiKey = API_KEY;
    if ($apiKey === '') {
        // No API key configured — session-only mode
        error_log('[API AUTH] No API_KEY configured. Session auth required but not present. '
            . 'remote=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        return false;
    }

    $providedKey = '';
    // X-API-Key header
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $providedKey = trim($_SERVER['HTTP_X_API_KEY']);
    }
    // Authorization: Bearer <key>
    elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        if (stripos($authHeader, 'Bearer ') === 0) {
            $providedKey = trim(substr($authHeader, 7));
        }
    }
    // Apache sometimes strips Authorization — try apache_request_headers()
    elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $providedKey = trim($headers['X-API-Key'] ?? ($headers['x-api-key'] ?? ''));
        if ($providedKey === '') {
            $authHeader = trim($headers['Authorization'] ?? ($headers['authorization'] ?? ''));
            if (stripos($authHeader, 'Bearer ') === 0) {
                $providedKey = trim(substr($authHeader, 7));
            }
        }
    }

    if ($providedKey !== '' && hash_equals($apiKey, $providedKey)) {
        return true;
    }

    // 3. Auth failed — log and deny
    error_log('[API AUTH] Unauthorized request. '
        . 'remote=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' '
        . 'uri=' . ($_SERVER['REQUEST_URI'] ?? '?') . ' '
        . 'key_provided=' . ($providedKey !== '' ? 'yes(mismatch)' : 'no'));
    return false;
}

function requireSuperAdmin(): void
{
    if (!isSuperAdmin()) {
        redirectTo('dashboard/?error=unauthorized');
    }
}

function requireOrgAdmin(?int $orgId = null): void
{
    if (!isAdmin()) {
        redirectTo('dashboard/?error=unauthorized');
    }
    if ($orgId !== null && !isSuperAdmin() && getOrgId() !== $orgId) {
        redirectTo('dashboard/?error=unauthorized');
    }
}

function ensureOrganizationsTable(): void
{
    $pdo = getDbConnection();

    // Get Moodle URL for the default scorm_base_url
    $moodleUrl = MOODLE_BASE_URL ?: 'https://moodle.yourdomain.com';

    $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        scorm_app_id VARCHAR(100) DEFAULT NULL,
        scorm_secret_key VARCHAR(255) DEFAULT NULL,
        scorm_base_url VARCHAR(255) DEFAULT '$moodleUrl',
        status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add organization_id to users if missing
    try {
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('organization_id', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN organization_id INT NULL AFTER id, ADD INDEX idx_org (organization_id)");
        }
        if (!in_array('organization_id', $userCols, true) && !in_array('organization_id', $userCols ?: [], true)) {
            $userCols2 = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('organization_id', $userCols2, true)) {
                $pdo->exec("ALTER TABLE users ADD FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL");
            }
        }
    } catch (PDOException $e) {
        error_log('[DB] ensureOrganizationsTable users: ' . $e->getMessage());
    }

    // Add organization_id to user_registrations if missing
    try {
        $regCols = $pdo->query("SHOW COLUMNS FROM user_registrations")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('organization_id', $regCols, true)) {
            $pdo->exec("ALTER TABLE user_registrations ADD COLUMN organization_id INT NULL AFTER user_id, ADD INDEX idx_reg_org (organization_id)");
        }
    } catch (PDOException $e) {
        error_log('[DB] ensureOrganizationsTable registrations: ' . $e->getMessage());
    }
}

function orgSql(string $tableAlias = ''): string
{
    $orgId = getOrgId();
    if ($orgId === null || isSuperAdmin()) {
        return '';
    }
    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    return " AND {$prefix}organization_id = " . (int)$orgId;
}

// —— Native SCORM Reader: Database Tables (Phase 1) ——

/**
 * Create all SCORM content-management tables if they don't exist.
 * Call this from any admin page that needs SCORM package management.
 */
function ensureScormTables(): void
{
    $pdo = getDbConnection();

    // scorm_packages references organizations(id) — ensure it exists first.
    // If it's already created, this is a no-op (safe to call every request).
    ensureOrganizationsTable();

    // scorm_packages: uploaded SCORM .zip packages
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        version VARCHAR(50) DEFAULT '1.0',
        scorm_version ENUM('1.2', '2004') NOT NULL DEFAULT '1.2',
        scorm_edition VARCHAR(20) NOT NULL DEFAULT '',
        manifest_id VARCHAR(255) NOT NULL DEFAULT '',
        package_version VARCHAR(100) NOT NULL DEFAULT '',
        sco_count INT NOT NULL DEFAULT 0,
        activity_tree JSON NULL,
        resource_metadata JSON NULL,
        content_hash VARCHAR(64) NOT NULL DEFAULT '',
        fingerprint JSON NULL,
        adapter_family VARCHAR(20) NOT NULL DEFAULT 'generic',
        adapter_version VARCHAR(50) NOT NULL DEFAULT '',
        runtime_hash CHAR(64) NOT NULL DEFAULT '',
        manifest_hash CHAR(64) NOT NULL DEFAULT '',
        parser_version VARCHAR(50) NOT NULL DEFAULT '',
        manifest_xml LONGTEXT NOT NULL,
        launch_sco_id INT NULL,
        upload_path VARCHAR(500) NOT NULL,
        status ENUM('active', 'archived', 'draft') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_org (organization_id),
        INDEX idx_status (status),
        INDEX idx_scorm_version (scorm_version, scorm_edition),
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // sco_items: SCOs defined in the manifest (one package has many SCOs)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sco_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_id INT NOT NULL,
        identifier VARCHAR(255) DEFAULT '',
        identifierref VARCHAR(255) DEFAULT '',
        title VARCHAR(255) DEFAULT '',
        launch_url VARCHAR(500) DEFAULT '',
        scorm_type VARCHAR(50) DEFAULT 'sco',
        data_from_lms TEXT,
        prerequisites VARCHAR(500) DEFAULT '',
        max_time_allowed VARCHAR(20) DEFAULT '',
        time_limit_action VARCHAR(50) DEFAULT '',
        mastery_score DECIMAL(5,2) NULL,
        sequencing TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (package_id) REFERENCES scorm_packages(id) ON DELETE CASCADE,
        INDEX idx_package (package_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // course_assignments: which packages are assigned to which orgs
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_id INT NOT NULL,
        organization_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (package_id) REFERENCES scorm_packages(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        UNIQUE KEY unique_assign (package_id, organization_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // —— Phase 2: SCORM tracking tables ——

    // scorm_attempts: one row per (user, sco, attempt_number)
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        organization_id INT NULL,
        department VARCHAR(255) NULL COMMENT 'Snapshot of user dept at time of attempt',
        sco_item_id INT NULL,
        package_id INT NOT NULL,
        scorm_edition VARCHAR(20) NOT NULL DEFAULT '',
        attempt_number INT DEFAULT 1,
        registration_id VARCHAR(255) DEFAULT '',
        lesson_status VARCHAR(50) DEFAULT 'not attempted',
        completion_status VARCHAR(50) DEFAULT '',
        success_status VARCHAR(50) DEFAULT '',
        score_raw DECIMAL(10,4) NULL,
        score_scaled DECIMAL(5,4) NULL,
        score_min DECIMAL(10,4) NULL,
        score_max DECIMAL(10,4) NULL,
        mastery_score DECIMAL(5,2) NULL,
        passed TINYINT(1) DEFAULT 0,
        session_time_seconds INT DEFAULT 0,
        total_time_seconds INT DEFAULT 0,
        lesson_location VARCHAR(500) DEFAULT '',
        suspend_data LONGTEXT,
        progress_measure DECIMAL(5,4) NULL,
        reported_progress_measure DECIMAL(5,4) NULL,
        estimated_progress_measure DECIMAL(5,4) NULL,
        progress_source VARCHAR(40) NOT NULL DEFAULT '',
        progress_confidence DECIMAL(5,4) NULL,
        progress_parser VARCHAR(100) NOT NULL DEFAULT '',
        progress_calculated_at TIMESTAMP NULL,
        progress_raw_hash CHAR(64) NOT NULL DEFAULT '',
        completion_threshold DECIMAL(5,4) NULL,
        scaled_passing_score DECIMAL(5,4) NULL,
        normalized_completion VARCHAR(20) NOT NULL DEFAULT '',
        normalized_success VARCHAR(20) NOT NULL DEFAULT '',
        status_source VARCHAR(20) NOT NULL DEFAULT '',
        attempt_state VARCHAR(30) NOT NULL DEFAULT '',
        last_request_id VARCHAR(64) NOT NULL DEFAULT '',
        entry VARCHAR(50) DEFAULT 'ab-initio',
        mode VARCHAR(20) NOT NULL DEFAULT '',
        credit VARCHAR(20) NOT NULL DEFAULT '',
        `exit` VARCHAR(50) DEFAULT '',
        launch_sco_id INT NULL,
        browser_info JSON NULL,
        is_complete TINYINT(1) DEFAULT 0,
        started_at TIMESTAMP NULL,
        last_accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (sco_item_id) REFERENCES sco_items(id) ON DELETE CASCADE,
        FOREIGN KEY (package_id) REFERENCES scorm_packages(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
        UNIQUE KEY uq_attempt (user_id, package_id, sco_item_id, attempt_number),
        INDEX idx_user_sco (user_id, sco_item_id),
        INDEX idx_user_pkg_sco (user_id, package_id, sco_item_id),
        INDEX idx_org_dept (organization_id, department),
        INDEX idx_package (package_id),
        INDEX idx_status (lesson_status),
        INDEX idx_completion_status (completion_status),
        INDEX idx_success_status (success_status),
        INDEX idx_attempt_state (attempt_state),
        INDEX idx_started_at (started_at),
        INDEX idx_last_accessed (last_accessed_at),
        INDEX idx_completion (is_complete, completed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_interactions: one row per cmi.interactions.n entry
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_interactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        user_id INT NOT NULL,
        interaction_index INT NOT NULL,
        interaction_id VARCHAR(255) DEFAULT '',
        interaction_type VARCHAR(50) DEFAULT '',
        learner_response TEXT,
        correct_responses JSON NULL,
        correct_response_ids JSON NULL,
        result VARCHAR(50) DEFAULT '',
        weighting DECIMAL(10,4) NULL,
        latency_seconds DECIMAL(10,2) NULL,
        description TEXT,
        timestamp TIMESTAMP NULL,
        FOREIGN KEY (attempt_id) REFERENCES scorm_attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_interaction (attempt_id, interaction_index),
        INDEX idx_attempt (attempt_id),
        INDEX idx_user_interaction (user_id, interaction_id),
        INDEX idx_interaction_id (interaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_objectives: one row per cmi.objectives.n
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_objectives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        user_id INT NOT NULL,
        objective_index INT NOT NULL,
        objective_id VARCHAR(255) DEFAULT '',
        score_raw DECIMAL(10,4) NULL,
        score_scaled DECIMAL(5,4) NULL,
        score_min DECIMAL(10,4) NULL,
        score_max DECIMAL(10,4) NULL,
        completion_status VARCHAR(50) DEFAULT '',
        success_status VARCHAR(50) DEFAULT '',
        progress_measure DECIMAL(5,4) NULL,
        description TEXT,
        FOREIGN KEY (attempt_id) REFERENCES scorm_attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_objective (attempt_id, objective_index),
        INDEX idx_attempt (attempt_id),
        INDEX idx_objective_id (objective_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_events: fine-grained audit log of every LMSCommit()
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        user_id INT NOT NULL,
        event_type VARCHAR(50) DEFAULT '',
        data_element VARCHAR(255) DEFAULT '',
        old_value TEXT,
        new_value TEXT,
        slide_id VARCHAR(255) DEFAULT '',
        request_id VARCHAR(64) NOT NULL DEFAULT '',
        changed_fields JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (attempt_id) REFERENCES scorm_attempts(id) ON DELETE CASCADE,
        INDEX idx_attempt_time (attempt_id, created_at),
        INDEX idx_user (user_id),
        INDEX idx_request_id (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_interaction_objectives: cmi.interactions.n.objectives.m.id links
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_interaction_objectives (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_comments_from_learner: SCORM 2004 cmi.comments_from_learner.n.*
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_comments_from_learner (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_request_idempotency: browser commit/beacon request dedupe
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_request_idempotency (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        request_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        attempt_id INT NULL,
        response JSON NULL,
        payload_hash CHAR(64) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_request_id (request_id),
        INDEX idx_attempt (attempt_id),
        INDEX idx_user (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // scorm_monitor: rejected-payload / duplicate-request / failed-persistence log
    $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_monitor (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        // Log the failure but don't crash the page — individual tables may
        // already exist, and some MySQL versions have quirks with JSON/FK.
        error_log('[SCORM] ensureScormTables error: ' . $e->getMessage());
    }
}

/**
 * Apply pending versioned SCORM schema migrations.
 *
 * The versioned migrations in /migrations are the canonical place for schema
 * evolution (see migrations/README.md). This helper is the automatic entry
 * point used by scorm-api/store.php and the admin upload handlers. It is a
 * no-op (one indexed SELECT) once everything is applied, and a MySQL advisory
 * lock serialises concurrent requests so a migration never runs twice.
 */
function ensureScormMigrations(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $pdo = getDbConnection();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                result VARCHAR(20) DEFAULT 'applied'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $dir = __DIR__ . '/migrations';
        $files = glob($dir . '/[0-9]*_*.php');
        if (!$files) {
            $checked = true;
            return;
        }
        sort($files, SORT_STRING);

        // Fast path: every discovered migration is already recorded.
        $applied = [];
        foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
            $applied[$row['version']] = true;
        }
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (!isset($applied[$version])) {
                $pending[] = $file;
            }
        }
        if (!$pending) {
            $checked = true;
            return;
        }

        $lockOk = (bool)$pdo->query("SELECT GET_LOCK('pp_scorm_migrations', 5)")->fetchColumn();
        if (!$lockOk) {
            error_log('[SCORM] ensureScormMigrations: lock busy, skipping this request.');
            return;
        }
        try {
            foreach ($pending as $file) {
                $version = basename($file, '.php');
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
                $stmt->execute([$version]);
                if ((int)$stmt->fetchColumn() > 0) {
                    continue;
                }
                $up = require $file;
                if (is_callable($up)) {
                    $up($pdo);
                }
                $ins = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
                $ins->execute([$version]);
                error_log('[SCORM] ensureScormMigrations applied ' . $version);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('pp_scorm_migrations')");
        }
        $checked = true;
    } catch (Throwable $e) {
        // Never let migration machinery break a page request; log for admins.
        error_log('[SCORM] ensureScormMigrations error: ' . $e->getMessage());
    }
}

/**
 * Ensure the security-hardening tables + user lockout columns exist.
 *
 * Auth hardening (migrations/0002_security_hardening.php) uses:
 *   security_events  — audit log (logins, admin actions, MFA, lockouts)
 *   auth_attempts    — per-account + per-IP login attempt tracker
 *   mfa_challenges   — email MFA 6-digit challenges
 *   users            — failed_login_count / failed_login_started_at / locked_until
 *
 * Called by login, MFA, password, and admin-action pages. Idempotent; safe to
 * run on every request.
 */
function ensureSecurityTables(): void
{
    $pdo = getDbConnection();
    try {
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

        // Per-account lockout state on users (guarded ALTERs).
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('failed_login_count', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_login_count INT NOT NULL DEFAULT 0 AFTER is_verified");
        }
        if (!in_array('failed_login_started_at', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_login_started_at DATETIME NULL AFTER failed_login_count");
        }
        if (!in_array('locked_until', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_started_at");
        }
    } catch (PDOException $e) {
        error_log('[SECURITY] ensureSecurityTables error: ' . $e->getMessage());
    }
}

/**
 * Ensure the invite_tokens table exists.
 */
function ensureInviteTokensTable(): void
{
    $pdo = getDbConnection();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS invite_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            email VARCHAR(255) DEFAULT NULL,
            course_id VARCHAR(255) DEFAULT NULL,
            department VARCHAR(255) DEFAULT NULL,
            is_team_lead TINYINT(1) DEFAULT 0,
            is_demo TINYINT(1) DEFAULT 0,
            max_uses INT DEFAULT NULL,
            use_count INT DEFAULT 0,
            created_by INT NULL,
            organization_id INT NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_email (email),
            INDEX idx_created_by (created_by),
            INDEX idx_is_demo (is_demo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add notify_emails column if missing (safe ALTER)
        try { $pdo->exec("ALTER TABLE invite_tokens ADD COLUMN notify_emails TEXT DEFAULT NULL AFTER max_uses"); } catch (PDOException $e2) {}
    } catch (PDOException $e) {
        error_log('[DB] ensureInviteTokensTable error: ' . $e->getMessage());
    }
}

/**
 * Ensure the course_assets table exists.
 */
function ensureCourseAssetsTable(): void
{
    $pdo = getDbConnection();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS course_assets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id VARCHAR(255) NOT NULL UNIQUE,
            course_title VARCHAR(255) DEFAULT '',
            certificate_template VARCHAR(255) DEFAULT NULL,
            thumbnail VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_course_id (course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add organization_id column if missing (safe ALTER)
        try { $pdo->exec("ALTER TABLE course_assets ADD COLUMN organization_id INT NULL AFTER course_title"); } catch (PDOException $e2) {}
    } catch (PDOException $e) {
        error_log('[DB] ensureCourseAssetsTable error: ' . $e->getMessage());
    }
}

/**
 * Get all course assets.
 */
function getAllCourseAssets(): array
{
    try {
        $pdo = getDbConnection();
        return $pdo->query("SELECT * FROM course_assets ORDER BY course_title ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[DB] getAllCourseAssets error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get course asset by course ID.
 */
function getCourseAsset(string $courseId): ?array
{
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM course_assets WHERE course_id = ?");
        $stmt->execute([$courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('[DB] getCourseAsset error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve a course thumbnail filename from course_assets for a course id
 * (exact match, numeric-normalized, then title fallback), or null when none
 * is configured. Results are cached for the duration of the request.
 */
function getCourseThumbnailFile(?string $courseId = null, ?string $courseTitle = ''): ?string
{
    static $assets = null;
    if ($assets === null) {
        $assets = getAllCourseAssets();
    }

    if ($courseId !== null && $courseId !== '') {
        // 1. Exact course_id match
        foreach ($assets as $a) {
            if (!empty($a['thumbnail']) && (string)$a['course_id'] === $courseId) {
                return (string)$a['thumbnail'];
            }
        }
        // 2. Numeric-normalized match ("012" vs "12")
        if (ctype_digit($courseId)) {
            $nid = (int)$courseId;
            foreach ($assets as $a) {
                if (!empty($a['thumbnail']) && ctype_digit((string)$a['course_id']) && (int)$a['course_id'] === $nid) {
                    return (string)$a['thumbnail'];
                }
            }
        }
    }

    // 3. Title fallback
    if ($courseTitle !== null && $courseTitle !== '') {
        foreach ($assets as $a) {
            if (!empty($a['thumbnail']) && !empty($a['course_title']) && strcasecmp((string)$a['course_title'], $courseTitle) === 0) {
                return (string)$a['thumbnail'];
            }
        }
    }

    return null;
}

/**
 * Build a public URL for a course thumbnail filename, falling back to the
 * default course image when none is configured.
 */
function courseThumbnailUrl(?string $file): string
{
    if ($file === null || $file === '') {
        return buildUrl('content/Understanding-and-Recognizing-Human-Trafficking_.webp');
    }
    if (preg_match('~^(https?:)?//~i', $file) || $file[0] === '/') {
        return $file;
    }
    return buildUrl('content/' . ltrim($file, '/'));
}

/**
 * Ensure the scorm_upload_jobs table exists.
 * Called by the upload handler before creating a job row.
 */
function ensureUploadJobsTable(): void
{
    try {
        $pdo = getDbConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS scorm_upload_jobs (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT NOT NULL,
            package_id      INT NULL,
            org_id          INT NULL,
            title           VARCHAR(255) NOT NULL DEFAULT '',
            scorm_version   VARCHAR(10) NOT NULL DEFAULT '1.2',
            tmp_path        VARCHAR(500) NOT NULL DEFAULT '',
            strip_prefix    VARCHAR(500) NOT NULL DEFAULT '',
            replace_flag    TINYINT(1) NOT NULL DEFAULT 0,
            status          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
            message         TEXT,
            progress_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sco_count       INT NULL,
            files_extracted INT NULL,
            launch_sco_id   INT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Add tmp_path / strip_prefix columns to existing tables (safe ALTER)
        try { $pdo->exec("ALTER TABLE scorm_upload_jobs ADD COLUMN tmp_path VARCHAR(500) NOT NULL DEFAULT '' AFTER scorm_version"); } catch (PDOException $e2) {}
        try { $pdo->exec("ALTER TABLE scorm_upload_jobs ADD COLUMN strip_prefix VARCHAR(500) NOT NULL DEFAULT '' AFTER tmp_path"); } catch (PDOException $e3) {}
        try { $pdo->exec("ALTER TABLE scorm_upload_jobs ADD COLUMN replace_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER strip_prefix"); } catch (PDOException $e4) {}
    } catch (PDOException $e) {
        error_log('[SCORM] ensureUploadJobsTable error: ' . $e->getMessage());
    }
}
