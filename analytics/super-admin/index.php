<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * ADVANCED ANALYTICS — Super Admin (Phase 4)
 *
 * Cross-organization view: org comparison, global search across all
 * orgs/courses/learners, and per-org drill-down.
 *
 * Access: Super Admin only.
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/analytics.php';

requireLogin();
requireSuperAdmin();

$pdo = getDbConnection();
$orgs = getOrgListForAnalytics();

// Filters
$filterOrg = (int)($_GET['org'] ?? 0);
$filterSearch = trim($_GET['q'] ?? '');
$filterDept = trim($_GET['dept'] ?? '');

$searchResults = [];
if ($filterSearch !== '' || $filterOrg > 0 || $filterDept !== '') {
    $searchResults = searchAcrossOrgs([
        'org_id' => $filterOrg > 0 ? $filterOrg : 0,
        'department' => $filterDept,
        'search' => $filterSearch,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cross-Org Analytics | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        :root { --accent: #60B49A; --radius: 16px; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; }
        .content-max-width { max-width: 1200px; margin: 0 auto; }

        .header-box { margin-bottom: 32px; }
        .header-box h1 { font-size: 2rem; font-weight: 800; margin: 0; }
        .header-box .sub { color: var(--text-muted); margin-top: 6px; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 28px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); }
        .card-header h2 { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .card-body { padding: 20px 24px; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-bar input, .filter-bar select {
            padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px;
            font-size: 0.88rem; font-family: inherit; background: #fff; min-width: 180px;
        }
        .btn-filter { background: var(--primary); color: #fff; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.88rem; }
        .btn-filter:hover { background: var(--primary-hover); }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 14px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 14px 20px; border-bottom: 1px solid var(--border); font-size: 0.88rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge-neutral { background: #f1f5f9; color: #64748b; }

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
                <h1>Cross-Organization Analytics</h1>
                <div class="sub">Compare training performance across all organizations.</div>
            </div>

            <!-- Org comparison -->
            <div class="card">
                <div class="card-header"><h2>Organization Comparison</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($orgs)): ?>
                        <div class="empty">No organizations with tracking data yet.</div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Organization</th><th>Learners</th><th>Completions</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($orgs as $o): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($o['name']); ?></strong></td>
                                    <td><?php echo (int)$o['learners']; ?></td>
                                    <td><?php echo (int)$o['completions']; ?></td>
                                    <td style="text-align:right;">
                                        <a href="<?php echo buildUrl('analytics/super-admin') . '/?org=' . (int)$o['id']; ?>" style="color:var(--primary); font-size:0.82rem; font-weight:700; text-decoration:none;">Drill down â†’</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Global search -->
            <div class="card">
                <div class="card-header"><h2>Global Search</h2></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar" action="<?php echo buildUrl('analytics/super-admin') . '/'; ?>">
                        <input type="text" name="q" placeholder="Search learner or course…" value="<?php echo htmlspecialchars($filterSearch); ?>">
                        <select name="org">
                            <option value="0">All Organizations</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?php echo (int)$o['id']; ?>" <?php echo $filterOrg === (int)$o['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="dept" placeholder="Department (optional)" value="<?php echo htmlspecialchars($filterDept); ?>">
                        <button type="submit" class="btn-filter">Search</button>
                    </form>
                </div>
                <?php if ($filterSearch !== '' || $filterOrg > 0 || $filterDept !== ''): ?>
                    <div class="card-body" style="padding-top:0;">
                        <?php if (empty($searchResults)): ?>
                            <div class="empty">No results found.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>Learner</th><th>Org</th><th>Department</th><th>Course</th><th>Status</th><th>Score</th><th>Progress</th><th>Last Access</th></tr></thead>
                                <tbody>
                                <?php foreach ($searchResults as $r): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?></strong>
                                            <div class="small muted"><?php echo htmlspecialchars($r['email']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($r['org_name'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($r['department'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($r['course_title']); ?></td>
                                        <td><span class="badge badge-neutral"><?php echo htmlspecialchars($r['lesson_status']); ?></span></td>
                                        <td><?php echo $r['score_raw'] !== null ? $r['score_raw'] : '—'; ?></td>
                                        <td><?php echo $r['progress_measure'] !== null ? round((float)$r['progress_measure'] * 100, 1) . '%' : '—'; ?></td>
                                        <td class="small muted"><?php echo $r['last_accessed_at'] ? date('M j, Y', strtotime($r['last_accessed_at'])) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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