<?php
/**
 * LOGIN CONTROLLER (index.php)
 * Branding: Pursuit Pathways
 */

require_once __DIR__ . '/../bootstrap.php';

$error = '';
$success_msg = '';

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

            if ($user && password_verify($password, $user['password_hash'])) {
                if (isset($user['is_verified']) && $user['is_verified'] == 0) {
                    $error = "Verify your email before logging in.";
                } else {
                    // FIX: Regenerate session ID FIRST to prevent session fixation,
                    // then set session variables, and close session write before redirect.
                    // This ensures data is fully committed to storage before the next request.
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email']   = $user['email'];
                    $_SESSION['user_role'] = $user['role'] ?? 'student';
                    $_SESSION['organization_id'] = $user['organization_id'] ?? null;
                    error_log('[LOGIN SUCCESS] user_id=' . $user['id'] . ' email=' . $user['email'] . ' role=' . ($user['role'] ?? 'student') . ' session_id=' . session_id());
                    session_write_close();
                    // Redirect to terms page if not accepted yet
                    if (empty($user['terms_accepted_at'])) {
                        redirectTo('terms/');
                    }
                    redirectTo('dashboard/');
                }
            } else {
                $error = "The credentials provided do not match our records.";
            }
        } catch (PDOException $e) {
            error_log('[LOGIN DB ERROR] ' . $e->getMessage());
            $error = "Connection interrupted. Please try again.";
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
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo RECAPTCHA_SITE_KEY; ?>"></script>
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
            --success: #15803d;
            --bg-success: #f0fdf4;
        }

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
    </script>

        <div class="footer-links">
            New to the platform? <a href="<?php echo $signup_url; ?>">Create an account</a>
        </div>
    </div>
</body>
</html>