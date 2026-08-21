<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * Multi-Course Library View - Mobile Responsive
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$currentUser = getCurrentUser();
$firstName = $currentUser['first_name'];
$userEmail = $currentUser['email'];
$userRole  = $currentUser['role'];

$userCourses = [];
if (!empty($userEmail)) {
    $courses = fetchScormRegistrations(['learnerId' => $userEmail]);

    if (!empty($courses)) {
        foreach ($courses as $reg) {
            $progressPct = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
            $resumeAvail = !empty($reg['resumeAvailable']);
            $userCourses[] = [
                'title'           => $reg['course']['title'] ?? 'Untitled Course',
                'regId'           => $reg['id'],
                'courseId'        => $reg['course']['id'] ?? '',
                'progress'        => $progressPct,
                'resumeAvailable' => $resumeAvail,
                'progressLabel'   => $progressPct > 0
                    ? $progressPct . '%'
                    : ($resumeAvail ? 'In Progress' : '0%'),
            ];
        }
    }
}

// Embedded course viewer mode
$launchMode = isset($_GET['launch']) && $_GET['launch'] == 1;
$courseUrl = $_SESSION['course_url'] ?? '';
$dashboard_url = buildUrl('dashboard/');
if ($launchMode && empty($courseUrl)) {
    redirectTo('course-page/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/main.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>

        * { box-sizing: border-box; }
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }

        /* Main Content */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; transition: 0.3s; }
        .content-container { max-width: 900px; margin: 0 auto; }
        .page-header { margin-bottom: 40px; }
        .page-header h1 { font-size: 2.2rem; font-weight: 800; margin: 0; color: var(--text-main); }

        /* Course Card Grid */
        .course-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
        .course-card { background: #ffffff; border: 1px solid var(--border); border-radius: 20px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .course-thumbnail { width: 100%; aspect-ratio: 16 / 7; object-fit: cover; display: block; background: var(--border); }
        .course-content { padding: 32px; }
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 16px; background: rgba(217, 119, 36, 0.15); color: #92400e; }
        .course-title { font-size: 1.6rem; font-weight: 700; margin-bottom: 20px; color: var(--text-main); }
        
        .progress-container { margin-bottom: 30px; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 600; margin-bottom: 8px; color: var(--text-muted); }
        .progress-bar-bg { height: 10px; background: var(--border); border-radius: 10px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: var(--primary); border-radius: 10px; transition: width 0.8s ease; }

        .btn-action { display: block; width: 100%; padding: 16px; background: var(--primary); color: white; text-align: center; text-decoration: none; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; }
        .btn-action:hover { background: var(--primary-hover); }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <?php if ($launchMode): ?>
    <style>
        @keyframes scorm-spin {
            to { transform: rotate(360deg); }
        }
        .scorm-loader-overlay {
            position: fixed; inset: 0; z-index: 999;
            display: flex; align-items: center; justify-content: center;
            background: #F4F9F7; transition: opacity 0.5s ease;
        }
        .scorm-loader-spinner {
            width: 40px; height: 40px;
            border: 3px solid #BBBDB7; border-top-color: #006F53;
            border-radius: 50%; animation: scorm-spin 0.8s linear infinite;
        }
        .scorm-loader-text { margin-top: 12px; color: #1A2E2A; font-size: 14px; }
        #scorm-frame {
            position: fixed; top: 0; left: var(--sidebar-width, 280px);
            width: calc(100% - var(--sidebar-width, 280px)); height: 100%;
            border: none; z-index: 1;
        }
        @media (max-width:1024px) {
            #scorm-frame { left: 0; width: 100%; }
        }
    </style>

    <div id="scorm-loader" class="scorm-loader-overlay">
        <div style="text-align:center;">
            <div class="scorm-loader-spinner"></div>
            <p class="scorm-loader-text">Loading course...</p>
        </div>
    </div>

    <iframe id="scorm-frame"
            src="<?php echo htmlspecialchars($courseUrl); ?>"
            allow="autoplay; fullscreen; microphone; camera; midi; encrypted-media; display-capture; clipboard-read; clipboard-write"
            loading="eager"
            referrerpolicy="no-referrer-when-downgrade">
    </iframe>

    <script>
        (function() {
            var frame = document.getElementById('scorm-frame');
            var loader = document.getElementById('scorm-loader');
            var dashUrl = "<?php echo $dashboard_url; ?>";

            frame.onload = function() {
                if (loader) {
                    loader.style.opacity = '0';
                    setTimeout(function() { loader.remove(); }, 500);
                }
            };

            // Listen for Moodle SCORM player postMessage exit events
            // Moodle sends a 'scorm:exit' event when the learner exits the SCORM player
            window.addEventListener('message', function(e) {
                try {
                    var data = JSON.parse(typeof e.data === 'string' ? e.data : '{}');
                    if (data.type === 'dispatchEvent' && data.event === 'scorm:exit') {
                        window.location.href = dashUrl;
                    }
                } catch(err) {}
            });

            // Fallback: poll for iframe navigation to dashboard
            var pollInterval = setInterval(function() {
                try {
                    if (frame && frame.contentWindow && frame.contentWindow.location) {
                        var iframeUrl = frame.contentWindow.location.href;
                        if (iframeUrl && iframeUrl.indexOf('/dashboard') > -1) {
                            clearInterval(pollInterval);
                            window.location.href = dashUrl;
                        }
                    }
                } catch(e) {
                    // Cross-origin read blocked — expected, continue
                }
            }, 3000);
        })();
    </script>
    <?php else: ?>
    <main>
        <div class="content-container">
            <div class="page-header">
                <h1>Your Courses</h1>
                <p>Welcome back, <?php echo $firstName; ?>. Pick a course to continue learning.</p>
            </div>

            <div class="course-grid">
                <?php if (empty($userCourses)): ?>
                    <p>You are not currently enrolled in any courses.</p>
                <?php else: ?>
                    <?php foreach ($userCourses as $course): 
                        $thumbnail = courseThumbnailUrl(getCourseThumbnailFile($course['courseId'] ?? '', $course['title'] ?? ''));
                    ?>
                        <div class="course-card" data-tour="tour-course-card">
                            <img src="<?php echo $thumbnail; ?>" alt="Course Thumbnail" class="course-thumbnail">
                            <div class="course-content">
                                <span class="status-badge">In Progress</span>
                                <h2 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h2>
                                
                                <div class="progress-container">
                                    <div class="progress-label">
                                        <span>Progress</span>
                                        <span><?php echo htmlspecialchars($course['progressLabel'] ?? '0%'); ?></span>
                                    </div>
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?php echo $course['progress']; ?>%;"></div>
                                    </div>
                                </div>

                                <!-- POST to login/index.php explicitly: buildUrl('login/') strips the
                                     trailing slash producing /login, which nginx 301-redirects to
                                     /login/. Browsers convert POST->GET on a 301, dropping the
                                     auto_launch POST body and landing the user on the login form. -->
                                <form action="<?php echo buildUrl('login/index.php'); ?>" method="POST">
                                    <?php echo csrfHiddenField(); ?>
                                    <input type="hidden" name="auto_launch" value="1">
                                    <input type="hidden" name="registration_id" value="<?php echo htmlspecialchars($course['regId']); ?>">
                                    <button type="submit" class="btn-action">
                                        <?php echo ($course['resumeAvailable'] ?? false) ? 'Resume Learning' : 'Start Course'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php endif; ?>

    <script>
        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('active');
        }
    </script>
</body>
</html>
