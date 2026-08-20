<?php
/**
 * FORGOT PASSWORD REQUEST (forgot-password.php)
 * Branding: Pursuit Pathways
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../signup/ghl_helper.php';
ensureUserColumns();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkRegistrationRateLimit('forgot-password', 3, 3600)) {
        $message = "Too many reset attempts. Please try again later.";
        $message_type = "error";
    } elseif (!verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $message = "Please complete the CAPTCHA verification.";
        $message_type = "error";
    } else {
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

    if (empty($email)) {
        $message = "Enter a valid email address.";
        $message_type = "error";
    } else {
        try {
            $pdo = getDbConnection();
            
            $stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
                $update->execute([$token, $expiry, $user['id']]);

                $reset_link = buildUrl('reset-password.php?token=' . urlencode($token));
                $siteName = getSiteName();
                $subject = "Password Reset - $siteName";
                $htmlBody = "
                    <div style='font-family: sans-serif; max-width: 600px; border: 1px solid #d9e3df; padding: 25px; border-radius: 12px;'>
                        <h2 style='color: #82ACD6;'>Reset Your Password</h2>
                        <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                        <p>You requested a password reset for your {$siteName} account. Click the button below to continue:</p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$reset_link}' style='background: #82ACD6; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Reset Password</a>
                        </div>
                        <p style='color: #5f6f6a; font-size: 13px;'>Link expires in 1 hour.</p>
                    </div>";

                sendGHLPortalEmail($email, $user['first_name'], $subject, $htmlBody);
            }

            $message = "If that email exists in our system, a reset link has been sent.";
            $message_type = "success";

        } catch (PDOException $e) {
            $message = "System error. Please try again later.";
            $message_type = "error";
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
    <title>Forgot Password | <?php echo getSiteName(); ?></title>
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
            --error: #b91c1c;
            --bg-error: #fef2f2;
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

        .alert { 
            font-size: 0.85rem; padding: 12px; border-radius: 6px; 
            margin-bottom: 24px; border: 1px solid transparent; 
        }
        .alert-success { background: var(--bg-success); color: var(--success); border-color: #dcfce7; }
        .alert-error { background: var(--bg-error); color: var(--error); border-color: #fee2e2; }

        .footer-links { margin-top: 24px; font-size: 0.85rem; color: var(--text-muted); }
        .footer-links a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand-logo">
            <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?> Logo" class="logo-img">
        </div>
        <div class="subtitle">Reset Your Password</div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@example.com" required>
            </div>

            <input type="hidden" name="g-recaptcha-response" id="recaptcha-token">
            <button type="submit" class="btn-primary">Send Reset Link</button>
        </form>
    <script>
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        grecaptcha.ready(function() {
            grecaptcha.execute('<?php echo RECAPTCHA_SITE_KEY; ?>', {action: 'forgot_password'}).then(function(token) {
                document.getElementById('recaptcha-token').value = token;
                form.submit();
            });
        });
    });
    </script>

        <div class="footer-links">
            Remembered your password? <a href="<?php echo buildUrl('login/'); ?>">Back to Login</a>
        </div>
    </div>
</body>
</html>
