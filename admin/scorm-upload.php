<?php
/**
 * PURSUIT PATHWAYS LMS
 * NATIVE SCORM READER — SCORM Package Management (Phase 1)
 *
 * Admin page for uploading SCORM .zip packages, viewing the package
 * library, and assigning packages to organizations.
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();
requireAdmin();
ensureOrganizationsTable();   // Must be created first — scorm_packages references it
ensureScormTables();

$pdo = getDbConnection();
$currentOrgId = getOrgId();
$isSuper = isSuperAdmin();

$success_msg = '';
$error_msg = '';

// —— Handle actions ——
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Security token mismatch. Please refresh and try again.';
    } elseif (isset($_POST['action_delete_package'])) {
        $packageId = (int)$_POST['action_delete_package'];

        // Verify access: non-super admins can only delete packages in their org
        if (!$isSuper) {
            $check = $pdo->prepare("SELECT id FROM scorm_packages WHERE id = ? AND organization_id = ?");
            $check->execute([$packageId, $currentOrgId]);
            if (!$check->fetch()) {
                $error_msg = 'You do not have permission to delete this package.';
            }
        }

        if ($error_msg === '') {
            try {
                // Delete files from disk first
                $pkg = $pdo->prepare("SELECT upload_path FROM scorm_packages WHERE id = ?");
                $pkg->execute([$packageId]);
                $path = $pkg->fetchColumn();
                if ($path) {
                    $dir = SCORM_STORAGE_PATH . '/' . $packageId;
                    if (is_dir($dir)) {
                        // Recursive delete
                        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
                        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($files as $f) {
                            if ($f->isDir()) {
                                @rmdir($f->getRealPath());
                            } else {
                                @unlink($f->getRealPath());
                            }
                        }
                        @rmdir($dir);
                    }
                }
                // —— Delete S3 objects for this package ——
                if (isS3Configured()) {
                    $s3Prefix = S3_PREFIX . $packageId . '/';
                    $s3Deleted = s3DeletePrefix($s3Prefix);
                    error_log('[SCORM] S3 cleanup on delete: pkg=' . $packageId . ' objects_deleted=' . $s3Deleted);
                }
                $pdo->prepare("DELETE FROM scorm_packages WHERE id = ?")->execute([$packageId]);
                $success_msg = 'Package deleted successfully.';
            } catch (PDOException $e) {
                error_log('[SCORM] Delete failed: ' . $e->getMessage());
                $error_msg = 'Failed to delete package. It may have related records.';
            }
        }
    } elseif (isset($_POST['action_toggle_status'])) {
        $packageId = (int)$_POST['action_toggle_status'];
        $newStatus = $_POST['new_status'] === 'archived' ? 'archived' : 'active';

        $permOk = true;
        if (!$isSuper) {
            $check = $pdo->prepare("SELECT id FROM scorm_packages WHERE id = ? AND organization_id = ?");
            $check->execute([$packageId, $currentOrgId]);
            if (!$check->fetch()) {
                $permOk = false;
                $error_msg = 'You do not have permission to modify this package.';
            }
        }

        if ($permOk) {
            $pdo->prepare("UPDATE scorm_packages SET status = ? WHERE id = ?")->execute([$newStatus, $packageId]);
            $success_msg = 'Package status updated.';
        }
    } elseif (isset($_POST['action_assign_org'])) {
        $packageId = (int)$_POST['action_assign_org_pkg'];
        $targetOrgId = (int)($_POST['action_assign_org'] ?? 0);

        if (!$isSuper) {
            $targetOrgId = $currentOrgId;
        }

        if ($targetOrgId > 0) {
            try {
                $pdo->prepare("INSERT IGNORE INTO course_assignments (package_id, organization_id, assigned_by)
                               VALUES (?, ?, ?)")
                    ->execute([$packageId, $targetOrgId, (int)($_SESSION['user_id'] ?? 0)]);
                $success_msg = 'Package assigned to organization.';
            } catch (PDOException $e) {
                error_log('[SCORM] Assign failed: ' . $e->getMessage());
                $error_msg = 'Failed to assign package.';
            }
        } else {
            $error_msg = 'Please select an organization to assign to.';
        }
    } elseif (isset($_POST['action_unassign_org'])) {
        $packageId = (int)$_POST['action_unassign_org_pkg'];
        $targetOrgId = (int)$_POST['action_unassign_org'];

        if (!$isSuper) {
            $targetOrgId = $currentOrgId;
        }

        $pdo->prepare("DELETE FROM course_assignments WHERE package_id = ? AND organization_id = ?")
            ->execute([$packageId, $targetOrgId]);
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
                if (!$check->fetch()) {
                    $permOk = false;
                    $error_msg = 'You do not have permission to edit this package.';
                }
            }
            if ($permOk) {
                try {
                    $pdo->prepare("UPDATE scorm_packages SET title = ?, description = ?, version = ?, status = ? WHERE id = ?")
                        ->execute([$editTitle, $editDescription !== '' ? $editDescription : null, $editVersion, $editStatus, $packageId]);
                    $success_msg = 'Package updated.';
                } catch (PDOException $e) {
                    error_log('[SCORM] Edit failed: ' . $e->getMessage());
                    $error_msg = 'Failed to update package.';
                }
            }
        }
    }
}

// —— Fetch packages ——
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

// Fetch all orgs for the assignment dropdown
$orgs = $pdo->query("SELECT id, name, status FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Service URL template for preview — uses the SCORM player wrapper
// (scorm-player/index.php?pkg={id}) so admins see the full player
// experience with the RTE active.
$servePreviewUrl = function (int $pkgId): string {
    return buildUrl('scorm-player/?pkg=' . $pkgId);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCORM Packages | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
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
            --radius: 16px;
            --sidebar-width: 280px;
            --admin-accent: #00808E;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; width: 100%; }
        .content-max-width { max-width: 1100px; margin: 0 auto; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 32px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 0.95rem; font-family: inherit; background: #fff; }
        .field input[type="file"] { padding: 10px; }
        .btn-primary { background: var(--primary); color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid var(--border); padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-danger { background: #fee2e2; color: #991b1b; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-danger:hover { background: #fecaca; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 16px 20px; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 14px 20px; border-bottom: 1px solid var(--border); font-size: 0.9rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-archived { background: #f1f5f9; color: #64748b; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-v12 { background: #dbeafe; color: #1d4ed8; }
        .badge-v2004 { background: #e0e7ff; color: #3730a3; }

        .alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fff7ed; color: #92400e; }

        .muted { color: var(--text-muted); }
        .small { font-size: 0.8rem; }
        .mono { font-family: 'Consolas', 'Courier New', monospace; font-size: 0.8rem; color: #64748b; }
        .file-drop { border: 2px dashed var(--border); border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: 0.2s; color: var(--text-muted); }
        .file-drop:hover, .file-drop.dragover { border-color: var(--primary); background: rgba(0, 128, 142, 0.08); color: var(--text-main); }

        /* Upload progress bar */
        .upload-progress-wrap { display: none; margin-top: 16px; padding: 16px 18px; background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; }
        .upload-progress-wrap.visible { display: block; }
        .upload-progress-label { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; font-weight: 700; margin-bottom: 8px; }
        .upload-progress-label .pct { color: var(--primary); }
        .upload-progress-bar { background: #e2e8f0; border-radius: 999px; height: 12px; overflow: hidden; }
        .upload-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #82ACD6, #00808E); border-radius: 999px; transition: width 0.2s ease; }
        .upload-progress-status { margin-top: 8px; font-size: 0.78rem; color: var(--text-muted); }
        .upload-result-ok { margin-top: 10px; padding: 10px 14px; background: #dcfce7; color: #166534; border-radius: 8px; font-weight: 700; font-size: 0.85rem; }
        .upload-result-error { margin-top: 10px; padding: 10px 14px; background: #fee2e2; color: #991b1b; border-radius: 8px; font-weight: 700; font-size: 0.85rem; }

        .status-inline { display: inline-flex; align-items: center; gap: 8px; }
        .status-inline form { display: inline; }
        .action-group { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .action-group form { display: inline; }
        .btn-repair { background: #fff7ed; color: #92400e; border: 1px solid #fed7aa; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-repair:hover { background: #ffedd5; }
        .btn-repair:disabled { opacity: 0.5; cursor: not-allowed; }
        .repair-result { font-size: 0.78rem; margin-top: 4px; }
        .repair-ok { color: #166534; }
        .repair-err { color: #991b1b; }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main>
        <div class="content-max-width">
            <header style="margin-bottom: 32px;">
                <h1 style="margin:0;">SCORM Packages</h1>
                <p style="color:var(--text-muted); margin-top:8px;">Upload and manage native SCORM course packages. Content is stored on your server and tracked by the native SCORM reader.</p>
            </header>

            <?php if ($success_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
            <?php if ($error_msg): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

            <!-- —— S3 Repair All —— -->
            <?php if ($isSuper && isS3Configured()): ?>
            <div class="card" style="padding:16px 24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div>
                    <strong>S3 Sync Repair</strong>
                    <div class="small muted" style="margin-top:2px;">If a package is missing files (e.g. videos dropped during upload), click Repair to re-sync all local files to S3.</div>
                </div>
                <button type="button" class="btn-repair" id="repairAllBtn" onclick="repairAll()">Repair All Packages</button>
            </div>
            <div id="repairAllResult" style="margin-bottom:16px;"></div>
            <?php endif; ?>

            <!-- —— Upload Form —— -->
            <div class="card">
                <h3 style="margin-top:0;">Upload New SCORM Package</h3>
                    <form id="uploadForm" method="POST" enctype="multipart/form-data" action="scorm-upload-handler.php">
                    <?php echo csrfHiddenField(); ?>

                    <div class="field">
                        <label for="file-drop">SCORM Package (.zip)</label>
                        <div class="file-drop" id="file-drop">
                            <div><strong>Drag & drop</strong> your SCORM .zip here, or <strong>click to browse</strong></div>
                            <div class="small muted" style="margin-top:8px;">Must contain imsmanifest.xml. Max 512 MB. Supports SCORM 1.2 and 2004.</div>
                        </div>
                        <input type="file" name="scorm_file" id="scorm_file" accept=".zip,application/zip" required style="display:none;">
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="field">
                            <label for="package_title">Course Title (optional)</label>
                            <input type="text" name="package_title" id="package_title" placeholder="Defaults to manifest title">
                        </div>
                        <div class="field">
                            <label for="organization_id">Assign To Organization</label>
                            <?php if ($isSuper): ?>
                                <select name="organization_id" id="organization_id">
                                    <option value="0">— Unassigned (Super Admin) —</option>
                                    <?php foreach ($orgs as $org): if (!$org['status']) continue; ?>
                                        <option value="<?php echo (int)$org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select name="organization_id" id="organization_id">
                                    <option value="<?php echo (int)$currentOrgId; ?>"><?php echo 'My Organization'; ?></option>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="package_desc">Description (optional)</label>
                        <textarea name="package_desc" id="package_desc" rows="2" placeholder="Short description shown to admins"></textarea>
                    </div>

                    <button type="submit" class="btn-primary" id="uploadBtn">Upload Package</button>
                    <span id="uploadProgress" class="muted small" style="margin-left:12px;"></span>

                    <!-- Real-time upload progress bar -->
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

            <!-- —— Package Library —— -->
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
                                        <?php if ($isSuper && $pkg['organization_id'] !== null): ?>
                                            <div class="small" style="color:var(--accent);">
                                                <?php
                                                    $orgName = 'Org #' . $pkg['organization_id'];
                                                    foreach ($orgs as $org) { if ((int)$org['id'] === (int)$pkg['organization_id']) { $orgName = $org['name']; break; } }
                                                    echo 'Org: ' . htmlspecialchars($orgName);
                                                ?>
                                            </div>
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
                                                // Show which orgs
                                                $assignStmt = $pdo->prepare("SELECT o.name FROM course_assignments ca JOIN organizations o ON o.id = ca.organization_id WHERE ca.package_id = ?");
                                                $assignStmt->execute([$pkg['id']]);
                                                $assignedNames = $assignStmt->fetchAll(PDO::FETCH_COLUMN);
                                            ?>
                                            <div class="small muted"><?php echo htmlspecialchars(implode(', ', $assignedNames)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $statusBadge = 'badge-' . $pkg['status'];
                                            $statusLabel = ucfirst($pkg['status']);
                                        ?>
                                        <span class="status-inline">
                                            <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfHiddenField(); ?>
                                                <input type="hidden" name="action_toggle_status" value="<?php echo (int)$pkg['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $pkg['status'] === 'active' ? 'archived' : 'active'; ?>">
                                                <button type="submit" class="btn-secondary" title="Toggle active/archived">
                                                    <?php echo $pkg['status'] === 'active' ? 'Archive' : 'Activate'; ?>
                                                </button>
                                            </form>
                                        </span>
                                    </td>
                                    <td class="small muted"><?php echo date('M j, Y g:i A', strtotime($pkg['created_at'])); ?></td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <div class="action-group" style="justify-content:flex-end;">
                                            <a href="<?php echo $servePreviewUrl((int)$pkg['id']); ?>" target="_blank" rel="noopener" class="btn-secondary" style="text-decoration:none; display:inline-block;">Preview</a>

                                            <?php if ($isSuper || (int)$pkg['organization_id'] === (int)$currentOrgId): ?>
                                                <button type="button" class="btn-secondary"
                                                    onclick="showEditPkg(this)"
                                                    data-id="<?php echo (int)$pkg['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($pkg['title'], ENT_QUOTES); ?>"
                                                    data-desc="<?php echo htmlspecialchars($pkg['description'] ?? '', ENT_QUOTES); ?>"
                                                    data-version="<?php echo htmlspecialchars($pkg['version'] ?? '1.0', ENT_QUOTES); ?>"
                                                    data-status="<?php echo htmlspecialchars($pkg['status'] ?? 'active', ENT_QUOTES); ?>">Edit</button>

                                                <!-- Assign to org (super admin) -->
                                                <?php if ($isSuper): ?>
                                                    <button type="button" class="btn-secondary" onclick="showAssign(<?php echo (int)$pkg['id']; ?>, '<?php echo addslashes($pkg['title']); ?>')">Assign</button>
                                                <?php endif; ?>

                                                <!-- Repair S3 Sync -->
                                            <?php if ($isSuper && isS3Configured()): ?>
                                                <button type="button" class="btn-repair" id="repair-btn-<?php echo (int)$pkg['id']; ?>" onclick="repairPkg(<?php echo (int)$pkg['id']; ?>, '<?php echo addslashes($pkg['title']); ?>')">Repair</button>
                                                <div id="repair-result-<?php echo (int)$pkg['id']; ?>" class="repair-result"></div>
                                            <?php endif; ?>

                                            <!-- Delete -->
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this SCORM package and all its files? This cannot be undone.');">
                                                    <?php echo csrfHiddenField(); ?>
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
    </main>

    <!-- Assign Modal -->
    <div id="assignModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:28px; max-width:420px; width:90%;">
            <h3 style="margin-top:0;" id="assignModalTitle">Assign Package</h3>
            <form method="POST">
                <?php echo csrfHiddenField(); ?>
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

    <script>
        // —— File Drop Zone ——
        const dropZone = document.getElementById('file-drop');
        const fileInput = document.getElementById('scorm_file');

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                const f = fileInput.files[0];
                dropZone.innerHTML = '<div><strong>' + f.name + '</strong></div><div class="small muted" style="margin-top:8px;">' + (f.size / 1024 / 1024).toFixed(2) + ' MB ready to upload</div>';
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                const f = fileInput.files[0];
                dropZone.innerHTML = '<div><strong>' + f.name + '</strong></div><div class="small muted" style="margin-top:8px;">' + (f.size / 1024 / 1024).toFixed(2) + ' MB ready to upload</div>';
            }
        });

        // —— File size validation + AJAX upload with real progress bar ——
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const f = fileInput.files[0];
            if (!f) { alert('Please select a .zip file first.'); return; }
            if (!f.name.toLowerCase().endsWith('.zip')) { alert('Only .zip files are supported.'); return; }
            if (f.size > 512 * 1024 * 1024) { alert('File exceeds 512 MB maximum.'); return; }

            const form = this;
            const btn = document.getElementById('uploadBtn');
            const wrap = document.getElementById('uploadProgressWrap');
            const fill = document.getElementById('uploadProgressFill');
            const pct = document.getElementById('uploadProgressPct');
            const fileLabel = document.getElementById('uploadProgressFile');
            const status = document.getElementById('uploadProgressStatus');
            const result = document.getElementById('uploadResult');

            // Reset UI
            result.innerHTML = '';
            fill.style.width = '0%';
            pct.textContent = '0%';
            fileLabel.textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
            status.textContent = 'Uploading to server…';
            wrap.classList.add('visible');
            btn.disabled = true;

            let pollTimer = null;

            // —— Phase 2: Poll the background job for progress ——
            function pollJob(jobId) {
                fetch('scorm-upload-status.php?job_id=' + jobId, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok) {
                            clearInterval(pollTimer);
                            btn.disabled = false;
                            status.textContent = 'Processing failed.';
                            result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.error || 'Unknown error') + '</div>';
                            return;
                        }
                        const p = data.progress_pct || 0;
                        // Upload phase = 0-50% on the bar; processing = 50-100%
                        const barPct = 50 + Math.round(p / 2);
                        fill.style.width = barPct + '%';
                        pct.textContent = barPct + '%';
                        status.textContent = data.message || 'Processing…';

                        if (data.status === 'done') {
                            clearInterval(pollTimer);
                            fill.style.width = '100%';
                            pct.textContent = '100%';
                            status.textContent = 'Complete!';
                            result.innerHTML = '<div class="upload-result-ok">&#10003; Package uploaded — "' +
                                ((data.title || '').replace(/"/g, '&quot;')) + '" (' +
                                (data.scorm_version || '?') + ', ' + (data.sco_count || 0) + ' SCOs, ' +
                                (data.files_extracted || 0) + ' files).</div>';
                            setTimeout(function() { window.location.reload(); }, 2000);
                        } else if (data.status === 'failed') {
                            clearInterval(pollTimer);
                            btn.disabled = false;
                            status.textContent = 'Processing failed.';
                            result.innerHTML = '<div class="upload-result-error">&#10007; ' + (data.message || 'Processing failed') + '</div>';
                        }
                        // else: queued/running — keep polling
                    })
                    .catch(err => {
                        // Network hiccup — keep polling
                        console.warn('Poll error:', err);
                    });
            }

            // —— Phase 1: Upload the file to the server ——
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);

            xhr.upload.addEventListener('progress', function(ev) {
                if (ev.lengthComputable) {
                    const p = Math.round((ev.loaded / ev.total) * 100);
                    // Upload phase occupies 0-50% of the bar
                    fill.style.width = Math.round(p / 2) + '%';
                    pct.textContent = Math.round(p / 2) + '%';
                    status.textContent = 'Uploading ' + (ev.loaded / 1024 / 1024).toFixed(1) + ' of ' + (ev.total / 1024 / 1024).toFixed(1) + ' MB…';
                }
            });

            xhr.addEventListener('load', function() {
                let data = {};
                try { data = JSON.parse(xhr.responseText); }
                catch (err) { data = { ok: false, error: 'Unexpected server response.' }; }

                if (xhr.status >= 200 && xhr.status < 300 && data.ok && data.job_id) {
                    // File received — now poll for background processing progress
                    fill.style.width = '50%';
                    pct.textContent = '50%';
                    status.textContent = 'File received. Processing in background…';
                    pollTimer = setInterval(function() { pollJob(data.job_id); }, 2000);
                    pollJob(data.job_id); // immediate first poll
                } else {
                    btn.disabled = false;
                    status.textContent = 'Upload failed (HTTP ' + xhr.status + ').';
                    const errMsg = data.error ? data.error.replace(/"/g, '&quot;') : ('Upload failed (HTTP ' + xhr.status + ').');
                    result.innerHTML = '<div class="upload-result-error">&#10007; ' + errMsg + '</div>';
                    if (xhr.status === 403) {
                        result.innerHTML += '<div class="small muted" style="margin-top:6px;">Your session may have expired. <a href="' + window.location.href + '" style="color:var(--accent);">Refresh this page</a> and try again.</div>';
                    }
                }
            });

            xhr.addEventListener('error', function() {
                btn.disabled = false;
                status.textContent = 'Network error.';
                result.innerHTML = '<div class="upload-result-error">&#10007; Network error during upload. Please try again.</div>';
            });

            xhr.send(new FormData(form));
        });

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

        // —— S3 Repair ——
        function repairPkg(pkgId, title) {
            const btn = document.getElementById('repair-btn-' + pkgId);
            const res = document.getElementById('repair-result-' + pkgId);
            btn.disabled = true;
            btn.textContent = 'Repairing…';
            res.innerHTML = '';
            fetch('scorm-s3-resync.php?pkg=' + pkgId, { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.textContent = 'Repair';
                    if (data.ok) {
                        res.innerHTML = '<span class="repair-ok">âœ“ ' + data.uploaded + ' uploaded, ' + data.missing + ' missing, ' + data.failed + ' failed</span>';
                    } else {
                        res.innerHTML = '<span class="repair-err">âœ— ' + (data.error || 'Error') + '</span>';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = 'Repair';
                    res.innerHTML = '<span class="repair-err">âœ— Network error</span>';
                });
        }

        function repairAll() {
            const btn = document.getElementById('repairAllBtn');
            const res = document.getElementById('repairAllResult');
            btn.disabled = true;
            btn.textContent = 'Repairing…';
            res.innerHTML = '<div class="alert" style="background:#fff7ed;color:#92400e;">Running S3 repair for all packages… This may take a few minutes for large packages.</div>';
            fetch('scorm-s3-resync.php?all=1', { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.textContent = 'Repair All Packages';
                    if (data.ok) {
                        let html = '<div class="alert alert-success">âœ“ Repair complete: ' + data.total_uploaded + ' files uploaded across ' + data.packages + ' packages.';
                        if (data.total_failed > 0) html += ' ' + data.total_failed + ' failed (check server logs).';
                        html += '</div>';
                        if (data.details && data.details.length) {
                            html += '<ul style="font-size:0.82rem;margin:0 0 16px;padding-left:20px;">';
                            data.details.forEach(d => {
                                html += '<li>Pkg #' + d.pkg + ' — ' + d.uploaded + ' uploaded, ' + d.missing + ' missing</li>';
                            });
                            html += '</ul>';
                        }
                        res.innerHTML = html;
                    } else {
                        res.innerHTML = '<div class="alert alert-error">âœ— ' + (data.error || 'Repair failed') + '</div>';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = 'Repair All Packages';
                    res.innerHTML = '<div class="alert alert-error">âœ— Network error during repair</div>';
                });
        }
    </script>
</body>
</html>