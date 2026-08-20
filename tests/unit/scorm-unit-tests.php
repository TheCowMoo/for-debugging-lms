<?php
/**
 * PURSUIT PATHWAYS LMS
 * SCORM NORMALIZATION UNIT TESTS
 *
 * Pure-function tests for scorm-api/scorm-normalize.php. No database access.
 * Run with:
 *     php tests/run.php
 * or directly:
 *     php tests/unit/scorm-unit-tests.php
 *
 * Covers: duration parsing, status normalization (1.2 vs 2004), score
 * conversion, payload/suspend-data limits, edition detection.
 *
 * @package  PP_LMS
 */

require_once __DIR__ . '/../../scorm-api/scorm-normalize.php';

$GLOBALS['__tests'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function t_assert(bool $cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['__tests']['pass']++;
        echo "  ok  - $msg\n";
    } else {
        $GLOBALS['__tests']['fail']++;
        $GLOBALS['__tests']['failures'][] = $msg;
        echo "  FAIL- $msg\n";
    }
}

echo "1. Duration parsing\n";
t_assert(scormDurationToSeconds('PT1H30M45S') === 5445, 'PT1H30M45S -> 5445s');
t_assert(scormDurationToSeconds('PT1H0M0S') === 3600, 'PT1H0M0S -> 3600s');
t_assert(scormDurationToSeconds('PT45S') === 45, 'PT45S -> 45s');
t_assert(scormDurationToSeconds('PT0.5S') === 0, 'PT0.5S -> 0s (floor)');
t_assert(scormDurationToSeconds('01:30:45') === 5445, 'HH:MM:SS -> 5445s');
t_assert(scormDurationToSeconds('') === 0, 'empty -> 0s');
t_assert(scormDurationToSeconds('garbage') === 0, 'garbage -> 0s');
t_assert(scormDurationToSeconds('120') === 120, 'plain integer -> 120s');

echo "2. Duration formatting roundtrip\n";
t_assert(scormSecondsToDuration(5445) === 'PT1H30M45S', '5445s -> PT1H30M45S');
t_assert(scormSecondsToDuration(0) === 'PT0H0M0S', '0s -> PT0H0M0S');

echo "3. Suspend-data limits\n";
t_assert(scormSuspendDataLimit('1.2') === 4096, '1.2 limit 4096');
t_assert(scormSuspendDataLimit('2004 2nd Edition') === 4000, '2004 2nd Ed limit 4000');
t_assert(scormSuspendDataLimit('2004 3rd Edition') === 64000, '2004 3rd Ed limit 64000');
t_assert(scormSuspendDataLimit('2004 4th Edition') === 64000, '2004 4th Ed limit 64000');
t_assert(scormSuspendDataLimit('') === 4096, 'unknown edition conservative 4096');
$san = scormSanitizeSuspendData(str_repeat('x', 5000), '1.2');
t_assert(strlen($san['value']) === 4096 && $san['truncated'] === true, 'suspend_data truncated to 4096');
$san2 = scormSanitizeSuspendData('abc', '2004 4th Edition');
t_assert($san2['value'] === 'abc' && $san2['truncated'] === false, 'short suspend_data untouched');

echo "4. Score conversion\n";
t_assert(scormScoreToScaled(80, 0, 100) === 0.8, 'raw 80/0..100 -> scaled 0.8');
t_assert(scormScoreToScaled(null, 0, 100) === null, 'null raw -> null');
t_assert(scormScoreToScaled(50, null, 100) === null, 'missing min -> null');
t_assert(scormScoreToScaled(50, 100, 100) === null, 'max <= min -> null');
t_assert(scormScoreToScaled(120, 0, 100) === 1.0, 'raw above max clamps to 1.0');


echo "5. Status normalization — SCORM 1.2\n";
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'passed'], '1.2');
t_assert($n['completion'] === 'completed' && $n['success'] === 'passed' && $n['state'] === 'passed', '1.2 passed -> completed/passed/state=passed');
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'failed'], '1.2');
t_assert($n['completion'] === 'completed' && $n['success'] === 'failed' && $n['state'] === 'failed', '1.2 failed -> failed');
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'incomplete'], '1.2');
t_assert($n['completion'] === 'incomplete' && $n['success'] === '' && $n['state'] === 'in_progress', '1.2 incomplete -> in_progress (not failed)');
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'browsed'], '1.2');
t_assert($n['completion'] === 'browsed' && $n['state'] === 'browsed', '1.2 browsed');
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'not attempted'], '1.2');
t_assert($n['completion'] === 'not_attempted', '1.2 not attempted');
// 1.2 has ONE status — success must not be invented from score alone.
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '85'], '1.2', 90);
t_assert($n['success'] === '' && $n['source'] === 'status', '1.2 completed below mastery -> no success signal');
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '95'], '1.2', 90);
t_assert($n['success'] === 'passed' && $n['source'] === 'mastery_score', '1.2 score >= mastery -> derived pass (source recorded)');

echo "6. Status normalization — SCORM 2004\n";
$n = scormNormalizeStatuses(['cmi.completion_status' => 'completed', 'cmi.success_status' => 'passed'], '2004');
t_assert($n['completion'] === 'completed' && $n['success'] === 'passed' && $n['state'] === 'passed', '2004 completed+passed');
$n = scormNormalizeStatuses(['cmi.completion_status' => 'incomplete', 'cmi.success_status' => 'unknown'], '2004');
t_assert($n['completion'] === 'incomplete' && $n['success'] === 'unknown', '2004 incomplete+unknown');
$n = scormNormalizeStatuses(['cmi.score.scaled' => '0.9', 'cmi.completion_status' => 'completed'], '2004', null, 0.8);
t_assert($n['success'] === 'passed' && $n['source'] === 'scaled_passing_score', '2004 scaled_passing_score pass inference');
// 2004 native success must NOT be derived from a 1.2-style lesson_status pass.
$n = scormNormalizeStatuses(['cmi.core.lesson_status' => 'passed'], '2004');
t_assert($n['success'] === 'passed' && $n['completion'] === 'completed', '2004 legacy lesson_status honoured as fallback');

echo "7. Edition detection\n";
t_assert(scormDetectEdition('<manifest><metadata><schemaversion>1.2</schemaversion></metadata></manifest>') === '1.2', 'schemaversion 1.2');
t_assert(scormDetectEdition('<manifest xmlns="http://www.adlnet.org/xsd/adlcp_v1p3"><metadata><schemaversion>2004 3rd Edition</schemaversion></metadata></manifest>') === '2004 3rd Edition', 'schemaversion 2004 3rd Edition');
t_assert(scormDetectEdition('<manifest><metadata><schemaversion>2004 4th Edition</schemaversion></metadata></manifest>') === '2004 4th Edition', 'schemaversion 2004 4th Edition');
t_assert(scormDetectEdition('<manifest xmlns="http://www.adlnet.org/xsd/adlcp_v1p3" xmlns:imscp="http://www.imsproject.org/xsd/imscp_rootv1p1p2">') === '2004 2nd Edition', 'adlcp_v1p3 + imscp_rootv1p1p2 -> 2nd Ed');
t_assert(scormDetectEdition('<manifest xmlns="http://www.adlnet.org/xsd/adlcp_rootv1p2">') === '1.2', 'adlcp_rootv1p2 -> 1.2');
t_assert(scormDetectEdition('<manifest xmlns="http://www.unknown.example/ns">') === '', 'unknown namespace -> empty');
t_assert(scormDetectEdition('') === '', 'empty manifest -> empty');

$GLOBALS['__tests']['fail'] > 0 ? exit(1) : exit(0);
