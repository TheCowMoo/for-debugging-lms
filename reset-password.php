<?php
/**
 * RESET PASSWORD CONTROLLER (reset-password.php)
 * Reset Password Controller
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/security.php';
ensureUserColumns();
ensureSecurityTables();

// 1. CONFIGURATION

$message = '';
$message_type = '';
$show_form = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

try {
    $pdo = getDbConnection();

    if (empty($token)) {
        throw new Exception("Invalid or missing reset token.");
    }

    // 2. VALIDATE TOKEN
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE reset_token = ? AND reset_expiry > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $show_form = true;
    } else {
        $message = "This reset link is invalid or has expired.";
        $message_type = "error";
    }

    // 3. HANDLE PASSWORD UPDATE
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $show_form) {
        $new_password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $message = "Passwords do not match.";
            $message_type = "error";
        } else {
            $policy = validatePasswordPolicy($new_password, $user['role'] ?? 'student');
            if (!$policy['ok']) {
                $message = $policy['error'];
                $message_type = "error";
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
                $update->execute([$hash, $user['id']]);
                // Password changed — invalidate outstanding serve tokens.
                bumpUserSecurityVersion((int)$user['id']);

                logSecurityEvent('password_changed', 'info', ['reason' => 'reset'], (int)$user['id'], '');
                redirectTo('login/?msg=updated');
            }
        }
    }

} catch (Exception $e) {
    $message = $e->getMessage();
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #82ACD6; --primary-hover: #00808E; --bg: #D3E2F3; --text: #232D63; --border: #BBBDB7; --accent: #00808E; --danger: #E4E348; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 420px; text-align: center; }
        .logo-img { max-width: 220px; margin-bottom: 20px; }
        .subtitle { color: var(--text); font-size: 0.9rem; margin-bottom: 25px; }
        .input-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; }
        .btn-primary { width: 100%; padding: 14px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 10px; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 0.85rem; border: 1px solid transparent; }
        .alert-error { background: var(--bg-error); color: var(--error); border-color: #fee2e2; }
    </style>
</head>
<body>
    <div class="card">
        <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?> Logo" class="logo-img">
        <h2>Set New Password</h2>
        <p class="subtitle">Choose a secure password.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($show_form): ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="input-group">
                    <label>New Password</label>
                    <input type="password" name="password" required placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;">
                </div>
                <div class="input-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;">
                </div>
                <button type="submit" class="btn-primary">Update Password</button>
            </form>
        <?php else: ?>
            <a href="<?php echo buildUrl('forgot-password'); ?>" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Request a new link</a>
        <?php endif; ?>
    </div>
</body>
</html>
