<?php
/**
 * ADMIN COURSE MANAGER
 * Manage course assets (certificates, thumbnails) + SCORM packages.
 * Super admin / admin only.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();
requireAdmin();

$pdo = getDbConnection();
ensureCourseAssetsTable();
ensureOrganizationsTable();
ensureScormTables();

$message = '';
$message_type = '';
$success_msg = '';
$error_msg = '';

// —— Tab selection ——
$tab = $_GET['tab'] ?? 'assets';

// —— Course Assets: Save ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save'])) {
    $courseId = trim($_POST['course_id'] ?? '');
    $courseTitle = trim($_POST['course_title'] ?? '');
    $certificateTemplate = trim($_POST['certificate_template'] ?? '');
    $thumbnail = trim($_POST['thumbnail'] ?? '');
    $assetOrgId = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;

    if ($courseId === '') {
        $message = "Course ID is required.";
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM course_assets WHERE course_id = ?");
            $stmt->execute([$courseId]);
            if ($stmt->fetch()) {
                $update = $pdo->prepare("UPDATE course_assets SET course_title = ?, certificate_template = ?, thumbnail = ?, organization_id = ? WHERE course_id = ?");
                $update->execute([$courseTitle, $certificateTemplate ?: null, $thumbnail ?: null, $assetOrgId, $courseId]);
                $message = "Course updated.";
            } else {
                $insert = $pdo->prepare("INSERT INTO course_assets (course_id, course_title, certificate_template, thumbnail, organization_id) VALUES (?, ?, ?, ?, ?)");
                $insert->execute([$courseId, $courseTitle, $certificateTemplate ?: null, $thumbnail ?: null, $assetOrgId]);
                $message = "Course added.";
            }
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Database error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// —— Course Assets: Delete ——
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $courseId = trim($_POST['action_delete']);
    try {
        $pdo->prepare("DELETE FROM course_assets WHERE course_id = ?")->execute([$courseId]);
        $message = "Course mapping removed.";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Database error.";
        $message_type = "error";
    }
}

// —— SCORM Package actions ——
$currentOrgId = getOrgId();
$isSuper = isSuperAdmin();
$s3Configured = isS3Configured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isScormAction = isset($_POST['action_delete_package']) || isset($_POST['action_toggle_status']) || isset($_POST['action_assign_org']) || isset($_POST['action_unassign_org']) || isset($_POST['action_edit_package']);
    if ($isScormAction && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Security token mismatch. Please refresh and try again.';
    } elseif (isset($_POST['action_delete_package'])) {
        $packageId = (int)$_POST['action_delete_package'];
        if (!$isSuper) {
            $check = $pdo->prepare("SELECT id FROM scorm_packages WHERE id = ? AND organization_id = ?");
            $check->execute([$packageId, $currentOrgId]);
            if (!$check->fetch()) {
                $error_msg = 'You do not have permission to delete this package.';
            }
        }
        if ($error_msg === '') {
            try {
                $pkg = $pdo->prepare("SELECT upload_path FROM scorm_packages WHERE id = ?");
                $pkg->execute([$packageId]);
                $path = $pkg->fetchColumn();
                if ($path) {
                    $dir = SCORM_STORAGE_PATH . '/' . $packageId;
                    if (is_dir($dir)) {
                        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
                        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($files as $f) {
                            if ($f->isDir()) @rmdir($f->getRealPath());
                            else @unlink($f->getRealPath());
                        }
                        @rmdir($dir);
                    }
                }
                if ($s3Configured) {
                    $s3Prefix = S3_PREFIX . $packageId . '/';
                    s3DeletePrefix($s3Prefix);
                }
                $pdo->prepare("DELETE FROM scorm_packages WHERE id = ?")->execute([$packageId]);
                $success_msg = 'Package deleted successfully.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete package.';
            }
        }
    } elseif (isset($_POST['action_toggle_status'])) {
        $packageId = (int)$_POST['action_toggle_status'];
        $newStatus = $_POST['new_status'] === 'archived' ? 'archived' : 'active';
        $permOk = true;
        if (!$isSuper) {
            $check = $pdo->prepare("SELECT id FROM scorm_packages WHERE id = ? AND organization_id = ?");
            $check->execute([$packageId, $currentOrgId]);
            if (!$check->fetch()) { $permOk = false; $error_msg = 'Permission denied.'; }
        }
        if ($permOk) {
            $pdo->prepare("UPDATE scorm_packages SET status = ? WHERE id = ?")->execute([$newStatus, $packageId]);
            $success_msg = 'Package status updated.';
        }
    } elseif (isset($_POST['action_assign_org'])) {
        $pkgId = (int)$_POST['action_assign_org_pkg'];
        $targetOrgId = (int)($_POST['action_assign_org'] ?? 0);
        if (!$isSuper) $targetOrgId = $currentOrgId;
        if ($targetOrgId > 0) {
            try {
                $pdo->prepare("INSERT IGNORE INTO course_assignments (package_id, organization_id, assigned_by) VALUES (?, ?, ?)")
                    ->execute([$pkgId, $targetOrgId, (int)($_SESSION['user_id'] ?? 0)]);
                $success_msg = 'Package assigned.';
            } catch (PDOException $e) { $error_msg = 'Assignment failed.'; }
        } else { $error_msg = 'Select an organization.'; }
    } elseif (isset($_POST['action_unassign_org'])) {
        $pkgId = (int)$_POST['action_unassign_org_pkg'];
        $targetOrgId = (int)$_POST['action_unassign_org'];
        if (!$isSuper) $targetOrgId = $currentOrgId;
        $pdo->prepare("DELETE FROM course_assignments WHERE package_id = ? AND organization_id = ?")
            ->execute([$pkgId, $targetOrgId]);
        $success_msg = 'Assignment removed.';
    } elseif (isset($_POST['action_edit_package'])) {
        $packageId = (int)$_POST['action_edit_package'];
        $editTitle = trim($_POST['edit_title'] ?? '');
        $editDescription = trim($_POST['edit_description'] ?? '');
        $editVersion = trim($_POST['edit_version'] ?? '') ?: '1.0';
        $editStatus = in_array($_POST['edit_status'] ?? '', ['active', 'archived', 'draft'], true) ? $_POST['edit_status'] : 'active';

        if ($editTitle === '') {
            $error_msg = 'Title is required.';
        } else {
            $permOk = true;
            if (!$isSuper) {
                $check = $pdo->prepare("SELECT id FROM scorm_packages WHERE id = ? AND organization_id = ?");
                $check->execute([$packageId, $currentOrgId]);
                if (!$check->fetch()) { $permOk = false; $error_msg = 'You do not have permission to edit this package.'; }
            }
            if ($permOk) {
                try {
                    $pdo->prepare("UPDATE scorm_packages SET title = ?, description = ?, version = ?, status = ? WHERE id = ?")
                        ->execute([$editTitle, $editDescription !== '' ? $editDescription : null, $editVersion, $editStatus, $packageId]);
                    $success_msg = 'Package updated.';
                } catch (PDOException $e) {
                    $error_msg = 'Failed to update package.';
                }
            }
        }
    }
}

// —— Fetch Course Assets ——
$availableCourses = fetchScormCourses();
if (!is_array($availableCourses)) $availableCourses = [];
$localAssets = getAllCourseAssets();
$contentFiles = [];
$contentDir = __DIR__ . '/../content';
if (is_dir($contentDir)) {
    $files = scandir($contentDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'])) $contentFiles[] = $f;
    }
    sort($contentFiles);
}

// —— Fetch SCORM Packages ——
$sql = "SELECT sp.*,
               (SELECT COUNT(*) FROM sco_items si WHERE si.package_id = sp.id) AS sco_count,
               (SELECT COUNT(*) FROM course_assignments ca WHERE ca.package_id = sp.id) AS assigned_orgs
        FROM scorm_packages sp WHERE 1=1";
$params = [];
if (!$isSuper && $currentOrgId !== null) {
    $sql .= " AND sp.organization_id = ?";
    $params[] = $currentOrgId;
}
$sql .= " ORDER BY sp.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$orgs = $pdo->query("SELECT id, name, status FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$servePreviewUrl = function (int $pkgId): string {
    return buildUrl('scorm-player/?pkg=' . $pkgId);
};

$userRole = $_SESSION['user_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Manager | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <style>
        :root {
            --primary: #82ACD6; --primary-hover: #00808E; --accent: #00808E;
            --bg-body: #D3E2F3; --bg-card: #FFFFFF; --text-main: #232D63;
            --text-muted: #232D63; --border: #BBBDB7; --radius: 16px;
            --sidebar-width: 280px; --admin-accent: #00808E;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; }
        .content-max-width { max-width: 1200px; margin: 0 auto; }
        .page-title { margin: 0 0 8px; font-size: 2rem; }
        .page-subtitle { margin: 0 0 24px; color: var(--text-muted); }

        /* Tabs */
        .tab-nav { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 2px solid var(--border); padding-bottom: 0; }
        .tab-btn { padding: 12px 24px; border: none; background: transparent; color: var(--text-muted); font-weight: 700; font-size: 0.95rem; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: 0.2s; font-family: inherit; }
        .tab-btn:hover { color: var(--text-main); background: rgba(0, 128, 142,0.08); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 32px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 0.95rem; font-family: inherit; background: #fff; }
        .btn-primary { background: var(--primary); color: #fff; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-danger { background: #fee2e2; color: #991b1b; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-danger:hover { background: #fecaca; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid var(--border); padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; text-decoration: none; display: inline-block; }
        .btn-inline { background: var(--bg-body); border: 1px solid var(--border); padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; color: var(--text-main); font-size: 0.85rem; }
        .btn-repair { background: #fff7ed; color: #92400e; border: 1px solid #fed7aa; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-repair:hover { background: #ffedd5; }
        .btn-repair:disabled { opacity: 0.5; cursor: not-allowed; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 14px 16px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge-yes { background: #dcfce7; color: #166534; }
        .badge-no { background: #f1f5f9; color: #64748b; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-archived { background: #f1f5f9; color: #64748b; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-v12 { background: #dbeafe; color: #1d4ed8; }
        .badge-v2004 { background: #e0e7ff; color: #3730a3; }

        .alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fff7ed; color: #92400e; }

        .grid-auto { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .muted { color: var(--text-muted); }
        .small { font-size: 0.8rem; }
        .status-inline { display: inline-flex; align-items: center; gap: 8px; }
        .action-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .action-group form { display: inline; }
        .repair-result { font-size: 0.78rem; margin-top: 4px; }
        .repair-ok { color: #166534; }
        .repair-err { color: #991b1b; }

        .file-drop { border: 2px dashed var(--border); border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: 0.2s; color: var(--text-muted); }
        .file-drop:hover, .file-drop.dragover { border-color: var(--primary); background: rgba(0, 128, 142,0.08); color: var(--text-main); }

        .upload-progress-wrap { display: none; margin-top: 16px; padding: 16px 18px; background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; }
        .upload-progress-wrap.visible { display: block; }
        .upload-progress-label { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; font-weight: 700; margin-bottom: 8px; }
        .upload-progress-label .pct { color: var(--primary); }
        .upload-progress-bar { background: #e2e8f0; border-radius: 999px; height: 12px; overflow: hidden; }
        .upload-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #82ACD6, #00808E); border-radius: 999px; transition: width 0.2s ease; }
        .upload-progress-status { margin-top: 8px; font-size: 0.78rem; color: var(--text-muted); }
        .upload-result-ok { margin-top: 10px; padding: 10px 14px; background: #dcfce7; color: #166534; border-radius: 8px; font-weight: 700; font-size: 0.85rem; }
        .upload-result-error { margin-top: 10px; padding: 10px 14px; background: #fee2e2; color: #991b1b; border-radius: 8px; font-weight: 700; font-size: 0.85rem; }

        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 20px; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main>
        <div class="content-max-width">
            <h1 class="page-title">Course Manager</h1>
            <p class="page-subtitle">Manage courses, SCORM packages, certificates, and thumbnails.</p>

            <?php if ($userRole === 'super_admin'): ?>
                <a href="<?php echo buildUrl('admin-course-manager/test-certificate.php'); ?>" class="btn-inline" style="display:inline-block;margin-bottom:16px;background:var(--primary);color:#fff;padding:8px 16px;">Test Certificate Preview</a>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <!-- —— Tabs —— -->
            <div class="tab-nav">
                <button class="tab-btn <?php echo $tab === 'assets' ? 'active' : ''; ?>" onclick="switchTab('assets')">Course Assets</button>
                <button class="tab-btn <?php echo $tab === 'packages' ? 'active' : ''; ?>" onclick="switchTab('packages')">Package Manager</button>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• COURSE ASSETS TAB â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div id="tab-assets" style="<?php echo $tab === 'packages' ? 'display:none;' : ''; ?>">
                <div class="card">
                    <h3 style="margin-top:0;">Edit Course Assets</h3>
                    <p style="color:var(--text-muted); margin-bottom:16px;">Select a course from the library or enter a Course ID manually.</p>
                    
                    <button onclick="document.getElementById('manual-form').style.display='block'" class="btn-inline" style="margin-bottom:16px;">+ Add New Course Manually</button>
                    
                    <div id="manual-form" style="display:none;margin-bottom:20px;padding:24px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);">
                        <form method="POST">
                            <input type="hidden" name="action_save" value="1">
                            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                            <div class="grid-auto">
                                <div class="field">
                                    <label>Course ID *</label>
                                    <input type="text" name="course_id" placeholder="e.g. activeshooterhpcas" required>
                                </div>
                                <div class="field">
                                    <label>Course Title</label>
                                    <input type="text" name="course_title" placeholder="e.g. Active Shooter Response">
                                </div>
                                <div class="field">
                                    <label>Certificate Template</label>
                                    <select name="certificate_template">
                                        <option value="">— None —</option>
                                        <?php foreach ($contentFiles as $f): 
                                            if (strpos($f, 'certificate') !== false || strpos($f, 'cert') !== false || preg_match('/\.(png|jpg|jpeg)$/i', $f)):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Thumbnail Image</label>
                                    <select name="thumbnail">
                                        <option value="">— Default —</option>
                                        <?php foreach ($contentFiles as $f): ?>
                                            <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Organization</label>
                                    <select name="organization_id">
                                        <option value="">— None (Global) —</option>
                                        <?php foreach ($orgs as $org): ?>
                                            <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn-primary">Save Course</button>
                        </form>
                    </div>

                    <form method="POST" style="margin-bottom:16px;">
                        <input type="hidden" name="action_save" value="1">
                        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                        <div class="grid-auto">
                            <div class="field">
                                <label>Pick from Course Library</label>
                                <select name="course_id" onchange="this.form.course_title.value=this.options[this.selectedIndex].dataset.title">
                                    <option value="">— Select Course —</option>
                                    <?php foreach ($availableCourses as $c): 
                                        $cid = $c['id'] ?? $c['courseId'] ?? '';
                                        $title = $c['title'] ?? $c['name'] ?? 'Untitled';
                                        if ($cid):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($cid); ?>" data-title="<?php echo htmlspecialchars($title); ?>"><?php echo htmlspecialchars($title . ' (ID: ' . $cid . ')'); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Course Title</label>
                                <input type="text" name="course_title" placeholder="Auto-filled from selection">
                            </div>
                            <div class="field">
                                <label>Certificate Template</label>
                                <select name="certificate_template">
                                    <option value="">— None —</option>
                                    <?php foreach ($contentFiles as $f): 
                                        if (strpos($f, 'certificate') !== false || strpos($f, 'cert') !== false || preg_match('/\.(png|jpg|jpeg)$/i', $f)):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Thumbnail</label>
                                <select name="thumbnail">
                                    <option value="">— Default —</option>
                                    <?php foreach ($contentFiles as $f): ?>
                                        <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Organization</label>
                                <select name="organization_id">
                                    <option value="">— None (Global) —</option>
                                    <?php foreach ($orgs as $org): ?>
                                        <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Save Course</button>
                    </form>
                </div>

                <div class="card" style="padding:0;overflow:hidden;">
                    <table>
                        <thead>
                            <tr>
                                <th>Course ID</th>
                                <th>Title</th>
                                <th>Organization</th>
                                <th>Certificate</th>
                                <th>Thumbnail</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($localAssets)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No courses configured. Add one above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($localAssets as $a): 
                                    $orgName = '—';
                                    if (!empty($a['organization_id'])) {
                                        foreach ($orgs as $org) {
                                            if ((int)$org['id'] === (int)$a['organization_id']) { $orgName = $org['name']; break; }
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($a['course_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($a['course_title'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($orgName); ?></td>
                                    <td>
                                        <?php if ($a['certificate_template']): ?>
                                            <span class="badge badge-yes"><?php echo htmlspecialchars($a['certificate_template']); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-no">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($a['thumbnail']): ?>
                                            <span class="badge badge-yes"><?php echo htmlspecialchars($a['thumbnail']); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-no">Default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <button onclick="editAsset('<?php echo addslashes($a['course_id']); ?>','<?php echo addslashes($a['course_title']); ?>','<?php echo addslashes($a['certificate_template'] ?? ''); ?>','<?php echo addslashes($a['thumbnail'] ?? ''); ?>')" class="btn-inline" style="margin-right:6px;">Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this course mapping?');">
                                            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                                            <button type="submit" name="action_delete" value="<?php echo htmlspecialchars($a['course_id']); ?>" class="btn-danger">Del</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• PACKAGE MANAGER TAB â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div id="tab-packages" style="<?php echo $tab !== 'packages' ? 'display:none;' : ''; ?>">
                <!-- S3 Repair All -->
                <?php if ($isSuper && $s3Configured): ?>
                <div class="card" style="padding:16px 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                    <div>
                        <strong>S3 Sync Repair</strong>
                        <div class="small muted" style="margin-top:2px;">If a package is missing files, click Repair to re-sync all local files to S3.</div>
                    </div>
                    <button type="button" class="btn-repair" id="repairAllBtn" onclick="repairAll()">Repair All Packages</button>
                </div>
                <div id="repairAllResult" style="margin-bottom:16px;"></div>
                <?php endif; ?>

                <!-- Upload Form -->
                <div class="card">
                    <h3 style="margin-top:0;">Upload New SCORM Package</h3>
                    <form id="uploadForm" method="POST" enctype="multipart/form-data" action="<?php echo buildUrl('admin/scorm-upload-handler.php'); ?>">
                        <?php echo csrfHiddenField(); ?>
                        <div class="field">
                            <label>SCORM Package (.zip)</label>
                            <div class="file-drop" id="file-drop">
                                <div><strong>Drag & drop</strong> your SCORM .zip here, or <strong>click to browse</strong></div>
                                <div class="small muted" style="margin-top:8px;">Must contain imsmanifest.xml. Max 512 MB.</div>
                            </div>
                            <input type="file" name="scorm_file" id="scorm_file" accept=".zip,application/zip" required style="display:none;">
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="field">
                                <label>Course Title (optional)</label>
                                <input type="text" name="package_title" id="package_title" placeholder="Defaults to manifest title">
                            </div>
                            <div class="field">
                                <label>Assign To Organization</label>
                                <?php if ($isSuper): ?>
                                    <select name="organization_id" id="organization_id">
                                        <option value="0">— Unassigned —</option>
                                        <?php foreach ($orgs as $org): if (!$org['status']) continue; ?>
                                            <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <select name="organization_id" id="organization_id">
                                        <option value="<?php echo (int)$currentOrgId; ?>">My Organization</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="field">
                            <label>Description (optional)</label>
                            <textarea name="package_desc" id="package_desc" rows="2" placeholder="Short description shown to admins"></textarea>
                        </div>
                        <button type="submit" class="btn-primary" id="uploadBtn">Upload Package</button>
                        <div class="upload-progress-wrap" id="uploadProgressWrap">
                            <div class="upload-progress-label">
                                <span id="uploadProgressFile">Uploading…</span>
                                <span class="pct" id="uploadProgressPct">0%</span>
                            </div>
                            <div class="upload-progress-bar">
                                <div class="upload-progress-fill" id="uploadProgressFill"></div>
                            </div>
                            <div class="upload-progress-status" id="uploadProgressStatus"></div>
                        </div>
                        <div id="uploadResult"></div>
                    </form>
                </div>

                <!-- Package Library -->
                <div class="card" style="padding:0;overflow:hidden;">
                    <h3 style="padding:20px 24px 0;margin-top:0;">Package Library</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>SCORM</th>
                                <th>SCOs</th>
                                <th>Assignments</th>
                                <th>Status</th>
                                <th>Uploaded</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No SCORM packages uploaded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($packages as $pkg): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($pkg['title']); ?></strong>
                                            <?php if (!empty($pkg['description'])): ?>
                                                <div class="small muted"><?php echo htmlspecialchars(mb_strimwidth($pkg['description'], 0, 80, '…')); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($pkg['scorm_version'] === '2004'): ?>
                                                <span class="badge badge-v2004">SCORM 2004</span>
                                            <?php else: ?>
                                                <span class="badge badge-v12">SCORM 1.2</span>
                                            <?php endif; ?>
                                            <div class="small muted">v<?php echo htmlspecialchars($pkg['version']); ?></div>
                                        </td>
                                        <td><?php echo (int)$pkg['sco_count']; ?></td>
                                        <td>
                                            <?php echo (int)$pkg['assigned_orgs']; ?> org(s)
                                            <?php if ($pkg['assigned_orgs'] > 0 && $isSuper): ?>
                                                <?php
                                                    $assignStmt = $pdo->prepare("SELECT o.name FROM course_assignments ca JOIN organizations o ON o.id = ca.organization_id WHERE ca.package_id = ?");
                                                    $assignStmt->execute([$pkg['id']]);
                                                    $assignedNames = $assignStmt->fetchAll(PDO::FETCH_COLUMN);
                                                ?>
                                                <div class="small muted"><?php echo htmlspecialchars(implode(', ', $assignedNames)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-inline">
                                                <span class="badge badge-<?php echo $pkg['status']; ?>"><?php echo ucfirst($pkg['status']); ?></span>
                                                <form method="POST" style="display:inline;">
                                                    <?php echo csrfHiddenField(); ?>
                                                    <input type="hidden" name="tab" value="packages">
                                                    <input type="hidden" name="action_toggle_status" value="<?php echo (int)$pkg['id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo $pkg['status'] === 'active' ? 'archived' : 'active'; ?>">
                                                    <button type="submit" class="btn-secondary" title="Toggle"><?php echo $pkg['status'] === 'active' ? 'Archive' : 'Activate'; ?></button>
                                                </form>
                                            </span>
                                        </td>
                                        <td class="small muted"><?php echo date('M j, Y', strtotime($pkg['created_at'])); ?></td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <div class="action-group" style="justify-content:flex-end;">
                                                <a href="<?php echo $servePreviewUrl((int)$pkg['id']); ?>" target="_blank" rel="noopener" class="btn-secondary">Preview</a>
                                                <?php if ($isSuper || (int)$pkg['organization_id'] === (int)$currentOrgId): ?>
                                                    <button type="button" class="btn-secondary"
                                                        onclick="showEditPkg(this)"
                                                        data-id="<?php echo (int)$pkg['id']; ?>"
                                                        data-title="<?php echo htmlspecialchars($pkg['title'], ENT_QUOTES); ?>"
                                                        data-desc="<?php echo htmlspecialchars($pkg['description'] ?? '', ENT_QUOTES); ?>"
                                                        data-version="<?php echo htmlspecialchars($pkg['version'] ?? '1.0', ENT_QUOTES); ?>"
                                                        data-status="<?php echo htmlspecialchars($pkg['status'] ?? 'active', ENT_QUOTES); ?>">Edit</button>
                                                    <button type="button" class="btn-secondary" onclick="showReplace(<?php echo (int)$pkg['id']; ?>, '<?php echo addslashes($pkg['title']); ?>')">Replace</button>
                                                    <?php if ($isSuper): ?>
                                                        <button type="button" class="btn-secondary" onclick="showAssign(<?php echo (int)$pkg['id']; ?>, '<?php echo addslashes($pkg['title']); ?>')">Assign</button>
                                                    <?php endif; ?>
                                                    <?php if ($isSuper && $s3Configured): ?>
                                                        <button type="button" class="btn-repair" id="repair-btn-<?php echo (int)$pkg['id']; ?>" onclick="repairPkg(<?php echo (int)$pkg['id']; ?>, '<?php echo addslashes($pkg['title']); ?>')">Repair</button>
                                                        <div id="repair-result-<?php echo (int)$pkg['id']; ?>" class="repair-result"></div>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this SCORM package? This cannot be undone.');">
                                                        <?php echo csrfHiddenField(); ?>
                                                        <input type="hidden" name="tab" value="packages">
                                                        <button type="submit" name="action_delete_package" value="<?php echo (int)$pkg['id']; ?>" class="btn-danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Assign Modal -->
    <div id="assignModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:28px; max-width:420px; width:90%;">
            <h3 style="margin-top:0;" id="assignModalTitle">Assign Package</h3>
            <form method="POST">
                <?php echo csrfHiddenField(); ?>
                <input type="hidden" name="tab" value="packages">
                <input type="hidden" name="action_assign_org_pkg" value="0" id="assignPkgId">
                <div class="field">
                    <label>Assign To Organization</label>
                    <select name="action_assign_org" id="assignOrgSelect" required>
                        <option value="">— Select —</option>
                        <?php foreach ($orgs as $org): if (!$org['status']) continue; ?>
                            <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeAssign()">Cancel</button>
                    <button type="submit" class="btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Package Modal -->
    <div id="editModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:1001; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:28px; max-width:520px; width:90%; max-height:90vh; overflow:auto;">
            <h3 style="margin-top:0;" id="editModalTitle">Edit Package</h3>
            <form method="POST">
                <?php echo csrfHiddenField(); ?>
                <input type="hidden" name="tab" value="packages">
                <input type="hidden" name="action_edit_package" value="0" id="editPkgId">
                <div class="field">
                    <label>Title</label>
                    <input type="text" name="edit_title" id="editPkgTitle" required>
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea name="edit_description" id="editPkgDesc" rows="3" placeholder="Short description shown to admins"></textarea>
                </div>
                <div class="field">
                    <label>Version</label>
                    <input type="text" name="edit_version" id="editPkgVersion" placeholder="e.g. 1.0">
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="edit_status" id="editPkgStatus">
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeEditPkg()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Replace Package Modal -->
    <div id="replaceModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:1002; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:28px; max-width:520px; width:90%; max-height:90vh; overflow:auto;">
            <h3 style="margin-top:0;">Replace SCORM File</h3>
            <p class="small muted" style="margin-top:0;">Upload a new SCORM .zip to replace the content of <strong id="replacePkgTitle"></strong>. The package ID, assignments and enrollments stay the same. Completed progress is kept as history; in-progress sessions restart.</p>
            <form id="replaceForm" method="POST" enctype="multipart/form-data" action="<?php echo buildUrl('admin/scorm-upload-handler.php'); ?>">
                <?php echo csrfHiddenField(); ?>
                <input type="hidden" name="replace_package_id" value="0" id="replacePkgId">
                <div class="field">
                    <label>New SCORM file (.zip)</label>
                    <input type="file" name="scorm_file" accept=".zip,application/zip" required>
                </div>
                <div class="upload-progress-wrap" id="replaceProgressWrap">
                    <div class="upload-progress-label">
                        <span id="replaceProgressFile">Uploading…</span>
                        <span class="pct" id="replaceProgressPct">0%</span>
                    </div>
                    <div class="upload-progress-bar">
                        <div class="upload-progress-fill" id="replaceProgressFill"></div>
                    </div>
                    <div class="upload-progress-status" id="replaceProgressStatus"></div>
                </div>
                <div id="replaceResult"></div>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
                    <button type="button" class="btn-secondary" onclick="closeReplace()">Cancel</button>
                    <button type="submit" id="replaceSubmitBtn" class="btn-primary">Replace Package</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function switchTab(name) {
        window.location.href = '?tab=' + name;
    }
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('active');
    }

    // —— Course Asset editing ——
    function editAsset(courseId, courseTitle, certTemplate, thumb) {
        var form = document.getElementById('manual-form');
        form.style.display = 'block';
        form.querySelector('[name=course_id]').value = courseId;
        form.querySelector('[name=course_title]').value = courseTitle;
        var certSelect = form.querySelector('[name=certificate_template]');
        for (var i = 0; i < certSelect.options.length; i++) {
            if (certSelect.options[i].value === certTemplate) { certSelect.selectedIndex = i; break; }
        }
        var thumbSelect = form.querySelector('[name=thumbnail]');
        for (var i = 0; i < thumbSelect.options.length; i++) {
            if (thumbSelect.options[i].value === thumb) { thumbSelect.selectedIndex = i; break; }
        }
        form.scrollIntoView({behavior:'smooth'});
    }

    // —— SCORM Upload ——
    <?php if ($tab === 'packages'): ?>
    (function() {
        var dropZone = document.getElementById('file-drop');
        var fileInput = document.getElementById('scorm_file');
        if (dropZone && fileInput) {
            dropZone.addEventListener('click', function() { fileInput.click(); });
            dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('dragover'); });
            dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('dragover'); });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault(); dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    var f = fileInput.files[0];
                    dropZone.innerHTML = '<div><strong>' + f.name + '</strong></div><div class="small muted" style="margin-top:8px;">' + (f.size/1024/1024).toFixed(2) + ' MB ready</div>';
                }
            });
            fileInput.addEventListener('change', function() {
                if (fileInput.files.length) {
                    var f = fileInput.files[0];
                    dropZone.innerHTML = '<div><strong>' + f.name + '</strong></div><div class="small muted" style="margin-top:8px;">' + (f.size/1024/1024).toFixed(2) + ' MB ready</div>';
                }
            });
        }

        var uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var f = fileInput.files[0];
                if (!f) { alert('Select a .zip file first.'); return; }
                if (!f.name.toLowerCase().endsWith('.zip')) { alert('Only .zip files supported.'); return; }
                if (f.size > 512*1024*1024) { alert('File exceeds 512 MB max.'); return; }

                var btn = document.getElementById('uploadBtn');
                var wrap = document.getElementById('uploadProgressWrap');
                var fill = document.getElementById('uploadProgressFill');
                var pctEl = document.getElementById('uploadProgressPct');
                var fileLabel = document.getElementById('uploadProgressFile');
                var status = document.getElementById('uploadProgressStatus');
                var result = document.getElementById('uploadResult');

                result.innerHTML = '';
                fill.style.width = '0%';
                pctEl.textContent = '0%';
                fileLabel.textContent = f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)';
                status.textContent = 'Uploading…';
                wrap.classList.add('visible');
                btn.disabled = true;

                var pollTimer = null;

                function pollJob(jobId) {
                    fetch('<?php echo buildUrl("admin/scorm-upload-status.php"); ?>?job_id=' + jobId, { credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.ok) {
                                if (pollTimer) clearInterval(pollTimer);
                                btn.disabled = false;
                                status.textContent = 'Failed.';
                                result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.error || 'Error') + '</div>';
                                return;
                            }
                            var p = data.progress_pct || 0;
                            var barPct = 50 + Math.round(p/2);
                            fill.style.width = barPct + '%';
                            pctEl.textContent = barPct + '%';
                            status.textContent = data.message || 'Processing…';
                            if (data.status === 'done') {
                                if (pollTimer) clearInterval(pollTimer);
                                fill.style.width = '100%';
                                pctEl.textContent = '100%';
                                status.textContent = 'Complete!';
                                result.innerHTML = '<div class="upload-result-ok">&#10003; Package uploaded — "' + (data.title || '').replace(/"/g,'"') + '" (' + (data.scorm_version||'?') + ', ' + (data.sco_count||0) + ' SCOs).</div>';
                                setTimeout(function() { window.location.href = '?tab=packages'; }, 2000);
                            } else if (data.status === 'failed') {
                                if (pollTimer) clearInterval(pollTimer);
                                btn.disabled = false;
                                status.textContent = 'Failed.';
                                result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.message||'Failed') + '</div>';
                            }
                        });
                }

                var xhr = new XMLHttpRequest();
                xhr.open('POST', uploadForm.action, true);
                xhr.upload.addEventListener('progress', function(ev) {
                    if (ev.lengthComputable) {
                        var p = Math.round((ev.loaded/ev.total)*100);
                        fill.style.width = Math.round(p/2) + '%';
                        pctEl.textContent = Math.round(p/2) + '%';
                        status.textContent = 'Uploading ' + (ev.loaded/1024/1024).toFixed(1) + ' of ' + (ev.total/1024/1024).toFixed(1) + ' MB…';
                    }
                });
                xhr.addEventListener('load', function() {
                    var data = {};
                    try { data = JSON.parse(xhr.responseText); } catch(err) { data = {ok:false, error:'Server error'}; }
                    if (xhr.status >= 200 && xhr.status < 300 && data.ok && data.job_id) {
                        fill.style.width = '50%';
                        pctEl.textContent = '50%';
                        status.textContent = 'Processing…';
                        pollTimer = setInterval(function() { pollJob(data.job_id); }, 2000);
                        pollJob(data.job_id);
                    } else {
                        btn.disabled = false;
                        status.textContent = 'Upload failed.';
                        result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.error || 'Upload failed') + '</div>';
                    }
                });
                xhr.addEventListener('error', function() {
                    btn.disabled = false;
                    status.textContent = 'Network error.';
                    result.innerHTML = '<div class="upload-result-error">&#10007; Network error.</div>';
                });
                xhr.send(new FormData(uploadForm));
            });
        }
    })();
    <?php endif; ?>

    // —— Assign modal ——
    function showAssign(pkgId, title) {
        document.getElementById('assignModalTitle').textContent = 'Assign: ' + title;
        document.getElementById('assignPkgId').value = pkgId;
        document.getElementById('assignOrgSelect').value = '';
        document.getElementById('assignModal').style.display = 'flex';
    }
    function closeAssign() {
        document.getElementById('assignModal').style.display = 'none';
    }
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) closeAssign();
    });

    // Edit Package modal
    function showEditPkg(btn) {
        document.getElementById('editPkgId').value = btn.dataset.id;
        document.getElementById('editPkgTitle').value = btn.dataset.title || '';
        document.getElementById('editPkgDesc').value = btn.dataset.desc || '';
        document.getElementById('editPkgVersion').value = btn.dataset.version || '';
        document.getElementById('editPkgStatus').value = btn.dataset.status || 'active';
        document.getElementById('editModal').style.display = 'flex';
    }
    function closeEditPkg() {
        document.getElementById('editModal').style.display = 'none';
    }
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditPkg();
    });

    // —— Replace Package modal ——
    function showReplace(pkgId, title) {
        document.getElementById('replacePkgId').value = pkgId;
        document.getElementById('replacePkgTitle').textContent = title;
        document.getElementById('replaceForm').querySelector('input[type=file]').value = '';
        document.getElementById('replaceResult').innerHTML = '';
        document.getElementById('replaceProgressWrap').classList.remove('visible');
        document.getElementById('replaceSubmitBtn').disabled = false;
        document.getElementById('replaceModal').style.display = 'flex';
    }
    function closeReplace() {
        document.getElementById('replaceModal').style.display = 'none';
    }
    document.getElementById('replaceModal').addEventListener('click', function(e) {
        if (e.target === this) closeReplace();
    });

    var replaceForm = document.getElementById('replaceForm');
    if (replaceForm) {
        replaceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var f = replaceForm.querySelector('input[type=file]').files[0];
            if (!f) { alert('Select a .zip file first.'); return; }
            if (!f.name.toLowerCase().endsWith('.zip')) { alert('Only .zip files supported.'); return; }
            if (f.size > 512*1024*1024) { alert('File exceeds 512 MB max.'); return; }
            if (!confirm('Replace this package with the selected file? Completed progress is kept as history; in-progress sessions will restart.')) return;

            var btn = document.getElementById('replaceSubmitBtn');
            var wrap = document.getElementById('replaceProgressWrap');
            var fill = document.getElementById('replaceProgressFill');
            var pctEl = document.getElementById('replaceProgressPct');
            var fileLabel = document.getElementById('replaceProgressFile');
            var status = document.getElementById('replaceProgressStatus');
            var result = document.getElementById('replaceResult');

            result.innerHTML = '';
            fill.style.width = '0%';
            pctEl.textContent = '0%';
            fileLabel.textContent = f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)';
            status.textContent = 'Uploading…';
            wrap.classList.add('visible');
            btn.disabled = true;

            var pollTimer = null;

            function pollJob(jobId) {
                fetch('<?php echo buildUrl("admin/scorm-upload-status.php"); ?>?job_id=' + jobId, { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.ok) {
                            if (pollTimer) clearInterval(pollTimer);
                            btn.disabled = false;
                            status.textContent = 'Failed.';
                            result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.error || 'Error') + '</div>';
                            return;
                        }
                        var p = data.progress_pct || 0;
                        var barPct = 50 + Math.round(p/2);
                        fill.style.width = barPct + '%';
                        pctEl.textContent = barPct + '%';
                        status.textContent = data.message || 'Processing…';
                        if (data.status === 'done') {
                            if (pollTimer) clearInterval(pollTimer);
                            fill.style.width = '100%';
                            pctEl.textContent = '100%';
                            status.textContent = 'Complete!';
                            result.innerHTML = '<div class="upload-result-ok">&#10003; Package replaced — "' + (data.title || '').replace(/"/g,'"') + '" (' + (data.scorm_version||'?') + ', ' + (data.sco_count||0) + ' SCOs).</div>';
                            setTimeout(function() { window.location.href = '?tab=packages'; }, 2000);
                        } else if (data.status === 'failed') {
                            if (pollTimer) clearInterval(pollTimer);
                            btn.disabled = false;
                            status.textContent = 'Failed.';
                            result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.message||'Failed') + '</div>';
                        }
                    });
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', replaceForm.action, true);
            xhr.upload.addEventListener('progress', function(ev) {
                if (ev.lengthComputable) {
                    var p = Math.round((ev.loaded/ev.total)*100);
                    fill.style.width = Math.round(p/2) + '%';
                    pctEl.textContent = Math.round(p/2) + '%';
                    status.textContent = 'Uploading ' + (ev.loaded/1024/1024).toFixed(1) + ' of ' + (ev.total/1024/1024).toFixed(1) + ' MB…';
                }
            });
            xhr.addEventListener('load', function() {
                var data = {};
                try { data = JSON.parse(xhr.responseText); } catch(err) { data = {ok:false, error:'Server error'}; }
                if (xhr.status >= 200 && xhr.status < 300 && data.ok && data.job_id) {
                    fill.style.width = '50%';
                    pctEl.textContent = '50%';
                    status.textContent = 'Processing…';
                    pollTimer = setInterval(function() { pollJob(data.job_id); }, 2000);
                } else {
                    btn.disabled = false;
                    status.textContent = 'Failed.';
                    result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.error || 'Replace failed') + '</div>';
                }
            });
            xhr.addEventListener('error', function() {
                btn.disabled = false;
                status.textContent = 'Failed.';
                result.innerHTML = '<div class="upload-result-error">&#10007; Network error during upload</div>';
            });
            xhr.send(new FormData(replaceForm));
        });
    }

    // —— S3 Repair ——
    function repairPkg(pkgId, title) {
        var btn = document.getElementById('repair-btn-' + pkgId);
        var res = document.getElementById('repair-result-' + pkgId);
        btn.disabled = true; btn.textContent = 'Repairing…'; res.innerHTML = '';
        fetch('<?php echo buildUrl("admin/scorm-s3-resync.php"); ?>?pkg=' + pkgId, { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false; btn.textContent = 'Repair';
                if (data.ok) res.innerHTML = '<span class="repair-ok">âœ“ ' + data.uploaded + ' uploaded, ' + data.missing + ' missing</span>';
                else res.innerHTML = '<span class="repair-err">âœ— ' + (data.error||'Error') + '</span>';
            })
            .catch(function() { btn.disabled = false; btn.textContent = 'Repair'; res.innerHTML = '<span class="repair-err">âœ— Network error</span>'; });
    }
    function repairAll() {
        var btn = document.getElementById('repairAllBtn');
        var res = document.getElementById('repairAllResult');
        btn.disabled = true; btn.textContent = 'Repairing…';
        res.innerHTML = '<div class="alert" style="background:#fff7ed;color:#92400e;">Running S3 repair for all packages…</div>';
        fetch('<?php echo buildUrl("admin/scorm-s3-resync.php"); ?>?all=1', { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false; btn.textContent = 'Repair All Packages';
                if (data.ok) {
                    var html = '<div class="alert alert-success">âœ“ Repair complete: ' + data.total_uploaded + ' files across ' + data.packages + ' packages.</div>';
                    res.innerHTML = html;
                } else res.innerHTML = '<div class="alert alert-error">âœ— ' + (data.error||'Failed') + '</div>';
            })
            .catch(function() { btn.disabled = false; btn.textContent = 'Repair All Packages'; res.innerHTML = '<div class="alert alert-error">âœ— Network error</div>'; });
    }
    </script>
</body>
</html>