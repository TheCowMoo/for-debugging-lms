<?php
/**
 * Audit-ready Records Page - Admin Only
 * Pulls live data from local DB and the SCORM backend for organization compliance.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userRole = $user['role'] ?? 'student';
} catch (PDOException $e) {
    die("Connection failed.");
}

if ($userRole !== 'admin') {
    redirectTo('certificate-vault');
}

// --- FETCH LOCAL DB USERS ---
$localUsers = [];
$totalLocalUsers = 0;
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT id, email, first_name, last_name, registration_id, role, department, is_team_lead, created_at FROM users ORDER BY last_name, first_name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $localUsers[] = $row;
    }
    $totalLocalUsers = count($localUsers);
} catch (PDOException $e) {
    error_log('[AUDIT] DB user fetch failed: ' . $e->getMessage());
}

// --- FETCH SCORM REGISTRATIONS ---
$registrations = fetchScormRegistrations();
$totalRegistrations = count($registrations);

// --- FETCH SCORM COURSES ---
$courses = fetchScormCourses();
$courseMap = [];
foreach ($courses as $course) {
    $courseId = $course['id'] ?? $course['courseId'] ?? '';
    if ($courseId) {
        $courseMap[$courseId] = $course['title'] ?? 'Untitled';
    }
}

// --- AGGREGATE STATISTICS ---
$completedCount = 0;
$inProgressCount = 0;
$totalSeconds = 0;
$totalCompletionAmount = 0;
$courseCompletionData = []; // courseId => [total, completed]
$registrationCourseMap = []; // regCourseId => count

foreach ($registrations as $reg) {
    $progress = $reg['registrationCompletionAmount'] ?? 0;
    $totalCompletionAmount += $progress;
    $totalSeconds += ($reg['totalSecondsTracked'] ?? 0);

    if (($reg['registrationCompletion'] ?? '') === 'COMPLETED') {
        $completedCount++;
    } else {
        $inProgressCount++;
    }

    $regCourseId = $reg['course']['id'] ?? '';
    $regCourseTitle = $reg['course']['title'] ?? 'Untitled';
    if ($regCourseId) {
        if (!isset($courseCompletionData[$regCourseId])) {
            $courseCompletionData[$regCourseId] = ['title' => $regCourseTitle, 'total' => 0, 'completed' => 0];
        }
        $courseCompletionData[$regCourseId]['total']++;
        if (($reg['registrationCompletion'] ?? '') === 'COMPLETED') {
            $courseCompletionData[$regCourseId]['completed']++;
        }
        $registrationCourseMap[$regCourseId] = ($registrationCourseMap[$regCourseId] ?? 0) + 1;
    }
}

$avgProgress = $totalRegistrations > 0 ? round(($totalCompletionAmount / $totalRegistrations) * 100) : 0;
$totalHours = round($totalSeconds / 3600, 1);

// --- MATCH LOCAL USERS TO SCORM REGISTRATIONS ---
$matchedCount = 0;
$unmatchedCount = 0;
$regLookup = [];
foreach ($registrations as $reg) {
    $regLookup[$reg['id']] = $reg;
}
foreach ($localUsers as $lu) {
    if (!empty($lu['registration_id']) && isset($regLookup[$lu['registration_id']])) {
        $matchedCount++;
    } else {
        $unmatchedCount++;
    }
}

// --- GENERATE CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-records-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    $sn = getSiteName();
    fputcsv($output, ['Audit Report - ' . $sn, 'Generated: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, ['User Email', 'First Name', 'Last Name', 'Role', 'Department', 'Registration ID', 'Course', 'Progress %', 'Status', 'Time Tracked (mins)', 'Last Access']);
    
    foreach ($localUsers as $lu) {
        $regId = $lu['registration_id'] ?? '';
        $reg = $regLookup[$regId] ?? null;
        $courseTitle = $reg ? ($reg['course']['title'] ?? 'N/A') : 'No Registration';
        $progress = $reg ? round(($reg['registrationCompletionAmount'] ?? 0) * 100) . '%' : 'N/A';
        $status = $reg ? ($reg['registrationCompletion'] ?? 'INCOMPLETE') : 'Not Enrolled';
        $timeTracked = $reg ? round(($reg['totalSecondsTracked'] ?? 0) / 60) : 0;
        $lastAccess = $reg ? ($reg['lastAccessDate'] ?? 'N/A') : 'N/A';
        
        fputcsv($output, [
            $lu['email'] ?? '',
            $lu['first_name'] ?? '',
            $lu['last_name'] ?? '',
            $lu['role'] ?? '',
            $lu['department'] ?? '',
            $regId,
            $courseTitle,
            $progress,
            $status,
            $timeTracked,
            $lastAccess,
        ]);
    }
    
    // Add unregistered SCORM users
    $foundRegIds = array_column($localUsers, 'registration_id');
    foreach ($registrations as $reg) {
        if (!in_array($reg['id'], $foundRegIds)) {
            $progress = round(($reg['registrationCompletionAmount'] ?? 0) * 100) . '%';
            $timeTracked = round(($reg['totalSecondsTracked'] ?? 0) / 60);
            fputcsv($output, [
                $reg['learner']['id'] ?? 'Unknown',
                $reg['learner']['firstName'] ?? '',
                $reg['learner']['lastName'] ?? '',
                'SCORM Only',
                '',
                $reg['id'],
                $reg['course']['title'] ?? 'Untitled',
                $progress,
                $reg['registrationCompletion'] ?? 'INCOMPLETE',
                $timeTracked,
                $reg['lastAccessDate'] ?? 'N/A',
            ]);
        }
    }
    
    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Records | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; min-height: 100vh; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; }
        main { margin-left: var(--sidebar-width); width: 100%; padding: 48px 64px; transition: 0.3s; }
        .content-max-width { max-width: 1200px; margin: 0 auto; }
        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 20px 20px; } }
        .page-title { margin: 0 0 8px; font-size: 2rem; }
        .page-subtitle { margin: 0 0 24px; color: var(--text-muted); }
        .back-link { display: inline-block; margin-bottom: 24px; color: var(--primary); font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
        .stat-label { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 8px; }
        .stat-value { font-size: 1.8rem; font-weight: 800; color: var(--text-main); }
        .stat-sub { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 28px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.05); }
        .card h2 { margin: 0 0 16px; font-size: 1.15rem; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th { background: #f8fafc; text-align: left; padding: 14px 16px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; white-space: nowrap; }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        .pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .pill-complete { background: #dcfce7; color: #166534; }
        .pill-incomplete { background: #fef3c7; color: #92400e; }
        .pill-notenrolled { background: #f1f5f9; color: #64748b; }
        .pill-matched { background: rgba(0, 112, 84, 0.12); color: #065f46; }
        .pill-unmatched { background: rgba(217, 119, 36, 0.12); color: #92400e; }

        .progress-bar { width: 80px; height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 6px; }
        .progress-fill { height: 100%; background: var(--primary); border-radius: 10px; }

        .export-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 24px; }
        .btn-export { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: var(--primary); color: #fff; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 0.9rem; }
        .btn-export:hover { background: #60B49A; }
        .btn-print { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: #fff; color: var(--text-main); border: 1px solid var(--border); border-radius: 12px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 0.9rem; }
        .btn-print:hover { background: var(--bg-body); }

        .section-toggle { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
        .toggle-btn { padding: 8px 18px; border-radius: 999px; border: 1px solid var(--border); background: #fff; cursor: pointer; font-size: 0.82rem; font-weight: 600; color: var(--text-muted); }
        .toggle-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }

        .empty-state { text-align: center; padding: 40px; color: var(--text-muted); }

        @media print {
            nav, .back-link, .export-toolbar, .section-toggle { display: none !important; }
            main { margin-left: 0; padding: 0; }
            .card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <div class="content-max-width">
            <a href="<?php echo buildUrl('certificate-vault'); ?>" class="back-link">← Back to Certificates</a>
            
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
                <div>
                    <h1 class="page-title" data-tour="tour-audit">Audit-ready Records</h1>
                    <p class="page-subtitle">Live organization compliance data from your local records.</p>
                </div>
            </div>

            <!-- Export Toolbar -->
            <div class="export-toolbar">
                <a href="?export=csv" class="btn-export">⬇ Export CSV</a>
                <button onclick="window.print()" class="btn-print">🖨 Print Report</button>
                <span style="font-size:0.82rem;color:var(--text-muted);margin-left:auto;">Last updated: <?php echo date('Y-m-d H:i'); ?></span>
            </div>

            <!-- Summary Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users (Local DB)</div>
                    <div class="stat-value"><?php echo $totalLocalUsers; ?></div>
                    <div class="stat-sub">Registered accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Registrations (SCORM)</div>
                    <div class="stat-value"><?php echo $totalRegistrations; ?></div>
                    <div class="stat-sub">Active course enrollments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $completedCount; ?></div>
                    <div class="stat-sub"><?php echo $totalRegistrations > 0 ? round(($completedCount / $totalRegistrations) * 100) : 0; ?>% completion rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Avg. Progress</div>
                    <div class="stat-value"><?php echo $avgProgress; ?>%</div>
                    <div class="stat-sub">Across all registrations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Training Time</div>
                    <div class="stat-value"><?php echo $totalHours; ?>h</div>
                    <div class="stat-sub"><?php echo round($totalSeconds / 86400, 1); ?> days tracked</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Account Match Rate</div>
                    <div class="stat-value"><?php echo $totalLocalUsers > 0 ? round(($matchedCount / $totalLocalUsers) * 100) : 0; ?>%</div>
                    <div class="stat-sub"><?php echo $matchedCount; ?> linked / <?php echo $unmatchedCount; ?> unlinked</div>
                </div>
            </div>

            <!-- Course Completion Breakdown -->
            <div class="card">
                <h2>Course Completion Breakdown</h2>
                <?php if (empty($courseCompletionData)): ?>
                    <p class="empty-state">No course data available.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Total Enrolled</th>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                    <th>In Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courseCompletionData as $cid => $data): ?>
                                    <?php 
                                        $rate = $data['total'] > 0 ? round(($data['completed'] / $data['total']) * 100) : 0;
                                        $inProg = $data['total'] - $data['completed'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($data['title']); ?></strong></td>
                                        <td><?php echo $data['total']; ?></td>
                                        <td><?php echo $data['completed']; ?></td>
                                        <td>
                                            <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $rate; ?>%"></div></div>
                                            <?php echo $rate; ?>%
                                        </td>
                                        <td><?php echo $inProg; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- User Registration Detail -->
            <div class="card">
                <h2>User Registration Detail</h2>
                <p style="color:var(--text-muted);margin:-8px 0 18px;font-size:0.92rem;">All local users matched against course registrations.</p>
                
                <?php if (empty($localUsers) && empty($registrations)): ?>
                    <p class="empty-state">No data to display.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Registration</th>
                                    <th>Course</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th>Last Access</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($localUsers as $lu): 
                                    $regId = $lu['registration_id'] ?? '';
                                    $reg = $regLookup[$regId] ?? null;
                                    
                                    if ($reg):
                                        $progress = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
                                        $status = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'Completed' : 'In Progress';
                                        $statusClass = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'pill-complete' : 'pill-incomplete';
                                        $timeTracked = round(($reg['totalSecondsTracked'] ?? 0) / 60);
                                        $lastAccess = !empty($reg['lastAccessDate']) ? date('M d, Y', strtotime($reg['lastAccessDate'])) : 'N/A';
                                        $courseTitle = $reg['course']['title'] ?? 'Untitled';
                                    else:
                                        $progress = 0;
                                        $status = 'Not Enrolled';
                                        $statusClass = 'pill-notenrolled';
                                        $timeTracked = 0;
                                        $lastAccess = 'N/A';
                                        $courseTitle = '—';
                                    endif;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars(($lu['first_name'] ?? '') . ' ' . ($lu['last_name'] ?? '')); ?></strong></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($lu['email'] ?? ''); ?></td>
                                        <td><span class="pill <?php echo $lu['role'] === 'admin' ? 'pill-complete' : 'pill-incomplete'; ?>"><?php echo htmlspecialchars($lu['role'] ?? 'student'); ?></span></td>
                                        <td><?php echo htmlspecialchars($lu['department'] ?? '—'); ?></td>
                                        <td style="font-size:0.78rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($regId); ?>"><?php echo $regId ? substr(htmlspecialchars($regId), 0, 20) . '…' : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($courseTitle); ?></td>
                                        <td>
                                            <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $progress; ?>%"></div></div>
                                            <?php echo $progress; ?>%
                                        </td>
                                        <td><span class="pill <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                        <td><?php echo $timeTracked; ?> min</td>
                                        <td style="font-size:0.82rem;"><?php echo $lastAccess; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <!-- SCORM-only registrations (users not in local DB) -->
                                <?php 
                                $localRegIds = array_filter(array_column($localUsers, 'registration_id'));
                                foreach ($registrations as $reg): 
                                    if (in_array($reg['id'], $localRegIds)) continue;
                                    $progress = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
                                    $status = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'Completed' : 'In Progress';
                                    $statusClass = ($reg['registrationCompletion'] ?? '') === 'COMPLETED' ? 'pill-complete' : 'pill-incomplete';
                                    $timeTracked = round(($reg['totalSecondsTracked'] ?? 0) / 60);
                                    $lastAccess = !empty($reg['lastAccessDate']) ? date('M d, Y', strtotime($reg['lastAccessDate'])) : 'N/A';
                                ?>
                                    <tr style="background:#fffbeb;">
                                        <td><strong><?php echo htmlspecialchars(($reg['learner']['firstName'] ?? '') . ' ' . ($reg['learner']['lastName'] ?? '')); ?></strong> <span style="font-size:0.7rem;color:var(--accent);font-weight:700;">(SCORM only)</span></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($reg['learner']['id'] ?? 'Unknown'); ?></td>
                                        <td>—</td>
                                        <td>—</td>
                                        <td style="font-size:0.78rem;"><?php echo substr(htmlspecialchars($reg['id']), 0, 20) . '…'; ?></td>
                                        <td><?php echo htmlspecialchars($reg['course']['title'] ?? 'Untitled'); ?></td>
                                        <td>
                                            <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $progress; ?>%"></div></div>
                                            <?php echo $progress; ?>%
                                        </td>
                                        <td><span class="pill <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                        <td><?php echo $timeTracked; ?> min</td>
                                        <td style="font-size:0.82rem;"><?php echo $lastAccess; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Export Data Summary -->
            <div class="card" style="background:#f0fdf4;border-color:#bbf7d0;">
                <h2 style="color:#166534;">✓ Audit Data Ready</h2>
                <p style="color:#166534;font-size:0.92rem;line-height:1.6;">
                    This report includes <?php echo $totalLocalUsers; ?> local user<?php echo $totalLocalUsers === 1 ? '' : 's'; ?> and <?php echo $totalRegistrations; ?> course registration<?php echo $totalRegistrations === 1 ? '' : 's'; ?> across <?php echo count($courseCompletionData); ?> course<?php echo count($courseCompletionData) === 1 ? '' : 's'; ?>.
                    Use the <strong>Export CSV</strong> button to download a machine-readable audit file, or <strong>Print Report</strong> for a physical copy.
                </p>
            </div>
        </div>
    </main>
</body>
</html>