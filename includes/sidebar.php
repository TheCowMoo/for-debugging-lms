<?php
// Shared sidebar include. Expects `buildUrl()` and `$userRole` to be available.
$uri = $_SERVER['REQUEST_URI'] ?? '';
function isActive(string $path): string {
    global $uri;
    return (strpos($uri, $path) !== false) ? ' active' : '';
}
?>
<div class="mobile-bar">
    <img src="<?php echo getLogoUrl(); ?>" alt="Logo" class="logo-img">
    <button class="menu-toggle" onclick="toggleMenu()" aria-label="Open menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>
</div>
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<nav id="sidebar">
    <div class="logo-area">
        <img src="<?php echo getLogoUrl(); ?>" alt="<?php echo getSiteName(); ?>" class="sidebar-logo">
        <button class="sidebar-close" onclick="toggleMenu()" aria-label="Close menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="nav-links-container">
        <div class="nav-section-label">Learner Menu</div>
        <a href="<?php echo buildUrl('dashboard'); ?>" class="nav-link<?php echo isActive('/dashboard'); ?>" data-tour="tour-dashboard">Dashboard</a>
        <a href="<?php echo buildUrl('course-page'); ?>" class="nav-link<?php echo isActive('/course-page'); ?>" data-tour="tour-courses">My Courses</a>
        <a href="<?php echo buildUrl('progress'); ?>" class="nav-link<?php echo isActive('/progress'); ?>" data-tour="tour-progress">Learning Progress</a>
        <a href="<?php echo buildUrl('certificate-vault'); ?>" class="nav-link<?php echo (strpos($uri, '/certificate-vault') !== false && strpos($uri, '/audit-records') === false) ? ' active' : ''; ?>" data-tour="tour-certificates">Certificates</a>
        <?php 
        $sidebarRole = $_SESSION['user_role'] ?? '';
        if ($sidebarRole === 'admin' || $sidebarRole === 'super_admin'): 
        ?>
            <div class="nav-section-label" style="color: var(--admin-accent);">Admin Controls</div>
            <a href="<?php echo buildUrl('user-management'); ?>" class="nav-link admin-link<?php echo isActive('/user-management'); ?>" data-tour="tour-users">User Management</a>
            <a href="<?php echo buildUrl('user-management/security-audit.php'); ?>" class="nav-link admin-link<?php echo isActive('/user-management/security-audit'); ?>" data-tour="tour-security-audit">Security Audit</a>
            <a href="<?php echo buildUrl('admin-progress'); ?>" class="nav-link admin-link<?php echo isActive('/admin-progress'); ?>" data-tour="tour-admin-progress">Admin Progress</a>
            <a href="<?php echo buildUrl('analytics/organization'); ?>" class="nav-link admin-link<?php echo isActive('/analytics/organization'); ?>" data-tour="org-analytics">Org Analytics</a>
            <a href="<?php echo buildUrl('admin-course-manager'); ?>" class="nav-link admin-link<?php echo isActive('/admin-course-manager'); ?>" data-tour="scorm-packages">Course Manager</a>
            <a href="<?php echo buildUrl('admin-demo-manager'); ?>" class="nav-link admin-link<?php echo isActive('/admin-demo-manager'); ?>">Demo Manager</a>
            <?php if ($sidebarRole === 'super_admin'): ?>
                <a href="<?php echo buildUrl('analytics/super-admin'); ?>" class="nav-link admin-link<?php echo isActive('/analytics/super-admin'); ?>" data-tour="cross-org">Cross-Org Analytics</a>
                <a href="<?php echo buildUrl('organizations'); ?>" class="nav-link admin-link<?php echo isActive('/organizations'); ?>" data-tour="tour-orgs">Organizations</a>
            <?php endif; ?>
        <?php endif; ?>
        <div class="nav-section-label">Support</div>
        <a href="<?php echo buildUrl('support'); ?>" class="nav-link<?php echo isActive('/support'); ?>" data-tour="tour-support">Support</a>
        <div class="nav-section-label">Account</div>
        <a href="<?php echo buildUrl('settings'); ?>" class="nav-link<?php echo isActive('/settings'); ?>" data-tour="tour-settings">Settings</a>
    </div>
    <a href="<?php echo buildUrl('logout.php'); ?>" class="logout">Log Out</a>
</nav>
<script>
function toggleMenu() {
    var sb = document.getElementById('sidebar');
    var ov = document.getElementById('overlay');
    if (!sb || !ov) return;
    sb.classList.toggle('open');
    ov.classList.toggle('active');
}
</script>
