# PP LMS — AI Reference Guide

> **Read this file first.** It is the canonical map of the Pursuit Pathways LMS codebase
> (`lms-pp`, the `for deploy/` tree in this repo). It explains how the whole system works,
> how the pieces connect, and the known bugs / gotchas you must watch out for.
> Keep it up to date when you change the system.

## 1. What this is

A **PHP 8.3 / MySQL** web app that delivers **SCORM 1.2 and SCORM 2004** e-learning courses:

- Learners log in, see a course catalog, and launch courses inside an iframe player.
- Course content (ZIP packages from Rise 360 / Storyline) is uploaded by admins, extracted, and
  stored in **Amazon S3** (with a local-disk fallback).
- Progress, scores, interactions, objectives and events are tracked via the SCORM run-time API
  and stored in MySQL.
- Multi-tenant: users, packages and assignments are scoped to **organizations**.
- A legacy **Moodle bridge** still exists for course metadata/tracking, but the platform primarily
  uses the **native SCORM reader** (`SCORM_BACKEND=auto` picks native when native data exists).

Deployments: `h-pcas.pursuitpathways.com` and `edu.pursuitpathways.com` share one S3 bucket but
use different `S3_PREFIX` values (`hpcas/` vs `scorm-content/`).

## 2. Tech stack & conventions

- PHP 8.3 (HestiaCP + nginx + php-fpm), MySQL InnoDB utf8mb4, PDO prepared statements only.
- Server-rendered PHP + vanilla JS + inline CSS. No frameworks, no composer, no build step.
- **Source files use CRLF line endings and UTF-8 (with BOM) — preserve both when editing.**
- Security headers, HSTS, secure HttpOnly SameSite=Lax session cookies are set in `bootstrap.php`.
- Every page starts with `require_once __DIR__ . '/../bootstrap.php'` (or `/bootstrap.php`), then
  calls an auth gate such as `requireLogin()`, `requireAdmin()`.
- All HTML output of user data is escaped with `htmlspecialchars()`; DB writes use prepared statements.
- `buildUrl($path)` produces absolute URLs; `csrfHiddenField()` / `validateCsrfToken()` protect POST forms.

## 3. Directory map

