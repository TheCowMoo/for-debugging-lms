<?php
/**
 * Course Runner — Asset Proxy
 * 
 * Fetches SCORM assets (JS, CSS, images, etc.) and API calls
 * on behalf of the user's browser, so all requests appear
 * to come from your domain.
 */

require_once __DIR__ . '/../bootstrap.php';

$url = trim($_GET['url'] ?? '');
if (empty($url)) {
    http_response_code(400);
    echo "No URL specified.";
    exit;
}

// Only allowed hosts for security
$appDomain = parse_url(BASE_URL, PHP_URL_HOST) ?: 'pursuitpathways.com';
$allowedHosts = [$appDomain, 'pursuitpathways.com'];

// Also allow the Moodle host if configured
$moodleHost = parse_url(MOODLE_BASE_URL, PHP_URL_HOST);
if ($moodleHost && !in_array($moodleHost, $allowedHosts)) {
    $allowedHosts[] = $moodleHost;
}
$parsed = parse_url($url);
$host = $parsed['host'] ?? '';
$isAllowed = false;
foreach ($allowedHosts as $ah) {
    if (strpos($host, $ah) !== false) {
        $isAllowed = true;
        break;
    }
}

// Also allow the SCORM launch URL from session
if (!$isAllowed && !empty($_SESSION['course_url'])) {
    $sessionHost = parse_url($_SESSION['course_url'], PHP_URL_HOST);
    if (strpos($host, $sessionHost) !== false || strpos($sessionHost, $host) !== false) {
        $isAllowed = true;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}

// ── Fetch the asset ──
// Forward the user's PHP session cookie so authenticated asset endpoints
// (e.g. the native scorm-content/serve.php, which calls requireLogin())
// recognize the user. Without it, assets would be redirected to /login/.
$userSessionCookie = session_name() . '=' . session_id();

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HEADER => false,
    CURLOPT_COOKIESESSION => true,
    CURLOPT_COOKIE => $userSessionCookie,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);
$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || $content === false) {
    error_log('[COURSE-RUNNER-PROXY] Asset fetch failed. httpCode=' . $httpCode . ' url=' . $url);
    http_response_code($httpCode === 301 || $httpCode === 302 || $httpCode === 303 || $httpCode === 307 || $httpCode === 308 ? 502 : ($httpCode >= 400 ? $httpCode : 502));
    echo "Failed to fetch asset.";
    exit;
}

// ── Set content type ──
if ($contentType) {
    // Take just the MIME type, not charset
    $parts = explode(';', $contentType);
    header('Content-Type: ' . trim($parts[0]));
} else {
    // Guess from extension
    $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    $mimeMap = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'json' => 'application/json',
        'xml' => 'application/xml',
    ];
    header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
}

// Allow cross-origin for iframe
header('Access-Control-Allow-Origin: *');

// Cache for 1 hour
header('Cache-Control: public, max-age=3600');

echo $content;