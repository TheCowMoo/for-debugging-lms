<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * NATIVE SCORM READER — SCORM Content Server (Phase 1)
 *
 * Serves static assets (HTML, JS, CSS, media, etc.) from uploaded SCORM
 * packages stored under content/scorm/{package_id}/. This is the ONLY
 * public entry point for package files — direct access to content/scorm/
 * is blocked by .htaccess.
 *
 * Query parameters:
 *   pkg  — scorm_packages.id (required); determines the package root
 *   path — relative file path within the package (defaults to launch SCO)
 *   nocache — bypasses the HTML rewrite cache when set to '1'
 *
 * Security:
 *   - Requires a valid, active package ID (org-scoped where applicable)
 *   - Path traversal is blocked (no .., no \)
 *   - Only known-safe file extensions are served
 *   - Content-Type is derived from a whitelist
 *
 * Caching:
 *   HTML pages (the most expensive to rewrite) are cached per-user,
 *   per-package, per-path on disk under content/cache/scorm/. SCORM
 *   packages are immutable once uploaded (re-uploads create a NEW ID),
 *   so cached entries never need invalidation. Use ?nocache=1 to bypass.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../scorm-api/scorm-normalize.php';

// ── Authentication ──
// SCORM asset requests (JS, CSS, sub-HTML) originate from inside an iframe.
// Browsers do NOT send SameSite=Lax cookies on these sub-resource requests,
// so $_SESSION['user_id'] is never set for them — requireLogin() would
// redirect every asset to the login page, which the browser then injects
// as JS content, causing SyntaxError.
//
// Solution: scorm-player/index.php appends a short-lived HMAC token (`t=`)
// to the iframe src URL. serve.php validates it here and, if valid, injects
// the user_id into the session so the rest of the page (requireLogin,
// orgSql, etc.) works normally.
$_serveToken = trim((string)($_GET['t'] ?? ''));
$_pkgIdForToken = (int)($_GET['pkg'] ?? 0);
if ($_serveToken !== '' && $_pkgIdForToken > 0) {
    $tokenUserId = validateServeToken($_serveToken, $_pkgIdForToken);
    if ($tokenUserId !== null && !isset($_SESSION['user_id'])) {
        // Token is valid — bootstrap a full session from the DB so that
        // requireLogin(), isSuperAdmin(), and the org-scoped package query
        // all work correctly for this user.
        // We do NOT call session_regenerate_id() here because this is a
        // sub-resource request, not a login event.
        $_SESSION['user_id'] = $tokenUserId;
        try {
            $tokenPdo  = getDbConnection();
            $tokenStmt = $tokenPdo->prepare(
                'SELECT role, organization_id FROM users WHERE id = ? LIMIT 1'
            );
            $tokenStmt->execute([$tokenUserId]);
            $tokenUser = $tokenStmt->fetch(PDO::FETCH_ASSOC);
            if ($tokenUser) {
                $_SESSION['user_role']       = $tokenUser['role'] ?? 'student';
                $_SESSION['organization_id'] = $tokenUser['organization_id'] ?? null;
            } else {
                // User not found — token is stale (user deleted); deny.
                http_response_code(403);
                exit('Forbidden.');
            }
        } catch (PDOException $tokenEx) {
            error_log('[SERVE] Token DB lookup failed: ' . $tokenEx->getMessage());
            // Fall through — requireLogin() will catch the missing session.
        }
    }
}

// Content is only served to authenticated users.
// orgSql() relies on the session org, so anonymous access would bypass
// the organization filter.
//
// Asset-aware auth: if this is a binary asset request (font/image/media —
// anything that is NOT an HTML page), an unauthenticated request MUST NOT
// 302-redirect to /login/. The browser would follow the redirect, receive
// the login page HTML, and attempt to parse it as the asset binary — causing
// "Failed to decode downloaded font" / "OTS parsing error" / "Unexpected token"
// errors. Assets instead return HTTP 403 so the browser treats them as failed
// resources rather than corrupted data.
$_assetPath = (string)($_GET['path'] ?? '');
$_isAssetRequest = (
    $_assetPath !== ''
    && !preg_match('/\.(html?)(\?|$)/i', $_assetPath)
);
if (!isset($_SESSION['user_id'])) {
    if ($_isAssetRequest) {
        http_response_code(403);
        error_log('[SERVE] EXIT 403: unauthenticated asset request path=' . $_assetPath);
        exit('Forbidden.');
    }
    redirectTo('login/');
}

// ── Serve-token refresh ──
// The serve token is short-lived (default 1h). On HTML entry pages, if the
// incoming token is near expiry, issue a fresh one so every asset URL, the
// RTE config, and the routing cookie in this page stay valid for the session.
$_serveTokenEffective = $_serveToken;
if ($_serveToken !== '' && !$_isAssetRequest) {
    $serveEntryExpiry = serveTokenExpiry($_serveToken);
    if ($serveEntryExpiry !== null && time() > $serveEntryExpiry - 900 && isset($_SESSION['user_id'])) {
        $_serveTokenEffective = generateServeToken((int)$_SESSION['user_id'], $_pkgIdForToken);
    }
}
// Downstream rewrite/base/config code reads $_GET['t']; point it at the
// effective (possibly refreshed) token.
$_GET['t'] = $_serveTokenEffective;

// ── Short-lived context cookie for nginx asset routing ──
// Rise/Storyline compute some asset URLs at runtime (no t= query and, with some
// privacy tools, no Referer). The nginx /scorm-content/ router falls back to
// this HttpOnly cookie to recover pkg + token. Same value as the t= token but
// HttpOnly, so page JavaScript cannot read it.
if ($_serveTokenEffective !== '' && $_pkgIdForToken > 0 && !$_isAssetRequest) {
    setcookie('scorm_ctx', $_pkgIdForToken . ':' . $_serveTokenEffective, [
        'expires'  => time() + 3600,
        'path'     => '/scorm-content/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

// ── Load package ──
$packageId = (int)($_GET['pkg'] ?? 0);
$rawPath = (string)($_GET['path'] ?? '(none)');
// Never log tokenized URLs — `t=` is a 4-hour bearer credential.
$serveRedactUri = preg_replace('/[?&]t=[^&]*/', '', (string)($_SERVER['REQUEST_URI'] ?? '?'));
error_log('[SERVE] ENTRY pkg=' . $packageId . ' path=' . $rawPath . ' s3cfg=' . (isS3Configured() ? '1' : '0') . ' uri=' . $serveRedactUri);
if ($packageId <= 0) {
    error_log('[SERVE] EXIT 400: invalid package');
    http_response_code(400);
    exit('Invalid package.');
}

ensureScormTables();

$pdo = getDbConnection();

// Access control:
//  - Super admins can see all active packages.
//  - Org users can see packages owned by their org OR assigned to their
//    org via course_assignments (a package can be owned globally and
//    assigned to multiple orgs).
$orgId = getOrgId();
$userId = (int)($_SESSION['user_id'] ?? 0);
$accessSql = "sp.status = 'active'";
$params = [$packageId];

if (!isSuperAdmin() && $orgId !== null) {
    $accessSql .= " AND (sp.organization_id = ? OR EXISTS (
                        SELECT 1 FROM course_assignments ca
                        WHERE ca.package_id = sp.id AND ca.organization_id = ?
                    ) OR EXISTS (
                        SELECT 1 FROM scorm_attempts a
                        WHERE a.package_id = sp.id AND a.user_id = ?
                    ))";
    $params[] = $orgId;
    $params[] = $orgId;
    $params[] = $userId;
} elseif (!isSuperAdmin()) {
    // Logged-in but no org — only packages not owned by any org
    $accessSql .= " AND (sp.organization_id IS NULL OR EXISTS (
                        SELECT 1 FROM scorm_attempts a
                        WHERE a.package_id = sp.id AND a.user_id = ?
                    ))";
    $params[] = $userId;
}

