<?php
/**
 * COURSE VIEWER — Proxied SCORM content via course-runner.
 * All content is served under your domain. The course-runner
 * fetches and rewrites links.
 */
require_once __DIR__ . '/../bootstrap.php';

if (!isset($_SESSION['course_url'])) {
    redirectTo('login/');
}

$courseUrl = $_SESSION['course_url'];
$dashboard_url = buildUrl('dashboard/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Learning Portal | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        <?php renderBrandStyles(); ?>
        :root { --bg: #0f172a; --text-main: #ffffff; }
        *, *::before, *::after { box-sizing: border-box; }
        body, html { margin: 0; padding: 0; height: 100%; width: 100%; overflow: hidden; background: var(--bg); font-family: 'Plus Jakarta Sans', sans-serif; }

        #courseFrame {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            border: none; background: var(--bg); z-index: 1;
        }

        .exit-btn {
            position: fixed; top: 16px; right: 16px; z-index: 9999;
            background: rgba(15, 23, 42, 0.85); color: #fff;
            padding: 10px 18px; border-radius: 10px; font-size: 12px;
            font-weight: 700; border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(8px); cursor: pointer; text-decoration: none;
        }

        #loader {
            position: fixed; inset: 0; background: var(--bg); z-index: 10000;
            display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 16px;
            transition: opacity 0.5s ease;
        }
        .spinner {
            width: 36px; height: 36px;
            border: 3px solid rgba(255,255,255,0.15);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        #loader p { color: #94a3b8; font-size: 14px; margin: 0; }
    </style>
</head>
<body>

    <div id="loader">
        <div class="spinner"></div>
        <p>Loading Course...</p>
    </div>

    <a href="<?php echo $dashboard_url; ?>" class="exit-btn">âœ• EXIT</a>

    <iframe id="courseFrame" allowfullscreen allow="autoplay; fullscreen"></iframe>

    <script>
        const frame = document.getElementById('courseFrame');
        const loader = document.getElementById('loader');
        const courseUrl = "<?php echo $courseUrl; ?>";
        const dashboardUrl = "<?php echo $dashboard_url; ?>";

        // Load SCORM content directly in the iframe
        frame.src = courseUrl;

        frame.onload = function() {
            if (loader) {
                loader.style.opacity = '0';
                setTimeout(function() { loader.style.display = 'none'; }, 500);
            }
        };
    </script>
</body>
</html>
