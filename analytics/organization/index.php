<?php
/**
 * PURSUIT PATHWAYS LMS
 * ADVANCED ANALYTICS — Organization (Phase 4)
 *
 * Organization-level dashboard: KPIs, department comparison table,
 * department drill-down (completion rates, knowledge gaps, at-risk learners),
 * and trend data.
 *
 * Access: Admin or Super Admin (org-scoped for org admins).
 *
 * Query params:
 *   dept  — optional department name to focus on
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/analytics.php';

requireLogin();
requireAdmin();

$selectedDept = trim($_GET['dept'] ?? '');
$overview = getOrganizationOverview();
$departments = getOrganizationDepartmentComparison();
$deptList = getDepartmentList();
$trend = getOrganizationTrendData('month', 8);

// New analytics: daily activity, funnel, question heatmap, slide timing
$dailyActivity = getDailyActivityData(30);
$funnel = getCompletionFunnel();
$questionHeatmap = getQuestionPerformanceHeatmap(20);
$slideBreakdown = getSlideTimeBreakdown(50);

// Department drill-down
$deptRates = [];
$deptGaps = [];
$atRisk = [];

if ($selectedDept !== '') {
    $deptRates = getDepartmentCompletionRates($selectedDept);
    $deptGaps = getDepartmentKnowledgeGaps($selectedDept, 12);
}
$atRisk = getAtRiskLearners(60);

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
    <title>Organization Analytics | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .header-box { margin-bottom: 32px; }
        .header-box h1 { font-size: 2rem; font-weight: 800; margin: 0; }
        .header-box .sub { color: var(--text-muted); margin-top: 6px; }

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
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

        .dept-nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .dept-nav a { padding: 8px 14px; border-radius: 8px; background: #f1f5f9; color: #334155; font-size: 0.82rem; font-weight: 700; text-decoration: none; }
        .dept-nav a.active { background: var(--primary); color: #fff; }

        .empty { padding: 40px; text-align: center; color: var(--text-muted); }
        .muted { color: var(--text-muted); }
        .small { font-size: 0.8rem; }
        .chart-container { height: 240px; position: relative; }

        .chip { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .chip-low { background: #fee2e2; color: #991b1b; }
        .chip-med { background: #fef3c7; color: #92400e; }
        .chip-high { background: #dcfce7; color: #166534; }

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
                <h1>Organization Analytics</h1>
                <div class="sub">Aggregate training performance across your organization.</div>
            </div>

            <div class="kpi-row">
                <div class="kpi-card"><div class="kpi-label">Learners</div><div class="kpi-value"><?php echo (int)$overview['total_learners']; ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Enrollments</div><div class="kpi-value"><?php echo (int)$overview['active_enrollments']; ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Completion Rate</div><div class="kpi-value"><?php echo $overview['completion_rate']; ?>%</div></div>
                <div class="kpi-card"><div class="kpi-label">Avg Score</div><div class="kpi-value"><?php echo $overview['avg_score'] !== null ? $overview['avg_score'] : '—'; ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Total Time</div><div class="kpi-value"><?php echo $overview['total_hours']; ?>h</div></div>
                <div class="kpi-card"><div class="kpi-label">Courses</div><div class="kpi-value"><?php echo (int)$overview['course_count']; ?></div></div>
            </div>

            <!-- Trend chart -->
            <div class="card">
                <div class="card-header"><h2>Activity Trend</h2><span class="small muted">Monthly</span></div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="trendChart"></canvas></div>
                </div>
            </div>

            <!-- Department comparison -->
            <div class="card">
                <div class="card-header"><h2>Department Comparison</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($departments)): ?>
                        <div class="empty">No department data yet. Departments appear once learners have attempted courses.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Department</th><th>Learners</th><th>Enrollments</th><th>Completed</th><th>Completion %</th><th>Avg Progress</th><th>Avg Score</th><th>Total Time</th></tr></thead>
                            <tbody>
                            <?php foreach ($departments as $d): ?>
                                <?php $completionPct = $d['enrollments'] > 0 ? round(($d['completions'] / $d['enrollments']) * 100, 1) : 0; ?>
                                <tr>
                                    <td><a href="<?php echo buildUrl('analytics/organization?dept=' . urlencode($d['department'])); ?>" style="color:var(--primary); font-weight:700; text-decoration:none;"><?php echo htmlspecialchars($d['department']); ?></a></td>
                                    <td><?php echo (int)$d['learners']; ?></td>
                                    <td><?php echo (int)$d['enrollments']; ?></td>
                                    <td><?php echo (int)$d['completions']; ?></td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div class="progress-bar-bg" style="flex:1;"><div class="progress-bar-fill" style="width:<?php echo $completionPct; ?>%;"></div></div>
                                            <span class="small" style="font-weight:700;"><?php echo $completionPct; ?>%</span>
                                        </div>
                                    </td>
                                    <td><?php echo $d['avg_progress'] !== null ? $d['avg_progress'] . '%' : '—'; ?></td>
                                    <td><?php echo $d['avg_score'] !== null ? $d['avg_score'] : '—'; ?></td>
                                    <td class="small muted"><?php echo $formatter((int)$d['total_seconds']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedDept !== ''): ?>
                <!-- Department drill-down -->
                <div class="card">
                    <div class="card-header">
                        <h2>Department: <?php echo htmlspecialchars($selectedDept); ?></h2>
                        <div class="dept-nav">
                            <?php foreach ($deptList as $dept): ?>
                                <a href="<?php echo buildUrl('analytics/organization?dept=' . urlencode($dept)); ?>" class="<?php echo $dept === $selectedDept ? 'active' : ''; ?>"><?php echo htmlspecialchars($dept); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($deptRates)): ?>
                            <div class="empty">No completion data for this department yet.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>Course</th><th>Learners</th><th>Completed</th><th>Passed</th><th>Completion %</th><th>Avg Score</th></tr></thead>
                                <tbody>
                                <?php foreach ($deptRates as $r): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($r['course_title']); ?></strong></td>
                                        <td><?php echo (int)$r['total_learners']; ?></td>
                                        <td><?php echo (int)$r['completed_learners']; ?></td>
                                        <td><?php echo (int)$r['passed_learners']; ?></td>
                                        <td><?php echo $r['total_learners'] > 0 ? round(($r['completed_learners'] / $r['total_learners']) * 100, 1) . '%' : '—'; ?></td>
                                        <td><?php echo $r['avg_score'] !== null ? $r['avg_score'] : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Knowledge gaps -->
                <div class="card">
                    <div class="card-header"><h2>Knowledge Gaps (Lowest Accuracy Questions)</h2></div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($deptGaps)): ?>
                            <div class="empty">No question-level data for this department.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>Course</th><th>Question</th><th>Accuracy</th><th>Answers</th><th>Avg Latency</th></tr></thead>
                                <tbody>
                                <?php foreach ($deptGaps as $g): ?>
                                    <tr>
                                        <td class="small muted"><?php echo htmlspecialchars($g['course_title']); ?></td>
                                        <td><?php echo htmlspecialchars($g['description'] ?: $g['interaction_id']); ?></td>
                                        <td>
                                            <?php
                                                $acc = (float)$g['accuracy_pct'];
                                                $chip = $acc < 50 ? 'chip-low' : ($acc < 75 ? 'chip-med' : 'chip-high');
                                            ?>
                                            <span class="chip <?php echo $chip; ?>"><?php echo $acc; ?>%</span>
                                        </td>
                                        <td><?php echo (int)$g['total_answers']; ?></td>
                                        <td class="small muted"><?php echo $g['avg_latency_seconds'] !== null ? round($g['avg_latency_seconds'], 1) . 's' : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- At-risk learners -->
            <div class="card">
                <div class="card-header"><h2>At-Risk Learners (Below 60% Progress)</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($atRisk)): ?>
                        <div class="empty">No learners below the progress threshold. Good job!</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Learner</th><th>Department</th><th>Course</th><th>Progress</th><th>Status</th><th>Last Access</th></tr></thead>
                            <tbody>
                            <?php foreach ($atRisk as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></strong>
                                        <div class="small muted"><?php echo htmlspecialchars($r['email']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['department'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($r['course_title']); ?></td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div class="progress-bar-bg" style="flex:1;"><div class="progress-bar-fill" style="width:<?php echo (float)$r['progress_pct']; ?>%;"></div></div>
                                            <span class="small" style="font-weight:700;"><?php echo $r['progress_pct']; ?>%</span>
                                        </div>
                                    </td>
                                    <td><span class="badge badge-warning"><?php echo htmlspecialchars($r['lesson_status']); ?></span></td>
                                    <td class="small muted"><?php echo $r['last_accessed_at'] ? date('M j, Y', strtotime($r['last_accessed_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Daily Activity -->
            <div class="card">
                <div class="card-header"><h2>Daily Activity</h2><span class="small muted">Last 30 days</span></div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="dailyActivityChart"></canvas></div>
                </div>
            </div>

            <!-- Completion Funnel -->
            <div class="card">
                <div class="card-header"><h2>Completion Funnel</h2></div>
                <div class="card-body">
                    <?php $funnelTotal = 0; foreach ($funnel as $f) { $funnelTotal += $f['count']; } ?>
                    <?php if ($funnelTotal === 0): ?>
                        <div class="empty">No enrollment data yet.</div>
                    <?php else: ?>
                        <?php foreach ($funnel as $i => $f): ?>
                            <?php
                                $stagePct = $funnelTotal > 0 ? round(($f['count'] / $funnelTotal) * 100, 1) : 0;
                                $prevCount = $i > 0 ? $funnel[$i - 1]['count'] : $f['count'];
                                $convPct = $prevCount > 0 ? round(($f['count'] / $prevCount) * 100, 1) : 0;
                            ?>
                            <div style="margin-bottom:18px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <strong style="font-size:0.9rem;"><?php echo htmlspecialchars($f['stage']); ?></strong>
                                    <span class="small muted"><?php echo (int)$f['count']; ?> (<?php echo $stagePct; ?>% of enrollments)</span>
                                </div>
                                <div class="progress-bar-bg" style="height:14px;">
                                    <div class="progress-bar-fill" style="width:<?php echo $stagePct; ?>%; background:<?php echo $f['color']; ?>;"></div>
                                </div>
                                <?php if ($i > 0): ?>
                                    <div class="small muted" style="margin-top:3px;"><?php echo $convPct; ?>% convert from <?php echo htmlspecialchars($funnel[$i - 1]['stage']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Question Performance Heatmap -->
            <div class="card">
                <div class="card-header"><h2>Question Performance Heatmap</h2><span class="small muted">Weakest questions first</span></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($questionHeatmap)): ?>
                        <div class="empty">No question data yet. Questions appear once learners answer assessments.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Course</th><th>Question</th><th>Type</th><th>Answers</th><th>Learners</th><th>Accuracy</th><th>Avg Latency</th></tr></thead>
                            <tbody>
                            <?php foreach ($questionHeatmap as $q): ?>
                                <?php
                                    $acc = (float)$q['accuracy_pct'];
                                    $heatBg = $acc >= 70 ? '#dcfce7' : ($acc >= 40 ? '#fef3c7' : '#fee2e2');
                                    $heatFg = $acc >= 70 ? '#166534' : ($acc >= 40 ? '#92400e' : '#991b1b');
                                ?>
                                <tr>
                                    <td style="max-width:200px;"><?php echo htmlspecialchars($q['course_title']); ?></td>
                                    <td style="max-width:280px;">
                                        <div title="<?php echo htmlspecialchars($q['interaction_id']); ?>"><?php echo htmlspecialchars($q['question_text']); ?></div>
                                    </td>
                                    <td><span class="chip chip-med"><?php echo htmlspecialchars($q['interaction_type'] ?: '—'); ?></span></td>
                                    <td><?php echo (int)$q['total_answers']; ?></td>
                                    <td><?php echo (int)$q['learners_attempted']; ?></td>
                                    <td>
                                        <span class="badge" style="background:<?php echo $heatBg; ?>; color:<?php echo $heatFg; ?>;"><?php echo $acc; ?>%</span>
                                    </td>
                                    <td class="small muted"><?php echo $q['avg_latency_seconds'] !== null ? $q['avg_latency_seconds'] . 's' : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Slide-by-Slide Time Breakdown -->
            <div class="card">
                <div class="card-header"><h2>Slide Time Breakdown</h2><span class="small muted"><?php echo (int)$slideBreakdown['parsed_attempts']; ?> attempts parsed from suspend_data</span></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($slideBreakdown['has_data']) || empty($slideBreakdown['summary'])): ?>
                        <div class="empty">Slide timing is embedded in course suspend_data. Data appears once learners use courses built by Rise 360 that expose slide timings.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Slide / Section</th><th>Total Time</th><th>Learners</th><th>Avg per Learner</th></tr></thead>
                            <tbody>
                            <?php foreach ($slideBreakdown['summary'] as $s): ?>
                                <?php
                                    $mins = round($s['total_seconds'] / 60, 1);
                                    $avgPerLearner = $s['learner_count'] > 0 ? round($s['total_seconds'] / $s['learner_count'], 1) : 0;
                                ?>
                                <tr>
                                    <td style="max-width:400px;"><?php echo htmlspecialchars($s['label']); ?></td>
                                    <td><?php echo $mins; ?>m</td>
                                    <td><?php echo (int)$s['learner_count']; ?></td>
                                    <td class="small muted"><?php echo $avgPerLearner; ?>s / learner</td>
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
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const labels = <?php echo json_encode(array_column($trend, 'bucket')); ?>;
        const attempts = <?php echo json_encode(array_map('intval', array_column($trend, 'attempts'))); ?>;
        const completions = <?php echo json_encode(array_map('intval', array_column($trend, 'completions'))); ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Attempts',
                        data: attempts,
                        borderColor: '#82ACD6',
                        backgroundColor: 'rgba(0, 128, 142,0.15)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#82ACD6'
                    },
                    {
                        label: 'Completions',
                        data: completions,
                        borderColor: '#00808E',
                        backgroundColor: 'rgba(0,128,142,0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#00808E'
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#5f6f6a' } },
                    y: { grid: { color: '#d9e3df' }, ticks: { color: '#5f6f6a', beginAtZero: true } }
                },
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });

        // —— Daily Activity chart ——
        const dailyCtx = document.getElementById('dailyActivityChart').getContext('2d');
        const dailyLabels = <?php echo json_encode(array_column($dailyActivity, 'label')); ?>;
        const dailyUsers = <?php echo json_encode(array_map('intval', array_column($dailyActivity, 'active_users'))); ?>;
        const dailySessions = <?php echo json_encode(array_map('intval', array_column($dailyActivity, 'sessions'))); ?>;

        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        label: 'Active Learners',
                        data: dailyUsers,
                        backgroundColor: 'rgba(0, 128, 142, 0.55)',
                        borderColor: '#82ACD6',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Sessions',
                        data: dailySessions,
                        backgroundColor: 'rgba(0, 128, 142, 0.4)',
                        borderColor: '#00808E',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: false, grid: { display: false }, ticks: { color: '#5f6f6a', maxRotation: 45, autoSkip: true, maxTicksLimit: 15 } },
                    y: { stacked: false, grid: { color: '#d9e3df' }, ticks: { color: '#5f6f6a', beginAtZero: true, precision: 0 } }
                },
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    </script>
</body>
</html>