$stmt = $pdo->prepare("SELECT sp.*, sp.id AS package_id,
                              (SELECT si.launch_url FROM sco_items si WHERE si.id = sp.launch_sco_id) AS launch_href,
                              (SELECT si.launch_url FROM sco_items si WHERE si.package_id = sp.id AND si.launch_url != '' ORDER BY si.id LIMIT 1) AS first_sco_href
                       FROM scorm_packages sp
                       WHERE sp.id = ? AND " . $accessSql);
$stmt->execute($params);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pkg) {
    error_log('[SERVE] EXIT 404: package not found or inactive, pkg=' . $packageId);
    http_response_code(404);
    exit('Package not found or inactive.');
}

$packageRoot = SCORM_STORAGE_PATH . '/' . $packageId;

// When S3 is enabled we serve directly from the bucket — a local disk
// directory is optional (it may exist if the package was extracted by
// the upload handler, but S3-only packages are fully supported).
if (!isS3Configured() && !is_dir($packageRoot)) {
    error_log('[SERVE] EXIT 500: package dir missing (no S3), root=' . $packageRoot);
    http_response_code(500);
    exit('Package files missing on disk.');
}
if (!isS3Configured()) {
    // Pre-warm realpath so error messages are consistent
    $rootReal = realpath($packageRoot);
    if ($rootReal === false) {
        error_log('[SERVE] EXIT 500: package root unresolvable, root=' . $packageRoot);
        http_response_code(500);
        exit('Package root is invalid.');
    }
} else {
    $rootReal = $packageRoot; // nominal — not checked against filesystem
}

// ── Determine requested file path ──
$relPath = trim((string)($_GET['path'] ?? ''), '/');
$hasExplicitPath = ($relPath !== '');
if (!$hasExplicitPath) {
    // Default: launch SCO if known, else first SCO, else index.html
    $relPath = $pkg['launch_href'] ?: $pkg['first_sco_href'];
    if ($relPath === '' || $relPath === null) {
        $relPath = 'index.html';
    }
    // S3-aware entry point probing: when S3 is configured, verify the
    // resolved DB href actually exists in S3. If it does not, try common
    // SCORM/Articulate fallback entry points before giving up.
    // The primary key is checked first; only on a miss do we probe further
    // (avoiding unnecessary HEAD requests on every asset load).
    if (isS3Configured()) {
        $primaryKey = S3_PREFIX . $packageId . '/' . $relPath;
        if (s3Exists($primaryKey)) {
            // DB href is valid — no further probing needed
            $found = true;
        } else {
            // DB href missing in S3 — try known fallback entry points
            $fallbacks = array_unique([
                'scormcontent/index.html',   // Articulate Storyline HTML5
                'scormdriver/indexAPI.html', // Articulate SCORM wrapper
                'index.html',               // package root (last resort)
            ]);
            $found = false;
            foreach ($fallbacks as $candidate) {
                if ($candidate === $relPath) { continue; } // already tried
                $testKey = S3_PREFIX . $packageId . '/' . $candidate;
                if (s3Exists($testKey)) {
                    error_log('[SERVE] S3 entry probe: DB href=' . $relPath . ' not in S3, using ' . $candidate);
                    $relPath = $candidate;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                error_log('[SERVE] S3 entry probe exhausted for pkg=' . $packageId . '; using resolved=' . $relPath);
            }
        }
    }
}

// Decode URL-encoded characters
$relPath = rawurldecode($relPath);

// Strip #fragment for extension detection — pathinfo() on
// "index.html#/preview" returns "html#/preview" as extension
// which doesn't match the MIME map → 403.
if (($hashPos = strpos($relPath, '#')) !== false) {
    $relPath = substr($relPath, 0, $hashPos);
}

error_log('[SERVE] DETERMINED relPath=' . $relPath);
// ── Path traversal protection ──
if (
    strpos($relPath, '..') !== false ||
    strpos($relPath, '\\') !== false ||
    strpos($relPath, "\0") !== false ||
    strpos($relPath, ':') !== false
) {
    error_log('[SERVE] EXIT 403: path traversal blocked relPath=' . $relPath);
    http_response_code(403);
    exit('Forbidden.');
}

// ── Extension whitelist ──
// When S3 is configured, realpath() may fail for files only in S3 (not local disk).
// Use $relPath for extension detection; path traversal was already validated above.
if (isS3Configured()) {
    $fullPath = $packageRoot . '/' . $relPath; // nominal path (may not exist locally)
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
} else {
    // Resolve real path and ensure it stays inside package root
    $fullPath = realpath($packageRoot . '/' . $relPath);
    if ($fullPath === false || strpos($fullPath, $rootReal) !== 0) {
        error_log('[SERVE] EXIT 404: realpath fail fullPath=' . var_export($fullPath, true) . ' rootReal=' . var_export($rootReal, true) . ' packageRoot=' . $packageRoot . ' relPath=' . $relPath);
        http_response_code(404);
        exit('File not found.');
    }
    if (!is_file($fullPath)) {
        error_log('[SERVE] EXIT 404: local file missing (no S3) fullPath=' . $fullPath);
        http_response_code(404);
        exit('File not found.');
    }
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
}
$mimeMap = [
    'html' => 'text/html; charset=utf-8',
    'htm' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'mjs' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'bmp' => 'image/bmp',
    'avif' => 'image/avif',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'eot' => 'application/vnd.ms-fontobject',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogv' => 'video/ogg',
    'm3u8' => 'application/vnd.apple.mpegurl',  // HLS playlist
    'ts'   => 'video/mp2t',                       // HLS transport stream segment
    'm4v'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4',
    'aac' => 'audio/aac',
    'pdf' => 'application/pdf',
    'swf' => 'application/x-shockwave-flash',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
    'zip' => 'application/zip',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

if (!isset($mimeMap[$ext])) {
    error_log('[SERVE] EXIT 403: ext not allowed ext=' . $ext . ' relPath=' . $relPath);
    http_response_code(403);
    exit('File type not allowed.');
}

$contentType = $mimeMap[$ext];

// ── Range request handling (video seeking) ──
// Video players (HLS.js, native <video>) send Range: bytes=X-Y requests
// to seek and buffer. Without 206 Partial Content responses, browsers
// cannot seek and many will refuse to play the video at all.
//
// IMPORTANT: This MUST run BEFORE the full-body read below. Reading the
// entire object into memory first (s3Get) would download e.g. a 200 MB
// video just to serve a 64 KB seek chunk. We compute $s3Key up front
// (needed by both the range handler and the full-body read), then handle
// Range with the lightweight s3Head()/s3GetRange() calls and exit with
// 206 before the full-body read ever runs.
$s3Key = isS3Configured() ? (S3_PREFIX . $packageId . '/' . $relPath) : '';

$isRangeRequest = isset($_SERVER['HTTP_RANGE']) && $ext !== 'html' && $ext !== 'htm';
$streamableExts = ['mp4','webm','ogv','m4v','mov','mp3','wav','ogg','m4a','aac','ts','m3u8','pdf'];
$isStreamable   = in_array($ext, $streamableExts, true);

if ($isRangeRequest && $isStreamable) {
    // Determine total file size (lightweight HEAD — never loads the body)
    $totalSize = -1;
    if (isS3Configured()) {
        $totalSize = s3Head($s3Key);
    } elseif (file_exists($fullPath)) {
        $totalSize = filesize($fullPath);
    }

    if ($totalSize > 0) {
        // Parse Range header: "bytes=START-END" or "bytes=START-"
        $rangeStr = trim($_SERVER['HTTP_RANGE']);
        if (preg_match('/^bytes=\s*(\d+)\s*-\s*(\d*)/', $rangeStr, $rm)) {
            $rangeStart = (int)$rm[1];
            $rangeEnd   = ($rm[2] !== '') ? (int)$rm[2] : ($totalSize - 1);

            // Clamp to valid range
            if ($rangeStart < 0) $rangeStart = 0;
            if ($rangeEnd >= $totalSize) $rangeEnd = $totalSize - 1;

            if ($rangeStart > $rangeEnd || $rangeStart >= $totalSize) {
                // Unsatisfiable range
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $totalSize);
                exit;
            }

            $chunkSize = $rangeEnd - $rangeStart + 1;

            // Fetch the byte range
            $chunk = null;
            if (isS3Configured()) {
                $chunk = s3GetRange($s3Key, $rangeStart, $rangeEnd);
                if ($chunk === null && file_exists($fullPath)) {
                    // S3 range failed — fall back to local disk
                    $fh = fopen($fullPath, 'rb');
                    if ($fh) {
                        fseek($fh, $rangeStart);
                        $chunk = fread($fh, $chunkSize);
                        fclose($fh);
                    }
                }
            } else {
                $fh = fopen($fullPath, 'rb');
                if ($fh) {
                    fseek($fh, $rangeStart);
                    $chunk = fread($fh, $chunkSize);
                    fclose($fh);
                }
            }

            if ($chunk !== null && $chunk !== false) {
                http_response_code(206);
                header('Content-Type: ' . $contentType);
                header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $totalSize);
                header('Content-Length: ' . strlen($chunk));
                header('Accept-Ranges: bytes');
                header('Cache-Control: public, max-age=3600');
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Content-Type-Options: nosniff');
                echo $chunk;
                error_log('[SERVE] RANGE 206 pkg=' . $packageId . ' path=' . $relPath . ' range=' . $rangeStart . '-' . $rangeEnd . '/' . $totalSize . ' chunk=' . strlen($chunk));
                exit;
            }
            // Range fetch failed — fall through to full-body serve
            error_log('[SERVE] RANGE fetch failed, falling through to full-body: ' . $relPath);
        }
    }
}

