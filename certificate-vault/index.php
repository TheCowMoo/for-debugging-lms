<?php
/**
 * Certificate Vault - dynamically pulls completed courses.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$currentUser = getCurrentUser();
$firstName = $currentUser['first_name'];
$userEmail = $currentUser['email'];
$userRole = $currentUser['role'];

// Define the two known course templates
$courseTemplates = [
    'activeshooterhpcas' => 'certificate_pursuitpathways_activeshooter.png',
    'RecognizingUnderstandingHumanTraffickinghpcas' => 'certificate_pursuitpathways_humantrafficking.jpg',
];

$certifications = [];

if (!empty($userEmail)) {
    $registrations = fetchScormRegistrations(['learnerId' => $userEmail]);
    if (!empty($registrations)) {
        foreach ($registrations as $reg) {
            $courseId = $reg['course']['id'] ?? '';
            $title = $reg['course']['title'] ?? 'Untitled Course';
            $isCompleted = ($reg['registrationCompletion'] ?? '') === 'COMPLETED';
            $pct = round(($reg['registrationCompletionAmount'] ?? 0) * 100);
            $lastUpdated = !empty($reg['updated']) ? date('M d, Y', strtotime($reg['updated'])) : 'N/A';
            $template = $courseTemplates[$courseId] ?? '';

            $certifications[] = [
                'title' => $title,
                'status' => $isCompleted ? 'Completed' : "In Progress ({$pct}%)",
                'is_completed' => $isCompleted,
                'type' => 'Certificate',
                'date' => $lastUpdated,
                'template' => $isCompleted ? $template : '',
                'courseId' => $courseId,
                'progress' => $pct,
            ];
        }
    }
}

// Sort: completed first, then by progress desc
usort($certifications, function ($a, $b) {
    if ($a['is_completed'] !== $b['is_completed']) {
        return $b['is_completed'] <=> $a['is_completed'];
    }
    return $b['progress'] <=> $a['progress'];
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificates | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo buildUrl('includes/main.css'); ?>">
    <style>
        :root {
            --primary: #82ACD6;
            --link: #00808E;
            --accent: #00808E;
            --bg-body: #D3E2F3;
            --bg-card: #ffffff;
            --text-main: #232D63;
            --text-muted: #232D63;
            --border: #BBBDB7;
            --radius: 16px;
            --sidebar-width: 280px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; min-height: 100vh; background: var(--bg-body); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; display: flex; }
        main { margin-left: var(--sidebar-width); width: 100%; padding: 48px 64px; }
        .content-max-width { max-width: 1040px; margin: 0 auto; }
        .page-title { margin: 0 0 12px; font-size: 2rem; }
        .page-subtitle { margin: 0 0 24px; color: var(--text-muted); }
        .vault-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 24px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.05); }
        .vault-card h2 { margin: 0 0 12px; font-size: 1.15rem; }
        .vault-card p { margin: 0; color: var(--text-muted); }
        .vault-list { display: grid; gap: 14px; margin-top: 18px; }
        .vault-item { display: grid; grid-template-columns: 1fr auto; gap: 14px; padding: 18px; border-radius: 18px; border: 1px solid var(--border); background: #F4F7F6; }
        .vault-meta { display: grid; gap: 6px; }
        .vault-title { margin: 0; font-size: 1rem; font-weight: 700; color: var(--text-main); }
        .vault-subtitle { margin: 0; color: var(--text-muted); font-size: 0.92rem; }
        .vault-actions { display: grid; gap: 10px; justify-items: end; }
        .vault-link { color: var(--link); font-weight: 700; text-decoration: none; }
        .vault-link:hover { text-decoration: underline; }
        .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 999px; font-size: 0.82rem; font-weight: 700; }
        .status-completed { background: rgba(0, 112, 84, 0.12); color: #065f46; }
        .status-inprogress { background: rgba(217, 119, 36, 0.12); color: #92400e; }
        .empty-state { padding: 40px; text-align: center; color: var(--text-muted); }
        @media (max-width: 1024px) { main { margin-left: 0; padding: 80px 20px 20px; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <div class="content-max-width">
            <h1 class="page-title">Certificates</h1>
            <p class="page-subtitle">Your certification records are pulled live from your completed courses.</p>

            <div class="vault-card">
                <h2>Your Certifications</h2>

                <?php if (empty($certifications)): ?>
                    <div class="empty-state">
                        <p>No course registrations found. Enroll in a course to see your certificates here.</p>
                        <a href="<?php echo buildUrl('course-page'); ?>" class="vault-link">Browse Courses</a>
                    </div>
                <?php else: ?>
                    <div class="vault-list">
                        <?php foreach ($certifications as $cert): ?>
                            <div class="vault-item">
                                <div class="vault-meta">
                                    <p class="vault-title"><?php echo htmlspecialchars($cert['title']); ?></p>
                                    <p class="vault-subtitle"><?php echo htmlspecialchars($cert['type']); ?> • <?php echo htmlspecialchars($cert['date']); ?></p>
                                    <p class="vault-subtitle" style="font-size:0.82rem;">Progress: <?php echo $cert['progress']; ?>%</p>
                                </div>
                                <div class="vault-actions">
                                    <span class="status-pill <?php echo $cert['is_completed'] ? 'status-completed' : 'status-inprogress'; ?>">
                                        <?php echo htmlspecialchars($cert['status']); ?>
                                    </span>
                                    <?php if ($cert['is_completed'] && !empty($cert['template'])): ?>
                                        <a href="<?php echo buildUrl('certificate-vault/certificate.php?template=' . urlencode($cert['template'])); ?>" class="vault-link">View / Download Certificate</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>