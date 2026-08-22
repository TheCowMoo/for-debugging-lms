<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * SECURITY HARDENING HELPERS
 *
 * Account lockout & throttling, security audit logging + admin alerting, and
 * mandatory email MFA for administrators. Shared by:
 *   - login/index.php, login/mfa.php        (auth flow)
 *   - reset-password.php, settings/index.php (password policy)
 *   - user-management/index.php             (admin-action audit logging)
 *   - user-management/security-audit.php    (audit log viewer)
 *
 * Schema lives in migrations/0002_security_hardening.php (canonical) and
 * ensureSecurityTables() in bootstrap.php (fresh-install baseline).
 *
 * @package  PP_LMS
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../signup/ghl_helper.php';   // sendGHLPortalEmail()
require_once __DIR__ . '/../auth_functions.php';      // sendSystemEmail() fallback

// ─────────────────────────────────────────────────────────────────────────
// IP helpers
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('getClientIp')) {
    /**
     * Proxy-aware client IP (Cloudflare, X-Forwarded-For, X-Real-IP).
     */
    function getClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            // X-Forwarded-For can be a comma-separated list; use the first.
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('ipHash')) {
    /**
     * Deterministic, keyed hash of an IP (never store raw IPs).
     */
    function ipHash(string $ip): string
    {
        return substr(hash('sha256', $ip . '|' . APP_CSRF_SECRET), 0, 32);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Lockout configuration
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('lockoutConfig')) {
    /**
     * Rule: max_failures consecutive failures within window_secs locks the
     * account for lock_minutes. All values overridable via .env.
     */
    function lockoutConfig(): array
    {
        return [
            'max_failures' => max(1, (int)(getenv('LOGIN_LOCKOUT_MAX') ?: 5)),
            'window_secs'  => max(30, (int)(getenv('LOGIN_LOCKOUT_WINDOW_SECS') ?: 300)),
            'lock_minutes' => max(1, (int)(getenv('LOGIN_LOCKOUT_MINUTES') ?: 5)),
        ];
    }
}

if (!function_exists('isAccountLocked')) {
    /**
     * Whether the account (by id, else by email) is currently locked.
     *
     * @return array{locked:bool, remaining_seconds:int}
     */
    function isAccountLocked(?int $userId, string $email): array
    {
        $pdo = getDbConnection();
        $lockedUntil = null;
        if ($userId !== null && $userId > 0) {
            $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $lockedUntil = $stmt->fetchColumn();
        }
        if (!$lockedUntil) {
            $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $lockedUntil = $stmt->fetchColumn();
        }
        if ($lockedUntil) {
            $ts = strtotime((string)$lockedUntil);
            if ($ts !== false && $ts > time()) {
                return ['locked' => true, 'remaining_seconds' => $ts - time()];
            }
        }
        return ['locked' => false, 'remaining_seconds' => 0];
    }
}

if (!function_exists('applyFailedLogin')) {
    /**
     * Record a failed login against the account and apply the lockout rule.
     *
     * @return array{locked:bool, remaining_seconds:int, user_id:int, attempts_remaining:int}
     */
    function applyFailedLogin(int $userId, string $email): array
    {
        $pdo = getDbConnection();
        if ($userId <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $found = $stmt->fetchColumn();
            $userId = $found ? (int)$found : 0;
        }

        $cfg = lockoutConfig();
        $now = time();
        $locked = false;
        $remaining = 0;
        $attemptsRemaining = 0;

        if ($userId > 0) {
            $stmt = $pdo->prepare(
                "SELECT failed_login_count, failed_login_started_at, locked_until FROM users WHERE id = ?"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $row = ['failed_login_count' => 0, 'failed_login_started_at' => null, 'locked_until' => null];
            }

            $lockedUntilTs = $row['locked_until'] ? strtotime((string)$row['locked_until']) : 0;

            // Already locked — do not extend or mutate.
            if ($lockedUntilTs !== false && $lockedUntilTs > $now) {
                return ['locked' => true, 'remaining_seconds' => $lockedUntilTs - $now, 'user_id' => $userId, 'attempts_remaining' => 0];
            }

            $count = (int)($row['failed_login_count'] ?? 0);
            $started = $row['failed_login_started_at'] ? strtotime((string)$row['failed_login_started_at']) : 0;

            // Rolling-window rollover: reset if the window elapsed.
            if (!$started || $started === false || ($now - $started) > $cfg['window_secs']) {
                $count = 0;
                $started = $now;
            }
            $count++;
            $startedAt = date('Y-m-d H:i:s', $started);

            if ($count >= $cfg['max_failures']) {
                $lockedUntilTs = $now + ($cfg['lock_minutes'] * 60);
                $locked = true;
                $remaining = $cfg['lock_minutes'] * 60;
                $count = 0;          // lock is active; counter resets for after expiry
                $startedAt = null;
                $attemptsRemaining = 0;
            } else {
                // Attempts left before the account locks.
                $attemptsRemaining = $cfg['max_failures'] - $count;
            }

            $pdo->prepare(
                "UPDATE users SET failed_login_count = ?, failed_login_started_at = ?, locked_until = ?
                 WHERE id = ?"
            )->execute([
                $count,
                $startedAt,
                $lockedUntilTs > 0 ? date('Y-m-d H:i:s', $lockedUntilTs) : null,
                $userId,
            ]);
        }

        return ['locked' => $locked, 'remaining_seconds' => $remaining, 'user_id' => $userId, 'attempts_remaining' => $attemptsRemaining];
    }
}

if (!function_exists('resetFailedLogin')) {
    /**
     * Clear per-account lockout state after a successful login/MFA.
     */
    function resetFailedLogin(int $userId): void
    {
        $pdo = getDbConnection();
        $pdo->prepare(
            "UPDATE users SET failed_login_count = 0, failed_login_started_at = NULL, locked_until = NULL WHERE id = ?"
        )->execute([$userId]);
    }
}

if (!function_exists('recordLoginAttempt')) {
    /**
     * Append a row to auth_attempts (per-account + per-IP) and compute the
     * current per-IP throttle state.
     *
     * @return array{ip_locked:bool, ip_remaining_seconds:int}
     */
    function recordLoginAttempt(?int $userId, string $email, bool $success, bool $lockoutTriggered = false): array
    {
        $pdo = getDbConnection();
        $ip = getClientIp();
        $ipKey = ipHash($ip);

        $stmt = $pdo->prepare(
            "INSERT INTO auth_attempts (email, user_id, ip_hash, attempted_at, success, user_agent, lockout_triggered)
             VALUES (?, ?, ?, NOW(), ?, ?, ?)"
        );
        $stmt->execute([
            strtolower(trim($email)),
            ($userId !== null && $userId > 0) ? $userId : null,
            $ipKey,
            $success ? 1 : 0,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            $lockoutTriggered ? 1 : 0,
        ]);

        // Per-IP throttle: max_failures failures from this IP within the window.
        $cfg = lockoutConfig();
        $ipLocked = false;
        $ipRemaining = 0;
        if (!$success) {
            $recent = $pdo->prepare(
                "SELECT id, attempted_at FROM auth_attempts
                 WHERE ip_hash = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL ? SECOND)
                 ORDER BY id DESC LIMIT ?"
            );
            $recent->execute([$ipKey, $cfg['window_secs'], $cfg['max_failures']]);
            $rows = $recent->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) >= $cfg['max_failures']) {
                $ipLocked = true;
                // Remaining = time until the oldest of the max_failures ages out.
                $oldest = end($rows);
                $ts = strtotime((string)$oldest['attempted_at']);
                $ipRemaining = max(1, $cfg['window_secs'] - (time() - $ts));
            }
        }
        return ['ip_locked' => $ipLocked, 'ip_remaining_seconds' => $ipRemaining];
    }
}