// For streamable files, always advertise Accept-Ranges so browsers know they can seek
if ($isStreamable) {
    header('Accept-Ranges: bytes');
}

// ── Read file ──
$readSource = 'none';
if (isS3Configured()) {
    $body = s3Get($s3Key);
    if ($body === null) {
        // Fall back to local disk for packages uploaded before S3 was enabled,
        // or when the S3 object is missing. Only attempt if file exists locally.
        $readSource = 'local-fallback';
        if (file_exists($fullPath) && is_file($fullPath)) {
            $body = file_get_contents($fullPath);
        } else {
            $body = false;
        }
    } else {
        $readSource = 's3';
    }
} else {
    $readSource = 'local';
    $body = file_get_contents($fullPath);
}
if ($body === false) {
    // ── Missing-file fallback for Storyline/Rise virtual HTML pages ──
    // Storyline 360 creates several virtual iframes at runtime (e.g.
    // analytics-frame.html, blank.html) whose src is resolved relative to
    // the <base> tag and routed through serve.php. These files do not exist
    // in S3 because they are generated by the player JS, not uploaded with
    // the SCORM package. Returning 500 causes the browser to try to execute
    // the error page as JavaScript → SyntaxError.
    //
    // For HTML files that are not found in S3 or on disk, return a minimal
    // blank HTML document. This is safe: Storyline communicates with these
    // frames via postMessage, not by reading their HTML content.
    if ($ext === 'html' || $ext === 'htm') {
        error_log('[SERVE] VIRTUAL HTML fallback: ' . $relPath . ' not in S3 or disk — returning blank page');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: SAMEORIGIN');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title></title></head><body></body></html>';
        exit;
    }
    error_log('[SERVE] EXIT 500: read fail source=' . $readSource . ' s3Key=' . $s3Key . ' fullPath=' . $fullPath . ' relPath=' . $relPath);
    http_response_code(500);
    exit('Unable to read file.');
}

// Defensive: strip a UTF-8 BOM if present. A BOM in a required PHP file
// would otherwise be echoed before the asset bytes, corrupting fonts,
// images and other binary content (OTS "invalid sfntVersion" errors).
if (substr($body, 0, 3) === "ï»¿") {
    $body = substr($body, 3);
}

error_log('[SERVE] OK pkg=' . $packageId . ' path=' . $relPath . ' source=' . $readSource . ' ext=' . $ext . ' len=' . strlen($body) . ' s3cfg=' . (isS3Configured() ? '1' : '0'));

// Headers so SCORM content renders correctly inside the player iframe
header('Content-Type: ' . $contentType);
header('X-Content-Type-Options: nosniff');

