<?php
/**
 * LOGIN CONTROLLER (index.php)
 * Branding: Huron-Perth Children's Aid Society
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';
ensureSecurityTables();

$error = '';
$success_msg = '';
$cooldownSeconds = 0; // countdown shown by the generic lockout message

$viewer_url    = buildUrl('course-viewer/');
$signup_url    = buildUrl('signup/');
$dashboard_url = buildUrl('dashboard/');
$forgot_url    = buildUrl('forgot-password');

// Check for incoming success flags
if (isset($_GET['verified']) && $_GET['verified'] == 1) {
    $success_msg = "Email verified successfully! You can now log in.";
} elseif (isset($_GET['signup']) && $_GET['signup'] == 'success') {
    $success_msg = "Account created. Check your email to verify.";
} elseif (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
    $success_msg = "Password updated successfully.";
}

/**
 * LOGIC A: AUTO-LAUNCH (From Dashboard / Course Page)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_launch'])) {
    // Validate CSRF token (required for auto-launch too)
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = "Invalid form submission. Please try again.";
        error_log('[AUTO_LAUNCH] CSRF validation failed. session_id=' . session_id() . ' user_id=' . ($_SESSION['user_id'] ?? 'NOT SET'));
    } elseif (!isset($_SESSION['user_id'])) {
        $error = "Your session has expired. Please log in again.";
        error_log('[AUTO_LAUNCH] No user_id in session. session_id=' . session_id());
    } else {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT registration_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $regId = trim($_POST['registration_id'] ?? ($user['registration_id'] ?? ''));
            if ($regId === '') {
                $error = "No registration ID found for this course.";
                error_log('[AUTO_LAUNCH] Empty registration_id for user_id=' . $_SESSION['user_id']);
            } else {
                $scormDebug = [];
                $launchLink = getScormLaunchLink($regId, $dashboard_url, $scormDebug);
                if (!empty($launchLink)) {
                    $_SESSION['course_url'] = $launchLink;
                    error_log('[AUTO_LAUNCH] Success. user_id=' . $_SESSION['user_id'] . ' reg_id=' . $regId);
                    session_write_close();
                    redirectTo('course-page/?launch=1');
                } else {
                    $scormStatus = $scormDebug['status'] ?? '?';
                    $scormBody = $scormDebug['raw'] ?? 'empty response';
                    $scormUrl = $scormDebug['url'] ?? 'unknown';
                    $error = "Unable to launch this course. The SCORM backend returned HTTP $scormStatus. (Debug: " . htmlspecialchars(substr($scormBody, 0, 300), ENT_QUOTES, 'UTF-8') . ")";
                    $error .= "<br><small style='word-break:break-all;font-size:11px;color:#666;'>URL: " . htmlspecialchars($scormUrl, ENT_QUOTES, 'UTF-8') . "</small>";
                    error_log('[AUTO_LAUNCH] Empty launch link for reg_id=' . $regId . ' status=' . $scormStatus . ' url=' . $scormUrl . ' body=' . $scormBody);
                }
            }
        } catch (Exception $e) {
            error_log('[AUTO_LAUNCH] Exception: ' . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

/**
 * LOGIC B: STANDARD LOGIN
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['auto_launch'])) {
    // Validate CSRF token
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!checkRegistrationRateLimit('login', 5, 3600)) {
        $error = "Too many login attempts. Please try again later.";
    } elseif (!verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $error = "Please complete the CAPTCHA verification.";
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $cooldownSeconds = 0;

        // Local staging test user (no database required)
        if ($email === TEST_USER_EMAIL && $password === TEST_USER_PASSWORD) {
            loginLocalTestUser();
            redirectTo('dashboard/');
        }

        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $userId = $user ? (int)$user['id'] : 0;

            // ── Account lockout gate ──
            // Generic message: identical whether the email exists or not, so
            // attackers cannot enumerate accounts from the lockout UI.
            // Best-effort: a lockout-DB failure must not mask the credentials check.
            try {
                $lock = isAccountLocked($userId, $email);
            } catch (Throwable $lockErr) {
                error_log('[LOGIN LOCKOUT ERROR] isAccountLocked: ' . $lockErr->getMessage());
                $lock = ['locked' => false, 'remaining_seconds' => 0];
            }
            if ($lock['locked']) {
                $cooldownSeconds = $lock['remaining_seconds'];
                $error = genericLockoutMessage($cooldownSeconds);
                recordLoginAttempt($userId ?: null, $email, false);
                logSecurityEvent('login_blocked_locked', 'warning', ['reason' => 'account_locked'], $userId ?: null, $email);
            } elseif ($user && password_verify($password, $user['password_hash'])) {
                // ── Credentials valid ──
                if (isset($user['is_verified']) && $user['is_verified'] == 0) {
                    $error = "Verify your email before logging in.";
                    recordLoginAttempt($userId, $email, false);
                    logSecurityEvent('login_failure', 'info', ['reason' => 'unverified'], $userId, $email);
                } else {
                    resetFailedLogin($userId);
                    recordLoginAttempt($userId, $email, true);
                    logSecurityEvent('login_success', 'info', [], $userId, $email);
                    // FIX: Regenerate session ID FIRST to prevent session fixation.
                    session_regenerate_id(true);
                    $role = $user['role'] ?? 'student';

                    // ── Mandatory email MFA for administrators ──
                    if (isAdminRole($role)) {
                        $code = issueMfaChallenge($userId);
                        sendMfaCodeEmail($user, $code);
                        $_SESSION['mfa_pending_user_id'] = $userId;
                        $_SESSION['email'] = $user['email'];
                        logSecurityEvent('mfa_issued', 'info', [], $userId, $email);
                        session_write_close();
                        redirectTo('login/mfa.php');
                    }

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['email']   = $user['email'];
                    $_SESSION['user_role'] = $role;
                    $_SESSION['organization_id'] = $user['organization_id'] ?? null;
                    $_SESSION['mfa_verified_at'] = time();
                    error_log('[LOGIN SUCCESS] user_id=' . $userId . ' email=' . $user['email'] . ' role=' . $role . ' session_id=' . session_id());
                    session_write_close();
                    // Redirect to terms page if not accepted yet
                    if (empty($user['terms_accepted_at'])) {
                        redirectTo('terms/');
                    }
                    redirectTo('dashboard/');
                }
            } else {
                // ── Credentials invalid ──
                // Lockout/audit bookkeeping is best-effort: a DB/audit failure
                // must never mask a bad-credentials result with a generic
                // "connection interrupted" error.
                $attemptsRemaining = 0;
                try {
                    $lockResult = applyFailedLogin($userId, $email);
                    $ipResult = recordLoginAttempt($userId ?: null, $email, false, $lockResult['locked']);
                    logSecurityEvent('login_failure', 'warning', ['reason' => 'bad_credentials'], $userId ?: null, $email);
                    if ($lockResult['locked']) {
                        logSecurityEvent('account_locked', 'critical', ['email' => $email], $userId ?: null, $email);
                        checkSecurityAlerts('account_locked', ['email' => $email, 'user_id' => $lockResult['user_id']]);
                    }
                    $cooldownSeconds = max($lockResult['remaining_seconds'], $ipResult['ip_remaining_seconds']);
                    $attemptsRemaining = (int)($lockResult['attempts_remaining'] ?? 0);
                } catch (Throwable $lockoutErr) {
                    error_log('[LOGIN LOCKOUT ERROR] bad_credentials bookkeeping: ' . $lockoutErr->getMessage());
                    $cooldownSeconds = 0;
                }

                if ($cooldownSeconds > 0) {
                    $error = genericLockoutMessage($cooldownSeconds);
                } elseif ($attemptsRemaining > 0) {
                    $error = 'Invalid password. ' . $attemptsRemaining . ' attempt' . ($attemptsRemaining === 1 ? '' : 's') . ' remaining before lockout.';
                } else {
                    $error = 'Invalid password.';
                }
            }
        } catch (PDOException $e) {
            error_log('[LOGIN DB ERROR] ' . $e->getMessage());
            $error = "Connection interrupted. Please try again.";
        }
    }

    // If the client IP is still throttled (e.g., after a refresh), keep showing
    // the generic countdown without revealing whether any account exists.
    if ($cooldownSeconds <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $cooldownSeconds = ipLockoutRemaining();
        if ($cooldownSeconds > 0) {
            $error = genericLockoutMessage($cooldownSeconds);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
    <style>
        <?php renderBrandStyles(); ?>

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg-body); 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            color: var(--text-main); 
        }

        .login-card { 
            background: var(--bg-card); 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px; 
            text-align: center;
        }

        .brand-logo { margin-bottom: 20px; }
        .logo-img { max-width: 220px; height: auto; display: block; margin: 0 auto; }
        .subtitle { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 32px; font-weight: 500; }

        .input-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text-main); }
        input { 
            width: 100%; padding: 12px 16px; border: 1px solid var(--border); 
            background: #fff; color: var(--text-main); border-radius: 8px; 
            box-sizing: border-box; font-size: 1rem; transition: all 0.2s;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

        .btn-primary { 
            width: 100%; padding: 14px; background: var(--primary); 
            color: #fff; border: none; border-radius: 8px; 
            cursor: pointer; font-weight: 600; font-size: 1rem;
            margin-top: 10px; transition: background 0.2s;
        }
        .btn-primary:hover { background: var(--primary-hover); }

        .error { 
            background: var(--bg-error); color: var(--error); font-size: 0.85rem; 
            padding: 12px; border-radius: 6px; margin-bottom: 24px; border: 1px solid #fee2e2;
        }

        .success-box {
            background: var(--bg-success); color: var(--success); font-size: 0.85rem;
            padding: 12px; border-radius: 6px; margin-bottom: 24px; border: 1px solid #dcfce7;
        }

        .footer-links { margin-top: 24px; font-size: 0.85rem; color: var(--text-muted); }
        .footer-links a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .footer-links a:hover { text-decoration: underline; }

        .forgot-link {
            display: block; text-align: right; margin-top: -15px; margin-bottom: 20px;
            font-size: 0.8rem; color: var(--text-muted); text-decoration: none;
        }
        .forgot-link:hover { color: var(--primary); }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-logo">
            <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?> Logo" class="logo-img">
        </div>
        <div class="subtitle"><?php echo getSiteName(); ?> LMS</div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success_msg): ?>
            <div class="success-box"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <?php echo csrfHiddenField(); ?>
            <input type="hidden" id="lockoutCooldown" value="<?php echo (int)$cooldownSeconds; ?>">
            <div class="input-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@example.com" required>
            </div>
            
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" required>
            </div>

            <a href="<?php echo $forgot_url; ?>" class="forgot-link">Forgot Password?</a>

            <input type="hidden" name="g-recaptcha-response" id="recaptcha-token">
            <button type="submit" class="btn-primary">Sign In</button>
        </form>
    <script>
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        grecaptcha.ready(function() {
            grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'login'}).then(function(token) {
                document.getElementById('recaptcha-token').value = token;
                form.submit();
            });
        });
    });

    // ── Generic lockout countdown (no account confirmation) ──
    (function () {
        var cooldown = parseInt(document.getElementById('lockoutCooldown').value || '0', 10);
        if (!cooldown || cooldown <= 0) return;
        var submitBtn = document.querySelector('button[type="submit"]');
        var errorBox = document.querySelector('.error');
        if (submitBtn) submitBtn.disabled = true;
        var tick = function () {
            if (cooldown <= 0) {
                if (errorBox) errorBox.textContent = 'Too many failed login attempts. Please try again.';
                if (submitBtn) submitBtn.disabled = false;
                return;
            }
            var m = Math.floor(cooldown / 60);
            var s = cooldown % 60;
            var label = (m > 0 ? m + 'm ' : '') + s + 's';
            if (errorBox) errorBox.textContent = 'Too many failed login attempts. Please wait ' + label + ' before trying again.';
            cooldown--;
            setTimeout(tick, 1000);
        };
        tick();
    })();
    </script>

        <div class="footer-links">
            New to the platform? <a href="<?php echo $signup_url; ?>">Create an account</a>
        </div>
    </div>
</body>
</html>