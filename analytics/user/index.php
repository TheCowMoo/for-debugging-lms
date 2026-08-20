<?php
/**
 * PURSUIT PATHWAYS LMS
 * ADVANCED ANALYTICS — User Detail (Phase 4)
 *
 * Individual learner view: course summaries, attempt history,
 * question-level breakdowns, competency maps, and time tracking.
 *
 * Access: Admin or Super Admin (org-scoped for org admins).
 *
 * Query params:
 *   user  — users.id of the learner
 *   pkg   — optional scorm_packages.id to filter
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/analytics.php';

requireLogin();
requireAdmin();

$pdo = getDbConnection();
$userId = (int)($_GET['user'] ?? 0);
$packageId = (int)($_GET['pkg'] ?? 0);

if ($userId <= 0) {
    redirectTo('admin-progress/');
}

// —— Verify user exists + org access ——
$scope = analyticsOrgScope('u');
$userStmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.organization_id
                           FROM users u WHERE u.id = ?" . $scope['sql']);
$userStmt->execute(array_merge([$userId], $scope['params']));
$learner = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$learner) {
    redirectTo('admin-progress/?error=not-found');
}

// —— Gather analytics ——
$summaries = getUserCourseSummary($userId);
$attemptHistory = getUserAttemptHistory($userId, $packageId ?: null);

$packages = [];
foreach ($summaries as $s) {
    $packages[] = ['id' => $s['package_id'], 'title' => $s['title']];
}

// Question analysis + competency map for the selected package
$questionAnalysis = [];
$competencyMap = [];
$timeOnTask = [];
if ($packageId > 0) {
    $questionAnalysis = getUserQuestionAnalysis($userId, $packageId);
    $competencyMap = getUserCompetencyMap($userId, $packageId);
    $timeOnTask = getUserTimeOnTask($userId, $packageId);
}

// Aggregate KPIs
$totalSeconds = 0;
$completedCount = 0;
$totalCourses = count($summaries);
$avgScore = 0;
$scoreCount = 0;

foreach ($summaries as $s) {
    $totalSeconds += $s['total_seconds'];
    if ($s['is_complete']) $completedCount++;
    if ($s['score_raw'] !== null) {
        $avgScore += $s['score_raw'];
        $scoreCount++;
    }
}
$avgScore = $scoreCount > 0 ? round($avgScore / $scoreCount, 1) : null;

$formatter = function(int $seconds): string {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learner Analytics | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        :root {
            --primary: #82ACD6; --primary-hover: #00808E; --accent: #00808E;
            --bg-body: #D3E2F3; --bg-card: #FFFFFF; --text-main: #232D63;
            --text-muted: #232D63; --border: #BBBDB7; --radius: 16px;
            --sidebar-width: 280px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; }
        .content-max-width { max-width: 1200px; margin: 0 auto; }

        .header-box { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
        .header-box h1 { font-size: 1.9rem; font-weight: 800; margin: 0; }
        .header-box .sub { color: var(--text-muted); margin-top: 6px; }
        .btn-back { background: #fff; border: 1px solid var(--border); padding: 10px 18px; border-radius: 10px; font-weight: 700; color: var(--text-main); text-decoration: none; display: inline-block; }

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 24px; }
        .kpi-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; margin-bottom: 6px; }
        .kpi-value { font-size: 1.7rem; font-weight: 800; color: var(--primary); }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 28px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-header h2, .card-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .card-body { padding: 20px 24px; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 14px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 14px 20px; border-bottom: 1px solid var(--border); font-size: 0.88rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-neutral { background: #f1f5f9; color: #64748b; }

        .progress-bar-bg { background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden; min-width: 80px; }
        .progress-bar-fill { height: 100%; border-radius: 999px; background: var(--primary); }

        .pkg-filter { display: flex; gap: 8px; flex-wrap: wrap; }
        .pkg-filter a { padding: 8px 14px; border-radius: 8px; background: #f1f5f9; color: #334155; font-size: 0.82rem; font-weight: 700; text-decoration: none; }
        .pkg-filter a.active { background: var(--primary); color: #fff; }

        .empty { padding: 40px; text-align: center; color: var(--text-muted); }
        .muted { color: var(--text-muted); }
        .small { font-size: 0.8rem; }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <main>
        <div class="content-max-width">
            <div class="header-box">
                <div>
                    <h1><?php echo htmlspecialchars($learner['first_name'] . ' ' . $learner['last_name']); ?></h1>
                    <div class="sub">
                        <?php echo htmlspecialchars($learner['email']); ?>
                        <?php if (!empty($learner['department'])): ?> • <?php echo htmlspecialchars($learner['department']); ?><?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo buildUrl('admin-progress'); ?>" class="btn-back">â† Back to Progress</a>
            </div>

            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-label">Courses</div><div class="kpi-value"><?php echo $totalCourses; ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Completed</div><div class="kpi-value"><?php echo $completedCount; ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Total Time</div><div class="kpi-value"><?php echo $formatter($totalSeconds); ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Avg Score</div><div class="kpi-value"><?php echo $avgScore !== null ? $avgScore : '—'; ?></div></div>
            </div>

            <!-- Course Summaries -->
            <div class="card">
                <div class="card-header">
                    <h2>Course Summaries</h2>
                    <div class="pkg-filter">
                        <a href="<?php echo buildUrl('analytics/user?user=' . $userId); ?>" class="<?php echo $packageId === 0 ? 'active' : ''; ?>">All</a>
                        <?php foreach ($packages as $p): ?>
                            <a href="<?php echo buildUrl('analytics/user?user=' . $userId . '&pkg=' . $p['id']); ?>" class="<?php echo $packageId === $p['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($p['title']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($summaries)): ?>
                        <div class="empty">No course enrollments found.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Course</th><th>Status</th><th>Progress</th><th>Score</th><th>Time</th><th>Attempts</th><th>Last Access</th></tr></thead>
                            <tbody>
                            <?php foreach ($summaries as $s): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($s['title']); ?></strong></td>
                                    <td>
                                        <?php
                                            $c = strtolower($s['status']);
                                            $cls = $c === 'completed' ? 'badge-success' : ($c === 'incomplete' ? 'badge-warning' : 'badge-neutral');
                                        ?>
                                        <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($s['status']); ?></span>
                                    </td>
                                    <td>
                                        <?php $pct = round($s['completion_amount'] * 100); ?>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div class="progress-bar-bg" style="flex:1;"><div class="progress-bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                                            <span class="small" style="font-weight:700;"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td><?php echo $s['score_raw'] !== null ? $s['score_raw'] : '—'; ?></td>
                                    <td class="small muted"><?php echo $formatter($s['total_seconds']); ?></td>
                                    <td><?php echo $s['attempts']; ?></td>
                                    <td class="small muted"><?php echo $s['last_accessed_at'] ? date('M j, Y g:i A', strtotime($s['last_accessed_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($packageId > 0): ?>
                <!-- Question-level analysis -->
                <div class="card">
                    <div class="card-header"><h2>Question-Level Performance</h2></div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($questionAnalysis)): ?>
                            <div class="empty">No interaction data captured for this course yet.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>#</th><th>Question</th><th>Type</th><th>Response</th><th>Result</th><th>Latency</th></tr></thead>
                                <tbody>
                                <?php foreach ($questionAnalysis as $q): ?>
                                    <tr>
                                        <td><?php echo (int)$q['interaction_index'] + 1; ?></td>
                                        <td><?php echo htmlspecialchars($q['description'] ?: $q['interaction_id'] ?: '—'); ?></td>
                                        <td class="small muted"><?php echo htmlspecialchars($q['interaction_type'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars(mb_strimwidth($q['learner_response'] ?? '—', 0, 60, '…')); ?></td>
                                        <td>
                                            <?php $r = strtolower($q['result'] ?? ''); ?>
                                            <span class="badge <?php echo $r === 'correct' ? 'badge-success' : ($r === 'incorrect' ? 'badge-danger' : 'badge-neutral'); ?>">
                                                <?php echo htmlspecialchars($q['result'] ?: '—'); ?>
                                            </span>
                                        </td>
                                        <td class="small muted"><?php echo $q['latency_seconds'] !== null ? round($q['latency_seconds'], 1) . 's' : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Competency map -->
                <div class="card">
                    <div class="card-header"><h2>Competency Map</h2></div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($competencyMap)): ?>
                            <div class="empty">No objective-level tracking data for this course.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>#</th><th>Objective</th><th>Score</th><th>Completion</th><th>Success</th></tr></thead>
                                <tbody>
                                <?php foreach ($competencyMap as $o): ?>
                                    <tr>
                                        <td><?php echo (int)$o['objective_index'] + 1; ?></td>
                                        <td><?php echo htmlspecialchars($o['objective_id'] ?: 'Objective ' . ((int)$o['objective_index'] + 1)); ?></td>
                                        <td><?php echo $o['score_raw'] !== null ? $o['score_raw'] : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($o['completion_status'] ?: '—'); ?></td>
                                        <td>
                                            <?php $ss = strtolower($o['success_status'] ?? ''); ?>
                                            <span class="badge <?php echo $ss === 'passed' ? 'badge-success' : ($ss === 'failed' ? 'badge-danger' : 'badge-neutral'); ?>">
                                                <?php echo htmlspecialchars($o['success_status'] ?: '—'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Time on task -->
                <div class="card">
                    <div class="card-header"><h2>Time on Task</h2></div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($timeOnTask)): ?>
                            <div class="empty">No session time data.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>SCO</th><th>Attempt</th><th>Session</th><th>Total</th><th>Status</th><th>Started</th></tr></thead>
                                <tbody>
                                <?php foreach ($timeOnTask as $t): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($t['sco_title'] ?: 'SCO #' . ($t['sco_item_id'] ?: '—')); ?></td>
                                        <td><?php echo (int)$t['attempt_number']; ?></td>
                                        <td class="small muted"><?php echo $formatter((int)$t['session_time_seconds']); ?></td>
                                        <td class="small muted"><?php echo $formatter((int)$t['total_time_seconds']); ?></td>
                                        <td><?php echo htmlspecialchars($t['lesson_status'] ?: '—'); ?></td>
                                        <td class="small muted"><?php echo $t['started_at'] ? date('M j, Y g:i A', strtotime($t['started_at'])) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Full attempt history -->
            <div class="card">
                <div class="card-header"><h2>Attempt History</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($attemptHistory)): ?>
                        <div class="empty">No attempts recorded.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Course</th><th>Attempt</th><th>Status</th><th>Score</th><th>Time</th><th>Started</th></tr></thead>
                            <tbody>
                            <?php foreach ($attemptHistory as $h): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($h['package_title'] ?: 'Untitled'); ?></strong></td>
                                    <td><?php echo (int)$h['attempt_number']; ?></td>
                                    <td><?php echo htmlspecialchars($h['lesson_status'] ?: ($h['completion_status'] ?: '—')); ?></td>
                                    <td><?php echo $h['score_raw'] !== null ? $h['score_raw'] : '—'; ?></td>
                                    <td class="small muted"><?php echo $formatter((int)$h['total_time_seconds']); ?></td>
                                    <td class="small muted"><?php echo $h['started_at'] ? date('M j, Y g:i A', strtotime($h['started_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('active');
        }
    </script>
</body>
</html>