// Non-HTML assets: serve immediately with path rewriting for JS & CSS
// S3-sourced assets: already cached by the optional Nginx reverse proxy
// (hestiacp/nginx.ssl.conf_scorm). For the fallback case (no Nginx proxy),
// apply a short cache so the browser revalidates.
//
// JS/CSS rewriter: Rise 360 webpack bundles set __webpack_require__.p
// to "/scorm-content/" in JS files. CSS may @import or url() from there.
// Without rewriting, all dynamic chunk loads bypass serve.php → 404.
if ($ext !== 'html' && $ext !== 'htm') {
    // Propagate the serve token into all rewritten URLs so that sub-asset
    // requests (JS chunks, CSS, etc.) are also authenticated without cookies.
    $_tokenSuffix = '';
    if (!empty($_GET['t'])) {
        $_tokenSuffix = '&t=' . rawurlencode((string)$_GET['t']);
    }
    $serveBaseShort = 'serve.php?pkg=' . $packageId . $_tokenSuffix . '&path=';

    if (($ext === 'js' || $ext === 'mjs' || $ext === 'css') && $readSource === 's3') {
        // ── Replace /scorm-content/ base URL in JS & CSS ──
        // Transforms: __webpack_require__.p = "/scorm-content/"
        //      into: __webpack_require__.p = "serve.php?pkg=19&path="
        //
        // BUG FIX: The original str_replace() was unconditional and corrupted
        // absolute URLs like "https://domain.com/scorm-content/file.html" by
        // replacing /scorm-content/ in the middle of the domain, producing
        // "https://domain.comserve.php?pkg=N&path=file.html" which the browser
        // rejects with: Uncaught SyntaxError: Unexpected identifier 'https'.
        //
        // The safe regex only replaces /scorm-content/ when it is preceded by
        // a quote, equals sign, whitespace, or open-paren — i.e., it is a
        // standalone path reference — and NOT when preceded by a domain name
        // character (letters/digits/dots/hyphens that form part of a URL host).
        $body = preg_replace(
            '~(["\'\'`=\s(]|^)/scorm-content/~',
            '$1' . $serveBaseShort,
            $body
        );

        // ── Catch bare webpack hex-hash chunks ──
        // e.g. __webpack_require__.p + "56803e6d" + ".css"
        // Guard: skip if the surrounding context already contains serve.php
        $body = preg_replace_callback(
            '#(["\'\'`])([a-f0-9]{6,16}\.(?:css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|json))(["\'\'`])#i',
            function ($m) use ($serveBaseShort) {
                return $m[1] . $serveBaseShort . $m[2] . $m[3];
            },
            $body
        );

        // ── CSS url() relative path rewriting ──
        // Rise 360 CSS files (mobile.min.css, desktop.min.css) use relative
        // url() paths like url(../../../html5/lib/stylesheets/mobile-fonts/...)
        // designed to be resolved from the CSS file's real path on disk.
        // When served through serve.php, the browser resolves them relative to
        // serve.php's URL (dropping the query string), producing wrong paths.
        // Fix: rewrite all relative url() references to absolute serve.php URLs.
        if ($ext === 'css') {
            $cssDir = dirname($relPath) . '/'; // e.g. "html5/lib/stylesheets/"
            $body = preg_replace_callback(
                '/url\(\s*([\'"]?)([^\)\'"]*)([\'"]?)\s*\)/i',
                function ($m) use ($serveBaseShort, $cssDir) {
                    $quote = $m[1];
                    $url   = $m[2];
                    // Skip: already absolute (http/https/data/serve.php)
                    if (preg_match('#^(https?://|data:|serve\.php)#i', $url)) {
                        return $m[0];
                    }
                    // Skip: protocol-relative
                    if (strpos($url, '//') === 0) {
                        return $m[0];
                    }
                    // Skip: empty or fragment-only (e.g. #map source map reference)
                    if (trim($url) === '' || $url[0] === '#') {
                        return $m[0];
                    }
                    // Resolve relative path against the CSS file's directory
                    $parts = explode('/', $cssDir . $url);
                    $resolved = [];
                    foreach ($parts as $part) {
                        if ($part === '' || $part === '.') continue;
                        if ($part === '..') { array_pop($resolved); continue; }
                        $resolved[] = $part;
                    }
                    $absPath = implode('/', $resolved);
                    return 'url(' . $quote . $serveBaseShort . $absPath . $quote . ')';
                },
                $body
            );
        }

        error_log('[SERVE] JS/CSS rewritten: ext=' . $ext . ' path=' . $relPath . ' len=' . strlen($body));
    }

    // ── HLS m3u8 playlist rewriting ──
    // HLS playlists (main.m3u8, stream_N.m3u8) contain relative references
    // to sub-playlists and .ts segment files, e.g.:
    //   stream_0.m3u8
    //   stream_0data000001.ts
    // When the HLS player fetches main.m3u8 via
    //   serve.php?path=story_content/video.hls/main.m3u8
    // and resolves "stream_0.m3u8" relative to that URL, the browser drops
    // the query string, producing /scorm-content/stream_0.m3u8 → 500.
    // Fix: rewrite all relative URI lines in the playlist to absolute serve.php URLs.
    if ($ext === 'm3u8') {
        $_tokenSuffix2 = '';
        if (!empty($_GET['t'])) {
            $_tokenSuffix2 = '&t=' . rawurlencode((string)$_GET['t']);
        }
        // CRITICAL: Use ABSOLUTE URLs (https://host/scorm-content/serve.php?...)
        // HLS.js fetches segment URLs via XHR/fetch. When the m3u8 contains
        // relative URLs like "serve.php?pkg=35&path=...", the browser resolves
        // them relative to the iframe's base URL (serve.php), which works for
        // the initial fetch but HLS.js internally resolves subsequent segment
        // URLs relative to the playlist URL — and since the playlist URL itself
        // is a serve.php URL (not a directory), relative resolution produces
        // /scorm-content/stream_0data000000.ts → direct nginx request → fails.
        // Absolute URLs bypass this entirely.
        $m3u8ServeBase = rtrim(buildUrl('scorm-content/serve.php'), '/') . '?pkg=' . $packageId . $_tokenSuffix2 . '&path=';
        $hlsDir = dirname($relPath) . '/'; // e.g. "story_content/video.hls/"

        // Strip UTF-8 BOM (\xEF\xBB\xBF) if present — Rise 360 m3u8 files
        // sometimes start with a BOM, which causes the first line to be treated
        // as a URI and prepended with the serve.php base URL.
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }

        // Helper: resolve a relative HLS path to an absolute serve.php URL
        $resolveHlsPath = function(string $rel) use ($m3u8ServeBase, $hlsDir): string {
            if (preg_match('#^https?://#i', $rel)) return $rel; // already absolute
            $parts = explode('/', $hlsDir . $rel);
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') continue;
                if ($part === '..') { array_pop($resolved); continue; }
                $resolved[] = $part;
            }
            return $m3u8ServeBase . implode('/', $resolved);
        };

        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            if ($trimmed[0] === '#') {
                // Rewrite URI="..." attributes inside EXT tags
                // e.g. #EXT-X-MEDIA:...,URI="stream_0.m3u8"
                //      #EXT-X-MAP:URI="init.mp4"
                $line = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    function ($m) use ($resolveHlsPath) {
                        return 'URI="' . $resolveHlsPath($m[1]) . '"';
                    },
                    $line
                );
                continue;
            }

            // Non-# lines: segment/sub-playlist URIs
            if (!preg_match('#^https?://#i', $trimmed)) {
                $line = $resolveHlsPath($trimmed);
            }
        }
        unset($line);
        $body = implode("\n", $lines);
        error_log('[SERVE] m3u8 rewritten: path=' . $relPath . ' hlsDir=' . $hlsDir);
    }

    $maxAge = $readSource === 's3' ? 604800 : 3600; // 7 days (S3) or 1h (local)
    header('Cache-Control: public, max-age=' . $maxAge);
    header('X-Frame-Options: SAMEORIGIN');
    echo $body;
    exit;
}

// ── HTML: SCORM RTE injection + URL rewriting ──
// Everything below runs ONLY for HTML files.

// ── HTML Output Cache (per-user, per-package, per-path) ──
// SCORM packages are immutable once uploaded (re-uploads create a new ID),
// so the fully injected + rewritten HTML can be safely cached on disk.
// The cache is per-user because window.SCORM_USER embeds learner identity.
$cacheUserId  = (int)($_SESSION['user_id'] ?? 0);
$cacheKey     = md5($relPath . '|' . (int)($_GET['sco'] ?? 0));
$scormCacheDir  = SCORM_CACHE_PATH . '/' . $packageId . '_' . $cacheUserId;
$scormCacheFile = $scormCacheDir . '/' . $cacheKey . '.html';

// ?nocache=1 bypasses the cache for debugging / template changes.
$cacheBypass = isset($_GET['nocache']) && $_GET['nocache'] === '1';

if (!$cacheBypass && is_file($scormCacheFile)) {
    $cachedBody = @file_get_contents($scormCacheFile);
    if ($cachedBody !== false && $cachedBody !== '') {
        error_log('[SERVE] CACHE HIT pkg=' . $packageId . ' user=' . $cacheUserId . ' path=' . $relPath . ' len=' . strlen($cachedBody));
        header('Cache-Control: public, max-age=3600');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-SCORM-Cache: HIT');
        echo $cachedBody;
        exit;
    }
}
header('X-SCORM-Cache: MISS');

// Current learner identity for cmi.core.student_id / cmi.learner_id
$sessUserId = $_SESSION['user_id'] ?? '';
$sessEmail  = $_SESSION['email'] ?? '';
$sessFirst  = $_SESSION['first_name'] ?? '';
$sessLast   = $_SESSION['last_name'] ?? '';

