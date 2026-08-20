<?php
/**
 * REGISTRATION CONTROLLER (signup.php)
 * Creates local user account only. Course enrollment
 * is done separately via user-management admin panel.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/ghl_helper.php';

$error = '';
$success_msg = '';

try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    die("Connection failed.");
}

ensureUserColumns();

// Pre-fill from invite link (token-based or legacy query params)
$invite_token = trim($_GET['token'] ?? '');
$invite_data = null;

if ($invite_token !== '') {
    ensureInviteTokensTable();
    $tokenStmt = $pdo->prepare("SELECT * FROM invite_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW())");
    $tokenStmt->execute([$invite_token]);
    $invite_data = $tokenStmt->fetch(PDO::FETCH_ASSOC);

    if ($invite_data) {
        $invite_course_id = $invite_data['course_id'] ?? '';
        $invite_org_id = !empty($invite_data['organization_id']) ? (int)$invite_data['organization_id'] : null;
        $prefill_department = $invite_data['department'] ?? '';
        $prefill_is_lead = (int)($invite_data['is_team_lead'] ?? 0);
        $is_demo_invite = (int)($invite_data['is_demo'] ?? 0);
        $demo_max_uses = (int)($invite_data['max_uses'] ?? 0);
        $demo_use_count = (int)($invite_data['use_count'] ?? 0);
        $notify_emails = !empty($invite_data['notify_emails']) ? json_decode($invite_data['notify_emails'], true) : [];
    }
} else {
    // Legacy: pre-fill from query params
    $prefill_department = trim($_GET['department'] ?? '');
    $prefill_is_lead = (isset($_GET['is_team_lead']) && $_GET['is_team_lead'] === '1') ? 1 : 0;
    $invite_course_id = trim($_GET['course_id'] ?? '');
    $invite_org_id = !empty($_GET['org_id']) ? (int)$_GET['org_id'] : null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!checkRegistrationRateLimit('signup', 5, 3600)) {
        $error = "Too many registration attempts. Please try again later.";
    } elseif (!verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $error = "Please complete the CAPTCHA verification.";
    } else {
        $email     = strtolower(trim($_POST['email']));
        $password  = $_POST['password'];
        $firstName = trim($_POST['first_name']);
        $lastName  = trim($_POST['last_name']);
        $v_token   = bin2hex(random_bytes(32));

        // Enforce the standard (non-admin) password policy at signup.
        $signupPolicy = validatePasswordPolicy($password, 'student');
        if (!$signupPolicy['ok']) {
            $error = $signupPolicy['error'];
        } else {

        $prefill_department = trim($_POST['department'] ?? $prefill_department);
        $prefill_is_lead = (isset($_POST['is_team_lead']) && $_POST['is_team_lead'] === '1') ? 1 : $prefill_is_lead;

        try {
            // 1. Check if user exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                throw new Exception("This email is already registered.");
            }

            // 2. Save local account (with org from invite)
            $inviteOrgId = !empty($_POST['invite_org_id']) ? (int)$_POST['invite_org_id'] : null;
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $newRole = !empty($is_demo_invite) ? 'demo_student' : 'student';
            $sql = "INSERT INTO users (email, password_hash, first_name, last_name, role, organization_id, registration_id, verification_token, is_verified, department, is_team_lead) 
                VALUES (:email, :pass, :fname, :lname, :role, :orgid, NULL, :vtoken, 0, :department, :is_team_lead)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':email'  => $email,
                ':pass'   => $passHash,
                ':fname'  => $firstName,
                ':lname'  => $lastName,
                ':role'   => $newRole,
                ':orgid'  => $inviteOrgId,
                ':vtoken' => $v_token,
                ':department' => $prefill_department,
                ':is_team_lead' => $prefill_is_lead,
            ]);

            $userId = $pdo->lastInsertId();

            // 2b. If a course was specified with invite, auto-enroll now
            $inviteCourseId = trim($_POST['invite_course_id'] ?? '');
            if (!empty($inviteCourseId)) {
                $regId = 'reg_' . substr(md5($email . $inviteCourseId), 0, 12);
                $payload = [
                    'courseId' => $inviteCourseId,
                    'registrationId' => $regId,
                    'learner' => ['id' => $email, 'firstName' => $firstName, 'lastName' => $lastName],
                ];
                $regResponse = createScormRegistration($payload);
                if (!empty($regResponse)) {
                    // The native backend returns the canonical registration id
                    // (n_{pkg}_u_{user}). Store that — not the placeholder
                    // 'reg_' id — so lookups by registration_id resolve to the
                    // real scorm_attempts row.
                    $nativeRegId = $regResponse['id'] ?? $regId;
                    $pdo->prepare("UPDATE users SET registration_id = ? WHERE id = ?")->execute([$nativeRegId, $userId]);
                    ensureUserRegistrationsTable();
                    // Resolve the course title from the package for a friendlier record
                    $courseTitle = '';
                    if (preg_match('/^n_(\d+)_u_/', $nativeRegId, $nm)) {
                        $tStmt = $pdo->prepare('SELECT title FROM scorm_packages WHERE id = ?');
                        $tStmt->execute([(int)$nm[1]]);
                        $courseTitle = (string)$tStmt->fetchColumn();
                    }
                    $pdo->prepare("INSERT INTO user_registrations (user_id, course_id, registration_id, course_title) VALUES (?, ?, ?, ?)")
                        ->execute([$userId, $inviteCourseId, $nativeRegId, $courseTitle]);
                }
            }

            // 2c. If this was a token-based demo invite, increment use count + notify
            $inviteToken = trim($_POST['invite_token'] ?? '');
            if ($inviteToken !== '') {
                ensureInviteTokensTable();
                // Get current use_count
                $countStmt = $pdo->prepare("SELECT id, use_count, notify_emails FROM invite_tokens WHERE token = ?");
                $countStmt->execute([$inviteToken]);
                $tokenRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                if ($tokenRow) {
                    $currentCount = (int)$tokenRow['use_count'];
                    $newCount = $currentCount + 1;
                    // Increment use_count
                    $pdo->prepare("UPDATE invite_tokens SET use_count = ? WHERE token = ?")->execute([$newCount, $inviteToken]);

                    // Link this signup to its demo campaign so the Demo Manager
                    // can list signups per invite (verified or not).
                    if (!empty($tokenRow['id'])) {
                        $pdo->prepare("UPDATE users SET invite_token_id = ? WHERE id = ?")->execute([(int)$tokenRow['id'], $userId]);
                    }
                    
                    // Send notification to the configured admin address
                    // (falls back to the per-slot notification emails if not set).
                    $notifyEmail = (defined('DEMO_SIGNUP_NOTIFY_EMAIL') && DEMO_SIGNUP_NOTIFY_EMAIL !== '')
                        ? DEMO_SIGNUP_NOTIFY_EMAIL
                        : null;
                    if (!$notifyEmail) {
                        $emails = !empty($tokenRow['notify_emails']) ? json_decode($tokenRow['notify_emails'], true) : [];
                        $notifyEmail = $emails[$currentCount] ?? null; // 0-indexed: currentCount is the index of the NEW signup
                    }
                    if ($notifyEmail) {
                        $siteNameNotify = getSiteName();
                        $subjectNotify = "Demo Signup #$newCount - $siteNameNotify";
                        $bodyNotify = "
                            <h2>New Demo Signup</h2>
                            <p><strong>$firstName $lastName</strong> ($email) has signed up via the demo invite link.</p>
                            <p>This is signup #$newCount for this campaign.</p>";
                        sendGHLPortalEmail($notifyEmail, 'Demo Manager', $subjectNotify, $bodyNotify);
                    }
                }
            }

            // 3. GHL Email Notification
            $verify_link = buildUrl('verify.php?token=' . urlencode($v_token));
            $siteName = getSiteName();
            $subject = "Verify your email address - $siteName";
            $verificationEmail = buildVerificationEmail($firstName, $verify_link, $siteName);

            if (sendGHLPortalEmail($email, $firstName, $subject, $verificationEmail['html'], $lastName, $verificationEmail['text'])) {
                redirectTo('login/?signup=success');
            } else {
                throw new Exception("Account created, but verification email failed to send.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    <style>
        :root { --primary: #82ACD6; --primary-hover: #00808E; --accent: #00808E; --danger: #E4E348; --bg-body: #D3E2F3; --bg-card: #FFFFFF; --text-main: #232D63; --text-muted: #232D63; --border: #BBBDB7; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }
        .login-card { background: var(--bg-card); padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); width: 100%; max-width: 440px; text-align: center; margin: 20px; }
        .brand-logo { margin-bottom: 20px; }
        .logo-img { max-width: 220px; height: auto; display: block; margin: 0 auto; }
        .subtitle { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 32px; font-weight: 500; }
        .input-group { text-align: left; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text-main); }
        input { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; font-size: 1rem; transition: all 0.2s; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .btn-primary { width: 100%; padding: 14px; background: var(--primary); color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; margin-top: 10px; }
        .error { background: #fef2f2; color: #b91c1c; font-size: 0.85rem; padding: 12px; border-radius: 6px; margin-bottom: 24px; border: 1px solid #fee2e2; }
        .footer-links { margin-top: 24px; font-size: 0.85rem; color: var(--text-muted); }
        .footer-links a { color: var(--primary); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-logo"><img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?> Logo" class="logo-img"></div>
        <div class="subtitle">Create account</div>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <?php echo csrfHiddenField(); ?>
            <div class="grid">
                <div class="input-group"><label>First Name</label><input type="text" name="first_name" required></div>
                <div class="input-group"><label>Last Name</label><input type="text" name="last_name" required></div>
            </div>
            <div class="input-group"><label>Email Address</label><input type="email" name="email" required></div>
            <div class="input-group"><label>Password</label><input type="password" name="password" required></div>
            <?php if (!empty($prefill_department)): ?>
                <div class="input-group"><label>Department</label><input type="text" value="<?php echo htmlspecialchars($prefill_department); ?>" readonly></div>
            <?php endif; ?>
            <input type="hidden" name="department" value="<?php echo htmlspecialchars($prefill_department); ?>">
            <input type="hidden" name="is_team_lead" value="<?php echo $prefill_is_lead ? '1' : '0'; ?>">
            <?php if (!empty($invite_course_id)): ?>
            <input type="hidden" name="invite_course_id" value="<?php echo htmlspecialchars($invite_course_id); ?>">
            <?php endif; ?>
            <?php if (!empty($invite_org_id)): ?>
            <input type="hidden" name="invite_org_id" value="<?php echo (int)$invite_org_id; ?>">
            <?php endif; ?>
            <?php if (!empty($invite_token)): ?>
            <input type="hidden" name="invite_token" value="<?php echo htmlspecialchars($invite_token); ?>">
            <?php endif; ?>
            <input type="hidden" name="g-recaptcha-response" id="recaptcha-token">
            <button type="submit" class="btn-primary">Register Account</button>
        </form>
    <script>
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        grecaptcha.ready(function() {
            grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'signup'}).then(function(token) {
                document.getElementById('recaptcha-token').value = token;
                form.submit();
            });
        });
    });
    </script>
        <div class="footer-links">Already have an account? <a href="<?php echo buildUrl('login/'); ?>">Sign In</a></div>
    </div>
</body>
</html>
