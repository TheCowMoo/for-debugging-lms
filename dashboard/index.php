<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * FULL INTEGRATED DASHBOARD (index.php)
 * Updated to match the Uniformed Analytics sidebar and mobile responsiveness.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$currentUser = getCurrentUser();
$firstName = $currentUser['first_name'];
$userEmail = $currentUser['email'];
$userRole = $currentUser['role'];

$localUserCount = 0;
$localUserTotalCount = 0;
$localRegistrationIds = [];
try {
    $pdo = getDbConnection();
    $orgFilter = orgSql();
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE 1=1" . $orgFilter);
    $localUserTotalCount = (int) $totalStmt->fetchColumn();

    $regStmt = $pdo->query("SELECT registration_id FROM users WHERE registration_id IS NOT NULL AND registration_id <> ''" . $orgFilter);
    while ($row = $regStmt->fetch(PDO::FETCH_ASSOC)) {
        $registrationId = trim($row['registration_id']);
        if ($registrationId !== '') {
            $localRegistrationIds[$registrationId] = true;
        }
    }
    $localUserCount = count($localRegistrationIds);
} catch (PDOException $e) {
    error_log('[DB] Failed to count local registrations: ' . $e->getMessage());
}

// Default values
$completionPercent = 0;
$minutesSpent = 0;
$courseTitle = "No Active Enrollment";
$activeRegId = "";
$courseId = "";
$userRegistrationsCount = 0;
$userCompletedCourses = 0;
$userIncompleteCourses = 0;
$userAverageProgress = 0;
$userTotalMinutes = 0;
$preparednessScore = 0;
$avgTrendPoints = [0, 0, 0, 0, 0, 0, 0];
$certifications = [];
$complianceCourses = [];

if (isTestUser()) {
    $courseTitle = "Compliance Awareness Simulation";
    $completionPercent = 91;
    $minutesSpent = 45;
    $preparednessScore = $completionPercent;
} elseif (!empty($userEmail)) {
    $userRegistrations = fetchScormRegistrations(['learnerId' => $userEmail]);

    if (!empty($userRegistrations)) {
        usort($userRegistrations, function ($a, $b) {
            return strtotime($b['updated'] ?? '') - strtotime($a['updated'] ?? '');
        });

        $userRegistrationsCount = count($userRegistrations);
        $userTotalProgress = 0;
        $userTotalMinutes = 0;
        $userCompletedCourses = 0;

        foreach ($userRegistrations as $reg) {
            $progress = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
            $userTotalProgress += $progress;
            $userTotalMinutes += ($reg['totalSecondsTracked'] ?? 0);
            if (($reg['registrationCompletion'] ?? '') === 'COMPLETED') {
                $userCompletedCourses++;
            }
        }

        $userAverageProgress = $userRegistrationsCount > 0 ? round($userTotalProgress / $userRegistrationsCount) : 0;
        $userIncompleteCourses = max(0, $userRegistrationsCount - $userCompletedCourses);

        $latest = $userRegistrations[0];
        $activeRegId = $latest['id'] ?? '';
        $courseId = $latest['course']['id'] ?? '';
        $courseTitle = $latest['course']['title'] ?? 'Course Content';
        $completionPercent = round(($latest['registrationCompletionAmount'] ?? 0) * 100);
        $minutesSpent = round(($latest['totalSecondsTracked'] ?? 0) / 60, 1);
    }

    $preparednessScore = $completionPercent;
} else {
    $preparednessScore = $completionPercent;
}

$statusBadgeMap = [
    'compliant' => 'Compliant',
    'upcoming' => 'Upcoming Renewal',
    'overdue' => 'Overdue',
];

$statusClassMap = [
    'compliant' => 'status-success',
    'upcoming' => 'status-warning',
    'overdue' => 'status-danger',
];

foreach ($complianceCourses as &$course) {
    $course['statusLabel'] = $statusBadgeMap[$course['state']] ?? 'Status';
    $course['statusClass'] = $statusClassMap[$course['state']] ?? 'status-warning';
}
unset($course);

$recentActivity = [
    ['icon' => '✔', 'text' => 'Sarah completed Active Threat Response'],
    ['icon' => '✔', 'text' => '12 new learners enrolled'],
    ['icon' => '⚠', 'text' => '5 certifications expiring this month'],
    ['icon' => '✔', 'text' => 'Emergency Department reached 95% completion'],
];