// Try to enrich from DB for a nicer display name
if (!empty($sessUserId) && !isTestUser()) {
    try {
        $pdo = getDbConnection();
        $uStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $uStmt->execute([(int)$sessUserId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $sessFirst = $uRow['first_name'] ?: $sessFirst;
            $sessLast  = $uRow['last_name'] ?: $sessLast;
            $sessEmail = $uRow['email'] ?: $sessEmail;
        }
    } catch (PDOException $e) {
        error_log('[SCORM] serve.php user lookup failed: ' . $e->getMessage());
    }
}

$inject = "<script>window.SCORM_PACKAGE_CONFIG = " . json_encode([
    'pkg' => $packageId,
    'sco' => (int)($_GET['sco'] ?? 0),
    // Optional resume attempt ID threaded through the launch URL. The RTE seeds
    // its attemptId from this so the first Initialize resumes the stored state.
    'attempt' => (int)($_GET['attempt'] ?? 0),
    'version' => $pkg['scorm_version'] ?? '1.2',
    // scorm_edition ('1.2', '2004 2nd Edition', ...) — drives suspend-data
    // limits and edition-specific field behaviour in scorm-rte.js.
    'edition' => $pkg['scorm_edition'] ?? '',
    // Edition-aware suspend_data character limit, computed server-side so the
    // RTE never needs to re-derive it (see scorm-normalize.php).
    'suspendLimit' => scormSuspendDataLimit($pkg['scorm_edition'] ?? ($pkg['scorm_version'] ?? '1.2')),
    'apiEndpoint' => rtrim(buildUrl('scorm-api/store.php'), '/'),
    // Serve token — lets scorm-rte.js authenticate its store.php tracking POST
    // without the session cookie (SameSite=Lax isn't sent from inside the iframe).
    'token' => (string)($_GET['t'] ?? ''),
    // Only expose the RTE diagnostics global when the player explicitly opts in
    // (admin ?diag=1), so learner state stays out of page JS otherwise.
    'debugRte' => !empty($_GET['diag']),
    // Compatibility mode: accept cross-version spellings (1.2 on 2004 and vice
    // versa) which many Storyline/Rise packages need. Set SCORM_COMPAT_MODE=0
    // in .env to enable strict per-version conformance globally.
    'compatMode' => !(defined('SCORM_COMPAT_MODE') && SCORM_COMPAT_MODE === '0'),
    // Where the top window should navigate when the course exits
    // (LMSFinish / Terminate). Used by scorm-rte.js to take over the exit
    // navigation from Storyline, which otherwise frames the exit URL and
    // triggers X-Frame-Options errors or a nested-iframe mess.
    'exitUrl' => rtrim(buildUrl('course-page/'), '/'),
]) . ";</script>";

$userName = trim($sessFirst . ' ' . $sessLast);
if ($userName === '') {
    $userName = $sessEmail ?: 'Learner';
}
$inject .= "<script>window.SCORM_USER = " . json_encode([
    'id' => $sessEmail ?: (string)$sessUserId,
    'name' => $userName,
]) . ";</script>";

// ── Rise 360 / Storyline URL intercept patch ──
// Rise 360 and Storyline build ALL asset URLs at runtime using:
//   window.location.protocol + "//" + window.location.host
//   + window.location.pathname.split(/\/+/).slice(0,-1).join("/")
//   + DATA_PATH_BASE + "/html5/lib/..."
//
// Inside the iframe, window.location.pathname is "/scorm-content/serve.php",
// so the computed base is "/scorm-content/" and asset URLs become:
//   https://edu.pursuitpathways.com/scorm-content/html5/lib/stylesheets/mobile.min.css
// These go directly to nginx → 404, completely bypassing serve.php.
//
// Object.defineProperty on window.location is blocked by browsers in iframes.
// The correct server-side fix is the nginx rewrite rule in:
//   server-config/hestiacp-nginx-scorm.conf
// which intercepts /scorm-content/{path} requests and rewrites them to
// serve.php?pkg=N&t=TOKEN&path={path} using the Referer header.
//
// As a belt-and-suspenders client-side fallback (for cases where the nginx
// rule is not yet active), we also intercept fetch() and XMLHttpRequest to
// rewrite any /scorm-content/{path} URL through serve.php.
$_tokenForPatch = '';
if (!empty($_GET['t'])) {
    $_tokenForPatch = rawurlencode((string)$_GET['t']);
}
$serveBase = rtrim(buildUrl('scorm-content/serve.php'), '/');
$_serveBaseJs = json_encode($serveBase . '?pkg=' . $packageId . ($_tokenForPatch ? '&t=' . $_tokenForPatch : '') . '&path=');
$inject .= '<script>';
$inject .= '(function(){';
$inject .= 'var _sb=' . $_serveBaseJs . ';';
$inject .= 'window.__SCORM_SERVE_BASE__=_sb;';
// Watchdog to resolve stuck loadDfds (e.g. from failed HLS video canplay events)
$inject .= 'setInterval(function(){';
$inject .= '  try{';
$inject .= '    var containers=document.querySelectorAll(".slide-transition-container");';
$inject .= '    for(var i=0;i<containers.length;i++){';
$inject .= '      var slideEl=containers[i].querySelector("[class*=\'cs-\']");';
$inject .= '      if(slideEl&&slideEl.view&&slideEl.view.loadDfd&&slideEl.view.loadDfd.state&&slideEl.view.loadDfd.state()==="pending"){';
$inject .= '        if(!slideEl.view._loadDfdStartTime) slideEl.view._loadDfdStartTime=Date.now();';
$inject .= '        if(Date.now()-slideEl.view._loadDfdStartTime>4000){';
$inject .= '          var dfds=slideEl.view.loadDfd.dfds;';
$inject .= '          if(dfds){';
$inject .= '            for(var j=0;j<dfds.length;j++){';
$inject .= '              if(dfds[j].state&&dfds[j].state()==="pending") dfds[j].resolve();';
$inject .= '            }';
$inject .= '          }';
$inject .= '        }';
$inject .= '      }';
$inject .= '    }';
$inject .= '  }catch(e){}';
$inject .= '}, 1000);';
// Intercept fetch() to rewrite direct /scorm-content/ URLs
$inject .= 'var _origFetch=window.fetch;';
$inject .= 'window.fetch=function(r,o){';
$inject .= 'var u=typeof r==="string"?r:(r&&r.url?r.url:"");';
$inject .= 'var m=u.match(/\/scorm-content\/(?!serve\.php|debug\.php|proxy\.php)(.+)$/);';
$inject .= 'if(m){u=_sb+m[1];r=typeof r==="string"?u:new Request(u,r);}';
$inject .= 'return _origFetch.call(this,r,o);';
$inject .= '};';
// Intercept XMLHttpRequest.open() to rewrite direct /scorm-content/ URLs
$inject .= 'var _origOpen=XMLHttpRequest.prototype.open;';
$inject .= 'XMLHttpRequest.prototype.open=function(m,u){';
$inject .= 'var args=Array.prototype.slice.call(arguments);';
$inject .= 'var match=u&&u.match&&u.match(/\/scorm-content\/(?!serve\.php|debug\.php|proxy\.php)(.+)$/);';
$inject .= 'if(match)args[1]=_sb+match[1];';
$inject .= 'return _origOpen.apply(this,args);';
$inject .= '};';
// Intercept dynamic element injection (createElement)
// Handles: link, script, img, source (absolute /scorm-content/ URLs)
//          iframe (relative URLs like "analytics-frame.html#uuid" from Rise 360)
//
// APPROACH: Use a Proxy-style setter that intercepts the src/href assignment,
// computes the rewritten URL, then calls Element.prototype.setAttribute to
// update the CONTENT ATTRIBUTE directly. This ensures the browser's resource
// loader sees the rewritten URL (browsers load resources from content attributes,
// not JS properties).
//
// We save _origSetAttr before overriding createElement so we can call it
// without triggering our own intercept.
$inject .= 'var _origSetAttr=Element.prototype.setAttribute;';
$inject .= 'var _origCreateEl=document.createElement.bind(document);';
$inject .= 'document.createElement=function(tag){';
$inject .= 'var el=_origCreateEl(tag);';
$inject .= 'var t=tag.toLowerCase();';
$inject .= 'if(t==="link"||t==="script"||t==="img"||t==="source"||t==="iframe"){';
$inject .= 'var _attr=t==="link"?"href":"src";';
$inject .= '(function(_el,_t,_a){';
$inject .= 'var _desc={';
$inject .= 'get:function(){return _el.getAttribute(_a)||"";},';
$inject .= 'set:function(v){';
// Compute rewritten URL
$inject .= 'var rv=v;';
// Rewrite absolute /scorm-content/ URLs (link, script, img, source, iframe)
$inject .= 'var m=v&&v.match&&v.match(/\/scorm-content\/(?!serve\.php|debug\.php|proxy\.php)(.+)$/);';
$inject .= 'if(m){rv=_sb+m[1];}';
// Rewrite relative URLs for ALL intercepted elements.
// Rise 360 sets script.src = "html5/data/js/frame.js" (relative path, no /scorm-content/ prefix)
// and iframe.src = "analytics-frame.html#uuid" (bare relative path).
// Any relative URL (no http/https, no protocol-relative //) is a SCORM content file.
$inject .= 'else if(v&&v.indexOf("http")<0&&v.indexOf("//")<0&&v.length>0){rv=_sb+v;}';
// Update the content attribute directly so the browser loads the rewritten URL
$inject .= '_origSetAttr.call(_el,_a,rv);';
$inject .= '}';
$inject .= '};';
$inject .= 'try{Object.defineProperty(_el,_a,{get:_desc.get,set:_desc.set,configurable:true});}catch(e){}';
$inject .= '})(el,t,_attr);';
$inject .= '}';
$inject .= 'return el;';
$inject .= '};';
// Prototype-level image src interception: catches new Image() and any img.src
// assignment that bypasses createElement (e.g. direct prototype assignment).
// Rise 360 uses new Image() for preloading slide assets.
$inject .= '(function(){';
$inject .= 'var _origImgSrcDesc=Object.getOwnPropertyDescriptor(HTMLImageElement.prototype,"src");';
$inject .= 'if(_origImgSrcDesc&&_origImgSrcDesc.set){';
$inject .= 'Object.defineProperty(HTMLImageElement.prototype,"src",{';
$inject .= 'get:_origImgSrcDesc.get,';
$inject .= 'set:function(v){';
$inject .= 'var rv=v;';
$inject .= 'var m=v&&v.match&&v.match(/\/scorm-content\/(?!serve\.php|debug\.php|proxy\.php)(.+)$/);';
$inject .= 'if(m){rv=_sb+m[1];}';
$inject .= 'else if(v&&v.indexOf("http")<0&&v.indexOf("//")<0&&v.length>0){rv=_sb+v;}';
$inject .= '_origImgSrcDesc.set.call(this,rv);';
$inject .= '},';
$inject .= 'configurable:true';
$inject .= '});';
$inject .= '}';
$inject .= '})();';
$inject .= '})();';
$inject .= '</script>';

// Inject the RTE using an absolute URL so the <base> tag (added below) does not
// redirect it through serve.php?path=scorm-rte.js (which would 500 — the file
// is not in S3). buildUrl() always returns an absolute https:// URL.
$rteUrl = rtrim(buildUrl('scorm-api/scorm-rte.js'), '/');
$inject .= '<script src="' . htmlspecialchars($rteUrl, ENT_QUOTES) . '" crossorigin="anonymous"></script>';

// Inject a tiny postMessage error reporter so the parent player page can
// display the debug overlay when a resource inside the iframe fails to load.
$inject .= '<script>(function(){';
$inject .= 'window.addEventListener("error",function(e){';
$inject .= 'if(e.target&&e.target.src){try{parent.postMessage({type:"scorm-resource-error",url:e.target.src,status:0},"*");}catch(x){}}';
$inject .= 'else if(e.target&&e.target.href){try{parent.postMessage({type:"scorm-resource-error",url:e.target.href,status:0},"*");}catch(x){}}';
$inject .= '},true);';
$inject .= '})();</script>';

// ── Inject <base href> so Rise 360 / Storyline relative URLs resolve through serve.php ──
// Rise 360 and Storyline build asset URLs at runtime using DATA_PATH_BASE (empty string)
// concatenated with the base URL derived from document.currentScript.src or
// window.location. Without a <base> tag the browser computes the directory as
// /scorm-content/ (the directory containing serve.php), so paths like
// "analytics-frame.html" or "html5/lib/scripts/frame.desktop.min.js" resolve to
// /scorm-content/analytics-frame.html — which goes directly to nginx and 404s.
//
// Setting <base href="serve.php?pkg=N&t=TOKEN&path="> makes every relative URL
// in the page resolve as serve.php?pkg=N&t=TOKEN&path=<relative-path>, which
// routes correctly through the PHP SCORM reader.
//
// IMPORTANT: The <base> tag must be the FIRST element in <head> (before any
// existing <link> or <script> tags) so the browser uses it for all subsequent
// relative URL resolutions in the document.
$_tokenForBase = '';
if (!empty($_GET['t'])) {
    $_tokenForBase = '&t=' . rawurlencode((string)$_GET['t']);
}
$baseHref = 'serve.php?pkg=' . $packageId . $_tokenForBase . '&path=';
$baseTag  = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES) . '">';
// Insert as the very first child of <head> (before charset meta, etc.)
$body = preg_replace('/<head(\b[^>]*)>/i', '<head$1>' . $baseTag, $body, 1);
// Fallback: if no <head> tag found, prepend before </head>
if (strpos($body, $baseTag) === false) {
    $body = str_replace('</head>', $baseTag . '</head>', $body);
}

