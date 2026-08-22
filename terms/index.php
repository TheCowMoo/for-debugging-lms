<?php
/**
 * TERMS OF USE — Display and acceptance gate.
 * First-time users must scroll to the bottom and click "I Agree".
 */

require_once __DIR__ . '/../bootstrap.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    redirectTo('login/');
}

// If already accepted terms, redirect to dashboard
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && !empty($user['terms_accepted_at'])) {
    redirectTo('dashboard/');
}

$siteName = getSiteName();
$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Terms of Use | <?php echo $siteName; ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            background: var(--bg-body);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .terms-card {
            background: var(--bg-card);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            max-width: 720px;
            width: 100%;
            padding: 40px 48px;
        }
        .terms-logo { text-align: center; margin-bottom: 12px; }
        .terms-logo img { max-height: 48px; }
        .terms-title { text-align: center; font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin: 0 0 4px; }
        .terms-date { text-align: center; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px; }

        .terms-scroll-box {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            height: 340px;
            overflow-y: auto;
            margin-bottom: 24px;
            font-size: 0.92rem;
            line-height: 1.7;
            color: #444;
            background: #fafbfc;
        }
        .terms-scroll-box h3 { font-size: 1rem; color: var(--text-main); margin: 20px 0 8px; }
        .terms-scroll-box h3:first-child { margin-top: 0; }
        .terms-scroll-box ul { margin: 6px 0 12px; padding-left: 20px; }
        .terms-scroll-box li { margin-bottom: 6px; }

        .terms-actions { text-align: center; }
        .btn-agree {
            width: 100%; max-width: 320px;
            padding: 16px;
            background: var(--primary); color: #fff;
            border: none; border-radius: 12px;
            font-weight: 700; font-size: 16px;
            cursor: pointer; opacity: 0.4;
            transition: opacity 0.3s, background 0.2s;
            pointer-events: none;
        }
        .btn-agree.enabled { opacity: 1; pointer-events: auto; }
        .btn-agree.enabled:hover { background: var(--primary-hover); }
        .btn-decline {
            margin-top: 10px;
            background: transparent; color: var(--text-muted);
            border: none; font-size: 14px; cursor: pointer;
            padding: 10px 20px; border-radius: 8px;
        }
        .btn-decline:hover { background: var(--bg-body); }

        @media (max-width: 640px) {
            .terms-card { padding: 24px 20px; }
            .terms-scroll-box { height: 260px; padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="terms-card">
        <div class="terms-logo">
            <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo $siteName; ?>">
        </div>
        <h1 class="terms-title">Terms of Use</h1>
        <p class="terms-date">Effective Date: June 6, 2026</p>

        <div class="terms-scroll-box" id="termsScroll">
            <p>Welcome to the <?php echo $siteName; ?> Learning Management System ("LMS"). By accessing or using this LMS, you agree to comply with these Terms of Use.</p>

            <h3>1. Authorized Use</h3>
            <p>The LMS and its content are provided solely for the personal or internal organizational training purposes authorized by <?php echo $siteName; ?> or the purchasing organization.</p>
            <p>Access to the LMS is granted on a limited, non-exclusive, non-transferable, and revocable basis.</p>
            <p>Users may not share their login credentials or permit any unauthorized individual to access LMS content using their account.</p>

            <h3>2. Course Content and Intellectual Property</h3>
            <p>All content made available through the LMS, including but not limited to courses, videos, assessments, documents, graphics, audio, presentations, learning materials, SCORM content, web-based HTML courses, certifications, methodologies, and related materials ("Content"), is protected by copyright and other intellectual property laws.</p>
            <p>For purposes of these Terms:</p>
            <p><strong>SCORM Content</strong> means learning content packaged in accordance with the Shareable Content Object Reference Model (SCORM) standard for online learning delivery and tracking.</p>
            <p><strong>Web-Based HTML Courses</strong> means browser-accessible training content delivered through HTML, JavaScript, multimedia, or similar web technologies.</p>
            <p>All rights not expressly granted are reserved by <?php echo $siteName; ?> and/or its licensors.</p>

            <h3>3. Prohibited Activities</h3>
            <p>Without prior written permission from <?php echo $siteName; ?>, users may not:</p>
            <ul>
                <li>Copy, reproduce, record, modify, translate, adapt, or create derivative works from any Content;</li>
                <li>Download, extract, capture, scrape, or distribute Content except where expressly permitted;</li>
                <li>Share, sell, sublicense, publish, post, upload, transmit, or otherwise disseminate Content to any third party;</li>
                <li>Use Content for commercial purposes;</li>
                <li>Remove copyright notices, trademarks, or proprietary markings;</li>
                <li>Reverse engineer, decompile, disassemble, or attempt to access underlying source code or course files;</li>
                <li>Circumvent security controls, access restrictions, or usage limitations.</li>
            </ul>

            <h3>4. Monitoring and Enforcement</h3>
            <p><?php echo $siteName; ?> reserves the right to monitor LMS usage for security, compliance, audit, and quality assurance purposes.</p>
            <p>Any unauthorized use, copying, distribution, sharing, or misuse of Content may result in:</p>
            <ul>
                <li>Immediate suspension or termination of LMS access;</li>
                <li>Revocation of certifications or course completions;</li>
                <li>Removal from training programs;</li>
                <li>Legal action to protect intellectual property rights;</li>
                <li>Recovery of damages and enforcement costs where permitted by law.</li>
            </ul>

            <h3>5. No Transfer of Ownership</h3>
            <p>Access to the LMS does not transfer ownership of any Content, software, training materials, methodologies, or intellectual property.</p>
            <p>Users receive only the limited rights expressly granted under these Terms.</p>

            <h3>6. Disclaimer</h3>
            <p>The LMS and its Content are provided for educational and informational purposes. Completion of training does not guarantee competency, certification, compliance, safety outcomes, employment qualifications, or legal defensibility.</p>
            <p>Users and their organizations remain responsible for applying training appropriately within their operational environment.</p>

            <h3>7. Changes to Terms</h3>
            <p><?php echo $siteName; ?> may update these Terms from time to time. Continued use of the LMS following any updates constitutes acceptance of the revised Terms.</p>

            <h3>8. Contact</h3>
            <p>Questions regarding these Terms or requests for permission to use Content should be directed to:</p>
            <p>
                <?php echo $siteName; ?><br>
                <a href="mailto:info@pursuitpathways.com">info@pursuitpathways.com</a><br>
                <a href="https://www.pursuitpathways.com" target="_blank" rel="noopener">www.pursuitpathways.com</a>
            </p>

            <p style="margin-top:20px; font-style:italic; color:#888;">By selecting "I Agree" or by accessing the LMS, you acknowledge that you have read, understood, and agree to these Terms of Use.</p>
        </div>

        <div class="terms-actions">
            <form method="POST" action="<?php echo buildUrl('terms/accept.php'); ?>">
                <button type="submit" class="btn-agree" id="btnAgree" disabled>I Agree</button>
            </form>
            <form method="POST" action="<?php echo buildUrl('logout.php'); ?>">
                <button type="submit" class="btn-decline">I Do Not Agree — Log Out</button>
            </form>
        </div>
    </div>

    <script>
        var scrollBox = document.getElementById('termsScroll');
        var btnAgree = document.getElementById('btnAgree');

        scrollBox.addEventListener('scroll', function() {
            var scrollPct = (scrollBox.scrollTop + scrollBox.clientHeight) / scrollBox.scrollHeight;
            if (scrollPct >= 0.95) {
                btnAgree.disabled = false;
                btnAgree.classList.add('enabled');
            }
        });
    </script>
</body>
</html>