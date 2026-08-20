<?php
/**
 * PURSUIT PATHWAYS LMS
 * ADMIN ANALYTICS DASHBOARD
 * Unified with Learner Sidebar & Role-Based Navigation
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

if (!isAdmin()) {
    redirectTo('dashboard/');
}

$userRole = $_SESSION['user_role'] ?? 'admin';
$localUsers = [];
$localUserCount = 0;
$localUserTotalCount = 0;
$localRegistrationIds = [];

try {
    $pdo = getDbConnection();
    $orgFilter = orgSql();
    $stmt = $pdo->query('SELECT id, email, first_name, last_name, registration_id, organization_id FROM users WHERE 1=1' . $orgFilter);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['registration_id'])) {
            $localUsers[$row['registration_id']] = $row;
            $localRegistrationIds[$row['registration_id']] = true;
        }
    }
    $localUserCount = count($localUsers);

    $totalStmt = $pdo->query('SELECT COUNT(*) FROM users');
    $localUserTotalCount = (int) $totalStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[DB] Unable to load local user registrations: ' . $e->getMessage());
}

$registrations = fetchScormRegistrations();

// 3. AGGREGATE GLOBAL STATISTICS
$totalLearners = count($registrations);
$totalCompletionAmount = 0;
$totalSeconds = 0;
$completedCount = 0;
$linkedCount = 0;
$unlinkedCount = 0;
$uniqueCourseIds = [];

foreach ($registrations as $reg) {
    $totalCompletionAmount += ($reg['registrationCompletionAmount'] ?? 0);
    $totalSeconds += ($reg['totalSecondsTracked'] ?? 0);
    if (($reg['registrationCompletion'] ?? '') === 'COMPLETED') {
        $completedCount++;
    }

    $courseId = $reg['course']['id'] ?? '';
    if ($courseId !== '') {
        $uniqueCourseIds[$courseId] = true;
    }

    if (isset($localUsers[$reg['id']])) {
        $linkedCount++;
    } else {
        $unlinkedCount++;
    }
}

$avgProgress = $totalLearners > 0 ? round(($totalCompletionAmount / $totalLearners) * 100) : 0;
$totalHours = round($totalSeconds / 3600, 1);
$uniqueCourseCount = count($uniqueCourseIds);
$missingLocalRegistrations = max(0, $localUserTotalCount - $localUserCount);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Analytics | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/main.css'); ?>">
    <style>
        :root {
            --primary: #82ACD6;
            --primary-hover: #00808E;
            --accent: #00808E;
            --danger: #E4E348;
            --bg-body: #D3E2F3;
            --bg-card: #FFFFFF;
            --text-main: #232D63;
            --text-muted: #232D63;
            --border: #BBBDB7;
            --radius: 16px;
            --sidebar-width: 280px;
            --admin-accent: #00808E;
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }

        /* --- MAIN CONTENT --- */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; }
        header { margin-bottom: 40px; }
        header h1 { font-size: 2rem; font-weight: 700; margin: 0; color: var(--text-main); }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .stat-label { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 1.5rem; font-weight: 700; margin-top: 8px; }

        .table-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 16px 24px; font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        td { padding: 16px 24px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        
        .progress-bar { width: 100%; height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden; margin-top: 4px; }
        .progress-fill { height: 100%; background: var(--primary); }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <header>
            <h1>Training Insights</h1>
            <p style="color:var(--text-muted); margin-top:8px;">Overview of current learner progress and certifications.</p>
            <p style="color:var(--text-muted); margin-top:6px; font-size:0.95rem;">Showing <?php echo $localUserCount; ?> learner account<?php echo $localUserCount === 1 ? '' : 's'; ?> linked to the platform.</p>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Learners</div>
                <div class="stat-value"><?php echo $totalLearners; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Local Accounts</div>
                <div class="stat-value"><?php echo $localUserTotalCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Matched Registrations</div>
                <div class="stat-value"><?php echo $linkedCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg. Progress</div>
                <div class="stat-value"><?php echo $avgProgress; ?>%</div>
            </div>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Learner</th>
                        <th>Course</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $reg): 
                        $pct = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
                        $localUser = $localUsers[$reg['id']] ?? null;
                        $learnerName = $localUser ? trim($localUser['first_name'] . ' ' . $localUser['last_name']) : trim(($reg['learner']['firstName'] ?? '') . ' ' . ($reg['learner']['lastName'] ?? ''));
                        $learnerEmail = $localUser ? $localUser['email'] : ($reg['learner']['email'] ?? 'Unknown');
                        $lastAccess = !empty($reg['lastAccessDate']) ? date('M d, Y', strtotime($reg['lastAccessDate'])) : 'N/A';
                    ?>
                    <tr>
                        <td>
                            <?php if ($localUser && !empty($localUser['id'])): ?>
                                <a href="<?php echo buildUrl('analytics/user?user=' . (int)$localUser['id']); ?>" style="font-weight:600; color:var(--primary); text-decoration:none;">
                                    <?php echo htmlspecialchars($learnerName ?: 'Unknown Learner'); ?> â†’
                                </a>
                            <?php else: ?>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($learnerName ?: 'Unknown Learner'); ?></div>
                            <?php endif; ?>
                            <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($learnerEmail); ?></div>
                        </td>
                        <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($reg['course']['title'] ?? 'Untitled Course'); ?></td>
                        <td>
                            <?php 
                                $lastAccessDate = $reg['lastAccessDate'] ?? null;
                                $daysSince = $lastAccessDate ? floor((time() - strtotime($lastAccessDate)) / 86400) : 999;
                                $isActive = $daysSince <= 30;
                            ?>
                            <span style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:999px; background: <?php echo $isActive ? 'rgba(0, 112, 84, 0.12)' : 'rgba(217, 119, 36, 0.12)'; ?>; color: <?php echo $isActive ? '#065f46' : '#92400e'; ?>; font-size:0.75rem; font-weight:700;">
                                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight:700; font-size:0.8rem; margin-bottom:4px;"><?php echo $pct; ?>%</div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $pct; ?>%"></div>
                            </div>
                        </td>
                        <td style="font-size:0.8rem; color:var(--text-muted);">
                            <?php echo htmlspecialchars($lastAccess); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>