$body = str_replace('</head>', $inject . '</head>', $body);

// ── Rewrite relative asset URLs to go through serve.php ──
// nginx does not process .htaccess rewrite rules, so relative URLs
// like "scormcontent/allowed-options.js" inside the iframe would 404.
// We rewrite them to: serve.php?pkg=12&path=scormcontent/allowed-options.js
//
// IMPORTANT: The rewrite must NOT touch <script>...</script> content.
// The regex matches src/href/data attributes which appear inside JS
// string literals and function calls, corrupting JavaScript syntax.
// Propagate the serve token into all rewritten HTML attribute URLs.
$_tokenSuffix = '';
if (!empty($_GET['t'])) {
    $_tokenSuffix = '&t=' . rawurlencode((string)$_GET['t']);
}
$serveBase = 'serve.php?pkg=' . $packageId . $_tokenSuffix . '&path=';
$stripPrefix = dirname($relPath);
if ($stripPrefix !== '.' && $stripPrefix !== '') {
    $stripPrefix = $stripPrefix . '/';
} else {
    $stripPrefix = '';
}

$rewriteCount = 0;

// ── Pre-pass: rewrite <script src="..."> attributes ──
// Script tags are caught by the preg_split below and placed in protected
// odd-index segments. The src= attribute inside the opening <script> tag
// would NEVER be rewritten. This pre-pass targets only the opening tag
// src= attribute before splitting — it does NOT touch JS code inside the tag.
$body = preg_replace_callback(
    '/<script\b([^>]*?)\bsrc\s*=\s*(["\'])((?!https?:\/\/|\/\/|\/|data:|javascript:|#|\?)[^"\']+)(["\'])([^>]*?)>/i',
    function ($m) use ($serveBase, $stripPrefix, &$rewriteCount) {
        $attrsBefore = $m[1];
        $q           = $m[2];
        $url         = $m[3];
        $endq        = $m[4];
        $attrsAfter  = $m[5];
        if (strpos($url, 'serve.php') !== false) {
            return $m[0];
        }
        $fullRel = $stripPrefix . $url;
        while (($pos = strpos($fullRel, '/../')) !== false) {
            $before = substr($fullRel, 0, $pos);
            $parent = dirname($before);
            if ($parent === '.') $parent = '';
            $fullRel = $parent . '/' . substr($fullRel, $pos + 4);
        }
        $fullRel = preg_replace('#^\./+#', '', $fullRel);
        $rewriteCount++;
        return '<script' . $attrsBefore . 'src=' . $q . $serveBase . urlencode($fullRel) . $endq . $attrsAfter . '>';
    },
    $body
);

