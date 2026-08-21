<?php
/**
 * SUPER ADMIN — Certificate Test Preview
 * Generate a certificate preview without needing SCORM completion data.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();
requireSuperAdmin();

$error = '';
$previewUrl = '';
$template = trim($_GET['template'] ?? '');
$name = trim($_GET['name'] ?? '');
$date = trim($_GET['date'] ?? '');

// Get available certificate templates from /content/
$contentDir = __DIR__ . '/../content';
$certTemplates = [];
if (is_dir($contentDir)) {
    $files = scandir($contentDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
            $certTemplates[] = $f;
        }
    }
    sort($certTemplates);
}

// Get logged-in user's name for default
$defaultName = 'Test Learner';
try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $defaultName = trim($user['first_name'] . ' ' . $user['last_name']);
    }
} catch (PDOException $e) {
    error_log('[CERT TEST] User lookup failed: ' . $e->getMessage());
}

$defaultDate = date('m/d/y');

if ($template && $name) {
    $issued = $date ? '&issued=' . urlencode($date) : '';
    $previewUrl = buildUrl('certificate-vault/certificate.php?template=' . urlencode($template) . '&name=' . urlencode($name) . $issued);
}

$userRole = $_SESSION['user_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Certificate | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; }
        .content-max-width { max-width: 900px; margin: 0 auto; }
        .page-title { margin: 0 0 8px; font-size: 2rem; }
        .page-subtitle { margin: 0 0 24px; color: var(--text-muted); }
        .back-link { display: inline-block; margin-bottom: 24px; color: var(--primary); font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 32px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .field input, .field select { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 0.95rem; font-family: inherit; background: #fff; }
        .btn-primary { background: var(--primary); color: #fff; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .alert { padding: 16px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; }
        .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .preview-img { max-width: 100%; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 20px; } .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main>
        <div class="content-max-width">
            <a href="<?php echo buildUrl('admin-course-manager'); ?>" class="back-link">← Back to Course Manager</a>
            <h1 class="page-title">Test Certificate</h1>
            <p class="page-subtitle">Preview a certificate with custom name and date. Super admin only.</p>

            <div class="card">
                <form method="GET" action="">
                    <div class="grid-2">
                        <div class="field">
                            <label>Certificate Template *</label>
                            <select name="template" required>
                                <option value="">— Select —</option>
                                <?php foreach ($certTemplates as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($template === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Name on Certificate *</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($name ?: $defaultName); ?>" required>
                        </div>
                        <div class="field">
                            <label>Date of Issue (MM/DD/YY)</label>
                            <input type="text" name="date" value="<?php echo htmlspecialchars($date ?: $defaultDate); ?>" placeholder="e.g. 07/13/26">
                        </div>
                        <div class="field" style="display:flex;align-items:end;">
                            <button type="submit" class="btn-primary">Generate Preview</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($previewUrl): ?>
                <div class="card" style="text-align:center;">
                    <h3 style="margin-top:0;">Certificate Preview</h3>
                    <p style="color:var(--text-muted); margin-bottom:16px;">Template: <strong><?php echo htmlspecialchars($template); ?></strong> | Name: <strong><?php echo htmlspecialchars($name); ?></strong> | Issued: <strong><?php echo htmlspecialchars($date ?: '—'); ?></strong></p>
                    <img src="<?php echo $previewUrl; ?>" alt="Certificate Preview" class="preview-img" style="max-width:100%;">
                    <p style="margin-top:16px;"><a href="<?php echo $previewUrl; ?>" class="back-link" download>⬇ Download This Preview</a></p>
                </div>
            <?php else: ?>
                <div class="alert alert-info">Select a template, enter a name, and click "Generate Preview" to see the certificate.</div>
            <?php endif; ?>
        </div>
    </main>
    <script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('active');
    }
    </script>
</body>
</html>