# SCORM Compatibility Contract

The supported combinations and the rules every layer (runtime, persistence,
reporting) must honour. Read this before changing SCORM behaviour.

## 1. Supported matrix

| Area | Required support |
|---|---|
| Standards | SCORM 1.2; SCORM 2004 2nd, 3rd, and 4th Edition tracking |
| Runtime APIs | `window.API` and `window.API_1484_11` (both installed on every package) |
| Core tracking | Completion/status, success/pass, score, time, location, suspend data, exit |
| Detailed tracking | Interactions, objectives, correct responses, learner responses, latency, descriptions |
| Packaging | Root manifest, XML namespaces, multi-SCO, manifest-driven launch href |
| Vendors | Storyline, Rise, Captivate, iSpring, Lectora, dominKnow, custom packages |
| Sequencing | Package sequencing metadata is **detected and reported**; runtime sequencing is **explicitly unsupported** (not certified) |

## 2. Status semantics — the "never conflate 1.2 and 2004" rule

- **SCORM 1.2 has ONE status**: `cmi.core.lesson_status`
  (`passed | completed | failed | incomplete | browsed | not attempted`).
  It is stored raw in `scorm_attempts.lesson_status`.
  `normalized_completion` / `normalized_success` are **derived views** over it:
  `passed` → completion `completed` + success `passed`. This is a derivation,
  never a claim that 1.2 natively separates completion and success.
  `status_source = 'lesson_status'` records the derivation.
- **SCORM 2004 has TWO native statuses**: `cmi.completion_status`
  (`completed | incomplete | not attempted | unknown`) and `cmi.success_status`
  (`passed | failed | unknown`). Both are stored raw and normalized
  independently. `status_source = 'completion_status'` or `'success_status'`.
- **Score-based pass inference** (no explicit pass status) is always derived and
  labelled: `status_source = 'mastery_score'` (1.2 `adlcp:masteryscore` /
  `cmi.student_data.mastery_score`) or `'scaled_passing_score'`
  (2004 `cmi.scaled_passing_score`). It only applies when completion is already
  signalled.
- **"Not reported" is distinct from zero/empty**: a missing status/score element
  stays `NULL`/`''` and reports show it in a separate "not reported" bucket.

## 3. Raw vs normalized storage

`scorm_attempts` retains every raw field verbatim (`lesson_status`,
`completion_status`, `success_status`, `score_*`, `progress_measure`,
`lesson_location`, `suspend_data`, `entry`, `exit`) AND the normalized view
(`normalized_completion`, `normalized_success`, `attempt_state`,
`status_source`). Nothing is lost in normalisation; analytics may choose either.

## 4. Suspend-data limits (edition-aware)

| Edition | Limit |
|---|---|
| SCORM 1.2 | 4,096 characters |
| SCORM 2004 2nd Edition | 4,000 characters |
| SCORM 2004 3rd Edition | 64,000 characters |
| SCORM 2004 4th Edition | 64,000 characters |
| Unknown | 4,096 (conservative) |

The RTE truncates writes at the limit; the persistence endpoint re-checks.
Truncation is logged in the RTE error log (visible in the admin diagnostics).

## 5. Launch URLs

Launch URLs always come from the manifest (`<resource href>`), stored in
`sco_items.launch_url`. `index.html`, `indexapi.html`, `index_lms.html`, and
`story.html` are never hardcoded as launch targets. A resilience fallback probe
exists in `serve.php` ONLY when the manifest href is missing from storage
(broken package), and it is labelled as such in logs.

## 6. Persistence guarantees

- **Exactly-once per request_id**: every RTE commit/beacon carries a client
  `request_id`; the server claims it before writing and replays the stored
  response for duplicates. No duplicate attempts, no double-counted time.
- **Incremental time**: `session_delta_ms` accumulates on the server;
  `Terminate()` + unload-beacon pairs add ≈0.
- **Atomicity**: attempt + interactions + objectives + links + comments + audit
  event commit in one transaction; any failure rolls back and returns a
  retryable error. `ok:true` is never returned for a partial write.
- **Upserts, not deletes**: interactions/objectives/comments are upserted by
  stable keys (`(attempt_id, interaction_index)` etc.); a commit never wipes the
  prior set.

## 7. Unsupported / not-implemented elements

Recognised-but-unimplemented elements are handled explicitly:

- `cmi.comments_from_lms`, `cmi.interactions._count`, `cmi.objectives._count`,
  `cmi.comments_from_learner._count`, `cmi.student_data.attempt_number` →
  refused (error 351/203 on write; 351/204 "not reported" on read).
- `cmi.core.student_preference.*` / `cmi.learner_preference.*` → accepted for
  the session, readable, **never persisted**, never claimed as stored.
- LMS-owned read-only elements (`student_id`, `learner_name`, `entry`,
  `lesson_mode`, `credit`, `total_time`, `session_time`, `_children`, …) →
  writes refused with the version's writable error code.
- Unknown elements → error 201/301 (invalid argument).

## 8. Error codes preserved per standard

- SCORM 1.2: `0`, `101`, `201`, `202`, `203`, `204`.
- SCORM 2004: `0`, `101`, `102`, `301`, `351`.

## 9. Sequencing

Manifest sequencing metadata (prerequisites, objectives, `imsss:*` rules) is
parsed and stored on `sco_items`/package metadata and reported in the admin
UI. Runtime sequencing behaviour (only launching SCOs whose prerequisites are
met) is **explicitly not implemented / unsupported** and must be labelled as
such — never silently half-implemented.

## 10. Analytics grain definitions

| Metric | Grain |
|---|---|
| Completion rate | latest attempt per (user, package, sco) — `normalized_completion`/legacy fallback |
| Pass rate | latest attempt per (user, package, sco) — separate from completion |
| Avg score | attempts reporting a score; NULL excluded |
| Interaction accuracy | interaction rows (all attempts) |
| Telemetry completeness | per-package % of attempts reporting each signal |

Dashboards must state the grain and show "not reported" separately from zero.
