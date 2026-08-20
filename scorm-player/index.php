<?php
/**
 * PURSUIT PATHWAYS LMS — SCORM Player
 *
 * Wraps the native SCORM reader (scorm-content/serve.php) in a full-screen
 * player with a top bar, loader, and a user-facing debug overlay that
 * explains exactly why a course fails to load.
 */
require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$packageId = (int)($_GET['pkg'] ?? 0);
$scoId     = (int)($_GET['sco'] ?? 0);

if ($packageId <= 0) {
    http_response_code(400);
    exit('Missing package ID.');
}

// ── Package access check ──
$pdo = getDbConnection();
try {
    if (isSuperAdmin()) {
        $stmt = $pdo->prepare("SELECT * FROM scorm_packages WHERE id = ? AND status = 'active'");
        $stmt->execute([$packageId]);
    } else {
        $orgId = (int)($_SESSION['organization_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT sp.* FROM scorm_packages sp
            WHERE sp.id = ? AND sp.status = 'active'
            AND (
                sp.organization_id = ?
                OR EXISTS (
                    SELECT 1 FROM course_assignments ca
                    WHERE ca.package_id = sp.id
                    AND ca.organization_id = ?
                )
                OR EXISTS (
                    SELECT 1 FROM scorm_attempts a
                    WHERE a.package_id = sp.id
                    AND a.user_id = ?
                )
            )
        ");
        $stmt->execute([$packageId, $orgId, $orgId, $userId]);
    }
    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[SCORM-PLAYER] DB error: ' . $e->getMessage());
    $pkg = null;
}

if (!$pkg) {
    http_response_code(404);
    exit('Course not found or access denied.');
}

// ── Build tokenised content URL ──
// A short-lived HMAC serve token (`t=`) is appended so that all asset sub-requests
// (JS chunks, CSS, HTML pages) inside the iframe are authenticated without relying
// on the session cookie. Browsers do NOT send SameSite=Lax cookies on sub-resource
// requests originating from inside an iframe, so without this token every asset
// request would be redirected to the login page, causing SyntaxError in the console.
$serveToken = generateServeToken((int)$_SESSION['user_id'], $packageId);
$contentUrl = buildUrl('scorm-content/serve.php?pkg=' . $packageId . '&t=' . urlencode($serveToken));
if ($scoId > 0) {
    $contentUrl .= '&sco=' . $scoId;
}

// Path-based route (only works with Apache .htaccess rewrite — kept as fallback)
$contentBase = buildUrl('scorm-content/' . $packageId . '/');
$fallbackUrl = $contentBase;
if ($scoId > 0) {
    $fallbackUrl .= '?sco=' . $scoId;
}
if (!empty($_GET['redirect'])) {
    $contentUrl .= '&redirect=' . urlencode($_GET['redirect']);
}

$currentUser = getCurrentUser();
$coursePageUrl = buildUrl('course-page/');

// Diagnostics
error_log(sprintf(
    '[SCORM-PLAYER] pkg=%d sco=%d contentUrl=%s fallbackUrl=%s user=%s',
    $packageId,
    $scoId,
    $contentUrl,
    $fallbackUrl,
    $currentUser['email'] ?? '?'
));

$serveFileExists = file_exists(__DIR__ . '/../scorm-content/serve.php');
error_log('[SCORM-PLAYER] serve.php exists on disk: ' . ($serveFileExists ? 'YES' : 'NO'));

// ── S3 availability check for debug overlay ──
$s3Configured = isS3Configured();
// Admin post-launch diagnostics panel (RTE telemetry) — opt-in via ?diag=1.
$showDiag = isAdmin() && !empty($_GET['diag']);
$debugInfo = json_encode([
    'pkg'          => $packageId,
    'sco'          => $scoId,
    'contentUrl'   => $contentUrl,
    'fallbackUrl'  => $fallbackUrl,
    's3Configured' => $s3Configured,
    'serveExists'  => $serveFileExists,
    'user'         => $currentUser['email'] ?? '?',
    'token'        => substr($serveToken, 0, 12) . '...',
    'showDiag'     => $showDiag,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pkg['title']); ?> | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; }

        .player-bar {
            height: 52px;
            background: #232D63;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            flex-shrink: 0;
        }
        .player-bar .course-title {
            font-weight: 700;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 55%;
        }
        .player-bar .learner-name {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.7);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .player-bar .actions { display: flex; align-items: center; gap: 10px; }
        .player-bar .btn {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }
        .player-bar .btn:hover { background: rgba(255,255,255,0.22); }
        .player-bar .btn-exit { background: #E4E348; color: #232D63; border-color: transparent; }
        .player-bar .btn-exit:hover { background: #d9d93d; }

        .player-frame-wrap { height: calc(100% - 52px); width: 100%; position: relative; }
        .player-frame-wrap iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        /* ── Loader ── */
        .player-loader {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #D3E2F3;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            transition: opacity 0.4s ease;
        }
        .player-loader.hidden { opacity: 0; pointer-events: none; }
        .spinner {
            width: 42px; height: 42px;
            border: 3px solid #BBBDB7;
            border-top-color: #82ACD6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .player-loader p { color: #232D63; font-size: 0.9rem; text-align: center; }

        /* ── Debug / Error Overlay ── */
        .debug-overlay {
            display: none;
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #fff;
            z-index: 20;
            overflow-y: auto;
            padding: 32px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .debug-overlay.visible { display: block; }
        .debug-overlay h2 {
            color: #C0392B;
            font-size: 1.3rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .debug-overlay h2 .icon { font-size: 1.6rem; }
        .debug-overlay .subtitle {
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .debug-overlay .section {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .debug-overlay .section h3 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #888;
            margin-bottom: 10px;
        }
        .debug-overlay .check-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .debug-overlay .check-row .status {
            font-size: 1.1rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .debug-overlay .check-row .label { color: #333; flex: 1; }
        .debug-overlay .check-row .detail { color: #888; font-size: 0.8rem; font-family: monospace; word-break: break-all; }
        .debug-overlay .error-list {
            background: #fff5f5;
            border: 1px solid #fcc;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .debug-overlay .error-list h3 { color: #C0392B; font-size: 0.85rem; margin-bottom: 8px; }
        .debug-overlay .error-list .err-item {
            font-size: 0.82rem;
            font-family: monospace;
            color: #c0392b;
            margin-bottom: 4px;
            word-break: break-all;
        }
        .debug-overlay .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .debug-overlay .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-block;
        }
        .debug-overlay .btn-retry { background: #232D63; color: #fff; }
        .debug-overlay .btn-retry:hover { background: #1a2150; }
        .debug-overlay .btn-exit { background: #E4E348; color: #232D63; }
        .debug-overlay .btn-exit:hover { background: #d9d93d; }
        .debug-overlay .btn-details { background: #f0f0f0; color: #333; }
        .debug-overlay .btn-details:hover { background: #e0e0e0; }
        .debug-overlay .raw-details {
            display: none;
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 8px;
            padding: 16px;
            font-family: monospace;
            font-size: 0.78rem;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: 16px;
            max-height: 300px;
            overflow-y: auto;
        }
        .debug-overlay .raw-details.visible { display: block; }

        /* ── Admin post-launch diagnostics panel ── */
        .diag-panel {
            display: none;
            position: fixed;
            right: 20px;
            bottom: 20px;
            width: 420px;
            max-width: calc(100vw - 40px);
            max-height: 70vh;
            overflow-y: auto;
            background: #1e1e1e;
            color: #d4d4d4;
            border: 1px solid #444;
            border-radius: 12px;
            padding: 18px 20px;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            z-index: 9999;
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }
        .diag-panel.visible { display: block; }
        .diag-panel h3 {
            margin: 0 0 10px;
            color: #82ACD6;
            font-size: 0.9rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .diag-panel .diag-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 0;
            border-bottom: 1px solid #333;
        }
        .diag-panel .diag-row .k { color: #9ca3af; }
        .diag-panel .diag-row .v { color: #7ee787; word-break: break-all; text-align: right; }
        .diag-panel .diag-err {
            color: #ff7b72;
            padding: 4px 0;
            border-bottom: 1px solid #333;
            word-break: break-all;
        }
        .diag-panel .diag-toggle {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 10000;
            padding: 8px 14px;
            border-radius: 8px;
            border: none;
            background: #232D63;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            display: none;
        }
        .diag-panel.visible + .diag-toggle { display: none; }

        @media (max-width: 768px) {
            .player-bar .learner-name { display: none; }
            .player-bar .course-title { max-width: 65%; }
            .debug-overlay { padding: 16px; }
        }
    </style>
</head>
<body>

    <div class="player-bar">
        <div class="course-title"><?php echo htmlspecialchars($pkg['title']); ?></div>
        <div class="learner-name"><?php echo htmlspecialchars($currentUser['first_name'] ?? 'Learner'); ?></div>
        <div class="actions">
            <a href="<?php echo $coursePageUrl; ?>" class="btn btn-exit">Exit Course</a>
        </div>
    </div>

    <div class="player-frame-wrap">

        <div class="player-loader" id="playerLoader">
            <div>
                <div class="spinner"></div>
                <p>Loading course…</p>
            </div>
        </div>

        <!-- ── Debug / Error Overlay ── -->
        <div class="debug-overlay" id="debugOverlay">
            <h2><span class="icon">⚠️</span> Course failed to load</h2>
            <p class="subtitle">The course could not be started. The details below will help your administrator fix the issue.</p>

            <div class="error-list" id="errorList" style="display:none">
                <h3>Errors detected</h3>
                <div id="errorItems"></div>
            </div>

            <div class="section">
                <h3>Diagnostics</h3>
                <div id="checkRows"></div>
            </div>

            <div class="actions">
                <button class="btn btn-retry" onclick="retryLoad()">Try Again</button>
                <a href="<?php echo $coursePageUrl; ?>" class="btn btn-exit">Back to Courses</a>
                <button class="btn btn-details" onclick="toggleDetails()">Show Technical Details</button>
            </div>

            <div class="raw-details" id="rawDetails"></div>
        </div>

        <iframe id="scormFrame"
                src="<?php echo htmlspecialchars($contentUrl); ?>"
                allow="autoplay; fullscreen; microphone; camera; midi; encrypted-media; display-capture; clipboard-read; clipboard-write"
                allowfullscreen
                referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>

    <?php if ($showDiag): ?>
    <!-- ── Admin post-launch diagnostics panel (reads window.__SCORM_RTE__) ── -->
    <button class="diag-toggle" id="diagToggle" onclick="toggleDiag()">SCORM Diagnostics</button>
    <div class="diag-panel" id="diagPanel">
        <h3>SCORM RTE Telemetry</h3>
        <div id="diagBody"><div class="diag-row"><span class="k">waiting for runtime…</span></div></div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        'use strict';

        var frame     = document.getElementById('scormFrame');
        var loader    = document.getElementById('playerLoader');
        var overlay   = document.getElementById('debugOverlay');
        var checkRows = document.getElementById('checkRows');
        var errorItems = document.getElementById('errorItems');
        var errorList  = document.getElementById('errorList');
        var rawDetails = document.getElementById('rawDetails');

        var serverInfo  = <?php echo $debugInfo; ?>;
        var primaryUrl  = serverInfo.contentUrl;
        var fallbackUrl = serverInfo.fallbackUrl;

        var loadSucceeded  = false;
        var fallbackUsed   = false;
        var consoleErrors  = [];
        var resourceErrors = [];
        var loaderHideTimer = null;

        // ── Intercept console errors ──
        var origError = console.error.bind(console);
        console.error = function () {
            var msg = Array.prototype.slice.call(arguments).join(' ');
            consoleErrors.push(msg);
            origError.apply(console, arguments);
        };

        // ── Intercept resource load errors from inside the iframe ──
        window.addEventListener('message', function (e) {
            if (e.data && e.data.type === 'scorm-resource-error') {
                resourceErrors.push(e.data.url + ' → ' + e.data.status);
            }
        });

        function hideLoader() {
            if (loader) {
                loader.classList.add('hidden');
                setTimeout(function () {
                    if (loader.parentNode) loader.parentNode.removeChild(loader);
                }, 500);
            }
        }

        function showDebugOverlay(reason, checks) {
            hideLoader();
            // Populate error list
            var allErrors = consoleErrors.concat(resourceErrors);
            if (reason) allErrors.unshift(reason);
            if (allErrors.length > 0) {
                errorList.style.display = '';
                errorItems.innerHTML = allErrors.slice(0, 12).map(function (e) {
                    return '<div class="err-item">' + escHtml(e) + '</div>';
                }).join('');
            }
            // Populate check rows
            checkRows.innerHTML = checks.map(function (c) {
                return '<div class="check-row">' +
                    '<span class="status">' + (c.ok ? '✅' : '❌') + '</span>' +
                    '<div><div class="label">' + escHtml(c.label) + '</div>' +
                    (c.detail ? '<div class="detail">' + escHtml(c.detail) + '</div>' : '') +
                    '</div></div>';
            }).join('');
            // Raw details
            rawDetails.textContent = JSON.stringify({
                serverInfo: serverInfo,
                consoleErrors: consoleErrors,
                resourceErrors: resourceErrors,
                reason: reason,
                timestamp: new Date().toISOString()
            }, null, 2);
            overlay.classList.add('visible');
        }

        function escHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // ── iframe load handler ──
        frame.addEventListener('load', function () {
            var doc, bodyText = '';
            try {
                doc = frame.contentDocument;
                bodyText = (doc && doc.body) ? (doc.body.textContent || '') : '';
            } catch (e) { /* cross-origin — course is running */ }

            // Detect login redirect injected as HTML inside the iframe
            if (bodyText.indexOf('login') !== -1 && bodyText.indexOf('password') !== -1 && bodyText.length < 5000) {
                showDebugOverlay('Session expired — course was redirected to login page.', buildChecks('session'));
                return;
            }

            // Detect plain-text error messages from serve.php
            if (bodyText.indexOf('Unable to read file') !== -1 ||
                bodyText.indexOf('File not found') !== -1 ||
                bodyText.indexOf('File type not allowed') !== -1 ||
                bodyText.indexOf('Forbidden') !== -1) {
                showDebugOverlay('serve.php returned an error: ' + bodyText.trim().substring(0, 120), buildChecks('serve'));
                return;
            }

            loadSucceeded = true;
            hideLoader();
        });

        frame.addEventListener('error', function () {
            if (!fallbackUsed) {
                fallbackUsed = true;
                // Only log to console when explicitly debugging (?debug=1)
                if (new URLSearchParams(window.location.search).has('debug')) {
                    console.warn('[SCORM-PLAYER] iframe error, trying fallback:', fallbackUrl);
                }
                frame.src = fallbackUrl;
            } else {
                showDebugOverlay('The course iframe failed to load (both primary and fallback URLs failed).', buildChecks('iframe'));
            }
        });

        // ── Timeout: if loader is still showing after 45s, probe and show debug ──
        // Mobile devices load large SCORM packages slower (mobile assets + slower
        // JS parsing), so the cap is set higher than desktop.
        loaderHideTimer = setTimeout(function () {
            if (!loadSucceeded) {
                probeAndShowDebug();
            }
        }, 45000);

        // ── Hard loader hide at 60s no matter what (prevents infinite spinner) ──
        setTimeout(function () {
            if (!loadSucceeded) {
                hideLoader();
            }
        }, 60000);

        // ── Early-detect: if the course's SCORM RTE initializes before the
        // iframe's load event (which waits for every sub-resource), hide the
        // loader immediately so users aren't stuck on a spinner. ──
        var scormInitCheck = setInterval(function () {
            try {
                var sw = frame.contentWindow;
                if (sw && sw.__SCORM_RTE__ && typeof sw.__SCORM_RTE__.initialized === 'function' && sw.__SCORM_RTE__.initialized()) {
                    if (!loadSucceeded) { loadSucceeded = true; hideLoader(); }
                    clearInterval(scormInitCheck);
                }
            } catch (e) { /* cross-origin or not ready yet */ }
        }, 2000);

        function probeAndShowDebug() {
            // Probe the content URL to get the actual HTTP status
            fetch(primaryUrl, { method: 'HEAD', credentials: 'include' })
                .then(function (r) {
                    var checks = buildChecks(r.ok ? 'timeout' : 'http-' + r.status);
                    checks.unshift({ ok: r.ok, label: 'Course entry page', detail: primaryUrl + ' → HTTP ' + r.status });
                    showDebugOverlay(
                        r.ok
                            ? 'Course loaded but did not start within 20 seconds. There may be a JavaScript error inside the course.'
                            : 'Course entry page returned HTTP ' + r.status + '.',
                        checks
                    );
                })
                .catch(function (err) {
                    showDebugOverlay('Network error reaching the course: ' + err.message, buildChecks('network'));
                });
        }

        function buildChecks(reason) {
            return [
                {
                    ok: serverInfo.serveExists,
                    label: 'SCORM reader (serve.php) found on server',
                    detail: serverInfo.serveExists ? 'File present' : 'serve.php missing from disk'
                },
                {
                    ok: serverInfo.s3Configured,
                    label: 'S3 storage configured',
                    detail: serverInfo.s3Configured
                        ? 'S3_BUCKET and S3_KEY are set in .env'
                        : 'S3_BUCKET or S3_KEY not set — falling back to local disk'
                },
                {
                    ok: (reason !== 'session'),
                    label: 'User session active',
                    detail: reason === 'session' ? 'Session expired — please log out and log back in' : 'Session valid (token: ' + serverInfo.token + ')'
                },
                {
                    ok: (reason !== 'http-403' && reason !== 'http-404'),
                    label: 'Package access granted',
                    detail: (reason === 'http-403' || reason === 'http-404')
                        ? 'Access denied or package not found (pkg=' + serverInfo.pkg + ')'
                        : 'Package #' + serverInfo.pkg + ' accessible'
                },
                {
                    ok: (reason !== 'http-500' && reason !== 'serve'),
                    label: 'Course files readable from S3',
                    detail: (reason === 'http-500' || reason === 'serve')
                        ? 'serve.php returned a 500 error — check S3 credentials and bucket name'
                        : 'S3 reads successful'
                },
                {
                    ok: (reason !== 'network'),
                    label: 'Network connectivity to server',
                    detail: reason === 'network' ? 'Could not reach ' + window.location.hostname : 'Connected'
                }
            ];
        }

        // ── Public API for retry ──
        window.retryLoad = function () {
            overlay.classList.remove('visible');
            loadSucceeded = false;
            consoleErrors = [];
            resourceErrors = [];
            frame.src = primaryUrl + '&nocache=1&_r=' + Date.now();
            loader.classList.remove('hidden');
            loaderHideTimer = setTimeout(function () {
                if (!loadSucceeded) probeAndShowDebug();
            }, 45000);
        };

        window.toggleDetails = function () {
            rawDetails.classList.toggle('visible');
        };

        <?php if ($showDiag): ?>
        // ── Admin diagnostics panel: poll the RTE inside the iframe ──
        // Same-origin (serve.php), so contentWindow.__SCORM_RTE__ is readable
        // directly. Shows API used, runtime version, commits, statuses, scores,
        // interaction/objective/comment counts, suspend-data length, and errors.
        var diagPanel = document.getElementById('diagPanel');
        var diagBody = document.getElementById('diagBody');
        var diagToggle = document.getElementById('diagToggle');

        window.toggleDiag = function () {
            diagPanel.classList.toggle('visible');
        };

        function escDiag(v) {
            return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        function refreshDiag() {
            var rte = null;
            try {
                if (frame.contentWindow && frame.contentWindow.__SCORM_RTE__) {
                    rte = frame.contentWindow.__SCORM_RTE__;
                }
            } catch (e) { /* cross-origin or not ready */ }

            if (!rte) {
                diagBody.innerHTML = '<div class="diag-row"><span class="k">runtime not initialised yet…</span></div>';
                return;
            }

            var state = rte.getState() || {};
            var errs = (rte.getErrors() || []).slice(-10);
            var rows = [
                ['Standard (API)', escDiag(rte.version === '2004' ? 'SCORM 2004 (API_1484_11)' : 'SCORM 1.2 (API)')],
                ['Edition', escDiag(rte.edition || 'unknown')],
                ['RTE version', escDiag(rte.rteVersion)],
                ['Suspend limit', escDiag(rte.suspendLimit)],
                ['Initialized', escDiag(rte.initialized ? rte.initialized() : '?')],
                ['Attempt ID', escDiag(rte.getAttemptId ? rte.getAttemptId() : '')],
                ['Commits', escDiag(rte.getCommitCount ? rte.getCommitCount() : '')],
                ['lesson_status', escDiag(state['cmi.core.lesson_status'] || '')],
                ['completion_status', escDiag(state['cmi.completion_status'] || '')],
                ['success_status', escDiag(state['cmi.success_status'] || '')],
                ['score.raw', escDiag(state['cmi.core.score.raw'] || state['cmi.score.raw'] || '')],
                ['score.scaled', escDiag(state['cmi.score.scaled'] || '')],
                ['progress', escDiag(state['cmi.progress_measure'] || '')],
                ['location', escDiag(state['cmi.core.lesson_location'] || state['cmi.location'] || '')],
                ['Interactions', escDiag(rte.getInteractionCount ? rte.getInteractionCount() : '')],
                ['Objectives', escDiag(rte.getObjectiveCount ? rte.getObjectiveCount() : '')],
                ['Comments', escDiag(rte.getCommentCount ? rte.getCommentCount() : '')],
                ['suspend_data length', escDiag(rte.getSuspendDataLength ? rte.getSuspendDataLength() : '')],
                ['Last error', escDiag(rte.getLastError ? rte.getLastError() : '')]
            ];
            var html = rows.map(function (r) {
                return '<div class="diag-row"><span class="k">' + r[0] + '</span><span class="v">' + r[1] + '</span></div>';
            }).join('');
            if (errs.length > 0) {
                html += '<h3 style="margin-top:12px">RTE Errors</h3>';
                html += errs.map(function (e) {
                    return '<div class="diag-err">' + escDiag(e.type + ' ' + (e.element || '') + ': ' + e.message) + '</div>';
                }).join('');
            }
            diagBody.innerHTML = html;
        }

        setInterval(refreshDiag, 2000);
        refreshDiag();
        <?php endif; ?>

    })();
    </script>
</body>
</html>
