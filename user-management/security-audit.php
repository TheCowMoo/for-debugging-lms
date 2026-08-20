<?php
/**
 * PURSUIT PATHWAYS LMS
 * SECURITY AUDIT LOG (user-management/security-audit.php)
 *
 * Viewable, filterable history of login attempts, lockouts, MFA events, and
 * administrative actions. Org admins see events for their org's users;
 * super admins see everything.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin();
requireAdmin();
ensureSecurityTables();
requireMfaComplete();

$pdo = getDbConnection();

$eventType  = trim($_GET['type'] ?? '');
$severity   = trim($_GET['severity'] ?? '');
$actor      = trim($_GET['actor'] ?? '');
$target     = trim($_GET['target'] ?? '');
$ip         = trim($_GET['ip'] ?? '');
$dateFrom   = trim($_GET['from'] ?? '');
$dateTo     = trim($_GET['to'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 50;

$where = [];
$params = [];

if ($eventType !== '') {
    $where[] = 'se.event_type = ?';
    $params[] = $eventType;
}
if ($severity !== '') {
    $where[] = 'se.severity = ?';
    $params[] = $severity;
}
if ($actor !== '') {
    $where[] = 'se.actor_email LIKE ?';
    $params[] = '%' . $actor . '%';
}
if ($target !== '') {
    $where[] = 'se.target_email LIKE ?';
    $params[] = '%' . $target . '%';
}
if ($ip !== '') {
    $where[] = 'se.actor_ip LIKE ?';
    $params[] = '%' . $ip . '%';
}
if ($dateFrom !== '') {
    $where[] = 'se.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'se.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

// Org scoping: org admins only see events touching their org's users.
if (!isSuperAdmin() && getOrgId() !== null) {
    $orgId = getOrgId();
    $where[] = "(se.actor_user_id IN (SELECT id FROM users WHERE organization_id = ?)
                 OR se.target_user_id IN (SELECT id FROM users WHERE organization_id = ?))";
    $params[] = $orgId;
    $params[] = $orgId;
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM security_events se" . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('[AUDIT] count failed: ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$events = [];
try {
    $sql = "SELECT se.id, se.event_type, se.severity, se.actor_user_id, se.actor_email, se.actor_ip,
                   se.target_user_id, se.target_email, se.detail, se.created_at
            FROM security_events se" . $whereSql . "
            ORDER BY se.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($events as &$e) {
        $decoded = json_decode((string)($e['detail'] ?? ''), true);
        $e['detail'] = is_array($decoded) ? $decoded : [];
    }
    unset($e);
} catch (PDOException $e) {
    error_log('[AUDIT] query failed: ' . $e->getMessage());
}

$severityBadge = ['info' => 'badge-info', 'warning' => 'badge-warning', 'critical' => 'badge-critical'];
$eventTypes = [];
try {
    foreach ($pdo->query("SELECT DISTINCT event_type FROM security_events ORDER BY event_type") as $row) {
        $eventTypes[] = $row['event_type'];
    }
} catch (PDOException $e) {}

$auditUrl = buildUrl('user-management/security-audit.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Audit Log | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        :root { --primary: #82ACD6; --primary-hover: #00808E; --bg: #D3E2F3; --text: #232D63; --border: #BBBDB7; --radius: 14px; --sidebar-width: 280px; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 40px 48px; }
        h1 { margin: 0 0 4px; }
        .sub { color: #5f6f6a; font-size: 0.9rem; margin-bottom: 24px; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 18px 22px; border-bottom: 1px solid var(--border); font-weight: 700; }
        .card-body { padding: 20px 22px; }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filters input, .filters select { padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.85rem; }
        .filters label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; color: #5f6f6a; }
        .btn { background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 0.85rem; }
        .btn:hover { background: var(--primary-hover); }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th { text-align: left; padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid var(--border); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #5f6f6a; }
        td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-critical { background: #fee2e2; color: #991b1b; }
        .mono { font-family: 'Courier New', monospace; font-size: 0.78rem; color: #475569; word-break: break-all; }
        .detail-json { color: #5f6f6a; font-size: 0.78rem; }
        .pager { display: flex; gap: 10px; align-items: center; justify-content: flex-end; padding: 14px 22px; }
        .empty { padding: 40px; text-align: center; color: #5f6f6a; }
        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 16px 16px; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main>
        <h1>Security Audit Log</h1>
        <p class="sub">Login attempts, lockouts, MFA events, and administrative actions.</p>

        <div class="card">
            <div class="card-body">
                <form method="GET" class="filters">
                    <div><label>Event type</label>
                        <select name="type">
                            <option value="">All</option>
                            <?php foreach ($eventTypes as $et): ?>
                                <option value="<?php echo htmlspecialchars($et); ?>" <?php echo $eventType === $et ? 'selected' : ''; ?>><?php echo htmlspecialchars($et); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label>Severity</label>
                        <select name="severity">
                            <option value="">All</option>
                            <option value="info" <?php echo $severity === 'info' ? 'selected' : ''; ?>>Info</option>
                            <option value="warning" <?php echo $severity === 'warning' ? 'selected' : ''; ?>>Warning</option>
                            <option value="critical" <?php echo $severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select></div>
                    <div><label>Actor email</label><input type="text" name="actor" value="<?php echo htmlspecialchars($actor); ?>" placeholder="contains…"></div>
                    <div><label>Target email</label><input type="text" name="target" value="<?php echo htmlspecialchars($target); ?>" placeholder="contains…"></div>
                    <div><label>IP</label><input type="text" name="ip" value="<?php echo htmlspecialchars($ip); ?>" placeholder="contains…"></div>
                    <div><label>From</label><input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
                    <div><label>To</label><input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="<?php echo $auditUrl; ?>" class="btn" style="background:#f1f5f9;color:var(--text);">Reset</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Events (<?php echo $total; ?>)</div>
            <?php if (empty($events)): ?>
                <div class="empty">No security events match the current filters.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th><th>Event</th><th>Severity</th><th>Actor</th><th>Target</th><th>IP</th><th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars((string)$e['created_at']); ?></td>
                            <td><strong><?php echo htmlspecialchars((string)$e['event_type']); ?></strong></td>
                            <td><span class="badge <?php echo $severityBadge[$e['severity']] ?? 'badge-info'; ?>"><?php echo htmlspecialchars((string)$e['severity']); ?></span></td>
                            <td><?php echo htmlspecialchars((string)($e['actor_email'] !== '' ? $e['actor_email'] : '—')); ?></td>
                            <td><?php echo htmlspecialchars((string)($e['target_email'] !== '' ? $e['target_email'] : '—')); ?></td>
                            <td class="mono"><?php echo htmlspecialchars((string)$e['actor_ip']); ?></td>
                            <td class="detail-json">
                                <?php if (!empty($e['detail'])): ?>
                                    <details><summary>view</summary><pre style="white-space:pre-wrap;word-break:break-all;font-size:0.74rem;"><?php echo htmlspecialchars(json_encode($e['detail'], JSON_PRETTY_PRINT)); ?></pre></details>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pager">
                    <?php if ($page > 1): ?>
                        <a class="btn" style="background:#f1f5f9;color:var(--text);" href="<?php echo $auditUrl; ?>?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($eventType); ?>&severity=<?php echo urlencode($severity); ?>&actor=<?php echo urlencode($actor); ?>&target=<?php echo urlencode($target); ?>&ip=<?php echo urlencode($ip); ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">← Prev</a>
                    <?php endif; ?>
                    <span style="font-size:0.82rem;color:#5f6f6a;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn" style="background:#f1f5f9;color:var(--text);" href="<?php echo $auditUrl; ?>?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($eventType); ?>&severity=<?php echo urlencode($severity); ?>&actor=<?php echo urlencode($actor); ?>&target=<?php echo urlencode($target); ?>&ip=<?php echo urlencode($ip); ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