// ── Protect script blocks using a forward depth-tracking parser ──
// Scans left-to-right, tracking <script...> / </script...> depth.
// This is algorithmically identical to how browsers and real HTML
// parsers match script tags, and handles nested "</script>" inside
// JavaScript strings correctly.
$scriptBlocks = [];
$temp   = $body;
$body   = ''; // rebuilt: safe HTML with placeholders
$scanLen = strlen($temp);
$scanPos = 0;

while ($scanPos < $scanLen) {
    // Find the next '<' that might start a script tag
    $ltPos = strpos($temp, '<', $scanPos);
    if ($ltPos === false) {
        $body .= substr($temp, $scanPos);
        break;
    }

    // Is it <script...> ? Guard: next char must NOT be alphabetic
    // (prevents false-positives on <scripts>, <scripting>, etc.)
    $isOpen  = (substr_compare($temp, '<script', $ltPos, 7, true) === 0
                && ($ltPos + 7 >= $scanLen || !ctype_alpha($temp[$ltPos + 7])));
    // Is it </script...> ? Guard: next char must NOT be alphabetic
    $isClose = (substr_compare($temp, '</script', $ltPos, 8, true) === 0
                && $ltPos + 8 < $scanLen
                && ($ltPos + 8 >= $scanLen || !ctype_alpha($temp[$ltPos + 8])));

    if (!$isOpen && !$isClose) {
        // Not a script tag — skip one character and continue
        $body .= $temp[$scanPos];
        $scanPos++;
        continue;
    }

    if ($isClose) {
        // </script> found WITHOUT a matching open — likely inside JS string.
        // Skip past one character, keep it in the output undisturbed.
        $body .= $temp[$scanPos];
        $scanPos++;
        continue;
    }

    // ── <script...> found — now scan forward to extract the full block ──
    // Find the '>' of the opening tag
    $openTagEnd = $ltPos;
    while ($openTagEnd < $scanLen && $temp[$openTagEnd] !== '>') {
        $openTagEnd++;
    }
    if ($openTagEnd >= $scanLen) {
        // Malformed — no closing '>', append rest as-is
        $body .= substr($temp, $scanPos);
        break;
    }
    $openTagEnd++; // include '>'

    // Everything before <script> is safe HTML
    $body .= substr($temp, $scanPos, $ltPos - $scanPos);

    // Now track depth forward from the tag end, looking for </script>
    $depth = 1;
    $searchPos = $openTagEnd;
    while ($searchPos < $scanLen && $depth > 0) {
        // Find next occurrence of '<' from here
        $nextLt = strpos($temp, '<', $searchPos);
        if ($nextLt === false) break;

        $nextOpen  = (substr_compare($temp, '<script', $nextLt, 7, true) === 0
                      && ($nextLt + 7 >= $scanLen || !ctype_alpha($temp[$nextLt + 7])));
        $nextClose = (substr_compare($temp, '</script', $nextLt, 8, true) === 0
                      && $nextLt + 8 < $scanLen
                      && ($nextLt + 8 >= $scanLen || !ctype_alpha($temp[$nextLt + 8])));

        if ($nextOpen) {
            $depth++;
            $searchPos = $nextLt + 7;
        } elseif ($nextClose) {
            $depth--;
            if ($depth === 0) {
                // Found matching close — find the end of this closing tag
                $closeTagEnd = $nextLt + 8;
                while ($closeTagEnd < $scanLen && ($temp[$closeTagEnd] === ' ' || $temp[$closeTagEnd] === "\t" || $temp[$closeTagEnd] === "\n" || $temp[$closeTagEnd] === "\r")) {
                    $closeTagEnd++;
                }
                if ($closeTagEnd < $scanLen && $temp[$closeTagEnd] === '>') {
                    $closeTagEnd++;
                }
                // Extract the full block: <script...> ... </script>
                $idx = count($scriptBlocks);
                $scriptBlocks[$idx] = substr($temp, $ltPos, $closeTagEnd - $ltPos);
                $body .= '%%SCORM_SCRIPT_' . $idx . '%%';
                $scanPos = $closeTagEnd;
                break;
            }
            $searchPos = $nextLt + 8;
        } else {
            $searchPos = $nextLt + 1;
        }
    }

    if ($depth > 0) {
        // Unclosed <script> — append it and everything after as safe HTML
        $body .= substr($temp, $ltPos);
        break;
    }
}
error_log('[SERVE] Script blocks protected: ' . count($scriptBlocks) . ' blocks, ' . (strlen($body) - strlen(str_replace('%%SCORM_SCRIPT_', '', $body))) . ' placeholder bytes');

// ── Rewrite HTML attributes (scripts already removed) ──
// The rewrite logic below now runs on HTML with script blocks replaced
// by placeholders — JavaScript code is completely protected.
try {
    $body = preg_replace_callback(
        '/(src|href|action|data)(\s*=\s*)(["\'])((?!https?:\/\/|\/\/|data:|javascript:|mailto:|#|\?)[^"\'#]+)(["\'])/i',
        function ($m) use ($serveBase, $stripPrefix, &$rewriteCount) {
            $attr = $m[1];
            $eq = $m[2];
            $q = $m[3];
            $url = $m[4];
            $endq = $m[5];

            if (strpos($url, 'serve.php') !== false) {
                return $m[0];
            }
            $fullRelPath = $stripPrefix . $url;
            while (($pos = strpos($fullRelPath, '/../')) !== false) {
                $before = substr($fullRelPath, 0, $pos);
                $parent = dirname($before);
                if ($parent === '.') $parent = '';
                $fullRelPath = $parent . '/' . substr($fullRelPath, $pos + 4);
            }
            $fullRelPath = preg_replace('#^\./+#', '', $fullRelPath);
            $rewriteCount++;
            return $attr . $eq . $q . $serveBase . urlencode($fullRelPath) . $endq;
        },
        $body
    );
} catch (\Throwable $e) {
    error_log('[SERVE] HTML attr rewrite failed: ' . $e->getMessage() . ' — serving unrewritten HTML');
}