| Path | What it does |
|---|---|
| `bootstrap.php` | Shared bootstrap: proxy-aware scheme detection, session config, security headers, `.env` loading, DB helpers, CSRF, auth, multi-org helpers, branding, SCORM serve tokens, and `ensureXxxTables()` schema bootstrap. |
| `s3-helpers.php` | Minimal AWS Signature V4 S3 client (`s3Put`, `s3Get`, `s3Head`, `s3Exists`, `s3GetRange`, `s3DeletePrefix`, `isS3Configured`). |
| `auth_functions.php` | Auth helpers used by the login/signup flows. |
| `login/`, `signup/`, `forgot-password/`, `reset-password.php`, `verify.php`, `logout.php` | Authentication flows; signup uses reCAPTCHA v2 and optional GoHighLevel integration. `login/index.php` now enforces account lockout + throttling and mandatory email MFA for admins (`login/mfa.php`). |
| `dashboard/` | Learner landing page after login. |
| `course-page/` | “My Courses” catalog for learners. |
| `course-viewer/`, `course-runner/` | Older course-delivery pages (largely superseded by `scorm-player/`). |
| `scorm-player/index.php` | Modern full-screen SCORM player: iframe to `serve.php` with a short-lived HMAC serve token (`?pkg=N&t=TOKEN`) and a debug overlay. |
| `scorm-content/serve.php` | The content server. Serves every package asset from S3/local disk, injects client-side URL intercepts, honours HTTP Range requests, rewrites HLS m3u8, rewrites & caches HTML. |
| (removed) `scorm-content/debug.php` | Web diagnostic — removed from production; forbidden by `tests/check-production.php`. |
| `scorm-api/scorm-rte.js` | Cross-version run-time (v3) injected into every content page: separate SCORM 1.2 (`window.API`) and SCORM 2004 (`window.API_1484_11`) adapters feeding ONE normalized state model; sends structured interactions/objectives/comments + a `request_id` on every commit/beacon. |
| `scorm-api/store.php` | Cross-version tracking receiver (v3): transactional, exact-once (idempotency key), upserts interactions/objectives (never full-deletes), stores links + comments, edition-aware suspend limits, bounded audit events, returns full resume state. |
| `scorm-api/scorm-normalize.php` | Pure normalization helpers (`scormDurationToSeconds`, `scormNormalizeStatuses`, `scormSuspendDataLimit`, `scormDetectEdition`, …) shared by store.php, serve.php, upload handlers, and the test suite. |
| `migrations/` | Versioned schema migrations + runner (`schema_migrations` table). Canonical place for schema evolution; `ensureScormMigrations()` auto-applies pending ones. |
| `admin/scorm-upload*.php` | Upload UI + handler (creates package + job), background worker (`scorm-upload-run.php`, `scorm-upload-worker.php`), status polling, S3 resync (“repair”). |
| `admin-course-manager/` | Course Manager (super-admin / org admin): **Course Assets** tab (course ID/title/cert template/thumbnail/org) and **Packages** tab (upload, preview, assign org, edit title/description/version/status, archive toggle, repair, delete). |
| `admin-demo-manager/` | Demo signup campaigns/slots. |
| `admin-progress/`, `progress/` | Progress dashboards (admin / learner). |
| `user-management/` | Admin user CRUD (invite, enroll, edit role/department, delete — all audit-logged) and `security-audit.php` (viewable, filterable security audit log). |
| `organizations/` | Super-admin organization CRUD. |
| `analytics/` | Analytics dashboards for users, org admins, and super-admins (`includes/analytics.php` = query helpers). |
| `certificate-vault/` | Certificates (list, audit records, PDF download). |
| `api/` | External webhook API: `register.php`, `enroll.php`, `invite.php`, `revoke.php`, `unenroll.php`. |
| `includes/` | Shared UI: `sidebar.php`, `main.css`, `sidebar.css`, `tour.php`, `analytics.php`, plus `security.php` (auth hardening: lockout, audit logging + alerts, email MFA). |
| `terms/`, `support/` | Terms acceptance, support page. |
| `settings/` | User settings (requires login): appearance (theme light/dark, font_scale small/normal/large), profile (first/last name, email), change password. Uses `getUserPreferences()` / `saveUserPreferences()` and the `users.preferences` JSON column. |
| `moodle-bridge.php` | Legacy Moodle web-services client; also hosts `fetchScormCourses()` / `nativeFetchScormCourses()` and the `shouldUseNativeBackend()` dispatch. |
| `server-config/` | Sample nginx SCORM-router + security configs. |
| `content/` | Branding images, plus protected `scorm/` (uploaded packages) and `cache/scorm/` (rewritten HTML cache). |
| (removed) `_diag.php`, `api-test.php`, `s3-test.php`, `upload-diag.php`, `email-test.php`, `temp-db-users.php`, `login-debug.php` | Web diagnostics / one-off test files — REMOVED from the production tree and forbidden by `tests/check-production.php`. |
| `SCORM-COMPATIBILITY.md` | The cross-version compatibility contract: supported matrix, 1.2-vs-2004 status semantics, suspend limits, persistence guarantees, unsupported elements, analytics grain. Read before changing SCORM behaviour. |
| `tests/` | No-framework test suite: `tests/run.php` runner, `tests/unit/scorm-unit-tests.php` (pure normalization tests), `tests/integration/rte-smoke.test.js` (RTE smoke test in a mocked browser), `tests/fixtures/registry.php` (vendor fixture matrix — data only). |
## 4. Environment configuration (`.env`)

