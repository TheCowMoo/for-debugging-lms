<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * MULTI-COURSE LEARNING PROGRESS - MOBILE RESPONSIVE
 * Updated to display progress across all enrolled courses.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$currentUser = getCurrentUser();
$firstName = $currentUser['first_name'];
$userEmail = $currentUser['email'];
$userRole = $currentUser['role'];

$userRegistrations = [];
$aggregateProgress = 0;
$aggregateMinutes = 0;
$completedCount = 0;
$totalCount = 0;

if (!empty($userEmail)) {
    $data = fetchScormRegistrations(['learnerId' => $userEmail]);
    if (!empty($data)) {
        $userRegistrations = $data;
        $totalCount = count($userRegistrations);
        foreach ($userRegistrations as $reg) {
            $aggregateProgress += ($reg['registrationCompletionAmount'] ?? 0) * 100;
            $aggregateMinutes += ($reg['totalSecondsTracked'] ?? 0) / 60;
            if (($reg['registrationCompletion'] ?? '') === 'COMPLETED') {
                $completedCount++;
            }
        }
        $aggregateProgress = $totalCount > 0 ? round($aggregateProgress / $totalCount) : 0;
        $aggregateMinutes = round($aggregateMinutes, 1);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Progress | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/main.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        :root { --radius: 16px; }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            min-height: 100vh;
        }

        main { 
            margin-left: var(--sidebar-width); 
            flex: 1; 
            padding: 48px 64px; 
            transition: 0.3s;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .header-title h1 { font-size: 1.75rem; font-weight: 700; margin: 0; }

        /* KPI Row */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
        }
        .kpi-label {
            color: var(--text-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--primary);
        }

        /* Course List */
        .course-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .course-table th {
            text-align: left;
            padding: 16px 20px;
            background: #f8fafc;
            color: var(--text-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            border-bottom: 1px solid var(--border);
        }
        .course-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .course-table tr:last-child td { border-bottom: none; }
        .course-title-cell { font-weight: 700; }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-incomplete { background: #fef3c7; color: #92400e; }

        .progress-bar-bg {
            background: #e2e8f0;
            border-radius: 999px;
            height: 10px;
            overflow: hidden;
            min-width: 100px;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--primary);
            transition: width 0.4s ease;
        }

        .btn-print {
            background: #ffffff; border: 1px solid var(--border);
            padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s;
        }
        .btn-print:hover { background: var(--bg-body); }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .course-table th, .course-table td { padding: 12px; }
            .course-table { font-size: 0.9rem; }
        }
        @media print {
            nav, .mobile-bar, .btn-print, .overlay { display: none !important; }
            main { margin-left: 0; padding: 0; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <header>
            <div class="header-title">
                <h1>Learning Progress</h1>
                <p style="color:var(--text-muted); margin: 5px 0 0;">Report for <strong><?php echo $firstName; ?></strong></p>
            </div>
            <button class="btn-print" onclick="window.print()">Download PDF</button>
        </header>

        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-label">Enrolled Courses</div>
                <div class="kpi-value"><?php echo $totalCount; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Completed</div>
                <div class="kpi-value"><?php echo $completedCount; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Average Progress</div>
                <div class="kpi-value"><?php echo $aggregateProgress; ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Time</div>
                <div class="kpi-value"><?php echo $aggregateMinutes; ?> min</div>
            </div>
        </div>

        <?php if (empty($userRegistrations)): ?>
            <div class="card">
                <p style="color: var(--text-muted);">No course registrations found for your account. <a href="<?php echo buildUrl('course-page'); ?>" style="color: var(--primary);">View available courses</a>.</p>
            </div>
        <?php else: ?>
            <table class="course-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userRegistrations as $reg): 
                        $pct = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
                        $mins = round(($reg['totalSecondsTracked'] ?? 0) / 60, 1);
                        $status = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'Completed' : 'In Progress';
                        $statusClass = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'status-completed' : 'status-incomplete';
                    ?>
                        <tr>
                            <td class="course-title-cell">
                                <?php echo htmlspecialchars($reg['course']['title'] ?? 'Untitled Course', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div class="progress-bar-bg" style="flex: 1;">
                                        <div class="progress-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                    <span style="font-weight: 700; min-width: 40px;"><?php echo $pct; ?>%</span>
                                </div>
                            </td>
                            <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                            <td><?php echo $mins; ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <script>
        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('active');
        }
    </script>
</body>
</html>