if (!function_exists('ipLockoutRemaining')) {
    /**
     * Remaining per-IP cooldown for the current client (shown on the generic
     * login countdown without confirming whether the email exists).
     */
    function ipLockoutRemaining(): int
    {
        $pdo = getDbConnection();
        $cfg = lockoutConfig();
        $ipKey = ipHash(getClientIp());
        $recent = $pdo->prepare(
            "SELECT id, attempted_at FROM auth_attempts
             WHERE ip_hash = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL ? SECOND)
             ORDER BY id DESC LIMIT ?"
        );
        $recent->execute([$ipKey, $cfg['window_secs'], $cfg['max_failures']]);
        $rows = $recent->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < $cfg['max_failures']) {
            return 0;
        }
        $oldest = end($rows);
        return max(1, $cfg['window_secs'] - (time() - strtotime((string)$oldest['attempted_at'])));
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Password policy
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('validatePasswordPolicy')) {
    /**
     * Enforce the password policy.
     *
     * Admin/super_admin accounts: minimum 12 characters with uppercase,
     * lowercase, number, and symbol. All other roles keep 8+ characters.
     *
     * @return array{ok:bool, error:string}
     */
    function validatePasswordPolicy(string $password, string $role = 'student'): array
    {
        $privileged = ($role === 'admin' || $role === 'super_admin');
        if ($privileged) {
            $errors = [];
            if (strlen($password) < 12) {
                $errors[] = 'at least 12 characters';
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'an uppercase letter';
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'a lowercase letter';
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'a number';
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'a symbol';
            }
            if (empty($errors)) {
                return ['ok' => true, 'error' => ''];
            }
            return ['ok' => false, 'error' => 'Password must include ' . implode(', ', $errors) . '.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('genericLockoutMessage')) {
    /**
     * Standard countdown message — identical whether or not the email exists
     * (prevents account enumeration). Rendered client-side with a JS timer.
     */
    function genericLockoutMessage(int $remainingSeconds): string
    {
        if ($remainingSeconds <= 0) {
            return 'Too many failed login attempts. Please try again shortly.';
        }
        return 'Too many failed login attempts. Please wait ' . $remainingSeconds . ' seconds before trying again.';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Audit logging + alerting
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('logSecurityEvent')) {
    /**
     * Append a row to the security_events audit log (best-effort).
     */
    function logSecurityEvent(
        string $eventType,
        string $severity = 'info',
        array $detail = [],
        ?int $targetUserId = null,
        string $targetEmail = ''
    ): void {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO security_events
                    (event_type, severity, actor_user_id, actor_email, actor_ip,
                     target_user_id, target_email, detail, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $actorUserId = (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;
            $actorEmail = (string)($_SESSION['email'] ?? '');
            $stmt->execute([
                $eventType,
                $severity,
                $actorUserId,
                $actorEmail,
                getClientIp(),
                $targetUserId,
                $targetEmail,
                json_encode($detail),
            ]);
        } catch (Throwable $e) {
            error_log('[SECURITY] logSecurityEvent failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sendAdminAlert')) {
    /**
     * Email the admin team (ADMIN_ALERT_EMAIL env, comma-separated; falls back
     * to all verified super_admin users).
     */
    function sendAdminAlert(string $subject, string $bodyHtml): void
    {
        $recipients = [];
        $envRecipients = trim((string)getenv('ADMIN_ALERT_EMAIL'));
        if ($envRecipients !== '') {
            foreach (explode(',', $envRecipients) as $r) {
                $r = trim($r);
                if (filter_var($r, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $r;
                }
            }
        }
        if (empty($recipients)) {
            try {
                $pdo = getDbConnection();
                foreach ($pdo->query("SELECT email FROM users WHERE role = 'super_admin' AND is_verified = 1") as $row) {
                    if (!empty($row['email'])) {
                        $recipients[] = $row['email'];
                    }
                }
            } catch (Throwable $e) {
                error_log('[SECURITY] admin recipient lookup failed: ' . $e->getMessage());
            }
        }
        $recipients = array_unique($recipients);
        if (empty($recipients)) {
            return;
        }
        $siteName = getSiteName();
        $html = "<div style='font-family:sans-serif;color:#333;'>"
              . "<h2 style='color:#991b1b;'>$siteName Security Alert</h2>"
              . $bodyHtml
              . "<p style='color:#5f6f6a;font-size:12px;'>Sent automatically by the $siteName security monitor.</p>"
              . "</div>";
        foreach ($recipients as $to) {
            $sent = @sendGHLPortalEmail($to, 'Security', $subject, $html, '', '', 'Security Alert');
            if (!$sent) {
                @sendSystemEmail($to, $subject, $html);
            }
        }
        logSecurityEvent('alert_sent', 'warning', ['subject' => $subject, 'recipients' => count($recipients)]);
    }
}

if (!function_exists('checkSecurityAlerts')) {
    /**
     * Threshold-based real-time alerting. Returns true if an alert was sent.
     *
     * Triggers:
     *   - account_locked            (5 failed logins in 5 minutes)
     *   - mfa_failure               (repeated MFA verification failures)
     *   - role_changed to super_admin (privilege escalation)
     *   - user_deleted              (admin deleted an account)
     */
    function checkSecurityAlerts(string $eventType, array $context = []): bool
    {
        $shouldAlert = false;
        $subject = '';
        $bodyParts = [];

        if ($eventType === 'account_locked') {
            $shouldAlert = true;
            $subject = 'Account locked after repeated failed logins';
            $bodyParts[] = '<p><strong>Event:</strong> account_lockout</p>';
            $bodyParts[] = '<p>An account was locked after ' . ((int)lockoutConfig()['max_failures']) . ' failed login attempts within ' . ((int)(lockoutConfig()['window_secs'] / 60)) . ' minutes.</p>';
            $bodyParts[] = '<p><strong>Email:</strong> ' . htmlspecialchars((string)($context['email'] ?? 'unknown')) . '</p>';
        } elseif ($eventType === 'mfa_failure') {
            $shouldAlert = true;
            $subject = 'Repeated MFA verification failures';
            $bodyParts[] = '<p><strong>Event:</strong> mfa_failure</p>';
            $bodyParts[] = '<p>An administrator entered an incorrect MFA code multiple times.</p>';
            if (!empty($context['email'])) {
                $bodyParts[] = '<p><strong>Email:</strong> ' . htmlspecialchars((string)$context['email']) . '</p>';
            }
        } elseif ($eventType === 'role_changed' && ($context['new_role'] ?? '') === 'super_admin') {
            $shouldAlert = true;
            $subject = 'Privilege escalation to Super Admin';
            $bodyParts[] = '<p><strong>Event:</strong> role_changed to super_admin</p>';
            $bodyParts[] = '<p><strong>Target user:</strong> ' . htmlspecialchars((string)($context['target_email'] ?? 'unknown')) . '</p>';
            $bodyParts[] = '<p><strong>Changed by:</strong> ' . htmlspecialchars((string)($context['actor_email'] ?? ($_SESSION['email'] ?? 'unknown'))) . '</p>';
        } elseif ($eventType === 'user_deleted') {
            $shouldAlert = true;
            $subject = 'User account deleted';
            $bodyParts[] = '<p><strong>Event:</strong> user_deleted</p>';
            $bodyParts[] = '<p><strong>Deleted user:</strong> ' . htmlspecialchars((string)($context['target_email'] ?? 'unknown')) . '</p>';
        }

        if (!$shouldAlert) {
            return false;
        }

        $bodyParts[] = '<p><strong>IP:</strong> ' . htmlspecialchars(getClientIp()) . '</p>';
        $bodyParts[] = '<p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        sendAdminAlert($subject, implode('', $bodyParts));
        return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Email MFA for administrators
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('isAdminRole')) {
    function isAdminRole(string $role): bool
    {
        return $role === 'admin' || $role === 'super_admin';
    }
}

if (!function_exists('issueMfaChallenge')) {
    /**
     * Create a 6-digit email MFA challenge and return the plaintext code
     * (the caller emails it to the user). Hash-only storage, 10-minute expiry.
     */
    function issueMfaChallenge(int $userId): string
    {
        $pdo = getDbConnection();
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttlMinutes = max(1, (int)(getenv('MFA_TTL_MINUTES') ?: 10));
        $expires = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
        $pdo->prepare(
            "INSERT INTO mfa_challenges (user_id, code_hash, expires_at, attempts) VALUES (?, ?, ?, 0)"
        )->execute([$userId, password_hash($code, PASSWORD_DEFAULT), $expires]);
        return $code;
    }
}

if (!function_exists('verifyMfaChallenge')) {
    /**
     * Verify a submitted MFA code against the latest active challenge.
     *
     * @return array{ok:bool, error:string}
     */
    function verifyMfaChallenge(int $userId, string $code): array
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT id, code_hash, expires_at, attempts FROM mfa_challenges
             WHERE user_id = ? AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$challenge) {
            return ['ok' => false, 'error' => 'This code has expired. Please request a new one.'];
        }
        if ((int)$challenge['attempts'] >= 5) {
            $pdo->prepare("UPDATE mfa_challenges SET consumed_at = NOW() WHERE id = ?")->execute([$challenge['id']]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
        }
        if (password_verify(trim($code), $challenge['code_hash'])) {
            $pdo->prepare("UPDATE mfa_challenges SET consumed_at = NOW() WHERE id = ?")->execute([$challenge['id']]);
            return ['ok' => true, 'error' => ''];
        }
        $pdo->prepare("UPDATE mfa_challenges SET attempts = attempts + 1 WHERE id = ?")->execute([$challenge['id']]);
        return ['ok' => false, 'error' => 'Incorrect code. Please try again.'];
    }
}

if (!function_exists('sendMfaCodeEmail')) {
    /**
     * Email the 6-digit MFA code (GoHighLevel, with PHP mail() fallback).
     */
    function sendMfaCodeEmail(array $user, string $code): bool
    {
        $siteName = getSiteName();
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($name === '') {
            $name = $user['email'] ?? 'there';
        }
        $ttl = (int)(getenv('MFA_TTL_MINUTES') ?: 10);
        $subject = "Your $siteName verification code";
        $html = "<div style='font-family:sans-serif;max-width:600px;border:1px solid #d9e3df;padding:25px;border-radius:12px;'>"
              . "<h2 style='color:#006F53;'>Verification Code</h2>"
              . '<p>Hello ' . htmlspecialchars($name) . ',</p>'
              . '<p>Use the code below to complete your sign-in to ' . htmlspecialchars($siteName) . ':</p>'
              . "<div style='text-align:center;margin:24px 0;'>"
              . "<span style='font-size:28px;font-weight:800;letter-spacing:6px;color:#1A2E2A;'>" . htmlspecialchars($code) . "</span></div>"
              . '<p style="color:#5f6f6a;font-size:13px;">This code expires in ' . $ttl . ' minutes. If you did not attempt to sign in, please ignore this email.</p>'
              . '</div>';
        $sent = @sendGHLPortalEmail(
            $user['email'] ?? '',
            $name,
            $subject,
            $html,
            '',
            'Your ' . $siteName . ' verification code is ' . $code . '. It expires in ' . $ttl . ' minutes.',
            'Security'
        );
        if (!$sent) {
            $sent = @sendSystemEmail($user['email'] ?? '', $subject, $html);
        }
        return (bool)$sent;
    }
}

if (!function_exists('isMfaComplete')) {
    /**
     * Whether the current session has completed MFA (admins only).
     */
    function isMfaComplete(): bool
    {
        return isAdmin() && !empty($_SESSION['mfa_verified_at']);
    }
}

if (!function_exists('requireMfaComplete')) {
    /**
     * Admin-page gate. Sessions created before the MFA rollout are
     * grandfathered; only NEW logins are required to complete MFA. A session
     * mid-MFA is redirected to the MFA page.
     */
    function requireMfaComplete(): void
    {
        if (!isAdmin()) {
            return;
        }
        if (!empty($_SESSION['mfa_pending_user_id'])) {
            redirectTo('login/mfa.php');
        }
        if (empty($_SESSION['mfa_verified_at'])) {
            // Grandfathered session (pre-MFA rollout). Log once, allow.
            if (empty($_SESSION['mfa_grandfathered_logged'])) {
                $_SESSION['mfa_grandfathered_logged'] = 1;
                logSecurityEvent('mfa_grandfathered', 'info', [], null, (string)($_SESSION['email'] ?? ''));
            }
        }
    }
}
