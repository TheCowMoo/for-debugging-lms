<?php
/**
 * ORGANIZATIONS MANAGEMENT
 * Super admin only — manage organizations and their SCORM credentials.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();
requireSuperAdmin();

$pdo = getDbConnection();
ensureOrganizationsTable();

$success_msg = '';
$error_msg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_save_org'])) {
        $id = (int)($_POST['org_id'] ?? 0);
        $name = trim($_POST['org_name'] ?? '');
        $slug = trim($_POST['org_slug'] ?? '');
        $appId = trim($_POST['scorm_app_id'] ?? '');
        $secretKey = trim($_POST['scorm_secret_key'] ?? '');
        $status = isset($_POST['org_status']) ? 1 : 0;

        if ($name === '' || $slug === '') {
            $error_msg = "Organization name and slug are required.";
        } elseif ($id > 0) {
            $stmt = $pdo->prepare("UPDATE organizations SET name = ?, slug = ?, scorm_app_id = ?, scorm_secret_key = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $slug, $appId ?: null, $secretKey ?: null, $status, $id]);
            $success_msg = "Organization updated.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO organizations (name, slug, scorm_app_id, scorm_secret_key, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $appId ?: null, $secretKey ?: null, $status]);
            $success_msg = "Organization created.";
        }
    }

    if (isset($_POST['action_delete_org'])) {
        $id = (int)$_POST['action_delete_org'];
        $pdo->prepare("UPDATE users SET organization_id = NULL WHERE organization_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM user_registrations WHERE organization_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$id]);
        $success_msg = "Organization deleted. Users unlinked.";
    }
}

// Fetch all organizations
$orgs = $pdo->query("SELECT id, name, slug, scorm_app_id, scorm_secret_key, status, created_at FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch user counts by org
$userCounts = [];
$countStmt = $pdo->query("SELECT organization_id, COUNT(*) as cnt, role FROM users GROUP BY organization_id, role");
while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
    $oid = $row['organization_id'] ?: '0';
    if (!isset($userCounts[$oid])) $userCounts[$oid] = ['students' => 0, 'admins' => 0, 'total' => 0];
    if ($row['role'] === 'admin' || $row['role'] === 'super_admin') {
        $userCounts[$oid]['admins'] += (int)$row['cnt'];
    } else {
        $userCounts[$oid]['students'] += (int)$row['cnt'];
    }
    $userCounts[$oid]['total'] += (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        :root { --text-muted: #374151; --border: #BBBDB7; --radius: 16px; }
        * { box-sizing: border-box; }
        body { margin: 0; background-color: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; min-height: 100vh; }
        main { margin-left: var(--sidebar-width); flex: 1; padding: 48px 64px; }
        .content-max-width { max-width: 1100px; margin: 0 auto; }
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 32px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 0.95rem; font-family: inherit; background: #fff; }
        .btn-primary { background: var(--primary); color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; }
        .btn-danger { background: #E4E348; color: #1A2E2A; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 16px 20px; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); font-weight: 700; }
        td { padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .alert { padding: 16px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fff7ed; color: #92400e; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 20px; } .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main>
        <div class="content-max-width">
            <header style="margin-bottom: 32px;">
                <h1 style="margin:0;">Organizations</h1>
                <p style="color:var(--text-muted);">Manage organizations and their SCORM API credentials.</p>
            </header>

            <?php if ($success_msg): ?><div class="alert alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
            <?php if ($error_msg): ?><div class="alert alert-error"><?php echo $error_msg; ?></div><?php endif; ?>

            <div class="card" data-tour="tour-org-create">
                <h3 style="margin-top:0;" id="form-title">Create New Organization</h3>
                <form method="POST">
                    <input type="hidden" name="action_save_org" value="1">
                    <input type="hidden" name="org_id" value="0" id="edit_org_id">
                    <div class="grid-2">
                        <div class="field">
                            <label>Organization Name</label>
                            <input type="text" name="org_name" id="edit_org_name" placeholder="e.g. Acme Organization" required>
                        </div>
                        <div class="field">
                            <label>Slug (URL-friendly)</label>
                            <input type="text" name="org_slug" id="edit_org_slug" placeholder="e.g. acme-organization" required>
                        </div>
                        <div class="field">
                            <label>SCORM App ID (optional)</label>
                            <input type="text" name="scorm_app_id" id="edit_scorm_app_id" placeholder="Leave blank to use global credentials">
                        </div>
                        <div class="field">
                            <label>SCORM Secret Key (optional)</label>
                            <input type="text" name="scorm_secret_key" id="edit_scorm_secret_key" placeholder="Leave blank to use global credentials">
                        </div>
                    </div>
                    <div class="field" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="org_status" value="1" checked id="edit_org_status" style="width:18px;height:18px;">
                        <label for="edit_org_status" style="margin:0;">Active</label>
                    </div>
                    <button type="submit" class="btn-primary">Save Organization</button>
                </form>
            </div>

            <div class="card" style="padding:0;overflow:hidden;" data-tour="tour-org-table">
                <table>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Slug</th>
                            <th>SCORM Keys</th>
                            <th>Status</th>
                            <th>Users</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orgs)): ?>
                            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No organizations yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orgs as $org):
                                $counts = $userCounts[$org['id']] ?? ['students' => 0, 'admins' => 0, 'total' => 0];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($org['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($org['slug']); ?></td>
                                <td>
                                    <?php if ($org['scorm_app_id']): ?>
                                        <span class="badge badge-success">Custom</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9;color:#64748b;">Global</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($org['status']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $counts['total']; ?> (<?php echo $counts['students']; ?> learners, <?php echo $counts['admins']; ?> admins)</td>
                                <td><?php echo date('M j, Y', strtotime($org['created_at'])); ?></td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <button onclick="editOrg(<?php echo $org['id']; ?>,'<?php echo addslashes($org['name']); ?>','<?php echo addslashes($org['slug']); ?>','<?php echo addslashes($org['scorm_app_id'] ?? ''); ?>','<?php echo addslashes($org['scorm_secret_key'] ?? ''); ?>',<?php echo $org['status'] ? 'true' : 'false'; ?>)" class="btn-primary" style="padding:8px 16px;font-size:0.85rem;">Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this organization? All users will be unlinked from it.');">
                                        <button type="submit" name="action_delete_org" value="<?php echo $org['id']; ?>" class="btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
    function editOrg(id, name, slug, appId, secretKey, active) {
        document.getElementById('edit_org_id').value = id;
        document.getElementById('edit_org_name').value = name;
        document.getElementById('edit_org_slug').value = slug;
        document.getElementById('edit_scorm_app_id').value = appId;
        document.getElementById('edit_scorm_secret_key').value = secretKey;
        document.querySelector('input[name="org_status"]').checked = active;
        document.getElementById('form-title').textContent = 'Edit Organization';
    }
    </script>
</body>
</html>