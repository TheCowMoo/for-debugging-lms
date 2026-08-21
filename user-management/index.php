<?php
/**
 * PURSUIT PATHWAYS LMS
 * USER MANAGEMENT - FULL SYSTEM
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin();

if (!isAdmin()) {
    redirectTo('dashboard/');
}

ensureSecurityTables();
requireMfaComplete();

$userRole = $_SESSION['user_role'] ?? 'admin';

require_once(__DIR__ . '/../signup/ghl_helper.php');

$pdo = getDbConnection();
ensureUserColumns();
ensureSegmentOptionsTable();

$segmentCategories = [
    'department' => 'Department',
    'office' => 'Office',
    'region' => 'Region',
    'role_type' => 'Role Type',
    'supervisor' => 'Supervisor',
];

$segmentOptions = [];
try {
    $segmentStmt = $pdo->query("SELECT id, category, option_value FROM segment_options ORDER BY category, option_value");
    while ($row = $segmentStmt->fetch(PDO::FETCH_ASSOC)) {
        $segmentOptions[$row['category']][] = [
            'id' => $row['id'],
            'value' => $row['option_value'],
        ];
    }
} catch (PDOException $e) {
    error_log('[DB] Failed to load segment options: ' . $e->getMessage());
}

$invite_status = "";
$user_update_status = "";
$enroll_status = "";

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_invite'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $invite_status = "csrf_error";
        } elseif (!checkRegistrationRateLimit('invite', 10, 3600)) {
            $invite_status = "rate_limited";
        } else {
        $email = filter_var($_POST['invite_email'], FILTER_SANITIZE_EMAIL);
        $fName = htmlspecialchars($_POST['first_name']);
        $lName = htmlspecialchars($_POST['last_name']);
        $department = trim($_POST['invite_department'] ?? '');
        $isLead = (!empty($_POST['invite_is_team_lead']) && $_POST['invite_is_team_lead'] === '1') ? '1' : '0';
        $inviteCourseId = trim($_POST['invite_course_id'] ?? '');

        $orgIdParam = (!isSuperAdmin() && getOrgId()) ? '&org_id=' . getOrgId() : (isSuperAdmin() && !empty($_POST['invite_org_id']) ? '&org_id=' . (int)$_POST['invite_org_id'] : '');
        $inviteLink = buildUrl('signup?email=' . urlencode($email) . '&first_name=' . urlencode($fName) . '&last_name=' . urlencode($lName) . '&department=' . urlencode($department) . '&is_team_lead=' . $isLead . ($inviteCourseId !== '' ? '&course_id=' . urlencode($inviteCourseId) : '') . $orgIdParam);

        $siteName = getSiteName();
        $subject = "Invitation to $siteName";
        $htmlBody = "
            <div style='font-family: sans-serif; color: #333333; line-height: 1.6;'>
                <h2>Hello {$fName},</h2>
                <p>You have been invited to access the <strong>{$siteName}</strong> Training Portal.</p>
                <p>Complete your registration:</p>
                <div style='margin: 30px 0;'>
                    <a href='{$inviteLink}' style='background:#82ACD6; color:#fff; padding:12px 24px; text-decoration:none; border-radius:8px; font-weight:bold;'>Accept Invitation & Sign Up</a>
                </div>
                <p style='font-size:12px; color:#5f6f6a;'>If the link doesn't work, copy this: <br> {$inviteLink}</p>
            </div>";

        if (sendGHLPortalEmail($email, $fName, $subject, $htmlBody, $lName)) {
            $invite_status = "sent";
            // ── Audit: admin invited a new user ──
            logSecurityEvent('user_invited', 'info', ['email' => $email, 'department' => $department]);
        } else {
            $invite_status = "error";
        }
    }
    }
    elseif (isset($_POST['action_add_segment_option'])) {
        $category = $_POST['segment_category'] ?? '';
        $value = trim($_POST['segment_value'] ?? '');

        if ($category && $value) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO segment_options (category, option_value) VALUES (?, ?)");
            $stmt->execute([$category, $value]);
            header("Location: ./?segment_saved=1");
            exit;
        }
    }
    elseif (isset($_POST['action_update_user'])) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = trim($_POST['user_role'] ?? 'student');
        $department = trim($_POST['user_department'] ?? '');
        $isLead = (!empty($_POST['user_is_team_lead']) && $_POST['user_is_team_lead'] === '1') ? 1 : 0;
        $allowedRoles = ['admin', 'student'];
        if (isSuperAdmin()) {
            $allowedRoles[] = 'super_admin';
        }

        if ($userId > 0 && in_array($role, $allowedRoles, true)) {
            // Capture the prior state for the audit trail.
            $beforeStmt = $pdo->prepare("SELECT id, email, role, department, is_team_lead FROM users WHERE id = ?");
            $beforeStmt->execute([$userId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE users SET role = ?, department = ?, is_team_lead = ? WHERE id = ?");
            $stmt->execute([$role, $department, $isLead, $userId]);

            // Update organization if super admin
            if (isSuperAdmin() && isset($_POST['user_org_id'])) {
                $orgId = !empty($_POST['user_org_id']) ? (int)$_POST['user_org_id'] : null;
                $pdo->prepare("UPDATE users SET organization_id = ? WHERE id = ?")->execute([$orgId, $userId]);
            }

            // Role/org entitlements changed — invalidate outstanding serve tokens.
            bumpUserSecurityVersion($userId);

            // ── Audit: admin user update / role change ──
            if ($before) {
                $targetEmail = (string)($before['email'] ?? '');
                if (($before['role'] ?? '') !== $role) {
                    logSecurityEvent(
                        'role_changed',
                        $role === 'super_admin' ? 'critical' : 'warning',
                        ['old_role' => $before['role'], 'new_role' => $role, 'user_id' => $userId, 'target_email' => $targetEmail],
                        $userId,
                        $targetEmail
                    );
                    checkSecurityAlerts('role_changed', ['new_role' => $role, 'target_email' => $targetEmail]);
                } else {
                    logSecurityEvent(
                        'user_updated',
                        'info',
                        ['user_id' => $userId, 'department' => $department, 'is_team_lead' => $isLead, 'target_email' => $targetEmail],
                        $userId,
                        $targetEmail
                    );
                }
            }

            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId) {
                $_SESSION['user_role'] = $role;
            }

            header("Location: ./?user_updated=1");
            exit;
        }
    }
    elseif (isset($_POST['action_enroll_user'])) {
        $enrollUserId = (int) ($_POST['enroll_user_id'] ?? 0);
        $enrollCourseId = trim($_POST['enroll_course_id'] ?? '');

        if ($enrollUserId > 0 && $enrollCourseId !== '') {
            try {
                $stmt = $pdo->prepare("SELECT email, first_name, last_name, registration_id FROM users WHERE id = ?");
                $stmt->execute([$enrollUserId]);
                $enrollUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($enrollUser) {
                    // ── Audit: admin enrolled a learner in a course ──
                    logSecurityEvent(
                        'user_enrolled',
                        'info',
                        ['user_id' => $enrollUserId, 'course_id' => $enrollCourseId, 'target_email' => $enrollUser['email'] ?? ''],
                        $enrollUserId,
                        (string)($enrollUser['email'] ?? '')
                    );

                    $registrationId = 'reg_' . substr(md5($enrollUser['email'] . $enrollCourseId), 0, 12);
                    $payload = [
                        'courseId' => $enrollCourseId,
                        'registrationId' => $registrationId,
                        'learner' => [
                            'id' => $enrollUser['email'],
                            'firstName' => $enrollUser['first_name'],
                            'lastName' => $enrollUser['last_name'],
                        ],
                    ];

                    $scormDebug = [];
                    $response = createScormRegistration($payload, $scormDebug);
                    if (!empty($response)) {
                        if (empty($enrollUser['registration_id'])) {
                            $update = $pdo->prepare("UPDATE users SET registration_id = ? WHERE id = ?");
                            $update->execute([$registrationId, $enrollUserId]);
                        }
                        $enroll_status = 'success';
                    } else {
                        $scormStatus = $scormDebug['status'] ?? '?';
                        $scormBody = $scormDebug['raw'] ?? '';
                        $enroll_status = 'error';
                        if ($scormStatus >= 400) {
                            // Store detailed error for display
                            $enroll_error_detail = "SCORM API returned HTTP $scormStatus. " . htmlspecialchars(substr($scormBody, 0, 300), ENT_QUOTES, 'UTF-8');
                        }
                    }
                } else {
                    $enroll_status = 'not_found';
                }
            } catch (Exception $e) {
                error_log('[ENROLL] ' . $e->getMessage());
                $enroll_status = 'error';
            }
        }
    }
    elseif (isset($_POST['action_delete_segment_option'])) {
        $optionId = (int) $_POST['action_delete_segment_option'];
        $stmt = $pdo->prepare("DELETE FROM segment_options WHERE id = ?");
        $stmt->execute([$optionId]);
        header("Location: ./?segment_deleted=1");
        exit;
    }
    elseif (isset($_POST['action_delete'])) {
        $regId = trim((string)($_POST['action_delete'] ?? ''));
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            header("Location: ./?success=csrf");
            exit;
        }
        if ($regId === '') {
            header("Location: ./?success=0");
            exit;
        }
        // Native backend registration ids look like n_{package_id}_u_{user_id}.
        // Parse them so we can remove the actual scorm_attempts that drive the
        // "Active Registrations" list (plus the local enrollment records).
        if (preg_match('/^n_(\d+)_u_(\d+)$/', $regId, $m)) {
            $packageId = (int)$m[1];
            $userId    = (int)$m[2];
            // Remove their SCORM attempts (cascades interactions/objectives/events).
            $pdo->prepare("DELETE FROM scorm_attempts WHERE package_id = ? AND user_id = ?")
                ->execute([$packageId, $userId]);
            // Clean up local enrollment records (registration_id is the canonical
            // native id; course_id is the package id for native enrollments).
            $pdo->prepare("DELETE FROM user_registrations WHERE registration_id = ? OR (user_id = ? AND course_id = ?)")
                ->execute([$regId, $userId, (string)$packageId]);
            $pdo->prepare("UPDATE users SET registration_id = NULL WHERE registration_id = ?")
                ->execute([$regId]);
        } else {
            // Legacy Moodle / 'reg_' format
            deleteScormRegistration($regId);
            $pdo->prepare("DELETE FROM user_registrations WHERE registration_id = ?")->execute([$regId]);
            $pdo->prepare("UPDATE users SET registration_id = NULL WHERE registration_id = ?")->execute([$regId]);
        }
        header("Location: ./?success=1");
        exit;
    }
    elseif (isset($_POST['action_delete_user'])) {
        $targetId = (int)($_POST['action_delete_user'] ?? 0);
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $userDeleteStatus = 'csrf_error';
        } elseif ($targetId <= 0) {
            $userDeleteStatus = 'not_found';
        } elseif ($targetId === (int)($_SESSION['user_id'] ?? 0)) {
            $userDeleteStatus = 'cannot_self';
        } else {
            try {
                $tStmt = $pdo->prepare("SELECT id, email, role, organization_id FROM users WHERE id = ?");
                $tStmt->execute([$targetId]);
                $target = $tStmt->fetch(PDO::FETCH_ASSOC);

                $canDelete = false;
                if ($target) {
                    if (isSuperAdmin()) {
                        $canDelete = true;
                    } else {
                        $myOrg = getOrgId();
                        $targetOrg = $target['organization_id'] !== null ? (int)$target['organization_id'] : null;
                        $privileged = ($target['role'] === 'admin' || $target['role'] === 'super_admin');
                        $canDelete = ($myOrg === $targetOrg) && !$privileged;
                    }
                }

                if (!$target) {
                    $userDeleteStatus = 'not_found';
                } elseif (!$canDelete) {
                    $userDeleteStatus = 'forbidden';
                } else {
                    // ── Audit: account deletion (critical) ──
                    logSecurityEvent(
                        'user_deleted',
                        'critical',
                        ['user_id' => $targetId, 'role' => $target['role'] ?? '', 'target_email' => $target['email'] ?? ''],
                        $targetId,
                        (string)($target['email'] ?? '')
                    );
                    checkSecurityAlerts('user_deleted', ['target_email' => (string)($target['email'] ?? '')]);

                    // Remove their SCORM registrations first (native backend no-op, but keeps
                    // external backends in sync) — mirrors api/revoke.php.
                    $regStmt = $pdo->prepare("SELECT registration_id FROM user_registrations WHERE user_id = ?");
                    $regStmt->execute([$targetId]);
                    foreach ($regStmt->fetchAll(PDO::FETCH_COLUMN) as $regId) {
                        if (!empty($regId)) {
                            deleteScormRegistration($regId);
                        }
                    }
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
                    $userDeleteStatus = 'deleted';
                }
            } catch (PDOException $e) {
                error_log('[USER MGMT] delete user error: ' . $e->getMessage());
                $userDeleteStatus = 'error';
            }
        }
        header("Location: ./?user_delete=" . $userDeleteStatus);
        exit;
    }
}

// --- FETCH USERS (org-filtered for org admins) ---
$users = [];
try {
    $orgFilter = orgSql();
    $userStmt = $pdo->query("SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.department, u.is_team_lead, u.registration_id, u.organization_id, o.name as org_name FROM users u LEFT JOIN organizations o ON u.organization_id = o.id WHERE 1=1" . $orgFilter . " ORDER BY u.last_name, u.first_name");
    while ($row = $userStmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = $row;
    }
} catch (PDOException $e) {
    error_log('[DB] Failed to load users: ' . $e->getMessage());
}

// Fetch organizations and courses
$orgs = [];
try {
    $orgStmt = $pdo->query("SELECT id, name FROM organizations WHERE status = 1 ORDER BY name");
    while ($row = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
        $orgs[] = $row;
    }
} catch (PDOException $e) {
    error_log('[DB] Failed to load orgs: ' . $e->getMessage());
}

$courses = fetchScormCourses();
if (!is_array($courses)) {
    $courses = [];
}

// --- FETCH REGISTRATIONS ---
$registrations = fetchScormRegistrations();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        :root {
            --primary: #82ACD6;
            --link: #00808E;
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
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }

        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; }
        .content-max-width { max-width: 1100px; margin: 0 auto; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }

        .invite-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; align-items: end; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .field input,
        .field select,
        .field textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 0.95rem; font-family: inherit; background: #fff; }
        .field select { appearance: none; background-image: linear-gradient(45deg, transparent 50%, var(--text-muted) 50%), linear-gradient(135deg, var(--text-muted) 50%, transparent 50%); background-position: calc(100% - 16px) calc(50% + 2px), calc(100% - 10px) calc(50% + 2px); background-size: 6px 6px; background-repeat: no-repeat; }
        .field input[type="checkbox"] { width: auto; height: auto; margin-left: 0; }

        .btn-invite { background: var(--primary); color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; height: 50px; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .btn-invite:hover { transform: translateY(-1px); box-shadow: 0 12px 24px rgba(37,99,235,0.18); }

        .course-list-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
        .course-list-note { color: var(--text-muted); font-size: 0.95rem; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 18px; }
        .course-card { border: 1px solid rgba(0, 130, 100, 0.16); border-radius: 18px; background: linear-gradient(180deg, #ffffff 0%, #ecf6f4 100%); padding: 20px; transition: transform 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 12px 28px rgba(0, 130, 100, 0.08); }
        .course-card:hover { transform: translateY(-3px); box-shadow: 0 18px 36px rgba(0, 130, 100, 0.16); }
        .course-card-title { margin: 0 0 10px; font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .course-card-meta { color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; }

        .card h3 { font-size: 1.25rem; margin-bottom: 14px; }
        .card p { color: var(--text-muted); line-height: 1.6; }
        
        .table-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 16px 20px; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        
        .btn-delete { background: #fff4e6; color: var(--accent); border: 1px solid rgba(217, 119, 36, 0.25); padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-update { background: var(--primary); color: white; border: none; padding: 10px 16px; border-radius: 10px; font-weight: 600; cursor: pointer; }
        .user-edit-input { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 0.9rem; font-family: inherit; }
        .user-edit-checkbox { width: 18px; height: 18px; }
        .user-table td { vertical-align: middle; }
        .user-table select, .user-table input { width: 100%; }
        .alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; border: 1px solid; }
        .alert-success { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
        .alert-error { background: #fff7ed; border-color: #fcd29b; color: #92400e; }

        @media (max-width: 1024px) {
            .invite-form { grid-template-columns: 1fr; }
            main { margin-left: 0; padding: 80px 20px 20px; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <div class="content-max-width">
            <header style="margin-bottom: 32px;">
                <h1 style="margin:0;" data-tour="tour-user-mgmt">User Management</h1>
                <p style="color:var(--text-muted);">Invite learners and manage user accounts.</p>
            </header>

            <?php if($invite_status === 'sent'): ?>
                <div class="alert alert-success">✓ Invitation sent successfully to GHL.</div>
            <?php elseif($invite_status === 'csrf_error'): ?>
                <div class="alert alert-error">⚠ Security token expired. Please refresh the page and try again.</div>
            <?php elseif($invite_status === 'error'): ?>
                <div class="alert alert-error">✗ Failed to send invitation. Check your API keys.</div>
            <?php elseif($invite_status === 'rate_limited'): ?>
                <div class="alert alert-error">⏱ Too many invites sent recently. Please wait and try again later.</div>
            <?php endif; ?>

            <?php if(isset($_GET['success'])): ?>
                <?php if ($_GET['success'] === 'csrf'): ?>
                    <div class="alert alert-error">⚠ Security token expired. Please refresh the page and try again.</div>
                <?php elseif ($_GET['success'] === '0'): ?>
                    <div class="alert alert-error">✗ Failed to remove registration.</div>
                <?php else: ?>
                    <div class="alert alert-success">✓ Registration removed successfully.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if(isset($_GET['user_updated'])): ?>
                <div class="alert alert-success">✓ User record updated successfully.</div>
            <?php endif; ?>
            <?php if(isset($_GET['user_delete'])): ?>
                <?php if ($_GET['user_delete'] === 'deleted'): ?>
                    <div class="alert alert-success">✓ User deleted successfully.</div>
                <?php elseif ($_GET['user_delete'] === 'cannot_self'): ?>
                    <div class="alert alert-error">✗ You cannot delete your own account.</div>
                <?php elseif ($_GET['user_delete'] === 'forbidden'): ?>
                    <div class="alert alert-error">✗ You don't have permission to delete this user.</div>
                <?php elseif ($_GET['user_delete'] === 'csrf_error'): ?>
                    <div class="alert alert-error">⚠ Security token expired. Please refresh the page and try again.</div>
                <?php else: ?>
                    <div class="alert alert-error">✗ Failed to delete user.</div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card" data-tour="tour-invite">
                <form method="POST" class="invite-form">
                    <?php echo csrfHiddenField(); ?>
                    <div class="field">
                        <label>First Name</label>
                        <input type="text" name="first_name" placeholder="John" required>
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" placeholder="Doe" required>
                    </div>
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" name="invite_email" placeholder="john@example.com" required>
                    </div>
                    <div class="field">
                        <label>Department</label>
                        <select name="invite_department">
                            <option value="">— None —</option>
                            <?php foreach ($segmentOptions['department'] ?? [] as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['value']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Assign Course (optional)</label>
                        <select name="invite_course_id">
                            <option value="">— Account Only (No Course) —</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course['id'] ?? $course['courseId'] ?? ''); ?>"><?php echo htmlspecialchars(($course['title'] ?? $course['name'] ?? 'Untitled') . ' (ID: ' . ($course['id'] ?? $course['courseId'] ?? '') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isSuperAdmin() && !empty($orgs)): ?>
                    <div class="field">
                        <label>Assign Organization</label>
                        <select name="invite_org_id">
                            <option value="">— Auto (Your Org) —</option>
                            <?php foreach ($orgs as $org): ?>
                                <option value="<?php echo $org['id']; ?>" <?php echo (getOrgId() === (int)$org['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($org['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="field" style="display:flex;align-items:center;gap:8px;">
                        <label style="margin:0;">Team Lead</label>
                        <input type="checkbox" name="invite_is_team_lead" value="1" style="width:18px;height:18px;margin-left:6px;">
                    </div>
                    <button type="submit" name="action_invite" class="btn-invite">Send Invite</button>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">Department Management</h3>
                <p style="color:var(--text-muted); margin-bottom:16px;">Create departments here so they can be assigned to learners and shown in readiness analytics. Departments are stored as segment options and then available in the department dropdown on user invitation and editing.</p>
                <form method="POST" class="invite-form">
                    <input type="hidden" name="segment_category" value="department">
                    <div class="field">
                        <label>Department Name</label>
                        <input type="text" name="segment_value" placeholder="Emergency Department" required>
                    </div>
                    <button type="submit" name="action_add_segment_option" class="btn-invite">Add Department</button>
                </form>
                <?php if (!empty($_GET['segment_saved'])): ?>
                    <div class="alert alert-success" style="margin-top:16px;">✓ Department saved and ready to assign.</div>
                <?php elseif (!empty($_GET['segment_deleted'])): ?>
                    <div class="alert alert-success" style="margin-top:16px;">✓ Department deleted.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">Current Segment Options</h3>
                <?php if (empty($segmentOptions)): ?>
                    <p style="color:var(--text-muted); margin:0;">No segment options configured yet. Use the form above to add them.</p>
                <?php else: ?>
                    <?php foreach ($segmentOptions as $categoryKey => $options): ?>
                        <div style="margin-bottom: 20px;">
                            <strong><?php echo htmlspecialchars($segmentCategories[$categoryKey] ?? ucfirst($categoryKey)); ?></strong>
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-top: 10px;">
                                <?php foreach ($options as $option): ?>
                                    <div style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:12px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                        <span><?php echo htmlspecialchars($option['value']); ?></span>
                                        <form method="POST" style="margin:0;">
                                            <button type="submit" name="action_delete_segment_option" value="<?php echo htmlspecialchars($option['id']); ?>" class="btn-delete">Remove</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 style="margin-top:0;" data-tour="tour-enroll">Course Enrollment</h3>
                <p style="color:var(--text-muted); margin-bottom:16px;">Enroll learners in available courses directly from the LMS.</p>

                <?php if ($enroll_status === 'success'): ?>
                    <div class="alert alert-success">✓ Learner enrolled successfully.</div>
                <?php elseif ($enroll_status === 'error'): ?>
                    <div class="alert alert-error">✗ Enrollment failed. <?php echo !empty($enroll_error_detail) ? '<br><small style="word-break:break-all;font-size:11px;color:#666;">' . $enroll_error_detail . '</small>' : 'Please verify the selection and try again.'; ?></div>
                <?php elseif ($enroll_status === 'not_found'): ?>
                    <div class="alert alert-error">✗ Selected user not found.</div>
                <?php endif; ?>

                <form method="POST" class="invite-form enrollment-form">
                    <div class="field">
                        <label>Choose Learner</label>
                        <select name="enroll_user_id" required>
                            <option value="">— Select Learner —</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Choose Course</label>
                        <select name="enroll_course_id" required>
                            <option value="">— Select Course —</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course['id'] ?? $course['courseId'] ?? ''); ?>"><?php echo htmlspecialchars(($course['title'] ?? $course['name'] ?? 'Untitled') . ' (ID: ' . ($course['id'] ?? $course['courseId'] ?? '') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="action_enroll_user" class="btn-invite">Enroll Learner</button>
                </form>

                <?php if (!empty($courses)): ?>
                    <div class="course-list" style="margin-top:24px;">
                        <div class="course-list-header">
                            <strong>Available Courses</strong>
                            <span class="course-list-note">Pick a course from the library to assign it to the selected learner.</span>
                        </div>
                        <div class="course-grid">
                            <?php foreach ($courses as $course): ?>
                                <div class="course-card">
                                    <div class="course-card-title"><?php echo htmlspecialchars($course['title'] ?? $course['name'] ?? 'Untitled'); ?></div>
                                    <div class="course-card-meta">ID: <?php echo htmlspecialchars($course['id'] ?? $course['courseId'] ?? 'unknown'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); margin-top:16px;">No courses found.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">User Accounts</h3>
                <p style="color:var(--text-muted); margin-bottom:16px;">Update role, department, and team lead status for existing accounts.</p>
                <?php if (empty($users)): ?>
                    <p style="color:var(--text-muted); margin:0;">No user accounts found.</p>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <form id="edit-user-<?php echo htmlspecialchars($user['id']); ?>" method="POST" style="display:none;">
                            <input type="hidden" name="action_update_user" value="1">
                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                            <input type="hidden" name="user_is_team_lead" value="0">
                        </form>
                    <?php endforeach; ?>

                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Organization</th>
                                <th>Department</th>
                                <th>Team Lead</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php $formId = 'edit-user-' . htmlspecialchars($user['id']); ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <select form="<?php echo $formId; ?>" name="user_role" class="user-edit-input">
                                            <option value="student" <?php echo ($user['role'] === 'student' ? 'selected' : ''); ?>>Student</option>
                                            <option value="admin" <?php echo ($user['role'] === 'admin' ? 'selected' : ''); ?>>Admin</option>
                                            <?php if (isSuperAdmin()): ?>
                                            <option value="super_admin" <?php echo ($user['role'] === 'super_admin' ? 'selected' : ''); ?>>Super Admin</option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if (isSuperAdmin()): ?>
                                        <select form="<?php echo $formId; ?>" name="user_org_id" class="user-edit-input">
                                            <option value="">— None —</option>
                                            <?php foreach ($orgs as $org): ?>
                                                <option value="<?php echo $org['id']; ?>" <?php echo ((int)$user['organization_id'] === (int)$org['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($org['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php else: ?>
                                        <span style="font-size:0.85rem;color:var(--text-muted);"><?php echo htmlspecialchars($user['org_name'] ?? '—'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input form="<?php echo $formId; ?>" list="department-list" name="user_department" class="user-edit-input" value="<?php echo htmlspecialchars($user['department']); ?>">
                                    </td>
                                    <td style="text-align:center;">
                                        <input form="<?php echo $formId; ?>" class="user-edit-checkbox" type="checkbox" name="user_is_team_lead" value="1" <?php echo ($user['is_team_lead'] ? 'checked' : ''); ?>>
                                    </td>
                                    <td style="text-align:right;">
                                        <button form="<?php echo $formId; ?>" type="submit" class="btn-update">Update</button>
                                        <?php
                                            $isSelf = ((int)$user['id'] === (int)($_SESSION['user_id'] ?? 0));
                                            $privUser = ($user['role'] === 'admin' || $user['role'] === 'super_admin');
                                            $canDeleteUser = !$isSelf && (isSuperAdmin() || !$privUser);
                                        ?>
                                        <?php if ($canDeleteUser): ?>
                                        <form method="POST" style="display:inline;margin-left:8px;" onsubmit="return confirm('Delete this user and all their progress? This cannot be undone.');">
                                            <?php echo csrfHiddenField(); ?>
                                            <button type="submit" name="action_delete_user" value="<?php echo (int)$user['id']; ?>" class="btn-delete">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <datalist id="department-list">
                        <?php foreach ($segmentOptions['department'] ?? [] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt['value']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                <?php endif; ?>
            </div>

            <div class="table-card">
                <div style="padding: 20px; border-bottom: 1px solid var(--border);">
                    <h3 style="margin:0; font-size: 1.1rem;">Active Registrations</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Course</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registrations)): ?>
                            <tr><td colspan="3" style="text-align:center; padding:40px; color:var(--text-muted);">No records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700;"><?= htmlspecialchars($reg['learner']['firstName'] . ' ' . $reg['learner']['lastName']) ?></div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($reg['learner']['id']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($reg['course']['title']) ?></td>
                                <td style="text-align:right;">
                                    <form method="POST" onsubmit="return confirm('Remove this learner?');" style="display:inline;">
                                        <?php echo csrfHiddenField(); ?>
                                        <button type="submit" name="action_delete" value="<?= htmlspecialchars($reg['id']) ?>" class="btn-delete">Remove</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>
