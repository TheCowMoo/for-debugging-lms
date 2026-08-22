<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * DEDICATED SUPPORT CENTER - UNIFORMED
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$currentUser = getCurrentUser();
$userRole = $currentUser['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support | <?php echo getSiteName(); ?></title>
    <link rel="icon" type="image/png" href="<?php echo getFaviconUrl(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo assetUrl('includes/main.css'); ?>">
    <style>
        <?php renderBrandStyles(); ?>

        * { box-sizing: border-box; }

        body { 
            margin: 0; 
            background: var(--bg-body); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            display: flex; 
            min-height: 100vh; 
        }

        /* MAIN CONTENT */
        main { 
            margin-left: var(--sidebar-width); 
            flex: 1; 
            padding: 48px 64px; 
            width: 100%;
        }

        .support-container { max-width: 1100px; margin: 0 auto; }

        .support-header { margin-bottom: 40px; }
        .support-header h1 { font-size: 2rem; font-weight: 700; margin: 0; color: var(--text-main); }
        .support-header p { color: var(--text-muted); font-size: 1.1rem; margin-top: 8px; }

        .support-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 40px;
            align-items: start;
        }

        .info-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .contact-method { margin-bottom: 10px; }
        .contact-method label { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--primary); letter-spacing: 1px; display: block; margin-bottom: 8px; }
        .contact-method div { font-size: 1.1rem; font-weight: 600; color: var(--text-main); }
        .contact-method a { color: inherit; text-decoration: none; }

        .form-wrapper {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.04);
            min-height: 800px; 
        }

        @media (max-width: 1024px) {
            main { margin-left: 0; padding: 80px 20px 20px; }
            .support-grid { grid-template-columns: 1fr; gap: 24px; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <?php require_once __DIR__ . '/../includes/tour.php'; renderTourButton(); renderTour($userRole); ?>

    <main>
        <div class="support-container">
            <div class="support-header">
                <h1>Got questions? We’re here.</h1>
                <p>Use the form below to reach our technical team or contact us directly.</p>
            </div>

            <div class="support-grid">
                <aside>
                    <div class="info-card">
                        <div class="contact-method">
                            <label>Direct Email</label>
                            <div><a href="mailto:info@pursuitpathways.com">info@pursuitpathways.com</a></div>
                        </div>
                    </div>

                    <div class="info-card" style="background: #eff6ff; border: 1px solid #dbeafe;">
                        <h4 style="margin: 0 0 10px 0; color: var(--primary);">Support Hours</h4>
                        <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; margin: 0;">
                            Monday – Friday, 9:00 AM to 5:00 PM EST. Most inquiries are resolved within 48 business hours.
                        </p>
                    </div>
                </aside>

                <div class="form-wrapper">
                    <iframe
                        src="https://booking.pursuitpathways.com/widget/form/g7Tq2OvfxEcmOLUOYlRs"
                        style="width:100%;height:100%;border:none;min-height: 800px;"
                        id="inline-g7Tq2OvfxEcmOLUOYlRs" 
                        title="LMS"
                    ></iframe>
                    <script src="https://booking.pursuitpathways.com/js/form_embed.js"></script>
                </div>
            </div>
        </div>
    </main>

    

</body>
</html>