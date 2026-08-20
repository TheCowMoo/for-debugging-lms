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
| `login/`, `signup/`, `forgot-password/`, `reset-password.php`, `verify.php`, `logout.php` | Authentication flows; signup uses reCAPTCHA v2 and optional GoHighLevel integration. |
| `dashboard/` | Learner landing page after login. |
| `course-page/` | “My Courses” catalog for learners. |
| `course-viewer/`, `course-runner/` | Older course-delivery pages (largely superseded by `scorm-player/`). |
| `scorm-player/index.php` | Modern full-screen SCORM player: iframe to `serve.php` with a short-lived HMAC serve token (`?pkg=N&t=TOKEN`) and a debug overlay. |
| `scorm-content/serve.php` | The content server. Serves every package asset from S3/local disk, injects client-side URL intercepts, honours HTTP Range requests, rewrites HLS m3u8, rewrites & caches HTML. |
| `scorm-content/debug.php` | Content-serving diagnostics. |
| `scorm-api/scorm-rte.js` | SCORM 1.2 + 2004 run-time injected into every content page (exposes both `window.API` and `window.API_1484_11`). |
| `scorm-api/store.php` | Persists SCORM commits (suspension data, status, scores, interactions, objectives, events). |
| `admin/scorm-upload*.php` | Upload UI + handler (creates package + job), background worker (`scorm-upload-run.php`, `scorm-upload-worker.php`), status polling, S3 resync (“repair”). |
| `admin-course-manager/` | Course Manager (super-admin / org admin): **Course Assets** tab (course ID/title/cert template/thumbnail/org) and **Packages** tab (upload, preview, assign org, edit title/description/version/status, archive toggle, repair, delete). |
| `admin-demo-manager/` | Demo signup campaigns/slots. |
| `admin-progress/`, `progress/` | Progress dashboards (admin / learner). |
| `user-management/` | Admin user CRUD. |
| `organizations/` | Super-admin organization CRUD. |
| `analytics/` | Analytics dashboards for users, org admins, and super-admins (`includes/analytics.php` = query helpers). |
| `certificate-vault/` | Certificates (list, audit records, PDF download). |
| `api/` | External webhook API: `register.php`, `enroll.php`, `invite.php`, `revoke.php`, `unenroll.php`. |
| `includes/` | Shared UI: `sidebar.php`, `main.css`, `sidebar.css`, `tour.php`, `analytics.php`. |
| `terms/`, `support/` | Terms acceptance, support page. |
| `moodle-bridge.php` | Legacy Moodle web-services client; also hosts `fetchScormCourses()` / `nativeFetchScormCourses()` and the `shouldUseNativeBackend()` dispatch. |
| `server-config/` | Sample nginx SCORM-router + security configs. |
| `content/` | Branding images, plus protected `scorm/` (uploaded packages) and `cache/scorm/` (rewritten HTML cache). |
| `_diag.php`, `api-test.php`, `s3-test.php`, `upload-diag.php`, `email-test.php`, `temp-db-users.php`, `login-debug.php` | Diagnostics / one-off files — mostly temporary; be careful about shipping them to production. |
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

## 6. Database schema (ensured by `ensure*` functions in `bootstrap.php`)

- `users` (plus extra columns/tables ensured separately: segment options, registrations, terms accepted)
- `organizations`
- `course_assets` — `course_id` (VARCHAR, unique), `course_title`, `certificate_template`, `thumbnail`, `organization_id`
- `scorm_packages` — `id`, `organization_id`, `title`, `description`, `version`, `scorm_version` ENUM(‘1.2’,‘2004’),
  `manifest_xml`, `launch_sco_id`, `upload_path`, `status` ENUM(‘active’,‘archived’,‘draft’), `created_at`, `updated_at`
- `sco_items` — per-manifest SCOs (`identifier`, `launch_url`, `mastery_score`, `prerequisites`, …)
- `course_assignments` — `(package_id, organization_id, assigned_by, assigned_at)`, unique per pair
- `scorm_attempts` — one row per (user, package, sco, attempt): `lesson_status`, `completion_status`,
  `score_raw`/`score_scaled`, `is_complete`, `completed_at`, department snapshot
- `scorm_interactions`, `scorm_objectives`, `scorm_events` — granular SCORM tracking data
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
- `scorm-api/scorm-rte.js` exposes BOTH SCORM 1.2 (`window.API`) and SCORM 2004
  (`window.API_1484_11`) APIs — Rise/Storyline sometimes probe the “wrong” one, so both must exist.
- Data persists through `scorm-api/store.php` into `scorm_attempts`, `scorm_interactions`,
  `scorm_objectives`, and `scorm_events`. Attempts survive full-page reloads by resuming by
  (user, package, sco, attempt).

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
6. **Duplicate S3 define block in `_diag.php`** (~lines 80–81) — harmless but sloppy.
7. **Diagnostics shipped in the tree.** `_diag.php`, `api-test.php`, `s3-test.php`, `upload-diag.php`,
   `email-test.php`, `temp-db-users.php`, `login-debug.php` exist in the repo; some are admin-gated,
   but avoid leaving them in production.
8. **Upload size limits.** `SCORM_MAX_UPLOAD_SIZE` is 512 MB, but the real ceiling is PHP
   `post_max_size` / `upload_max_filesize` and nginx `client_max_body_size`.
9. **Serve tokens expire after 4 hours.** Long open sessions will start failing mid-course; reloading
   the player page issues a fresh token.
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

## 11. Deployment

1. Upload the `for deploy/` tree to `public_html/` on the server.
2. Install the nginx SCORM router (`README.md` has the exact block + symlink), then
   `sudo nginx -t && sudo systemctl reload nginx`.
3. Clear the cache: `sudo rm -rf .../content/cache/scorm/*`.
4. Set `.env` with the deployment’s `S3_PREFIX` (e.g. `hpcas/`).
5. PHP 8.3 fpm socket: `/run/php/php8.3-fpm-h-pcas.pursuitpathways.com.sock`.

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
- When you discover new bugs or change behavior, **update this file** so future prompts stay accurate.
