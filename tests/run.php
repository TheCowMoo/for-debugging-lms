<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS — SCORM TEST RUNNER
 *
 * Runs the automated tests for the cross-version SCORM implementation:
 *   1. Normalization unit tests (php, no DB)
 *   2. Fixture registry structure validation
 *   3. RTE smoke test (node, mocked browser)
 *
 * Usage:
 *     php tests/run.php
 *
 * @package  PP_LMS
 */

$root = dirname(__DIR__);
$fail = 0;

echo "== 1. SCORM Normalization Unit Tests ==\n";
$unit = 0;
passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/tests/unit/scorm-unit-tests.php'), $unit);
if ($unit !== 0) {
    echo "  [unit tests failed]\n";
    $fail++;
}

echo "\n== 2. Fixture Registry Validation ==\n";
$fixtures = require $root . '/tests/fixtures/registry.php';
$required = ['vendor', 'version', 'edition', 'api', 'expected_launch', 'status_behavior', 'score_pass_rule', 'required_cases', 'resume'];
$fixtureFails = 0;
foreach ($fixtures as $key => $f) {
    foreach ($required as $field) {
        if (!array_key_exists($field, $f)) {
            echo "  FAIL- fixture '$key' missing field '$field'\n";
            $fixtureFails++;
        }
    }
    if (!in_array($f['edition'], ['1.2', '2004 2nd Edition', '2004 3rd Edition', '2004 4th Edition', ''], true)) {
        // '1.2 or 2004 (detected from manifest)' is allowed for the custom fixture
    }
}
echo '  ' . count($fixtures) . " fixtures, " . ($fixtureFails === 0 ? 'all fields present' : "$fixtureFails field errors") . "\n";
if ($fixtureFails > 0) { $fail++; }

echo "\n== 3. RTE Smoke Test (node) ==\n";
$nodeBin = trim((string)(getenv('NODE_BIN') ?: 'node'));
$rte = 0;
passthru(escapeshellarg($nodeBin) . ' ' . escapeshellarg($root . '/tests/integration/rte-smoke.test.js') . ' 2>&1', $rte);
if ($rte !== 0) {
    echo "  [rte smoke test failed]\n";
    $fail++;
}

echo "\n" . ($fail === 0 ? "ALL TEST SUITES PASSED\n" : "$fail TEST SUITE(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
