<?php
/**
 * Course Runner — SCORM Content Proxy
 *
 * Fetches SCORM content from the backend (Moodle)
 * and serves it under your domain. Strips any hosting platform's
 * chrome/UI so the user sees ONLY the raw SCORM course.
 *
 * Flow:
 *   1. User clicks "Start Course"
 *   2. getScormLaunchLink() sets $_SESSION with the backend URL
 *   3. This script fetches that URL server-side via cURL
 *   4. Rewrites all asset URLs to go through /course-runner/proxy.php
 *   5. Strips Moodle headers, nav, footer if present
 *   6. Serves the clean SCORM HTML
 */

require_once __DIR__ . '/../bootstrap.php';

// Must have a session with a course URL
$isMoodle = isset($_GET['moodle']) && $_GET['moodle'] == 1;

if ($isMoodle) {
    // Moodle mode: get the stored Moodle player URL
    if (!isset($_SESSION['moodle_player_url'])) {
        redirectTo('login/');
    }
    $scormUrl = $_SESSION['moodle_player_url'];
} else {
    // Legacy course-url mode
    if (!isset($_SESSION['course_url'])) {
        redirectTo('login/');
    }
    $scormUrl = $_SESSION['course_url'];
}

$dashboardUrl = buildUrl('dashboard/');

// —— Fetch SCORM launch page ——
// Forward the user's real PHP session cookie so the target (e.g. the native
// scorm-player, which calls requireLogin()) recognizes the authenticated user.
// Without it, the server-side cURL request has no session and the target
// redirects to /login/ — which would then be proxied back into the course iframe.
$userSessionCookie = session_name() . '=' . session_id();

// FOLLOWLOCATION is intentionally OFF: if authentication fails the target
// responds with a 3xx redirect. We must NOT follow it (that would fetch and
// proxy the login page) — instead the redirect status falls through to the
// error page below.
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $scormUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HEADER => false,
    CURLOPT_COOKIESESSION => true,
    CURLOPT_COOKIE => $userSessionCookie,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);
$rawHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($httpCode !== 200 || $rawHtml === false || $rawHtml === '') {
    // Failed to fetch — show error page
    error_log('[COURSE-RUNNER] Fetch failed. httpCode=' . $httpCode . ' url=' . $scormUrl . ' session=' . (isset($_SESSION['user_id']) ? 'authenticated' : 'NOT-AUTHENTICATED'));
    http_response_code(502);
    echo "<html><head><title>Course Load Error</title></head><body>";
    echo "<h2>Unable to load course content</h2>";
    if ($httpCode === 301 || $httpCode === 302 || $httpCode === 303 || $httpCode === 307 || $httpCode === 308) {
        // Authentication redirect — session not forwarded. Don't follow it.
        echo "<p>The course server redirected to another page (HTTP $httpCode). This usually means the user session was not recognized. Please log out and log back in, then try again.</p>";
    } else {
        echo "<p>The course server did not respond correctly (HTTP $httpCode).</p>";
    }
    echo "<a href='$dashboardUrl' style='padding:10px 20px;background:#82ACD6;color:#fff;text-decoration:none;border-radius:8px;'>Return to Dashboard</a>";
    echo "</body></html>";
    exit;
}

// —— Strip Moodle Chrome ——
// Moodle wraps the SCORM player in its theme (header, nav, footer).
// We extract only the content between the #region-main or #page-content divs.
$html = $rawHtml;

if ($isMoodle) {
    // Remove Moodle's navigation bar, header, and footer
    // Strategy 1: Look for the main content area that contains the SCORM player
    $strippedHtml = '';
    
    // Try to extract content from #region-main (Moodle's main content wrapper)
    if (preg_match('/<div[^>]*role="main"[^>]*>(.*?)<\/div>\s*<!--\s*end\s+of\s+region\s+main\s*-->/si', $html, $m)) {
        $strippedHtml = $m[1];
    }
    // Try alternative: #page-content
    elseif (preg_match('/<div[^>]*id="page-content"[^>]*>(.*?)<\/div>\s*<!--\s*end\s+of\s+content\s*-->/si', $html, $m)) {
        $strippedHtml = $m[1];
    }
    // Try alternative: .region-main
    elseif (preg_match('/<section[^>]*class="[^"]*region-main[^"]*"[^>]*>(.*?)<\/section>/si', $html, $m)) {
        $strippedHtml = $m[1];
    }
    
    if (!empty(trim($strippedHtml))) {
        $html = $strippedHtml;
    }
    // Fallback: if we can't strip the chrome, keep the full HTML but still proxy assets
    
    // Remove any Moodle redirect meta tags that might break the iframe
    $html = preg_replace('/<meta[^>]*url=.*?>/i', '', $html);
}

// —— Build base URL for rewriting relative paths ——
$parsedUrl = parse_url($effectiveUrl);
$baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
$basePath = dirname($parsedUrl['path'] ?? '/');

$proxyUrl = buildUrl('course-runner/proxy.php');

// —— Inject player config ——
$injectScript = '<script>window.playerConfUrl="' . addslashes(buildUrl('course-runner/player.php')) . '";</script>';
$html = str_replace('</head>', $injectScript . '</head>', $html);

// —— Rewrite asset URLs to our proxy ——
// <img src="...">, <link href="...">, <script src="...">, <iframe src="...">
$html = preg_replace_callback(
    '/(src|href|action|data)\s*=\s*["\']((?!https?:\/\/|javascript:|mailto:|#|\/\/|playerConfUrl|data:)[^"\']+)["\']/i',
    function($m) use ($proxyUrl, $baseUrl, $basePath) {
        $attr = $m[1];
        $url = $m[2];
        // Make absolute if relative
        if (strpos($url, '/') === 0) {
            $fullUrl = $baseUrl . $url;
        } elseif (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $fullUrl = rtrim($baseUrl . $basePath, '/') . '/' . ltrim($url, '/');
        } else {
            $fullUrl = $url;
        }
        return $attr . '="' . $proxyUrl . '?url=' . urlencode($fullUrl) . '"';
    },
    $html
);

// Rewrite inline CSS url() references
$html = preg_replace_callback(
    '/url\([\'"]?((?!https?:\/\/|data:)[^\'"\)]+)[\'"]?\)/i',
    function($m) use ($proxyUrl, $baseUrl, $basePath) {
        $url = $m[1];
        if (strpos($url, '/') === 0) {
            $fullUrl = $baseUrl . $url;
        } elseif (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $fullUrl = rtrim($baseUrl . $basePath, '/') . '/' . ltrim($url, '/');
        } else {
            $fullUrl = $url;
        }
        return "url('" . $proxyUrl . '?url=' . urlencode($fullUrl) . "')";
    },
    $html
);

// Remove X-Frame-Options so we can iframe it
$html = preg_replace('/<meta[^>]*http-equiv=["\']X-Frame-Options["\'][^>]*>/i', '', $html);

// Set content type
if ($contentType && strpos($contentType, 'html') !== false) {
    header('Content-Type: text/html; charset=utf-8');
}

echo $html;