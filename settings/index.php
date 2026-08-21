<?php
/**
 * PURSUIT PATHWAYS LMS
 * USER SETTINGS (settings/index.php)
 * - Appearance: theme (light/dark) + font scale (small/default/large)
 * - Account: first name, last name, email
 * - Security: change password
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin();

$pdo = getDbConnection();
ensureUserPreferencesColumn();
ensureSecurityTables();

$userId = $_SESSION['user_id'] ?? null;

// Full profile row (test user has no DB row — leave $user null).
$user = null;
if (!isTestUser() && $userId) {
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, role, is_verified FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

$prefs = getUserPreferences();

$message = '';
$message_type = '';

// ---- Handle form submissions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = "Invalid form submission. Please try again.";
        $message_type = 'error';
    } elseif (isset($_POST['action_appearance'])) {
        // Appearance: theme + font scale
        if (saveUserPreferences([
            'theme'      => $_POST['theme'] ?? 'light',
            'font_scale' => $_POST['font_scale'] ?? 'normal',
        ])) {
            $message = "Appearance settings saved.";
            $message_type = 'success';
        } else {
            $message = "Could not save appearance settings. Please try again.";
            $message_type = 'error';
        }
        $prefs = getUserPreferences();
    } elseif (isset($_POST['action_profile'])) {
        // Account: name + email
        if (isTestUser() || !$user) {
            $message = "Profile editing is unavailable for this account.";
            $message_type = 'error';
        } else {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            $email     = strtolower(trim($_POST['email'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                $message = "First and last name are required.";
                $message_type = 'error';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $message_type = 'error';
            } else {
                $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $dup->execute([$email, $userId]);
                if ($dup->fetch()) {
                    $message = "That email is already in use by another account.";
                    $message_type = 'error';
                } else {
                    $upd = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
                    $upd->execute([$firstName, $lastName, $email, $userId]);
                    // Email is a security-relevant identifier — invalidate tokens on change.
                    if ($email !== ($user['email'] ?? '')) {
                        bumpUserSecurityVersion((int)$userId);
                    }
                    $_SESSION['email'] = $email; // keep session in sync
                    $user['first_name'] = $firstName;
                    $user['last_name']  = $lastName;
                    $user['email']      = $email;
                    $message = "Profile updated successfully.";
                    $message_type = 'success';
                }
            }
        }
    } elseif (isset($_POST['action_password'])) {
        // Security: change password
        if (isTestUser() || !$user) {
            $message = "Password changes are unavailable for this account.";
            $message_type = 'error';
        } else {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $pdo->prepare("SELECT password_hash, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !password_verify($current, $row['password_hash'] ?? '')) {
                $message = "Your current password is incorrect.";
                $message_type = 'error';
            } elseif ($new !== $confirm) {
                $message = "New passwords do not match.";
                $message_type = 'error';
            } else {
                $policy = validatePasswordPolicy($new, $row['role'] ?? 'student');
                if (!$policy['ok']) {
                    $message = $policy['error'];
                    $message_type = 'error';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $upd  = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upd->execute([$hash, $userId]);
                    // Password changed — invalidate outstanding serve tokens.
                    bumpUserSecurityVersion((int)$userId);
                    logSecurityEvent('password_changed', 'info', ['reason' => 'self_service'], (int)$userId, $user['email'] ?? '');
                    $message = "Password updated successfully.";
                    $message_type = 'success';
                }
            }
        }
    }
}

$profileFirstName = htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES, 'UTF-8');
$profileLastName  = htmlspecialchars($user['last_name'] ?? '', ENT_QUOTES, 'UTF-8');
$profileEmail     = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/main.css'); ?>">
    <style>
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; transition: 0.3s; }
        .content-max-width { max-width: 860px; margin: 0 auto; }
        header { margin-bottom: 32px; }
        header h1 { font-size: 1.9rem; font-weight: 700; margin: 0; }
        header p { color: var(--text-muted); margin-top: 8px; font-size: 1rem; }

        .settings-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .settings-card h2 { font-size: 1.15rem; font-weight: 700; margin: 0 0 6px; }
        .card-desc { color: var(--text-muted); font-size: 0.92rem; margin: 0 0 20px; }

        .option-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 16px 0; border-top: 1px solid var(--border); }
        .option-row:first-of-type { border-top: none; padding-top: 4px; }
        .option-label { font-weight: 700; }
        .option-sub { color: var(--text-muted); font-size: 0.85rem; margin-top: 3px; }

        .segmented { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--bg-body); }
        .segmented label { position: relative; margin: 0; }
        .segmented input { position: absolute; opacity: 0; pointer-events: none; }
        .segmented span { display: block; padding: 9px 18px; font-size: 0.9rem; font-weight: 600; color: var(--text-muted); border-right: 1px solid var(--border); cursor: pointer; transition: background 0.15s, color 0.15s; }
        .segmented label:last-child span { border-right: none; }
        .segmented input:checked + span { background: var(--primary); color: #ffffff; }
        .segmented span:hover { background: var(--bg-body); color: var(--text-main); }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; }
        .field input { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 0.95rem; font-family: inherit; background: var(--bg-card); color: var(--text-main); box-sizing: border-box; }
        .field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }

        .btn-save { background: var(--primary); color: #ffffff; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.95rem; transition: background 0.2s; }
        .btn-save:hover { background: var(--primary-hover); }

        .alert { padding: 13px 16px; border-radius: 10px; font-size: 0.9rem; margin-bottom: 22px; font-weight: 600; }
        .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

        .note { background: var(--bg-body); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; color: var(--text-muted); margin-top: 16px; line-height: 1.5; }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main>
        <div class="content-max-width">
            <header>
                <h1>Settings</h1>
                <p>Manage your appearance, profile, and security.</p>
            </header>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="settings-card">
                <h2>Appearance</h2>
                <p class="card-desc">Choose how the portal looks for you.</p>
                <form method="POST">
                    <?php echo csrfHiddenField(); ?>
                    <input type="hidden" name="action_appearance" value="1">

                    <div class="option-row">
                        <div>
                            <div class="option-label">Theme</div>
                            <div class="option-sub">Dark mode reduces glare in low-light environments.</div>
                        </div>
                        <div class="segmented" role="radiogroup" aria-label="Theme">
                            <label>
                                <input type="radio" name="theme" value="light" <?php echo $prefs['theme'] === 'light' ? 'checked' : ''; ?>>
                                <span>Light</span>
                            </label>
                            <label>
                                <input type="radio" name="theme" value="dark" <?php echo $prefs['theme'] === 'dark' ? 'checked' : ''; ?>>
                                <span>Dark</span>
                            </label>
                        </div>
                    </div>

                    <div class="option-row">
                        <div>
                            <div class="option-label">Font size</div>
                            <div class="option-sub">Adjust text size across the portal.</div>
                        </div>
                        <div class="segmented" role="radiogroup" aria-label="Font size">
                            <label>
                                <input type="radio" name="font_scale" value="small" <?php echo $prefs['font_scale'] === 'small' ? 'checked' : ''; ?>>
                                <span>Small</span>
                            </label>
                            <label>
                                <input type="radio" name="font_scale" value="normal" <?php echo $prefs['font_scale'] === 'normal' ? 'checked' : ''; ?>>
                                <span>Default</span>
                            </label>
                            <label>
                                <input type="radio" name="font_scale" value="large" <?php echo $prefs['font_scale'] === 'large' ? 'checked' : ''; ?>>
                                <span>Large</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn-save">Save Appearance</button>
                </form>
            </div>

            <div class="settings-card">
                <h2>Account</h2>
                <p class="card-desc">Update your name and login email.</p>

                <?php if (isTestUser()): ?>
                    <div class="note">You are viewing the demo test account, so profile editing is disabled here.</div>
                <?php else: ?>
                    <form method="POST">
                        <?php echo csrfHiddenField(); ?>
                        <input type="hidden" name="action_profile" value="1">

                        <div class="form-grid">
                            <div class="field">
                                <label for="first_name">First name</label>
                                <input type="text" id="first_name" name="first_name" required value="<?php echo $profileFirstName; ?>">
                            </div>
                            <div class="field">
                                <label for="last_name">Last name</label>
                                <input type="text" id="last_name" name="last_name" required value="<?php echo $profileLastName; ?>">
                            </div>
                        </div>

                        <div class="field">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" required value="<?php echo $profileEmail; ?>">
                        </div>

                        <button type="submit" class="btn-save">Save Profile</button>
                    </form>
                    <div class="note">Note: your email is used as your learner ID for course records. Changing it may affect links to existing course progress and certificates.</div>
                <?php endif; ?>
            </div>

            <div class="settings-card">
                <h2>Security</h2>
                <p class="card-desc">Change your password. <?php echo isAdminRole($user['role'] ?? 'student') ? 'Admin accounts: minimum 12 characters with uppercase, lowercase, number, and symbol.' : 'Use at least 8 characters.'; ?></p>

                <?php if (isTestUser()): ?>
                    <div class="note">You are viewing the demo test account, so password changes are disabled here.</div>
                <?php else: ?>
                    <form method="POST">
                        <?php echo csrfHiddenField(); ?>
                        <input type="hidden" name="action_password" value="1">

                        <div class="field">
                            <label for="current_password">Current password</label>
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>

                        <div class="form-grid">
                            <div class="field">
                                <label for="new_password">New password</label>
                                <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                            </div>
                            <div class="field">
                                <label for="confirm_password">Confirm new password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            </div>
                        </div>

                        <button type="submit" class="btn-save">Update Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>


