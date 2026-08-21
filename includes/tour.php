<?php
/**
 * Interactive Tour / Walkthrough System
 * Targets page elements via data-tour attributes.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/tour.php';
 *   renderTour('student');  // or 'admin', 'super_admin'
 *   renderTourButton();     // floating "?" help button
 */
?>
<style>
.tour-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(0,0,0,0.55);
    display: none;
    pointer-events: none;
}
.tour-overlay.active { display: block; }

.tour-highlight {
    position: relative;
    z-index: 100000 !important;
    pointer-events: auto !important;
    box-shadow: 0 0 0 4px #006F53, 0 0 20px rgba(0, 111, 83, 0.4);
    border-radius: 8px;
    transition: box-shadow 0.3s ease;
}

.tour-tooltip {
    position: fixed;
    z-index: 100001;
    background: #fff;
    border-radius: 16px;
    padding: 24px 22px;
    max-width: 340px;
    width: 100%;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    display: none;
    pointer-events: auto;
    max-height: 80vh;
    overflow-y: auto;
}
.tour-tooltip.active { display: block; }

.tour-tooltip .tour-count {
    font-size: 0.72rem; font-weight: 700;
    color: #BBBDB7; text-transform: uppercase;
    letter-spacing: 0.1em; margin-bottom: 6px;
}
.tour-tooltip .tour-icon { font-size: 1.6rem; margin-bottom: 8px; }
.tour-tooltip h4 { margin: 0 0 6px; font-size: 1.1rem; color: #1A2E2A; }
.tour-tooltip p { margin: 0 0 18px; font-size: 0.9rem; color: #1A2E2A; line-height: 1.5; }

.tour-tooltip .tt-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.tour-tooltip .tt-next {
    flex: 1 1 auto; padding: 12px; background: #006F53; color: #fff;
    border: none; border-radius: 10px; font-weight: 700; font-size: 14px;
    cursor: pointer; transition: background 0.2s;
    min-height: 44px;
}
.tour-tooltip .tt-next:hover { background: #60B49A; }
.tour-tooltip .tt-prev, .tour-tooltip .tt-skip {
    padding: 12px 16px; background: transparent; color: #BBBDB7;
    border: 1px solid #d9e3df; border-radius: 10px; font-weight: 600; font-size: 13px;
    cursor: pointer; transition: background 0.2s;
    min-height: 44px;
}
.tour-tooltip .tt-prev:hover, .tour-tooltip .tt-skip:hover { background: #f4f7f6; }

.tour-help-btn {
    position: fixed; bottom: 24px; right: 24px; z-index: 9998;
    width: 56px; height: 56px; border-radius: 50%;
    background: #006F53; color: #fff; border: none;
    font-size: 22px; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: transform 0.2s, background 0.2s;
    display: flex; align-items: center; justify-content: center;
    pointer-events: auto;
    bottom: calc(24px + env(safe-area-inset-bottom, 0px));
}
.tour-help-btn:hover { transform: scale(1.1); background: #60B49A; }

@media (max-width: 640px) {
    .tour-tooltip { max-width: calc(100vw - 24px); left: 12px !important; right: 12px !important; top: auto !important; bottom: calc(16px + env(safe-area-inset-bottom, 0px)) !important; max-height: 55vh; }
    .tour-help-btn { width: 52px; height: 52px; font-size: 20px; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom, 0px)); }
}
@media (max-width: 380px) {
    .tour-tooltip { padding: 18px 16px; }
    .tour-tooltip h4 { font-size: 1rem; }
    .tour-tooltip p { font-size: 0.85rem; margin-bottom: 14px; }
    .tt-actions { gap: 6px; }
    .tour-tooltip .tt-prev, .tour-tooltip .tt-skip { padding: 10px 12px; font-size: 12px; }
    .tour-tooltip .tt-next { padding: 10px; font-size: 13px; }
}
</style>

<?php
function getTourSteps(string $role): array
{
    // ── STUDENT TOUR ──
    $studentSteps = [
        'tour-start' => [
            'icon' => '👋', 'title' => 'Welcome to the LMS!',
            'text' => 'This tutorial will walk you through the platform. You can click the "?" button at any time to restart the tour.',
        ],
        'tour-dashboard' => [
            'icon' => '📊', 'title' => 'Your Dashboard',
            'text' => 'This is your personal dashboard. The hero card shows your current course progress, active enrollments, and average completion. Below are KPI cards with at-a-glance numbers.',
            'target' => 'tour-dashboard',
        ],
        'tour-hero' => [
            'icon' => '📈', 'title' => 'Progress Ring & Stats',
            'text' => 'The doughnut chart shows your preparedness score. Below it, your "Learning Snapshot" shows active enrollments, completed courses, and latest session minutes.',
            'target' => 'tour-hero',
        ],
        'tour-courses' => [
            'icon' => '📚', 'title' => 'Launching a Course',
            'text' => 'Go to "My Courses" in the sidebar. Each course card has a "Start Course" or "Resume Learning" button. Click it and the course launches inline — no popups needed.',
            'target' => 'tour-courses',
        ],
        'tour-course-card' => [
            'icon' => '📋', 'title' => 'Course Card Actions',
            'text' => 'Each course card shows the title, a progress bar with percentage, and a button to start or resume. Your progress is tracked automatically — just pick up where you left off.',
            'target' => 'tour-course-card',
        ],
        'tour-progress' => [
            'icon' => '📈', 'title' => 'Learning Progress Page',
            'text' => 'This page shows a detailed table of all your registrations with completion percentages, time spent, and last access dates. Use it to track your overall learning journey.',
            'target' => 'tour-progress',
        ],
        'tour-certificates' => [
            'icon' => '🏆', 'title' => 'Certificates Vault',
            'text' => 'When you complete a course, your certificate appears here. You can view it online or download a PDF copy. Certificates show your name, course title, and completion date.',
            'target' => 'tour-certificates',
        ],
        'tour-support' => [
            'icon' => '💬', 'title' => 'Support Page',
            'text' => 'Need help or have questions? The Support page has contact information. You can also restart this tour anytime using the "?" button in the bottom-right corner.',
            'target' => 'tour-support',
        ],
        'tour-finished' => [
            'icon' => '🎉', 'title' => 'You\'re All Set!',
            'text' => 'You\'ve completed the student tour. Explore the platform at your own pace. If you need help, use the "?" button or visit the Support page.',
        ],
    ];

    // ── ADMIN TOUR (includes all student features + admin tools) ──
    $adminSteps = array_merge(
        array_slice($studentSteps, 0, 1),
        [
            'admin-dashboard' => [
                'icon' => '📊', 'title' => 'Dashboard (Admin View)',
                'text' => 'Your personal dashboard works the same as a student\'s. The admin features are in the sidebar under "Admin Controls".',
                'target' => 'tour-dashboard',
            ],
            'tour-user-mgmt' => [
                'icon' => '👥', 'title' => 'User Management',
                'text' => 'This is your command center. Use the "Send Invite" form to add new learners. Fill in their name, email, assign a department, and optionally assign a course they\'ll be enrolled in upon signup.',
                'target' => 'tour-user-mgmt',
            ],
            'tour-invite-form' => [
                'icon' => '📧', 'title' => 'Sending Invites',
                'text' => 'Fill in first name, last name, and email. Optionally assign a department and course. When the user signs up via the invite link, they\'ll be auto-enrolled in the selected course. Click Send Invite to deliver via email.',
                'target' => 'tour-invite',
            ],
            'tour-enroll' => [
                'icon' => '📋', 'title' => 'Enrolling Learners',
                'text' => 'The "Course Enrollment" section lets you enroll existing users in courses. Select a learner, choose a course, and click "Enroll Learner". The registration is created automatically.',
                'target' => 'tour-enroll',
            ],
            'tour-user-table' => [
                'icon' => '📝', 'title' => 'Editing Users',
                'text' => 'The "User Accounts" table shows all users. You can edit their role (Student/Admin), department, and team lead status inline. Click "Update" to save changes.',
                'target' => 'tour-users',
            ],
            'tour-admin-progress' => [
                'icon' => '📊', 'title' => 'Admin Analytics',
                'text' => 'The Admin Progress page shows organization-wide statistics: total learners, completion rates, matched registrations, and a per-learner progress table. Use this to monitor training completion across your organization.',
                'target' => 'tour-admin-progress',
            ],
            'tour-audit' => [
                'icon' => '📋', 'title' => 'Audit-ready Records',
                'text' => 'The Audit Records page lets you download compliance reports and CSV exports. This is useful for proving training completion during audits or regulatory reviews.',
                'target' => 'tour-audit',
            ],
        ],
        array_slice($studentSteps, 6, 1),
        [
            'tour-finished' => [
                'icon' => '🎉', 'title' => 'Admin Tour Complete!',
                'text' => 'You\'re ready to manage learners. Invite users, enroll them in courses, and track their progress. The "?" button is always available to restart this tour.',
            ],
        ]
    );

    // ── SUPER ADMIN TOUR (includes all admin + org management) ──
    $superSteps = array_merge(
        array_slice($adminSteps, 0, 7),
        [
            'tour-orgs' => [
                'icon' => '🏢', 'title' => 'Organizations Management',
                'text' => 'This page is only visible to Super Admins. Create multiple organizations, each with their own SCORM API credentials. This keeps courses, learners, and stats completely isolated between organizations.',
                'target' => 'tour-orgs',
            ],
            'tour-org-create' => [
                'icon' => '➕', 'title' => 'Creating an Organization',
                'text' => 'Use the form to create a new organization. Give it a name and URL-friendly slug. Paste the organization\'s own SCORM App ID and Secret Key (leave blank to use the global credentials). To set Organization Admins, edit their role in User Management.',
                'target' => 'tour-org-create',
            ],
            'tour-org-table' => [
                'icon' => '📋', 'title' => 'Organization List',
                'text' => 'The table shows all organizations, their SCORM key status (Custom or Global), active status, user counts, and creation date. Use the Edit and Delete buttons to manage them.',
                'target' => 'tour-org-table',
            ],
            'tour-super-invite' => [
                'icon' => '📧', 'title' => 'Assigning Users to Orgs',
                'text' => 'As a Super Admin, the invite form has an extra "Assign Organization" dropdown. Select which org the new user should belong to. You can also move users between orgs from the User Accounts table\'s Organization column.',
                'target' => 'tour-invite',
            ],
            'tour-isolation' => [
                'icon' => '🔒', 'title' => 'Data Isolation',
                'text' => 'Each organization has its own SCORM credentials. Users from Org A can ONLY see Org A\'s courses, progress, and stats. Super Admins can view everything. This is enforced at the database level — no leaks between orgs.',
                'target' => 'tour-orgs',
            ],
        ],
        [   
            'tour-finished' => [
                'icon' => '🎉', 'title' => 'Super Admin Tour Complete!',
                'text' => 'You have full control over all organizations. Create new orgs, assign SCORM credentials, manage users across orgs, and monitor everything from a single dashboard. Remember to delete the promote.php file after setting up!',
            ],
        ]
    );

    if ($role === 'super_admin') return $superSteps;
    if ($role === 'admin') return $adminSteps;
    return $studentSteps;
}
?>

<script>
(function() {
    const TOUR_KEY = 'pp_lms_tour_completed_v2';
    let tourSteps = [];
    let currentStep = 0;
    let currentRole = 'student';

    function findTarget(selector) {
        return document.querySelector('[data-tour="' + selector + '"]');
    }

    function removeHighlight() {
        document.querySelectorAll('.tour-highlight').forEach(function(el) {
            el.classList.remove('tour-highlight');
        });
    }

    function positionTooltip(targetEl, tooltip) {
        if (!targetEl) {
            tooltip.style.left = '50%';
            tooltip.style.top = '50%';
            tooltip.style.transform = 'translate(-50%, -50%)';
            return;
        }
        // On small screens the CSS media query anchors the tooltip to the
        // bottom with !important, so let CSS handle it and skip JS positioning.
        if (window.innerWidth <= 640) {
            tooltip.style.transform = 'none';
            return;
        }
        var rect = targetEl.getBoundingClientRect();
        var tooltipW = Math.min(340, window.innerWidth - 32);
        var left = rect.right + 16;
        var top = rect.top + rect.height / 2;

        if (left + tooltipW > window.innerWidth - 16) {
            left = rect.left - tooltipW - 16;
        }
        if (left < 16) {
            left = 16;
            top = rect.bottom + 12;
        }
        if (top < 16) top = 16;
        var estH = Math.min(tooltip.offsetHeight || 320, window.innerHeight - 32);
        if (top + estH > window.innerHeight - 16) {
            top = Math.max(16, window.innerHeight - estH - 16);
        }

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
        tooltip.style.transform = 'none';
    }

    function createTourUI() {
        var overlay = document.createElement('div');
        overlay.id = 'tour-overlay';
        overlay.className = 'tour-overlay';

        var tooltip = document.createElement('div');
        tooltip.id = 'tour-tooltip';
        tooltip.className = 'tour-tooltip';
        tooltip.innerHTML = [
            '<div class="tour-count" id="tt-count"></div>',
            '<div class="tour-icon" id="tt-icon"></div>',
            '<h4 id="tt-title"></h4>',
            '<p id="tt-text"></p>',
            '<div class="tt-actions">',
                '<button class="tt-prev" id="tt-prev">← Back</button>',
                '<button class="tt-next" id="tt-next">Next →</button>',
                '<button class="tt-skip" id="tt-skip">Skip</button>',
            '</div>'
        ].join('');

        document.body.appendChild(overlay);
        document.body.appendChild(tooltip);
    }

    function showStep(index) {
        var steps = Object.values(tourSteps);
        if (index >= steps.length) {
            closeTour();
            return;
        }
        var step = steps[index];
        var total = steps.length;

        document.getElementById('tt-count').textContent = 'Step ' + (index + 1) + ' of ' + total;
        document.getElementById('tt-icon').textContent = step.icon;
        document.getElementById('tt-title').textContent = step.title;
        document.getElementById('tt-text').textContent = step.text;

        var prevBtn = document.getElementById('tt-prev');
        prevBtn.style.display = index === 0 ? 'none' : 'inline-block';

        var nextBtn = document.getElementById('tt-next');
        nextBtn.textContent = index === total - 1 ? '✓ Done' : 'Next →';

        removeHighlight();

        var targetEl = step.target ? findTarget(step.target) : null;
        if (targetEl) {
            // On small screens the sidebar is off-canvas; open it so a
            // highlighted nav target is actually visible, then bring it into view.
            if (window.innerWidth <= 1024 && targetEl.closest('#sidebar')) {
                var sbEl = document.getElementById('sidebar');
                var ovEl = document.getElementById('overlay');
                if (sbEl) sbEl.classList.add('open');
                if (ovEl) ovEl.classList.add('active');
            }
            targetEl.classList.add('tour-highlight');
            try { targetEl.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
            positionTooltip(targetEl, document.getElementById('tour-tooltip'));
        } else {
            var tooltip = document.getElementById('tour-tooltip');
            tooltip.style.left = '50%';
            tooltip.style.top = '50%';
            tooltip.style.transform = 'translate(-50%, -50%)';
        }

        document.getElementById('tour-overlay').classList.add('active');
        document.getElementById('tour-tooltip').classList.add('active');
    }

    function closeTour() {
        removeHighlight();
        var overlay = document.getElementById('tour-overlay');
        var tooltip = document.getElementById('tour-tooltip');
        if (overlay) { overlay.classList.remove('active'); overlay.remove(); }
        if (tooltip) { tooltip.classList.remove('active'); tooltip.remove(); }
        try { localStorage.setItem(TOUR_KEY, '1'); } catch(e) {}
    }

    function startTour(steps) {
        if (steps) { tourSteps = steps; }

        var existing = document.getElementById('tour-overlay');
        if (existing) existing.remove();
        var existingTt = document.getElementById('tour-tooltip');
        if (existingTt) existingTt.remove();

        currentStep = 0;
        createTourUI();

        document.getElementById('tour-overlay').classList.add('active');

        document.getElementById('tt-prev').addEventListener('click', function() {
            if (currentStep > 0) { currentStep--; showStep(currentStep); }
        });

        document.getElementById('tt-next').addEventListener('click', function() {
            var steps = Object.values(tourSteps);
            if (currentStep < steps.length - 1) { currentStep++; showStep(currentStep); }
            else { closeTour(); }
        });

        document.getElementById('tt-skip').addEventListener('click', closeTour);

        showStep(0);
    }

    function initTour(steps) {
        if (!localStorage.getItem(TOUR_KEY)) {
            if (document.readyState === 'complete') {
                setTimeout(function() { startTour(steps); }, 600);
            } else {
                window.addEventListener('load', function() { setTimeout(function() { startTour(steps); }, 600); });
            }
        }
    }

    window.launchTour = function(steps) {
        if (steps) { tourSteps = steps; }
        startTour(tourSteps);
    };

    window.initTour = initTour;
})();
</script>
<?php
function renderTour(string $role = 'student'): void
{
    $steps = getTourSteps($role);
    $stepsJson = json_encode($steps);
    echo "<script>window.addEventListener('load', function() { initTour($stepsJson); });</script>";
}

function renderTourButton(): void
{
    echo '<button class="tour-help-btn" onclick="launchTour()" title="Take a tour">?</button>';
}