<?php
/**
 * PURSUIT PATHWAYS LMS
 * MFA VERIFICATION (login/mfa.php)
 *
 * Mandatory email-based Multi-Factor Authentication for administrators.
 * After a valid password, the user is redirected here with a 6-digit code
 * emailed to them (10-minute expiry, max 5 verification attempts).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';
ensureSecurityTables();

$error = '';
$message = '';

// Only reachable mid-login (password verified, MFA pending).
if (empty($_SESSION['mfa_pending_user_id'])) {
    redirectTo('login/');
}
$pendingUserId = (int)$_SESSION['mfa_pending_user_id'];

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, organization_id FROM users WHERE id = ?");
    $stmt->execute([$pendingUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_destroy();
        redirectTo('login/');
    }
} catch (PDOException $e) {
    error_log('[MFA] DB error loading user: ' . $e->getMessage());
    $user = ['first_name' => '', 'email' => (string)($_SESSION['email'] ?? ''), 'role' => 'admin', 'organization_id' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend'])) {
        // Rate-limited resend.
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $error = 'Invalid form submission. Please try again.';
        } elseif (!checkRegistrationRateLimit('mfa-resend', 3, 600)) {
            $error = 'Too many resend requests. Please wait a few minutes.';
        } else {
            $code = issueMfaChallenge($pendingUserId);
            sendMfaCodeEmail($user, $code);
            logSecurityEvent('mfa_issued', 'info', ['reason' => 'resend'], $pendingUserId, $user['email']);
            $message = 'A new verification code has been sent to your email.';
        }
    } else {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            $error = 'Invalid form submission. Please try again.';
        } else {
            $code = trim($_POST['code'] ?? '');
            if (!preg_match('/^\d{6}$/', $code)) {
                $error = 'Please enter the 6-digit code from your email.';
            } else {
                $result = verifyMfaChallenge($pendingUserId, $code);
                if ($result['ok']) {
                    resetFailedLogin($pendingUserId);
                    logSecurityEvent('mfa_success', 'info', [], $pendingUserId, $user['email']);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $pendingUserId;
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'] ?? 'admin';
                    $_SESSION['organization_id'] = $user['organization_id'] ?? null;
                    $_SESSION['mfa_verified_at'] = time();
                    unset($_SESSION['mfa_pending_user_id']);
                    error_log('[MFA SUCCESS] user_id=' . $pendingUserId . ' email=' . $user['email'] . ' session_id=' . session_id());
                    session_write_close();
                    redirectTo('dashboard/');
                } else {
                    $error = $result['error'];
                    logSecurityEvent('mfa_failure', 'warning', ['reason' => 'bad_code'], $pendingUserId, $user['email']);
                    checkSecurityAlerts('mfa_failure', ['email' => $user['email'], 'user_id' => $pendingUserId]);
                }
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
    <title>Verify Your Identity | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <style>
        :root {
            --primary: #82ACD6; --primary-hover: #00808E; --bg: #D3E2F3; --text: #232D63;
            --border: #BBBDB7; --danger: #991b1b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 420px; text-align: center; }
        .logo-img { max-width: 220px; margin-bottom: 20px; }
        .subtitle { color: var(--text); font-size: 0.9rem; margin-bottom: 8px; font-weight: 500; }
        .hint { color: #5f6f6a; font-size: 0.82rem; margin-bottom: 24px; }
        .input-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text); }
        input { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; text-align: center; letter-spacing: 8px; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(130, 172, 214, 0.25); }
        .btn { width: 100%; padding: 14px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; margin-top: 6px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-ghost { background: #f1f5f9; color: var(--text); margin-top: 10px; }
        .btn-ghost:hover { background: #e2e8f0; }
        .error { background: #fee2e2; color: var(--danger); font-size: 0.85rem; padding: 12px; border-radius: 6px; margin-bottom: 18px; border: 1px solid #fecaca; }
        .success { background: #dcfce7; color: #166534; font-size: 0.85rem; padding: 12px; border-radius: 6px; margin-bottom: 18px; border: 1px solid #bbf7d0; }
    </style>
</head>
<body>
    <div class="card">
        <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?> Logo" class="logo-img">
        <h2 style="color: var(--text); margin-bottom: 8px;">Verify Your Identity</h2>
        <p class="subtitle">Two-step verification required</p>
        <p class="hint">A 6-digit code was sent to <?php echo htmlspecialchars($user['email'] ?? ''); ?>. It expires in <?php echo (int)(getenv('MFA_TTL_MINUTES') ?: 10); ?> minutes.</p>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?php echo csrfHiddenField(); ?>
            <div class="input-group">
                <label for="code">Verification Code</label>
                <input type="text" id="code" name="code" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary">Verify Code</button>
        </form>

        <form method="POST" style="margin-top: 12px;">
            <?php echo csrfHiddenField(); ?>
            <button type="submit" name="resend" value="1" class="btn btn-ghost">Resend Code</button>
        </form>

        <p style="margin-top: 20px; font-size: 0.8rem; color: #5f6f6a;">
            <a href="<?php echo buildUrl('logout.php'); ?>" style="color: var(--text);">Cancel and sign out</a>
        </p>
    </div>
</body>
</html>