// Determine thumbnail based on which course is active
$thumbnail = courseThumbnailUrl(getCourseThumbnailFile($courseId, $courseTitle));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/main.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        <?php renderBrandStyles(); ?>

        * { box-sizing: border-box; }
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }

        /* MAIN CONTENT */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; transition: 0.3s; }
        .content-max-width { max-width: 1100px; margin: 0 auto; }
        header { margin-bottom: 40px; }
        header h1 { font-size: 2rem; font-weight: 700; margin: 0; color: #0f172a; }
        header p { color: var(--text-muted); margin-top: 8px; font-size: 1.1rem; }

        /* DASHBOARD SPECIFICS */
        .page-hero { background: linear-gradient(180deg, #1A2E2A 0%, #006F53 100%); color: #ffffff; border-radius: 28px; padding: 24px; box-shadow: 0 35px 70px rgba(0, 111, 83, 0.18); display: grid; grid-template-columns: 1fr; gap: 20px; align-items: center; margin-bottom: 28px; }
        @media (min-width: 900px) {
            .page-hero { grid-template-columns: 1.7fr 1fr; padding: 34px 36px; gap: 28px; }
        }
        .page-eyebrow { color: rgba(255,255,255,0.78); font-size: 0.78rem; letter-spacing: 0.18em; text-transform: uppercase; margin-bottom: 14px; }
        .page-hero h1 { margin: 0; font-size: 1.6rem; line-height: 1.05; letter-spacing: -0.03em; }
        @media (min-width: 900px) { .page-hero h1 { font-size: 2.65rem; } }
        .page-hero p { max-width: 640px; color: rgba(255,255,255,0.85); font-size: 1rem; margin-top: 16px; line-height: 1.8; }
        .hero-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-top: 24px; }
        .hero-stat { background: rgba(255,255,255,0.08); border: 1px solid rgba(148,163,184,0.18); border-radius: 18px; padding: 18px; }
        .hero-stat .label { color: #9ca3af; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.14em; margin-bottom: 8px; }
        .hero-stat .value { font-size: 1.95rem; font-weight: 800; line-height: 1; color: #ffffff; }
        .hero-stat .subtext { color: #cbd5e1; font-size: 0.9rem; margin-top: 8px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 24px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 20px; padding: 24px; box-shadow: 0 16px 34px rgba(0, 0, 0, 0.06); }
        .kpi-card .kpi-label { color: var(--text-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 10px; }
        .kpi-card .kpi-value { font-size: 2.1rem; font-weight: 800; color: var(--text-main); }
        .kpi-card .kpi-mini { color: #475569; font-size: 0.92rem; margin-top: 12px; }

        .segment-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 24px; padding: 24px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.05); margin-bottom: 24px; }
        .segment-controls { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-top: 18px; }
        .segment-filter { background: var(--bg-body); border: 1px solid var(--border); border-radius: 18px; padding: 18px; }
        .segment-filter label { display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--text-muted); margin-bottom: 10px; }
        .segment-filter span { display: block; color: var(--text-main); font-weight: 700; }
        .segment-pill { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 999px; background: rgba(0, 112, 84, 0.12); color: #065f46; font-size: 0.85rem; font-weight: 700; margin-right: 10px; margin-top: 10px; }
        .segment-summary { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 20px; }
        .segment-summary .segment-status { color: #0f172a; font-weight: 700; }
        .section-card, .course-card, .stat-card, .metric-card, .readiness-grid, .stats-grid, .chart-container { min-width: 0; }
        .course-card { width: 100%; max-width: 100%; overflow: hidden; }
        .course-image { width: 100%; height: auto; display: block; border-radius: 18px; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 24px; }
        .readiness-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 22px; min-width: 0; }
        .metric-card { background: var(--bg-body); border-radius: 18px; padding: 18px; min-width: 0; }
        .metric-label { color: var(--text-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 10px; }
        .metric-value { font-size: 1.8rem; font-weight: 800; color: var(--text-main); }
        .metric-detail { color: #475569; font-size: 0.9rem; margin-top: 10px; word-break: break-word; }

        .dashboard-main-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
        @media (min-width: 900px) {
            .dashboard-main-grid { grid-template-columns: 1.6fr 1fr; }
        }
        .section-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 24px; padding: 24px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.05); }
        .section-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
        .section-title { font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--text-main); }
        .section-note { color: var(--text-muted); font-size: 0.92rem; max-width: 540px; }

        .alert-list { display: grid; gap: 12px; }
        .alert-item { display: grid; grid-template-columns: auto 1fr; gap: 16px; padding: 18px; border-radius: 18px; border: 1px solid var(--border); background: var(--bg-body); }
        .alert-badge { min-width: 10px; height: 10px; border-radius: 999px; margin-top: 6px; }
        .alert-item.danger { border-color: #fee2e2; background: #fff1f2; }
        .alert-item.warning { border-color: #fef3c7; background: #fffbeb; }
        .alert-item.success { border-color: #dcfce7; background: #ecfdf5; }
        .alert-text { display: grid; gap: 4px; }
        .alert-title { font-weight: 700; color: var(--text-main); }
        .alert-detail { font-size: 0.92rem; color: var(--text-muted); }

        .activity-list { display: grid; gap: 10px; margin-top: 18px; }
        .activity-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; }
        .activity-icon { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; min-height: 32px; border-radius: 50%; background: #e2e8f0; color: #0f172a; font-weight: 700; }
        .activity-text { color: #475569; font-size: 0.95rem; line-height: 1.4; }

        .vault-card { margin-top: 18px; }
        .vault-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
        .vault-header h3 { margin: 0; font-size: 1rem; color: #0f172a; }
        .vault-header p { margin: 0; color: #64748b; font-size: 0.9rem; }
        .vault-list { display: grid; gap: 12px; }
        .vault-item { display: grid; grid-template-columns: 1fr auto; gap: 14px; padding: 16px; border-radius: 18px; border: 1px solid #e2e8f0; background: #f8fafc; }
        .vault-meta { display: grid; gap: 4px; }
        .vault-title { font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0; }
        .vault-subtitle { color: #475569; font-size: 0.9rem; }
        .vault-badge { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .vault-badge.complete { background: #dcfce7; color: #166534; }
        .vault-badge.ready { background: #dbeafe; color: #1d4ed8; }
        .vault-link { color: var(--primary); font-weight: 700; text-decoration: none; }
        .vault-link:hover { text-decoration: underline; }

        .org-records { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 18px; }
        .org-records h4 { margin: 0 0 10px; font-size: 0.95rem; color: #0f172a; }
        .org-records-item { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
        .org-records-item:last-child { border-bottom: none; }
        .org-records-title { color: #475569; font-size: 0.92rem; margin: 0; }
        .org-records-status { color: #0f172a; font-size: 0.88rem; } 

        .trend-chart { height: 240px; }
        .trend-legend { display: flex; justify-content: space-between; gap: 12px; margin-top: 18px; }
        .trend-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: #f8fafc; color: #475569; font-size: 0.85rem; }
        .trend-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .trend-dot.primary { background: #2563eb; }
        .trend-dot.highlight { background: #f59e0b; }

        .chart-container { min-height: 220px; display:flex; align-items:center; justify-content:center; }
        .chart-container canvas { max-width: 100%; height: 220px !important; }

        .progress-detail { display: grid; gap: 14px; }
        .progress-item { display: grid; gap: 10px; }
        .progress-label { display: flex; justify-content: space-between; align-items: center; color: #64748b; font-size: 0.9rem; }
        .progress-bar-bg { background: #e2e8f0; border-radius: 999px; height: 12px; overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 999px; transition: width 0.4s ease; }
        .progress-bar-fill.primary { background: #2563eb; }
        .progress-bar-fill.warning { background: #f59e0b; }
        .progress-bar-fill.danger { background: #dc2626; }

        .timeline-list { display: grid; gap: 12px; margin-top: 16px; }
        .timeline-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 18px; padding: 16px; }
        .timeline-title { margin: 0 0 8px; font-weight: 700; color: #0f172a; }
        .timeline-status { font-size: 0.85rem; color: #64748b; }
        .timeline-pill { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .pill-danger { background: #fee2e2; color: #991b1b; }
        .pill-warning { background: #fef3c7; color: #92400e; }
        .pill-success { background: #dcfce7; color: #166534; }

        .footer-cta { padding-top: 14px; margin-top: 18px; border-top: 1px solid #e2e8f0; }
        .footer-cta a { color: var(--primary); font-weight: 700; text-decoration: none; }

        .renewal-list { margin-top: 18px; display: grid; gap: 12px; }
        .renewal-card { background: #f8fafc; border-radius: 16px; padding: 16px; border: 1px solid var(--border); }
        .renewal-meta { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .renewal-title { font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; }
        .renewal-detail { color: var(--text-muted); font-size: 0.9rem; line-height: 1.4; }
        .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.02em; }
        .status-success { background: #dcfce7; color: #166534; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .status-danger { background: #fee2e2; color: #991b1b; }

        /* RESPONSIVE BREAKPOINTS */
        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .dashboard-main-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .course-card { padding: 20px; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <div class="content-max-width">
            <section class="page-hero" data-tour="tour-hero">
                <div>
                    <div class="page-eyebrow">Personal Learning Center</div>
                    <h1>Welcome back, <?php echo $firstName; ?>.</h1>
                    <p>Your current course progress and next steps are summarized below.</p>
                    <div class="hero-summary">
                        <div class="hero-stat">
                            <div class="label">Current Course Progress</div>
                            <div class="value"><?php echo $completionPercent; ?>%</div>
                            <div class="subtext"><?php echo htmlspecialchars($courseTitle); ?></div>
                        </div>
                        <div class="hero-stat">
                            <div class="label">Active Enrollments</div>
                            <div class="value"><?php echo $userRegistrationsCount; ?></div>
                            <div class="subtext"><?php echo $userCompletedCourses; ?> completed</div>
                        </div>
                        <div class="hero-stat">
                            <div class="label">Average Progress</div>
                            <div class="value"><?php echo $userAverageProgress; ?>%</div>
                            <div class="subtext"><?php echo $userIncompleteCourses; ?> incomplete</div>
                        </div>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="preparednessRing"></canvas>
                </div>
            </section>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Active Enrollments</div>
                    <div class="kpi-value"><?php echo $userRegistrationsCount; ?></div>
                    <div class="kpi-mini">Courses currently in progress.</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Completed Courses</div>
                    <div class="kpi-value"><?php echo $userCompletedCourses; ?></div>
                    <div class="kpi-mini">Courses you have finished.</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Average Progress</div>
                    <div class="kpi-value"><?php echo $userAverageProgress; ?>%</div>
                    <div class="kpi-mini">Mean completion across active courses.</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Latest Session</div>
                    <div class="kpi-value"><?php echo $minutesSpent; ?> min</div>
                    <div class="kpi-mini">Minutes on your most recent course.</div>
                </div>
            </div>

            <!-- Learning Trend -->
            <div class="section-card" style="margin-bottom: 24px;">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Learning Trend</h3>
                        <p class="section-note">Your progress over the last 7 months.</p>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="dashboard-main-grid">
                <div>
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h2 class="section-title">Your Learning Snapshot</h2>
                                <p class="section-note">Key progress metrics for your current courses.</p>
                            </div>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-label">Active Enrollments</div>
                                <div class="stat-value"><?php echo $userRegistrationsCount; ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Completed Courses</div>
                                <div class="stat-value"><?php echo $userCompletedCourses; ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Avg. Progress</div>
                                <div class="stat-value"><?php echo $userAverageProgress; ?>%</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Latest Session</div>
                                <div class="stat-value"><?php echo $minutesSpent; ?> min</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <h2 class="section-title">Active Program</h2>
                                <p class="section-note">Your current course progress and time spent.</p>
                            </div>
                        </div>
                        <div class="course-card" style="padding: 20px; margin-bottom: 22px;">
                            <img src="<?php echo $thumbnail; ?>" alt="Course" class="course-image" style="margin-bottom: 16px;">
                            <div class="tag">Active Program</div>
                            <div class="course-title"><?php echo htmlspecialchars($courseTitle); ?></div>
                            <div class="stat-row" style="margin-top: 16px;">
                                <span class="stat-label">Completion</span>
                                <span class="stat-value" style="color: var(--primary); "><?php echo $completionPercent; ?>%</span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Time Spent</span>
                                <span class="stat-value"><?php echo $minutesSpent; ?> min</span>
                            </div>
                        </div>
                    </div>

                    <div class="section-card vault-card">
                        <div class="vault-header">
                            <div>
                                <h3>Certificates</h3>
                            </div>
                        </div>
                        <div class="vault-list">
                            <?php foreach ($certifications as $cert): ?>
                                <div class="vault-item">
                                    <div class="vault-meta">
                                        <p class="vault-title"><?php echo htmlspecialchars($cert['title']); ?></p>
                                        <p class="vault-subtitle"><?php echo htmlspecialchars($cert['type']); ?> • <?php echo htmlspecialchars($cert['date']); ?></p>
                                    </div>
                                    <div>
                                        <span class="vault-badge complete"><?php echo htmlspecialchars($cert['status']); ?></span>
                                        <div style="margin-top: 10px; text-align: right;"><a href="<?php echo buildUrl('certificate-vault'); ?>" class="vault-link">View Vault</a></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('active');
        }

        const ringCtx = document.getElementById('preparednessRing').getContext('2d');
        new Chart(ringCtx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [<?php echo $preparednessScore; ?>, <?php echo (100 - $preparednessScore); ?>],
                    backgroundColor: ['#006F53', '#F4F9F7'],
                    borderWidth: 0,
                    cutout: '72%'
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            }
        });

        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
                datasets: [{
                    label: 'Preparedness',
                    data: [<?php echo implode(', ', $avgTrendPoints); ?>],
                    borderColor: '#006F53',
                    backgroundColor: 'rgba(130, 172, 214, 0.18)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#006F53'
                }]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#5f6f6a' } },
                    y: { grid: { color: '#d9e3df' }, ticks: { color: '#5f6f6a', stepSize: 10 }, beginAtZero: true, max: 100 }
                },
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
            }
        });
    </script>
</body>
</html>