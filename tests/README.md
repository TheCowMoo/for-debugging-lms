# SCORM Test Suite

Automated tests for the cross-version SCORM implementation. No external test
framework — plain PHP + Node so they run on any shared host.

## Running

    php tests/run.php

This runs:

1. **Unit tests** (`tests/unit/scorm-unit-tests.php`) — pure functions in
   `scorm-api/scorm-normalize.php`: duration parsing, status normalization
   (SCORM 1.2 vs 2004 semantics), score conversion, suspend-data limits,
   edition detection. No database required.
2. **Fixture registry validation** (`tests/fixtures/registry.php`) — verifies
   every vendor fixture records authoring tool/version, edition, API, expected
   launch file, status behavior, score/pass rule, interactions/objectives, and
   required cases.
3. **RTE smoke test** (`tests/integration/rte-smoke.test.js`) — runs
   `scorm-api/scorm-rte.js` inside a mocked browser (Node) and verifies:
   Initialize + sync resume, scalar/status writes, suspend-data truncation,
   interaction/objective/comment capture, correct-response ids, objective
   links, request_id on every commit, single-flight commits, Terminate +
   unload-beacon single-persistence (no double-counted time), cross-version
   alias reads, unsupported-field refusal with correct error codes, and the
   diagnostics surface.

    node tests/integration/rte-smoke.test.js

## Fixture matrix

The fixture registry (`tests/fixtures/registry.php`) is DATA ONLY — no vendor
branches in runtime code. For each of Storyline 360, Rise 360, Captivate,
iSpring, Lectora, dominKnow, and unknown/custom exports it records the expected
behaviour the integration suite must hold true for (launch href from manifest,
status semantics, score/pass rules, interaction/objective expectations, resume,
multi-SCO isolation, duplicate beacons, concurrent commits).

## DB-dependent integration tests (staging only)

Full integration tests (upload → manifest parse → launch → commits → resume →
analytics) require a staging database with the migrations applied and the
`ANALYTICS_V2=1` feature flag. They are not run automatically by `tests/run.php`
because they mutate data; run them manually against staging using throwaway
orgs/users.
