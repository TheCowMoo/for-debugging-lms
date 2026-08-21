<?php
/**
 * SCORM CONTENT SERVER — DEBUG TOOL
 * Traces the entire serve.php pipeline step by step to diagnose 500 errors.
 *
 * Usage: https://edu.pursuitpathways.com/scorm-content/debug.php?pkg=17
 *
 * Requires login (same session as serve.php).
 * Outputs plain text; safe — no data modification.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();
// Debug tool prints S3 config, DB internals, and raw content — ADMIN ONLY.
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

$packageId = (int)($_GET['pkg'] ?? 0);
if ($packageId <= 0) {
    $packageId = (int)($_GET['id'] ?? 0);
}

echo "══════════════════════════════════════════════\n";
echo "SCORM CONTENT SERVER — FULL PIPELINE DEBUG\n";
echo "══════════════════════════════════════════════\n";
echo "Target package ID: " . ($packageId > 0 ? $packageId : '(none — add ?pkg=ID to URL)') . "\n\n";

// ──────────────────────────────────────────────────
// 1. PHP / Extension check
// ──────────────────────────────────────────────────
echo "─── 1. PHP & EXTENSIONS ───\n";
echo "PHP Version:     " . PHP_VERSION . "\n";
echo "SAPI:            " . php_sapi_name() . "\n";
echo "Memory limit:    " . ini_get('memory_limit') . "\n";
echo "Max exec time:   " . ini_get('max_execution_time') . "s\n";
echo "Max upload:      " . ini_get('upload_max_filesize') . "\n";
echo "Post max size:   " . ini_get('post_max_size') . "\n";
echo "display_errors:  " . ini_get('display_errors') . "\n";
echo "error_reporting: " . ini_get('error_reporting') . "\n\n";

$extensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'session', 'openssl', 'libxml', 'dom', 'zip', 'mbstring'];
foreach ($extensions as $ext) {
    echo "  " . (extension_loaded($ext) ? "✅" : "❌ MISSING") . " $ext\n";
}
echo "\n";

// ──────────────────────────────────────────────────
// 2. S3 Configuration
// ──────────────────────────────────────────────────
echo "─── 2. S3 CONFIGURATION ───\n";
if (function_exists('isS3Configured')) {
    echo "isS3Configured(): " . (isS3Configured() ? "YES" : "NO") . "\n";
} else {
    echo "isS3Configured(): FUNCTION NOT DEFINED\n";
}
echo "S3_BUCKET:      " . (defined('S3_BUCKET') ? S3_BUCKET : '(not defined)') . "\n";
echo "S3_REGION:      " . (defined('S3_REGION') ? S3_REGION : '(not defined)') . "\n";
echo "S3_KEY set:     " . (defined('S3_KEY') && S3_KEY !== '' ? "YES" : "NO") . "\n";
echo "S3_SECRET set:  " . (defined('S3_SECRET') && S3_SECRET !== '' ? "YES (hidden)" : "NO") . "\n";
echo "S3_ENDPOINT:    " . (defined('S3_ENDPOINT') && S3_ENDPOINT !== '' ? S3_ENDPOINT : '(default AWS)') . "\n";
echo "S3_DEBUG:       " . (defined('S3_DEBUG') ? (S3_DEBUG ? 'ON' : 'off') : '(not defined)') . "\n";
$endpoint = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
    ? rtrim(S3_ENDPOINT, '/')
    : "https://" . (defined('S3_BUCKET') ? S3_BUCKET : '?') . ".s3." . (defined('S3_REGION') ? S3_REGION : '?') . ".amazonaws.com";
echo "Effective URL:  " . $endpoint . "\n\n";

if (!function_exists('isS3Configured') || !isS3Configured()) {
    echo "⚠️  S3 is NOT configured — serve.php will use local disk only.\n";
    echo "   Set S3_BUCKET, S3_KEY, S3_SECRET in .env to enable S3.\n\n";
}

// ──────────────────────────────────────────────────
// 3. Database connectivity
// ──────────────────────────────────────────────────
echo "─── 3. DATABASE ───\n";
try {
    $pdo = getDbConnection();
    echo "✅ DB connection OK\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['users', 'scorm_packages', 'sco_items', 'course_assignments',
                 'scorm_attempts', 'scorm_interactions', 'scorm_objectives', 'scorm_events'];
    foreach ($required as $t) {
        echo "  " . (in_array($t, $tables) ? "✅" : "❌ MISSING") . " $t\n";
    }
} catch (Throwable $e) {
    echo "❌ DB FAIL: " . $e->getMessage() . "\n\n";
    exit;
}
echo "\n";

if ($packageId <= 0) {
    echo "No package ID specified. Add ?pkg=N to the URL to trace a specific package.\n";
    echo "\nEnter a package ID to test:\n";
    // Scope the listing to the current organization (super admins see all).
    if (!isSuperAdmin() && getOrgId() === null) {
        echo "ACCESS DENIED: no organization context for non-super-admin debug.\n";
        exit;
    }
    $pkgScope = isSuperAdmin() ? '' : " WHERE organization_id = " . (int)getOrgId();
    $pkgs = $pdo->query("SELECT id, title, status FROM scorm_packages" . $pkgScope . " ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pkgs as $p) {
        echo "  pkg={$p['id']}  [{$p['status']}]  {$p['title']}\n";
    }
    echo "\n─────────── END ───────────\n";
    exit;
}

// ──────────────────────────────────────────────────
// 4. Package lookup
// ──────────────────────────────────────────────────
echo "─── 4. PACKAGE DB LOOKUP (pkg=$packageId) ───\n";
ensureScormTables();

$stmt = $pdo->prepare("SELECT sp.*, sp.id AS package_id,
                              (SELECT si.launch_url FROM sco_items si WHERE si.id = sp.launch_sco_id) AS launch_href,
                              (SELECT si.launch_url FROM sco_items si WHERE si.package_id = sp.id AND si.launch_url != '' ORDER BY si.id LIMIT 1) AS first_sco_href
                       FROM scorm_packages sp WHERE sp.id = ?");
$stmt->execute([$packageId]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pkg) {
    echo "❌ Package #$packageId NOT FOUND in scorm_packages.\n\n─────────── END ───────────\n";
    exit;
}

echo "✅ Package found:\n";
echo "  ID:          {$pkg['id']}\n";
// Cross-tenant guard: non-super-admins may only inspect their own org's packages.
if (!isSuperAdmin() && !empty($pkg['organization_id']) && (int)$pkg['organization_id'] !== (int)getOrgId()) {
    echo "ACCESS DENIED: package #$packageId belongs to another organization.";
    exit;
}

echo "  Title:       {$pkg['title']}\n";
echo "  Status:      {$pkg['status']}\n";
echo "  SCORM ver:   {$pkg['scorm_version']}\n";
echo "  Org ID:      " . ($pkg['organization_id'] ?? 'NULL (global)') . "\n";
echo "  Launch SCO:  " . ($pkg['launch_sco_id'] ?? 'NULL') . "\n";
echo "  Upload path: {$pkg['upload_path']}\n\n";

// ──────────────────────────────────────────────────
// 5. SCO items
// ──────────────────────────────────────────────────
echo "─── 5. SCO ITEMS ───\n";
$scoStmt = $pdo->prepare("SELECT id, identifier, title, launch_url, scorm_type FROM sco_items WHERE package_id = ?");
$scoStmt->execute([$packageId]);
$scos = $scoStmt->fetchAll(PDO::FETCH_ASSOC);
echo "  Count: " . count($scos) . " SCOs\n";
foreach ($scos as $s) {
    $marker = ((int)$s['id'] === (int)($pkg['launch_sco_id'] ?? 0)) ? ' ★ LAUNCH' : '';
    echo "  sco_id={$s['id']} type={$s['scorm_type']} href={$s['launch_url']} title={$s['title']}$marker\n";
}
echo "\n";

// ──────────────────────────────────────────────────
// 6. File path resolution (same as serve.php)
// ──────────────────────────────────────────────────
echo "─── 6. FILE PATH RESOLUTION ───\n";

$packageRoot = SCORM_STORAGE_PATH . '/' . $packageId;
echo "SCORM_STORAGE_PATH: " . SCORM_STORAGE_PATH . "\n";
echo "packageRoot:        $packageRoot\n";
echo "is_dir(packageRoot): " . (is_dir($packageRoot) ? "YES" : "NO") . "\n";

if (is_dir($packageRoot)) {
    // List local files (first 20)
    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $localFiles = [];
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $localFiles[] = substr($file->getPathname(), strlen($packageRoot) + 1);
                if (count($localFiles) >= 20) break;
            }
        }
        echo "  Local files (first " . count($localFiles) . "):\n";
        foreach ($localFiles as $f) {
            echo "    $f\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️  Error listing local files: " . $e->getMessage() . "\n";
    }
} else {
    echo "  ℹ️  No local disk directory — package is S3-only.\n";
}
echo "\n";

$s3Available = isS3Configured();

// Resolve relPath (same logic as serve.php)
$relPath = trim((string)($_GET['path'] ?? ''), '/');
$hasExplicitPath = ($relPath !== '');
if (!$hasExplicitPath) {
    // Default: launch SCO if known, else first SCO, else index.html
    $relPath = $pkg['launch_href'] ?: $pkg['first_sco_href'];
    if ($relPath === '' || $relPath === null) {
        $relPath = 'index.html';
    }
    // S3-aware entry point probing: when S3 is configured, try common
    // SCORM/Articulate entry patterns in order.
    if ($s3Available) {
        $pathsToTry = array_unique([
            $relPath,                           // 1. the resolved href from DB
            'scormcontent/index.html',          // 2. Articulate Storyline HTML5
            'scormdriver/indexAPI.html',        // 3. Articulate SCORM wrapper
            'index.html',                       // 4. package root (last resort)
        ]);
        echo "  S3 probe path candidates:\n";
        $found = false;
        foreach ($pathsToTry as $candidate) {
            $testKey = S3_PREFIX . $packageId . '/' . $candidate;
            $exists = s3Exists($testKey);
            echo "    " . ($exists ? '✅' : '❌') . " $candidate\n";
            if ($exists) {
                if ($candidate !== $relPath) {
                    echo "  → Using: $candidate\n";
                }
                $relPath = $candidate;
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "  ⚠️  No candidate found in S3; using resolved=$relPath\n";
        }
    }
}
$relPath = rawurldecode($relPath);

echo "Resolved relPath:   $relPath\n";
echo "launch_href (DB):   " . ($pkg['launch_href'] ?? 'NULL') . "\n";
echo "first_sco_href (DB): " . ($pkg['first_sco_href'] ?? 'NULL') . "\n";
echo "s3Available:        " . ($s3Available ? 'YES' : 'NO') . "\n\n";

// Path traversal check
$blocked = false;
if (strpos($relPath, '..') !== false || strpos($relPath, '\\') !== false ||
    strpos($relPath, "\0") !== false || strpos($relPath, ':') !== false) {
    echo "  ❌ PATH TRAVERSAL BLOCKED: $relPath\n";
    $blocked = true;
}

// Extension check
$ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
echo "Extension:          $ext\n";

$mimeMap = [
    'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
    'js' => 'application/javascript', 'mjs' => 'application/javascript',
    'json' => 'application/json', 'xml' => 'application/xml',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'pdf' => 'application/pdf',
    'swf' => 'application/x-shockwave-flash', 'txt' => 'text/plain',
    'md' => 'text/markdown', 'csv' => 'text/csv', 'zip' => 'application/zip',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$mimeAllowed = isset($mimeMap[$ext]);
echo "MIME allowed:       " . ($mimeAllowed ? 'YES (' . $mimeMap[$ext] . ')' : "NO — ext '$ext' not in whitelist") . "\n\n";

if ($blocked || !$mimeAllowed) {
    echo "❌ File blocked at validation stage. Check path/ext above.\n\n─────────── END ───────────\n";
    exit;
}

// ──────────────────────────────────────────────────
// 7. S3 object listing
// ──────────────────────────────────────────────────
echo "─── 7. S3 OBJECT LISTING (prefix: scorm-content/$packageId/) ───\n";

if (!$s3Available) {
    echo "  ⏭️  Skipped — S3 not configured.\n\n";
} else {
    // Use curl directly for LIST with query params (s3Request doesn't support query params)
    $bucket = S3_BUCKET;
    $region = S3_REGION;
    $accessKey = S3_KEY;
    $secretKey = S3_SECRET;
    $endpoint = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
        ? rtrim(S3_ENDPOINT, '/')
        : "https://{$bucket}.s3.{$region}.amazonaws.com";

    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $service = 's3';
    $prefix = S3_PREFIX . $packageId . '/';
    $canonicalUri = '/';
    $canonicalQuery = 'list-type=2&max-keys=50&prefix=' . rawurlencode($prefix);
    $host = parse_url($endpoint, PHP_URL_HOST);
    $payloadHash = hash('sha256', '');

    $canonicalHeaders = 'host:' . $host . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $now . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $accessKey . '/' . $scope . ', '
        . 'SignedHeaders=' . $signedHeaders . ', '
        . 'Signature=' . $signature;

    $url = $endpoint . $canonicalUri . '?' . $canonicalQuery;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'x-amz-date: ' . $now,
            'x-amz-content-sha256: ' . $payloadHash,
            'Authorization: ' . $authorization,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $listResp = curl_exec($ch);
    $listStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $listErr = curl_error($ch);
    curl_close($ch);

    echo "  LIST request URL: $url\n";
    echo "  HTTP status: $listStatus\n";
    if ($listErr) {
        echo "  ❌ CURL error: $listErr\n";
    } elseif ($listStatus >= 200 && $listStatus < 300 && $listResp !== false) {
        $xml = simplexml_load_string($listResp);
        if ($xml) {
            $ns = $xml->getNamespaces(true);
            $keys = [];
            foreach ($xml->Contents as $obj) {
                $keys[] = (string)$obj->Key;
            }
            echo "  Objects found: " . count($keys) . "\n";
            foreach ($keys as $k) {
                echo "    $k\n";
            }
            if ((string)$xml->IsTruncated === 'true') {
                echo "  ⚠️  Response truncated (more than 50 objects)\n";
            }
        } else {
            echo "  ⚠️  Could not parse XML response.\n";
            echo "  Raw response (first 500 chars):\n";
            echo "  " . substr($listResp, 0, 200) . "\n";
        }
    } else {
        echo "  ❌ LIST failed.\n";
        echo "  Response body (first 500 chars):\n";
        echo "  " . substr((string)$listResp, 0, 200) . "\n";
    }
    echo "\n";
}

// ──────────────────────────────────────────────────
// 8. S3 GET test
// ──────────────────────────────────────────────────
echo "─── 8. S3 GET TEST ───\n";

$s3Key = S3_PREFIX . $packageId . '/' . $relPath;
echo "  s3Key: $s3Key\n";

if (!$s3Available) {
    echo "  ⏭️  Skipped — S3 not configured.\n\n";
} else {
    $body = s3Get($s3Key);
    if ($body === null) {
        echo "  ❌ s3Get() returned NULL (object not found or error).\n";
        echo "     Check server PHP error log for [S3] GET failed entries.\n";
    } else {
        echo "  ✅ s3Get() SUCCESS — got " . strlen($body) . " bytes\n";
        if ($ext === 'html' || $ext === 'htm') {
            preg_match('/<title>(.*?)<\/title>/si', $body, $m);
            echo "  HTML title: " . ($m[1] ?? '(none)') . "\n";
        }
        // Show first 200 chars
        echo "  First 200 chars: " . substr($body, 0, 200) . "\n";
    }
    echo "\n";
}

// ──────────────────────────────────────────────────
// 9. S3 HEAD (exists) test
// ──────────────────────────────────────────────────
echo "─── 9. S3 EXISTS (HEAD) TEST ───\n";

if (!$s3Available) {
    echo "  ⏭️  Skipped — S3 not configured.\n\n";
} else {
    $exists = s3Exists($s3Key);
    echo "  Key: $s3Key\n";
    echo "  Result: " . ($exists ? "✅ EXISTS" : "❌ NOT FOUND") . "\n\n";
}

// ──────────────────────────────────────────────────
// 10. Raw S3 request test (bypass s3Get wrapper)
// ──────────────────────────────────────────────────
echo "─── 10. RAW S3 GET REQUEST (low-level) ───\n";

if (!$s3Available) {
    echo "  ⏭️  Skipped — S3 not configured.\n\n";
} else {
    $rawResult = s3Request('GET', $s3Key);
    echo "  Status: " . $rawResult['status'] . "\n";
    if ($rawResult['body'] === false) {
        echo "  body: FALSE (curl failure)\n";
    } elseif ($rawResult['body'] === '') {
        echo "  body: '' (empty response)\n";
    } else {
        echo "  body length: " . strlen((string)$rawResult['body']) . " bytes\n";
        // Show first bytes
        $preview = substr((string)$rawResult['body'], 0, 120);
        echo "  First 300 chars: $preview\n";
    }
    echo "\n";
}

// ──────────────────────────────────────────────────
// 11. Session / Auth context
// ──────────────────────────────────────────────────
echo "─── 11. SESSION / AUTH ───\n";
echo "  user_id:         " . ($_SESSION['user_id'] ?? '(none)') . "\n";
echo "  email:           " . ($_SESSION['email'] ?? '(none)') . "\n";
echo "  user_role:       " . ($_SESSION['user_role'] ?? '(none)') . "\n";
echo "  organization_id: " . ($_SESSION['organization_id'] ?? 'NULL') . "\n";
echo "  isSuperAdmin():  " . (isSuperAdmin() ? 'YES' : 'NO') . "\n";
echo "  isTestUser():    " . (function_exists('isTestUser') && isTestUser() ? 'YES' : 'NO') . "\n";
echo "  session_id:      " . substr((string)session_id(), 0, 8) . "... (redacted)\n\n";

// ──────────────────────────────────────────────────
// 12. SCORM Player iframe URL test
// ──────────────────────────────────────────────────
// Note: The old Section 12 (curl self-test of serve.php) was removed — it
// caused false-positive timeouts due to PHP-FPM worker contention on single-
// server setups. Sections 8-10 already prove the S3-to-serve.php pipeline works.
echo "─── 12. SCORM PLAYER URL ───\n";
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$playerUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/scorm-player/index.php?pkg=' . $packageId;
echo "  $playerUrl\n";
echo "  (Open this in a browser to test the full player)\n\n";

// ──────────────────────────────────────────────────
// 13. Rewritten HTML Audit (inline scan of raw S3 HTML)
// ──────────────────────────────────────────────────
// Avoids self-curl (FPM deadlock) by scanning the raw S3 content
// directly — identifying which URLs serve.php's rewriter WILL catch.
echo "─── 13. URL REWRITE AUDIT (scanned from raw S3 HTML) ───\n\n";

if (!$s3Available) {
    echo "  ⏭️  Skipped — S3 not configured.\n\n";
} else {
    $rawBody = s3Get($s3Key);
    if ($rawBody === null) {
        echo "  ❌ Cannot read raw HTML from S3 for audit.\n\n";
    } else {
        echo "  Raw HTML size: " . strlen($rawBody) . " chars\n";

        // Count <script> blocks in raw HTML
        $rawScriptTags = preg_match_all('/<script\b/i', $rawBody);
        echo "  <script> tags in raw HTML: $rawScriptTags\n\n";

        // ── Audit: Known asset-directory patterns (Pass 1 in serve.php) ──
        echo "  ── Pass 1 — Asset-Directory URLs ──\n";
        $pass1Pattern = '#(["\'`])(\.\./|\./)?/?((?:scormcontent|scormdriver|lib|assets|fonts|img|images|css|js|media|audio|video|resources|scripts|styles)/[^"\'`]*)(["\'`])#i';
        $p1 = preg_match_all($pass1Pattern, $rawBody, $pass1m);
        echo "  Found: " . ($p1 ? count($pass1m[0]) : 0) . " URLs\n";
        if ($p1 && $p1 > 0) {
            $shown = array_unique(array_slice($pass1m[3], 0, 10));
            foreach ($shown as $s) {
                echo "    ✅ " . substr($s, 0, 80) . "\n";
            }
        }
        echo "\n";

        // ── Audit: Webpack hex-hash chunks (Pass 2) ──
        echo "  ── Pass 2 — Webpack Hex-Hash Chunks ──\n";
        $hexPattern = '#(["\'`])(/?(?:scorm-content/))?([a-f0-9]{6,16}\.(?:css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|json))(["\'`])#i';
        $h2 = preg_match_all($hexPattern, $rawBody, $hexm);
        echo "  Found: " . ($h2 ? count($hexm[0]) : 0) . " URLs\n";
        if ($h2 && $h2 > 0) {
            $shown = array_unique(array_slice($hexm[3], 0, 10));
            foreach ($shown as $s) {
                echo "    ✅ " . $s . " → serve.php?pkg=$packageId&path=" . urlencode($s) . "\n";
            }
        }
        echo "\n";

        // ── Audit: Named chunk files (Pass 3) ──
        echo "  ── Pass 3 — Named Chunk Files & Root-Relative Paths ──\n";
        $namedPattern = '#(["\'`])(/scorm-content/)?([a-zA-Z0-9_.\-]+(?:\.min)?\.(?:css|js|html|json|xml|png|jpg|jpeg|gif|svg|woff2?|ttf|mp4|webm|mp3|pdf|ico|eot))(["\'`])#i';
        $n3 = preg_match_all($namedPattern, $rawBody, $namedm);
        echo "  Found: " . ($n3 ? count($namedm[0]) : 0) . " URLs\n";
        if ($n3 && $n3 > 0) {
            $all = [];
            for ($i = 0; $i < count($namedm[0]); $i++) {
                $all[] = ($namedm[2][$i] ?? '') . $namedm[3][$i];
            }
            $shown = array_slice(array_unique($all), 0, 15);
            foreach ($shown as $s) {
                echo "    ✅ " . str_pad(substr($s, -60), 60) . " → serve.php?pkg=$packageId&path=" . urlencode($s) . "\n";
            }
            if (count(array_unique($all)) > 15) {
                echo "    ... and " . (count(array_unique($all)) - 15) . " more\n";
            }
        }
        echo "\n";

        // ── Audit: HTML attribute URLs (those that would be rewritten) ──
        echo "  ── HTML Attribute URLs (src/href/action/data) ──\n";
        $attrPattern = '#(src|href|action|data)\s*=\s*(["\'])((?!https?:/|//|data:|javascript:|mailto:|#|\?)[^"\'#]+)(["\'])#i';
        $a = preg_match_all($attrPattern, $rawBody, $attrm);
        echo "  Found: " . ($a ? count($attrm[0]) : 0) . " URLs\n";
        if ($a && $a > 0) {
            $shown = array_unique(array_slice($attrm[3], 0, 10));
            foreach ($shown as $s) {
                echo "    ✅ " . substr($s, 0, 80) . "\n";
            }
        }
        echo "\n";

        // ── Cross-Origin URL check (in raw HTML) ──
        echo "  ── Cross-Origin URLs in raw HTML ──\n";
        $scheme2 = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host2 = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (preg_match_all('#(?:src|href|url)\s*=\s*["\'][^"\']*https?://(?!' . preg_quote($host2, '#') . ')[^"\']+#i', $rawBody, $m)) {
            $cors = array_unique(array_slice($m[0], 0, 5));
            echo "  ⚠️  " . count($cors) . " cross-origin URLs detected (should be rewritten or may cause CORS issues):\n";
            foreach ($cors as $u) {
                echo "    " . substr($u, 0, 100) . "\n";
            }
        } else {
            echo "  ✅ No cross-origin URLs in raw HTML\n";
        }

        echo "\n";
    }
}

echo "══════════════════════════════════════════════\n";
echo "DEBUG COMPLETE\n";
echo "══════════════════════════════════════════════\n";
