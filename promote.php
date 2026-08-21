<?php
/**
 * ONE-TIME SCRIPT: Check session and promote to super_admin
 * DELETE THIS FILE AFTER USE FOR SECURITY
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin(); // Must already be an admin to self-promote

$pdo = getDbConnection();
$msg = '';
$error = '';

// Promote current user to super_admin
if (isset($_GET['promote'])) {
    $stmt = $pdo->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    // Force session update
    $_SESSION['user_role'] = 'super_admin';
    $msg = "Your role has been set to super_admin! Refresh the page.";
}

// Get current DB role
$stmt = $pdo->prepare("SELECT id, email, role, organization_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html><body style="font-family:sans-serif;padding:40px;background:#F4F9F7;">
<div style="max-width:500px;margin:auto;background:#fff;padding:30px;border-radius:16px;">
    <h2>Session & Role Diagnostics</h2>
    <?php if ($msg): ?><div style="background:#dcfce7;padding:12px;border-radius:8px;margin-bottom:16px;color:#166534;"><?php echo $msg; ?></div><?php endif; ?>
    <?php if ($error): ?><div style="background:#fee2e2;padding:12px;border-radius:8px;margin-bottom:16px;color:#991b1b;"><?php echo $error; ?></div><?php endif; ?>
    
    <h3>Database Record:</h3>
    <pre style="background:#f1f5f9;padding:12px;border-radius:8px;">
ID:         <?php echo $dbUser['id'] ?? 'N/A'; ?>
Email:      <?php echo $dbUser['email'] ?? 'N/A'; ?>
DB Role:    <?php echo $dbUser['role'] ?? 'N/A'; ?>
Org ID:     <?php echo $dbUser['organization_id'] ?? 'NULL'; ?>
    </pre>

    <h3>Session ($_SESSION):</h3>
    <pre style="background:#f1f5f9;padding:12px;border-radius:8px;">
user_id:    <?php echo $_SESSION['user_id'] ?? 'NOT SET'; ?>
user_role:  <?php echo $_SESSION['user_role'] ?? 'NOT SET'; ?>
org_id:     <?php echo $_SESSION['organization_id'] ?? 'NULL'; ?>
    </pre>

    <h3>Function Checks:</h3>
    <pre style="background:#f1f5f9;padding:12px;border-radius:8px;">
isSuperAdmin(): <?php echo isSuperAdmin() ? '✅ TRUE' : '❌ FALSE'; ?>
isAdmin():      <?php echo isAdmin() ? '✅ TRUE' : '❌ FALSE'; ?>
getOrgId():     <?php echo getOrgId() ?? 'NULL'; ?>
    </pre>

    <p style="margin-top:20px;">
        <a href="?promote=1" style="display:inline-block;background:#006F53;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:700;">Promote me to Super Admin</a>
        <br><br>
        <a href="promote.php" style="color:#60B49A;">Refresh</a> | 
        <a href="organizations/" style="color:#60B49A;">Go to Organizations</a> |
        <a href="logout.php" style="color:#ef4444;">Log Out</a>
    </p>
    <p style="color:#999;font-size:12px;">Delete this file after use: <code>promote.php</code></p>
</div>
</body></html>