# SCORM Schema Migrations

Versioned, ordered schema changes for the cross-version SCORM implementation.
Each migration is a PHP file named `NNNN_description.php` that returns a closure
`function (PDO $pdo): void` which applies the change. The runner records applied
versions in the `schema_migrations` table so each migration runs exactly once,
in filename order, on every deployment (fresh or existing).

## Why not runtime `CREATE TABLE IF NOT EXISTS` only?

The platform historically created tables on page load via
`ensureScormTables()`. That works for greenfield installs but cannot evolve a
production schema without scattering `ALTER TABLE` statements through request
handlers. Migrations keep schema evolution in one place, recorded, and safe to
apply on shared hosting (no CLI required — the runner is also callable over HTTP
by an authenticated admin).

## Running

### CLI

    php migrations/run.php

### Web (admin)

    GET /migrations/run.php?token=<migration-run-token>

where the token is an HMAC (see `run.php` for the exact formula). A logged-in
super-admin/admin can also call it from a browser if a CSRF token is supplied.

### Automatic

`bootstrap.php` exposes `ensureScormMigrations()`, called by the SCORM
persistence endpoint (`scorm-api/store.php`) and the admin upload handlers.
It performs one indexed `SELECT` from `schema_migrations` per request (cached
per PHP process via a static variable) and only runs pending migrations. A
MySQL advisory lock prevents concurrent requests from applying the same
migration twice.

## Conventions

- Migration filenames must be zero-padded numbers followed by `_` and a slug,
  e.g. `0001_scorm_compat_foundation.php`.
- The closure receives the PDO connection. Use prepared statements.
- Guard every change for idempotency (check `information_schema` before
  `ALTER`/`CREATE`) so a partially-applied migration can be completed on retry.
- Never include a UTF-8 BOM in migration files.
- Do not delete columns in older migrations; additive-only is the policy until
  a clean-up migration is explicitly reviewed.

## Applied versions

    SELECT version, applied_at FROM schema_migrations ORDER BY version;