// Also rewrite CSS url() references
try {
    $body = preg_replace_callback(
        '/url\(\s*(["\']?)((?!https?:\/\/|data:|["\'])[^"\'\)]+)(["\']?)\s*\)/i',
        function ($m) use ($serveBase, $stripPrefix, &$rewriteCount) {
            $q1 = $m[1];
            $url = $m[2];
            $q2 = $m[3];

            if (strpos($url, 'serve.php') !== false) {
                return $m[0];
            }
            $fullRelPath = $stripPrefix . $url;
            while (($pos = strpos($fullRelPath, '/../')) !== false) {
                $before = substr($fullRelPath, 0, $pos);
                $parent = dirname($before);
                if ($parent === '.') $parent = '';
                $fullRelPath = $parent . '/' . substr($fullRelPath, $pos + 4);
            }
            $fullRelPath = preg_replace('#^\./+#', '', $fullRelPath);
            $rewriteCount++;
            return 'url(' . $q1 . $serveBase . urlencode($fullRelPath) . $q2 . ')';
        },
        $body
    );
} catch (\Throwable $e) {
    error_log('[SERVE] CSS url rewrite failed: ' . $e->getMessage());
}

// ── Rewrite SCORM paths inside script blocks ──
foreach ($scriptBlocks as $idx => &$block) {
    try {
        // Pass 1: Known asset-directory patterns (scormcontent/, lib/, html5/, ...)
        // BUG FIX: Added html5|mobile|tablet|desktop|content|data|course|story|lms
        // to the known-dir alternation. Rise 360 packages use html5/ as their
        // top-level directory (html5/lib/scripts/, html5/lib/stylesheets/, etc.).
        // Without html5 in this list, all Rise 360 asset paths were silently
        // skipped by Pass 1 and fell through to Pass 3 which only catches
        // root-level filenames — so html5/lib/... paths were never rewritten → 404.
        $block = preg_replace_callback(
            '#([\"\'\x60])(\.\./|\./)?/?((?:scormcontent|scormdriver|html5|mobile|tablet|desktop|lib|assets|fonts|img|images|css|js|media|audio|video|resources|scripts|styles|content|data|course|story|lms)/[^\"\'\x60]*)([\"\'\x60])#',
            function ($m) use ($serveBase, $stripPrefix) {
                $rel = ($m[2] ?? '') . $m[3];
                if ($m[2] === '../' || $m[2] === './' || !preg_match('#^(?:scormcontent|scormdriver)/#', $rel)) {
                    $rel = $stripPrefix . $rel;
                    while (($pos = strpos($rel, '/../')) !== false) {
                        $before = substr($rel, 0, $pos);
                        $parent = dirname($before);
                        if ($parent === '.') $parent = '';
                        $rel = $parent . '/' . substr($rel, $pos + 4);
                    }
                    $rel = preg_replace('#^\./+#', '', $rel);
                }
                return $m[1] . $serveBase . urlencode($rel) . $m[4];
            },
            $block
        );

        // Pass 2: Catch webpack dynamic chunks and bare hash files.
        // Articulate Rise 360 / Storyline webpack bundles construct URLs
        // like: __webpack_require__.p + "a1b2c3d4.css" which resolves to
        // "/scorm-content/a1b2c3d4.css" at runtime. Our asset-dir pattern
        // (above) never matches these because there is no directory prefix.
        $block = preg_replace_callback(
            '#([\"\'\x60])(/?(?:scorm-content/))?([a-f0-9]{6,16}\.(?:css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|json))([\"\'\x60])#i',
            function ($m) use ($serveBase, $packageId) {
                $rel = $m[3];
                $fullPath = rtrim(($m[2] ?? '') . $rel, '/');
                return $m[1] . $serveBase . urlencode($fullPath) . $m[4];
            },
            $block
        );

        // Pass 3: Catch named chunk files AND root-relative paths.
        // Rise 360 uses named webpack chunks (not hex hashes):
        //   "desktop.min.css", "frame.desktop.min.js", "allowed-options.js"
        // AND constructs iframes with root-relative paths:
        //   "/scorm-content/analytics-frame.html#uuid"
        //
        // This regex catches any [a-zA-Z0-9_.-]+.ext OR /scorm-content/...
        // references inside JavaScript string literals.
        //
        // BUG FIX: Added guard to skip strings that already contain serve.php
        // or a percent-encoded slash (%2F) — these are already-rewritten URLs
        // from Pass 1/2 or the str_replace above. Without this guard, Pass 3
        // would match the filename at the end of a rewritten URL and wrap it
        // again, producing double-paths like:
        //   serve.php?pkg=N&path=serve.php%3Fpkg%3DN%26path%3Dfile.html
        // BUG FIX (analytics-frame.html): Rise 360 constructs the analytics iframe as:
        //   e.src = "analytics-frame.html#" + uuid
        // The original Pass 3 regex required the closing quote to immediately follow
        // the file extension, so "analytics-frame.html#" never matched (the # broke it).
        // The updated regex adds an optional (#[^"'`\s]*) capture group (group 4) to
        // consume and preserve any #fragment that appears between the filename and the
        // closing quote. The closing quote is now captured as group 5.
        // IMPORTANT: This regex uses ~ as delimiter (not #) because the pattern
        // contains a literal # character in the fragment capture group (#[...]).
        // Using # as both the delimiter and a literal character inside the pattern
        // causes PHP to treat the # as the closing delimiter, breaking the regex.
        $block = preg_replace_callback(
            '~(["\'\'\x60])(/scorm-content/)?([a-zA-Z0-9_.\-]+(?:\.min)?\.(?:css|js|html|json|xml|png|jpg|jpeg|gif|svg|woff2?|ttf|mp4|webm|mp3|pdf|ico|eot))(#[^"\'\'\x60\s]*)?(["\'\x60])~i',
            function ($m) use ($serveBase) {
                // Skip already-rewritten URLs
                if (strpos($m[0], 'serve.php') !== false || strpos($m[0], '%2F') !== false) {
                    return $m[0];
                }
                $rel      = $m[3];          // filename
                $fragment = $m[4] ?? '';    // optional #fragment (e.g. "#" in "analytics-frame.html#")
                $closeQ   = $m[5];          // closing quote
                return $m[1] . $serveBase . urlencode($rel) . $fragment . $closeQ;
            },
            $block
        );
    } catch (\Throwable $e) {
        error_log('[SERVE] Script block ' . $idx . ' path rewrite failed: ' . $e->getMessage());
    }
}
unset($block);

// ── Restore script blocks ──
foreach ($scriptBlocks as $idx => $block) {
    $body = str_replace('%%SCORM_SCRIPT_' . $idx . '%%', $block, $body);
}

// ── Write fully-injected, rewritten HTML to cache ──
if (!$cacheBypass) {
    if (!is_dir($scormCacheDir)) {
        @mkdir($scormCacheDir, 0755, true);
    }
    if (is_dir($scormCacheDir) && is_writable($scormCacheDir)) {
        $cacheTmpFile = $scormCacheFile . '.tmp.' . getmypid();
        if (@file_put_contents($cacheTmpFile, $body) !== false) {
            @rename($cacheTmpFile, $scormCacheFile);
            error_log('[SERVE] CACHE WRITE pkg=' . $packageId . ' user=' . $cacheUserId . ' path=' . $relPath . ' len=' . strlen($body));
        }
    }
}

error_log('[SERVE] HTML rewrite count=' . $rewriteCount . ' stripPrefix=' . $stripPrefix);

header('Cache-Control: public, max-age=3600');
header('X-Frame-Options: SAMEORIGIN');
echo $body;
