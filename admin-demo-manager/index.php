<?php
/**
 * ADMIN DEMO MANAGER
 * Create and manage multi-use demo invite links.
 * Super admin only.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();
requireSuperAdmin();

$pdo = getDbConnection();
ensureInviteTokensTable();
ensureOrganizationsTable();
ensureUserColumns();

$message = '';
$message_type = '';

// Handle POST: Generate new demo invite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    if (!checkRegistrationRateLimit('demo-create', 5, 3600)) {
        $message = "Too many demo links created recently. Please try again later.";
        $message_type = "error";
    } else {
    $courseId     = trim($_POST['course_id'] ?? '');
    $maxUses      = (int)($_POST['max_uses'] ?? 25);
    $department   = trim($_POST['department'] ?? '');
    $expiresDays  = max(1, (int)($_POST['expires_days'] ?? 7));
    $orgId        = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;

    // Collect dynamic notify emails
    $notifyEmails = [];
    for ($i = 0; $i < $maxUses; $i++) {
        $emailField = trim($_POST['notify_email_' . $i] ?? '');
        if ($emailField !== '') {
            $notifyEmails[] = $emailField;
        }
    }
    $notifyEmailsJson = !empty($notifyEmails) ? json_encode($notifyEmails) : null;

    if ($courseId === '') {
        $message = "Please select a course.";
        $message_type = "error";
    } else {
        // Generate token
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO invite_tokens (token, email, course_id, department, is_demo, max_uses, notify_emails, use_count, created_by, organization_id, expires_at)
            VALUES (?, NULL, ?, ?, 1, ?, ?, 0, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        $stmt->execute([$token, $courseId, $department ?: null, $maxUses, $notifyEmailsJson, $_SESSION['user_id'], $orgId, $expiresDays]);

        $inviteLink = buildUrl('signup/?token=' . urlencode($token));
        // Resolve the course name for the notification email
        $courseTitle = $courseId;
        if (ctype_digit((string)$courseId)) {
            $tStmt = $pdo->prepare('SELECT title FROM scorm_packages WHERE id = ?');
            $tStmt->execute([(int)$courseId]);
            $tTitle = $tStmt->fetchColumn();
            if ($tTitle) { $courseTitle = (string)$tTitle; }
        } else {
            $asset = getCourseAsset((string)$courseId);
            if ($asset && !empty($asset['course_title'])) { $courseTitle = $asset['course_title']; }
        }

        // Send initial notification email if the first slot has an email
        $firstEmail = $notifyEmails[0] ?? null;
        if ($firstEmail) {
            $siteName = getSiteName();
            $subject = "Demo Invite Link - $siteName";
            $body = "
                <h2>Demo Course Invite</h2>
                <p>A demo invite link has been created for the course: <strong>" . htmlspecialchars($courseTitle) . "</strong></p>
                <p>Click on this link to register and access the course:</p>
                <div style='text-align:center;margin:30px 0;'>
                    <a href='$inviteLink' style='background:#006F53;color:#fff;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;'>Open Demo Link</a>
                </div>
                <p style='color:#5f6f6a;font-size:13px;'>Expires in $expiresDays days</p>
                <p style='color:#5f6f6a;font-size:12px;'>Link: $inviteLink</p>";
            require_once __DIR__ . '/../signup/ghl_helper.php';
            $emailSent = sendGHLPortalEmail($firstEmail, 'Admin', $subject, $body);
            $emailCount = count($notifyEmails);
            $message = "Demo invite link created!" . ($emailSent ? ' Email sent to slot #1 (' . htmlspecialchars($firstEmail) . ').' : '') . ($emailCount > 1 ? " {$emailCount} notification slots configured." : '');
        } else {
        $message = "Demo invite link created!";
        }
        $message_type = "success";
    }
    }
}

// Handle POST: Revoke a demo invite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_revoke'])) {
    $tokenId = (int)($_POST['token_id'] ?? 0);
    if ($tokenId) {
        $pdo->prepare("UPDATE invite_tokens SET expires_at = NOW() WHERE id = ? AND is_demo = 1")->execute([$tokenId]);
        $message = "Demo invite revoked.";
        $message_type = "success";
    }
}

// Fetch SCORM courses for dropdown
$courses = fetchScormCourses();
if (!is_array($courses)) {
    $courses = [];
}
// Build a course id => title lookup for the campaigns table display
$courseTitleMap = [];
foreach ($courses as $c) {
    $cid = (string)($c['id'] ?? $c['courseId'] ?? '');
    $ctitle = $c['title'] ?? $c['name'] ?? '';
    if ($cid !== '' && $ctitle !== '') {
        $courseTitleMap[$cid] = $ctitle;
    }
}
// Also include catalog titles for string course ids (legacy)
foreach (getAllCourseAssets() as $a) {
    if (!empty($a['course_id']) && !empty($a['course_title'])) {
        $courseTitleMap[(string)$a['course_id']] = (string)$a['course_title'];
    }
}

// Fetch organizations for dropdown
$orgs = $pdo->query("SELECT id, name FROM organizations WHERE status = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all demo invites with stats
$demoInvites = $pdo->query("
    SELECT 
        t.*,
        u.email AS creator_email
    FROM invite_tokens t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.is_demo = 1
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo Manager | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/main.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; }
        .content-max { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 1.8rem; margin: 0 0 8px; }
        .subtitle { color: var(--text-muted); margin-bottom: 32px; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 32px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); margin-bottom: 28px; }
        .card-title { font-size: 1.15rem; font-weight: 700; margin: 0 0 20px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { text-align: left; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text-main); }
        .form-group input, .form-group select { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; font-family: inherit; background: #fff; color: var(--text-main); }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0, 111, 83, 0.2); }
        .form-full { grid-column: 1 / -1; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 0.9rem; font-family: inherit; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-outline { background: transparent; color: var(--text-main); border: 1px solid var(--border); }
        .btn-outline:hover { background: var(--bg-body); }

        .msg { padding: 14px; border-radius: 10px; margin-bottom: 20px; }
        .msg.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .msg.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); padding: 12px 8px; border-bottom: 1px solid var(--border); }
        td { padding: 14px 8px; border-bottom: 1px solid var(--border); font-size: 0.92rem; }
        .usage-bar { display: flex; align-items: center; gap: 8px; }
        .usage-fill { height: 8px; border-radius: 999px; background: var(--border); flex: 1; max-width: 100px; }
        .usage-fill-inner { height: 100%; border-radius: 999px; background: var(--primary); }
        .pill { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .pill-active { background: #dcfce7; color: #166534; }
        .pill-expired { background: #fef3c7; color: #92400e; }
        .pill-full { background: #fee2e2; color: #991b1b; }
        .copy-btn { background: none; border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 0.8rem; color: var(--text-muted); }
        .copy-btn:hover { background: var(--bg-body); }
        .signup-list { margin-top: 16px; background: var(--bg-body); border-radius: 12px; padding: 16px; display: none; }
        .signup-list.open { display: block; }
        .signup-list table { font-size: 0.85rem; }
        .signup-list th { font-size: 0.7rem; }
        .empty-state { color: var(--text-muted); text-align: center; padding: 40px; }

        #notify-emails-container { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px; }
        #notify-emails-container .form-group { margin-bottom: 0; }
        
        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .form-grid { grid-template-columns: 1fr; }
            #notify-emails-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main>
        <div class="content-max">
            <h1>🎯 Demo Campaign Manager</h1>
            <p class="subtitle">Create shareable demo invite links. Track signups per link.</p>

            <?php if ($message): ?>
                <div class="msg <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <!-- Create Demo Link -->
            <div class="card">
                <h2 class="card-title">Create New Demo Campaign</h2>
                <form method="POST" id="demo-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Course *</label>
                            <select name="course_id" required>
                                <option value="">— Select Course —</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course['id'] ?? $course['courseId'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(($course['title'] ?? $course['name'] ?? 'Untitled') . ' (ID: ' . ($course['id'] ?? $course['courseId'] ?? '') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Max Signups</label>
                            <input type="number" name="max_uses" id="max_uses" value="25" min="1" max="100" onchange="updateNotifyEmails()">
                        </div>
                        <div class="form-group">
                            <label>Assign Organization</label>
                            <select name="organization_id">
                                <option value="">— None (Global) —</option>
                                <?php foreach ($orgs as $org): ?>
                                    <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Department Tag (optional)</label>
                            <input type="text" name="department" placeholder="e.g. External Reviewers">
                        </div>
                        <div class="form-group">
                            <label>Expires In (days)</label>
                            <input type="number" name="expires_days" value="7" min="1" max="365">
                        </div>
                        <div class="form-full" style="margin-top:4px;">
                            <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:8px; color:var(--text-main);">
                                Notification Emails <span style="font-weight:400;color:var(--text-muted);font-size:0.8rem;">(one per signup slot — changes with Max Signups above)</span>
                            </label>
                            <div id="notify-emails-container">
                                <?php for ($i = 0; $i < 25; $i++): ?>
                                <div class="form-group notify-slot" id="notify-slot-<?php echo $i; ?>" style="<?php echo $i >= 25 ? 'display:none;' : ''; ?>">
                                    <input type="email" name="notify_email_<?php echo $i; ?>" placeholder="Signup #<?php echo $i + 1; ?> notification email">
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="form-group form-full" style="margin-top:8px;">
                            <button type="submit" name="action_create" class="btn btn-primary">Generate Demo Link</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Active Demo Links -->
            <div class="card">
                <h2 class="card-title">Active Demo Campaigns</h2>

                <?php if (empty($demoInvites)): ?>
                    <div class="empty-state">No demo campaigns created yet.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Link</th>
                                <th>Usage</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($demoInvites as $invite): 
                                $isExpired    = !empty($invite['expires_at']) && strtotime($invite['expires_at']) < time();
                                $isFull       = !empty($invite['max_uses']) && (int)$invite['use_count'] >= (int)$invite['max_uses'];
                                $isActive     = !$isExpired && !$isFull;
                                $usagePct     = !empty($invite['max_uses']) ? round(((int)$invite['use_count'] / (int)$invite['max_uses']) * 100) : 0;
                                $inviteLink   = buildUrl('signup/?token=' . urlencode($invite['token']));
                                $notifyEmails = !empty($invite['notify_emails']) ? json_decode($invite['notify_emails'], true) : [];
                            ?>
                            <tr>
                                <td>
                                    <?php
                                        $inviteCid = (string)($invite['course_id'] ?? '');
                                        $inviteTitle = $inviteCid !== '' ? ($courseTitleMap[$inviteCid] ?? '') : '';
                                        $courseLabel = $inviteCid !== ''
                                            ? (($inviteTitle !== '') ? $inviteTitle . ' (ID: ' . $inviteCid . ')' : $inviteCid)
                                            : '—';
                                    ?>
                                    <strong><?php echo htmlspecialchars($courseLabel); ?></strong>
                                    <?php if ($invite['department']): ?>
                                        <br><span style="font-size:0.8rem;color:var(--text-muted);"><?php echo htmlspecialchars($invite['department']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" id="link-<?php echo $invite['id']; ?>" 
                                           value="<?php echo htmlspecialchars($inviteLink); ?>" 
                                           readonly style="width:200px;font-size:0.78rem;padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-body);">
                                    <button class="copy-btn" onclick="copyLink(<?php echo $invite['id']; ?>)">Copy</button>
                                </td>
                                <td>
                                    <div class="usage-bar">
                                        <span><?php echo (int)$invite['use_count']; ?><?php echo $invite['max_uses'] ? '/' . (int)$invite['max_uses'] : ''; ?></span>
                                        <?php if ($invite['max_uses']): ?>
                                            <div class="usage-fill">
                                                <div class="usage-fill-inner" style="width: <?php echo min(100, $usagePct); ?>%;"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($notifyEmails)): ?>
                                        <div style="font-size:0.7rem;color:var(--text-muted);margin-top:4px;"><?php echo count($notifyEmails); ?> notification<?php echo count($notifyEmails) !== 1 ? 's' : ''; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="pill pill-active">Active</span>
                                    <?php elseif ($isFull): ?>
                                        <span class="pill pill-full">Full</span>
                                    <?php else: ?>
                                        <span class="pill pill-expired">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($invite['creator_email'] ?: '—'); ?></td>
                                <td style="font-size:0.85rem;"><?php echo date('M d, Y', strtotime($invite['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-outline" style="padding:6px 12px;font-size:0.8rem;" onclick="toggleSignups(<?php echo $invite['id']; ?>)">Signups</button>
                                    <?php if ($isActive): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="token_id" value="<?php echo $invite['id']; ?>">
                                        <button type="submit" name="action_revoke" class="btn btn-danger" style="padding:6px 12px;font-size:0.8rem;" onclick="return confirm('Revoke this demo invite? Existing users will not be affected.');">Revoke</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr id="signups-<?php echo $invite['id']; ?>" style="display:none;">
                                <td colspan="7">
                                    <?php
                                    $signups = $pdo->prepare("SELECT id, email, first_name, last_name, created_at, is_verified FROM users WHERE invite_token_id = ? ORDER BY created_at DESC LIMIT 100");
                                    $signups->execute([(int)$invite['id']]);
                                    $signupUsers = $signups->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    <div class="signup-list open">
                                        <?php if (empty($signupUsers)): ?>
                                            <p style="color:var(--text-muted);margin:0;">No signups yet for this campaign.</p>
                                        <?php else: ?>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Email</th>
                                                        <th>Name</th>
                                                        <th>Signed Up</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($signupUsers as $su): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($su['email']); ?></td>
                                                            <td><?php echo htmlspecialchars(trim(($su['first_name'] ?? '') . ' ' . ($su['last_name'] ?? ''))); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($su['created_at'])); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
    function updateNotifyEmails() {
        var max = parseInt(document.getElementById('max_uses').value) || 25;
        for (var i = 0; i < 100; i++) {
            var slot = document.getElementById('notify-slot-' + i);
            if (slot) {
                slot.style.display = i < max ? '' : 'none';
            }
        }
    }
    // Initialize on load
    updateNotifyEmails();

    function copyLink(id) {
        var input = document.getElementById('link-' + id);
        input.select();
        document.execCommand('copy');
        var btn = input.nextElementSibling;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
    }
    function toggleSignups(id) {
        var row = document.getElementById('signups-' + id);
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('active');
    }
    </script>
</body>
</html>