`bootstrap.php` loads `.env` from the repo root with a small built-in `KEY=value` parser (no library).
Copy `.env.example` to `.env` and fill in real values.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL credentials. **In dev mode bootstrap falls back to hardcoded dev credentials if unset — never rely on that in production.** |
| `APP_ENV` | `development` or `production`. |
| `APP_DOMAIN` | Overrides host detection. |
| `SITE_NAME`, `LOGO_FILENAME`, `FAVICON_FILENAME` | Branding. |
| `S3_BUCKET`, `S3_REGION`, `S3_KEY`, `S3_SECRET`, `S3_ENDPOINT`, `S3_DEBUG`, `S3_PREFIX` | S3 storage. Objects are stored at `{S3_PREFIX}{packageId}/{filePath}`. |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`, `RECAPTCHA_SCORE_THRESHOLD` | Signup anti-bot. |
| `API_KEY` | Shared secret for `/api/*` webhooks (`X-API-Key` or `Authorization: Bearer`). |
| `MOODLE_BASE_URL`, `MOODLE_WSTOKEN` | Legacy Moodle backend. |
| `SCORM_BACKEND` | `native` / `moodle` / `auto` (default `auto`). |
| `ANALYTICS_V2` | `1` enables the cross-version analytics dashboards (completion-vs-pass, interaction accuracy, score distribution, telemetry completeness, persistence monitoring). Behind a feature flag; off by default. |
| `ADMIN_ALERT_EMAIL` | Comma-separated security-alert recipients (falls back to all verified super_admin users when empty). |
| `LOGIN_LOCKOUT_MAX`, `LOGIN_LOCKOUT_WINDOW_SECS`, `LOGIN_LOCKOUT_MINUTES` | Account lockout rule: N failures within W seconds locks for M minutes (defaults 5 / 300 / 5). |
| `MFA_TTL_MINUTES` | Email MFA code lifetime for admins (default 10). |
| `SCORM_STORAGE_PATH`, `SCORM_CACHE_PATH` | Local disk locations (defaults under `content/`). |
| `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME` | Email sender. |
| `GHL_API_KEY`, `GHL_LOCATION_ID` | GoHighLevel (optional; falls back to PHP `mail()`). |
| `DEMO_SIGNUP_NOTIFY_EMAIL` | Demo-signup notification recipient. |
| `TEST_USER_*` | Localhost staging user. |

Note: `SCORM_MAX_UPLOAD_SIZE` (512 MB) and `RECAPTCHA_SCORE_THRESHOLD` (0.5) have in-code defaults.

## 5. Authentication & roles

- Session-cookie auth. Gates: `requireLogin()`, `requireAdmin()`, `requireSuperAdmin()` in `bootstrap.php`.
- Roles: `user`, `admin`, `super_admin`. `getOrgId()` = session `organization_id`;
  `isSuperAdmin()` / `isOrgAdmin()` helpers. Org admins only see/act on their own org’s users,
  packages and assignments; super admins span all orgs.
- `/api/*` endpoints authenticate via `API_KEY` header OR an admin session.

### Security hardening (login lockout, audit, MFA)

Implemented across `includes/security.php`, `login/index.php`, `login/mfa.php`,
`user-management/index.php`, `user-management/security-audit.php`,
`reset-password.php`, `settings/index.php`, `signup/index.php`. Schema in
`migrations/0002_security_hardening.php` + `ensureSecurityTables()`.

- **Account lockout & throttling**: `LOGIN_LOCKOUT_MAX` (5) failures within
  `LOGIN_LOCKOUT_WINDOW_SECS` (300) locks the account for `LOGIN_LOCKOUT_MINUTES`
  (5). Tracked on `users.failed_login_count/failed_login_started_at/locked_until`
  (per-account) and `auth_attempts` (per-IP, keyed by `ipHash()`). The login UI
  shows a **generic countdown** — identical whether the email exists (no user
  enumeration).
- **Mandatory email MFA for admins**: after password success, `login/index.php`
  issues a 6-digit code (`mfa_challenges`, hash-only, `MFA_TTL_MINUTES`=10, max 5
  attempts) and redirects to `login/mfa.php`. Successful MFA sets
  `$_SESSION['mfa_verified_at']`. Sessions created before rollout are
  **grandfathered** (see `requireMfaComplete()`); only new logins must complete MFA.
- **Admin password policy**: `validatePasswordPolicy()` — admin/super_admin
  passwords require ≥12 chars with upper/lower/number/symbol; non-admins keep 8+.
  Enforced in `reset-password.php`, `settings/index.php`, `signup/index.php`.
- **Audit logging + alerts**: `logSecurityEvent()` records login success/failure,
  lockouts, MFA issue/success/failure, role changes, user update/delete/enroll/
  invite, and password changes into `security_events`. `checkSecurityAlerts()`
  emails `ADMIN_ALERT_EMAIL` (fallback: all verified super_admin users) on account
  lockout, repeated MFA failures, privilege escalation to super_admin, and user
  deletion. `user-management/security-audit.php` is the viewable/filterable log
  (org-scoped for org admins; super admins see all).

## 6. Database schema (ensured by `ensure*` functions in `bootstrap.php`)

- `users` (plus extra columns ensured separately: `department`, `is_team_lead`, `reset_token`, `reset_expiry`, `invite_token_id`, `preferences` for appearance settings; plus tables `segment_options`, `user_registrations`, terms)
- `organizations`
- `course_assets` — `course_id` (VARCHAR, unique), `course_title`, `certificate_template`, `thumbnail`, `organization_id`
- `scorm_packages` — `id`, `organization_id`, `title`, `description`, `version`, `scorm_version` ENUM(‘1.2’,‘2004’),
  `scorm_edition` (‘1.2’ | ‘2004 2nd Edition’ | ‘2004 3rd Edition’ | ‘2004 4th Edition’), `manifest_id`, `package_version`,
  `sco_count`, `activity_tree` (JSON), `resource_metadata` (JSON), `content_hash`, `fingerprint` (JSON),
  `manifest_xml`, `launch_sco_id`, `upload_path`, `status` ENUM(‘active’,‘archived’,‘draft’), `created_at`, `updated_at`
- `sco_items` — per-manifest SCOs (`identifier`, `launch_url`, `mastery_score`, `prerequisites`, …)
- `course_assignments` — `(package_id, organization_id, assigned_by, assigned_at)`, unique per pair
- `scorm_attempts` — one row per (user, package, sco, attempt), UNIQUE `(user_id, package_id, sco_item_id, attempt_number)`:
  raw `lesson_status`, `completion_status`, `success_status`, `score_raw`/`score_scaled`, `lesson_location`,
  `suspend_data`, `progress_measure`, `entry`, `mode`, `credit`, `exit`, `completion_threshold`,
  `scaled_passing_score`; normalized `normalized_completion`, `normalized_success`, `status_source`,
  `attempt_state`; `scorm_edition`, `last_request_id`, `is_complete`, timestamps
- `scorm_interactions` (UNIQUE `(attempt_id, interaction_index)`, `correct_response_ids` JSON),
  `scorm_objectives` (UNIQUE `(attempt_id, objective_index)`), `scorm_events` (`request_id`, `changed_fields`),
  `scorm_interaction_objectives` (junction: `cmi.interactions.n.objectives.m.id`),
  `scorm_comments_from_learner`, `scorm_request_idempotency` (exact-once), `scorm_monitor`
  (rejected/duplicate/failed-persistence log)
- `schema_migrations` — applied versioned migrations (see `migrations/`)
- `security_events` — audit log (logins, lockouts, MFA, admin actions; `actor_ip` stored for future geo analysis)
- `auth_attempts` — per-account + per-IP login attempt tracker
- `mfa_challenges` — email MFA 6-digit challenges (hash-only, 10-min expiry)
- `users` — lockout columns `failed_login_count`, `failed_login_started_at`, `locked_until`
- `invite_tokens`, `scorm_upload_jobs`, plus demo campaign tables from `admin-demo-manager`
## 7. SCORM package lifecycle (upload → store → serve → track)

**Upload**
1. Admin opens Course Manager → Packages tab → `admin/scorm-upload.php` uploads the ZIP.
2. `admin/scorm-upload-handler.php` reads `imsmanifest.xml`, derives title/description/version
   (POST `package_title` / `package_desc` override the manifest), inserts a `scorm_packages` row
   (status `draft`), creates a `scorm_upload_jobs` row, and returns `{ok:true, job_id}` immediately
   (avoids 504 timeouts on large files).
3. Background worker (`admin/scorm-upload-run.php`, driven by `scorm-upload-worker.php` or spawned
   by the handler) extracts the ZIP, uploads each file to S3 at `{S3_PREFIX}{packageId}/{filePath}`,
   parses manifest `<item>`s into `sco_items`, sets `launch_sco_id`, and marks the package `active`.
   It also records `scorm_edition` (1.2 / 2004 2nd/3rd/4th), manifest metadata (`manifest_id`,
   `package_version`, `sco_count`, `activity_tree`, `resource_metadata`), a `content_hash`, and a
   package `fingerprint` JSON. Launch hrefs always come from the manifest.
4. `admin/scorm-upload-status.php` polls progress (`progress_pct`, `message`).
5. “Repair” (`admin/scorm-s3-resync.php`) compares S3 against local `content/scorm/{packageId}` and
   re-uploads any missing objects (used from the Packages tab).
6. **Replace** (Packages tab → Replace button): uploads a new ZIP to overwrite an existing package’s
   content while keeping the same package id, so assignments, links and enrollments stay valid.
   Completed attempts are kept as history (their `sco_item_id` is nulled); in-progress attempts are
   dropped so learners restart the new content. See bug #10.

**Serving** (see `README.md` for the full root-cause write-up)
- The player opens `/scorm-player/index.php?pkg=N`, which generates a 4-hour HMAC **serve token** and
  embeds an iframe to `/scorm-content/serve.php?pkg=N&t=TOKEN&path=index.html`.
- `serve.php` validates the token (BEFORE session auth — iframe sub-resource requests do not carry the
  SameSite=Lax session cookie), then serves files from S3 or local disk with the correct Content-Type
  and HTTP 206 Range support for media.
- Rise/Storyline courses compute asset URLs from `window.location` as `/scorm-content/…`. Two layers
  make those resolve:
  1. `serve.php` injects client-side intercepts (fetch, XHR.open, createElement, Image.src) that
     rewrite `/scorm-content/` to `serve.php?pkg=N&path=…`.
  2. The **nginx SCORM router** (`location ^~ /scorm-content/`) catches requests that slip past,
     extracts `pkg`/`t` from the `Referer` header, and internally rewrites them to `serve.php`.
     Without the `^~` modifier, HestiaCP’s static-file regex location wins and everything 404s.
- Rewritten HTML is cached under `content/cache/scorm/`. **The cache must be cleared after every
  deploy** or stale HTML (missing the intercepts) breaks course assets.

**Tracking**
- `scorm-api/scorm-rte.js` (v3) exposes BOTH SCORM 1.2 (`window.API`) and SCORM 2004
  (`window.API_1484_11`) adapters feeding ONE normalized state model — Rise/Storyline sometimes
  probe the “wrong” one, so both must exist. Every commit/terminate/beacon carries a client
  `request_id` and an incremental `session_delta_ms` (exact-once, no double-counted time).
- `scorm-api/store.php` (v3) is transactional and validates method/body/shape/limits → auth
  (session or serve token) → package/SCO/attempt ownership → idempotency → transaction →
  concurrency-safe attempt numbering → normalize-while-keeping-raw → upsert interactions/
  objectives (never full-delete) → links + comments → bounded audit event → commit → full
  resume state. Any failure rolls back and returns a retryable error; `ok:true` is never
  returned for a partial write.
- Raw + normalized statuses are both stored (`lesson_status`/`completion_status`/`success_status`
  raw; `normalized_completion`/`normalized_success`/`attempt_state`/`status_source` derived).
  SCORM 1.2 success is ALWAYS a derived view of lesson_status (never claimed as native).
  See `SCORM-COMPATIBILITY.md`.
- Schema evolution lives in `migrations/` (versioned, recorded in `schema_migrations`).
  `ensureScormMigrations()` (bootstrap) auto-applies pending migrations from `store.php` and
  the upload handlers; run `php migrations/run.php` (or the web token variant) to apply manually.
- Admin post-launch diagnostics: `scorm-player/index.php?diag=1` (admins only) polls
  `window.__SCORM_RTE__` inside the same-origin iframe and shows API used, edition, runtime
  version, commits, statuses, scores, interaction/objective/comment counts, suspend-data length,
  and RTE errors.
- Analytics V2 (set `ANALYTICS_V2=1` in `.env`): completion-vs-pass per package, interaction
  accuracy/latency, score distribution, telemetry completeness, persistence monitoring
  (rejected/duplicate/failed). Reports separate “not reported” from zero and use the latest
  attempt per learner/SCO for compliance views.

## 8. Admin & analytics tools

- **Course Manager** (`admin-course-manager/`) — two tabs:
  - *Course Assets*: maps external course IDs to title, certificate template, thumbnail, org.
  - *Packages*: manages `scorm_packages`. Row actions: Preview, **Edit** (title, description, version,
    status via modal → `action_edit_package` POST), **Replace** (new SCORM .zip → same package id,
    completed progress kept as history), Assign (super admin), Archive toggle, Repair (super admin +
    S3), Delete. Org admins only see these actions for their own org’s packages.
- **Demo Manager** (`admin-demo-manager/`): demo signup campaigns and slots.
- **User Management** (`user-management/`): create/edit/disable users, roles, password resets.
- **Organizations** (`organizations/`): super-admin org CRUD.
- **Analytics**: `analytics/user/`, `analytics/organization/`, `analytics/super-admin/` dashboards.

## 9. External API (`/api/*`)

All endpoints require `X-API-Key` / `Authorization: Bearer <API_KEY>` OR an admin session:
- `api/register.php` — create a user.
- `api/enroll.php` — enroll a user in a course.
- `api/invite.php` — create invite tokens.
- `api/revoke.php`, `api/unenroll.php` — revoke / unenroll.
## 10. Known bugs & gotchas

1. **Hardcoded fallback credentials (SECURITY).** `bootstrap.php` falls back to dev DB credentials
   (`Nathan_scorm_admin` / plaintext password) and hardcoded reCAPTCHA keys when env vars are missing.
   Never deploy without a fully-populated `.env`.
2. **`.env.example` is stale on S3.** It documents `S3_SCORM_PREFIX` but the code reads `S3_PREFIX`;
   copying `.env.example` verbatim leaves `S3_PREFIX` unset and it silently defaults to `scorm-content/`.
3. **Mojibake in `admin-course-manager/index.php`.** Box-drawing comments (`â•` = ═) and the S3-repair
   JS messages (`âœ“` = ✓, `âœ—` = ✗) are double-encoded UTF-8 and render garbled in the browser.
   Fixing requires re-encoding those literals to single UTF-8.
4. **Stale SCORM HTML cache.** Rewritten HTML is cached under `content/cache/scorm/`; after deploying
   code changes you must clear it or courses keep serving old HTML without the URL intercepts (404s).
5. **nginx router requirement.** `/scorm-content/*` only works through `serve.php` via the
   `location ^~ /scorm-content/` block. The iframe URL must carry `?pkg=N&t=TOKEN` so the router can
   pull `pkg`/`t` from the `Referer`. See `README.md` + `server-config/`.
6. ~~**Duplicate S3 define block in `_diag.php`**~~ — `_diag.php` has been removed from the tree.
7. **Diagnostics shipped in the tree.** `_diag.php`, `api-test.php`, `s3-test.php`, `upload-diag.php`,
   `email-test.php`, `temp-db-users.php`, `login-debug.php`, and `scorm-content/debug.php` were
   removed from production and are now forbidden by `tests/check-production.php` (run it before
   packaging/deploying).
8. **Upload size limits.** `SCORM_MAX_UPLOAD_SIZE` is 512 MB, but the real ceiling is PHP
   `post_max_size` / `upload_max_filesize` and nginx `client_max_body_size`.
9. **Serve tokens are short-lived (default 1h, `SCORM_TOKEN_TTL`) and revocable.** They embed a
   user `security_version` that is bumped on password/role/org/email changes (immediate revocation).
   `store.php` returns a `refresh_token` when the current token is near expiry and `serve.php`
   refreshes entry pages, so long open sessions keep tracking. Reloading the player page also
   issues a fresh token.
10. **Replacing a package’s content.** A fresh upload always creates a NEW package id, but the
    Packages tab also has **Replace** — it POSTs `replace_package_id` to `scorm-upload-handler.php`,
    which creates a `replace_flag=1` job; the worker (`scorm-upload-run.php` / `scorm-upload-worker.php`)
    wipes the old files (S3 + local + HTML cache + old `sco_items`) and extracts/uploads/parses the new
    ZIP onto the SAME package id. Completed `scorm_attempts` are preserved as history (`sco_item_id` is
    nulled first, in-progress attempts are deleted so learners restart). Replace is not atomic: a
    mid-process failure leaves the package in `draft` until retried.
11. **`requireLogin()` vs SCORM iframes.** SCORM sub-resources don’t send the Lax session cookie, so
    `serve.php` must authenticate via the `t=` token — do not add `requireLogin()` before token checks.

12. **PHP files must NOT start with a UTF-8 BOM.** A BOM before `<?php` is echoed into every response
    that loads the file (entry points AND files loaded via `require`). That corrupts JSON APIs
    (the upload/replace handlers then show the generic "Server error") and binary assets served
    by `serve.php` (fonts -> `OTS parsing error: invalid sfntVersion`). `bootstrap.php`,
    `admin/scorm-upload-handler.php` and `admin-course-manager/index.php` have been fixed; keep
    BOMs out of PHP files. `serve.php` also defensively strips a BOM from asset bodies before serving.

13. **Migrations auto-apply from tracking POSTs.** `store.php` calls `ensureScormMigrations()` on
    every commit (one indexed `schema_migrations` SELECT once applied, MySQL `GET_LOCK` serialises
    concurrent applies). Do not add DDL to request handlers — add a versioned migration in
    `migrations/` instead and run `php migrations/run.php` (or let the bootstrap auto-applier do it).

14. **`store.php` requires the migrated schema.** The v3 endpoint reads/writes the new columns and
    tables (`scorm_edition`, `normalized_*`, `attempt_state`, `scorm_interaction_objectives`,
    `scorm_comments_from_learner`, `scorm_request_idempotency`, `scorm_monitor`). Deploy order is:
    migrations first (read-compatible), then persistence, then the RTE. The unique key
    `uq_attempt (user_id, package_id, sco_item_id, attempt_number)` makes attempt numbering
    concurrency-safe; NULL `sco_item_id` rows never collide.

15. **Duplicate request_id semantics.** A replayed `request_id` returns the stored response (200),
    `409 committed:true` when the original committed but its response write was lost (client treats
    it as success), or `409 retryable` while the original is still in flight. On a persistence
    failure the claim row is deleted so a retry re-attempts safely.

16. **suspend_data truncation.** The RTE truncates writes at the edition limit (4096 / 4000 / 64000)
    and logs it; `store.php` re-checks server-side. Truncation shows in the admin diagnostics panel.

17. **MFA rollout does not force re-login.** New admin logins must complete email MFA
    (`login/mfa.php`), but sessions that existed before the feature shipped are grandfathered —
    they get `mfa_grandfathered` audit events until the user logs out and back in. Never
    hard-block grandfathered sessions or you will lock the whole admin team out.

18. **Lockout messages must stay generic.** `genericLockoutMessage()` is intentionally identical
    whether the email exists (no user enumeration). Unknown emails still feed the per-IP throttle
    window in `auth_attempts`. Do not differentiate lockout messages per account existence.

19. **`sendGHLPortalEmail()` returns false on failure; MFA/alert emails fall back to
    `sendSystemEmail()`.** If GoHighLevel is misconfigured, admins may not receive MFA codes —
    check `GHL_API_KEY`/`GHL_LOCATION_ID` and `MAIL_FROM_EMAIL` before troubleshooting MFA.

## 11. Deployment

1. Upload the `for deploy/` tree to `public_html/` on the server.
2. Apply schema migrations: `php migrations/run.php` (or hit the web token URL, or rely on
   `ensureScormMigrations()` auto-applying on the first tracking POST). Deploy order per the
   rollout plan: migrations → read-compatible persistence → new RTE → fixtures in staging →
   analytics comparison → `ANALYTICS_V2=1` → production.
3. Install the nginx SCORM router (`README.md` has the exact block + symlink), then
   `sudo nginx -t && sudo systemctl reload nginx`.
4. Clear the cache: `sudo rm -rf .../content/cache/scorm/*`.
5. Set `.env` with the deployment’s `S3_PREFIX` (e.g. `hpcas/`).
6. PHP 8.3 fpm socket: `/run/php/php8.3-fpm-h-pcas.pursuitpathways.com.sock`.

## 12. Notes for AI assistants working on this codebase

- **Trace a feature end-to-end** (UI → POST handler → bootstrap helper → DB table) before editing.
- **Never write a UTF-8 BOM** to PHP files (it corrupts JSON and binary output; see bug #12).
- **Be careful with backslashes** in PHP strings: editing through shell pipelines can collapse a
  double backslash to a single one, and a single backslash before a closing quote silently
  breaks the string (PHP parse error "unexpected identifier"). Prefer forward slashes or chr(92).
- Use PDO prepared statements and `htmlspecialchars()` everywhere, like the rest of the code.
- Form POSTs must include `csrfHiddenField()` and be handled inside the CSRF-guarded branch.
- Respect the permission model: super admin = all orgs; org admin = only rows where
  `organization_id` matches `getOrgId()`.
- After touching `serve.php`, remember the HTML cache gotcha (#4).
- **SCORM schema changes go in `migrations/`**, never in request handlers. Keep the migration
  closures idempotent (guard against `information_schema`). `ensureScormMigrations()` auto-applies.
- **Status semantics:** never make SCORM 1.2 look like it has native separate completion/success
  statuses — store raw fields verbatim and only ever *derive* the normalized views (record
  `status_source`). See `SCORM-COMPATIBILITY.md`.
- **The RTE and store.php speak structured payloads** (`values`, `interactions`, `objectives`,
  `comments`, `request_id`, `session_delta_ms`). Keep both the structured arrays and the flat
  `values` map in sync; store.php falls back to parsing flat `cmi.interactions.n.*` for older
  clients.
- Run `php tests/run.php` (and `node tests/integration/rte-smoke.test.js`) after touching
  `scorm-normalize.php`, `scorm-rte.js`, or `store.php` normalization logic.
- When you discover new bugs or change behavior, **update this file** so future prompts stay accurate.
