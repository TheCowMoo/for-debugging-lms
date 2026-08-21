<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * CROSS-VERSION SCORM NORMALIZATION HELPERS
 *
 * Pure functions shared by scorm-api/store.php, the analytics helpers, and the
 * automated test suite. Keeping these free of HTTP/DB side effects makes them
 * unit-testable and guarantees the same normalization logic everywhere.
 *
 * Conventions:
 *   - Raw SCORM values are ALWAYS retained verbatim (lesson_status,
 *     completion_status, success_status, score fields).
 *   - normalized_completion / normalized_success / attempt_state are derived
 *     views over the raw values. They never claim SCORM 1.2 has native separate
 *     completion and success statuses: for 1.2, success is derived from
 *     lesson_status (passed/failed) and `status_source` records the derivation.
 *   - "Not reported" (missing element) is kept distinct from an explicit
 *     "unknown"/"not attempted" value.
 *
 * @package  PP_LMS
 */

if (!function_exists('scormDurationToSeconds')) {
    /**
     * Convert a SCORM duration to seconds.
     *
     * Supports ISO 8601 (PT1H30M45S, PT0.5S) used by SCORM 1.2/2004, the
     * HH:MM:SS form used by some exporters, and plain integers/decimals.
     */
    function scormDurationToSeconds(string $duration): int
    {
        if (trim($duration) === '') {
            return 0;
        }
        $duration = trim($duration);
        $seconds = 0;
        if (preg_match('/^P(?:\d+D)?T(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?$/', $duration)) {
            if (preg_match('/(\d+(?:\.\d+)?)H/', $duration, $m)) {
                $seconds += (int)$m[1] * 3600;
            }
            if (preg_match('/(\d+(?:\.\d+)?)M/', $duration, $m)) {
                $seconds += (int)$m[1] * 60;
            }
            if (preg_match('/(\d+(?:\.\d+)?)S/', $duration, $m)) {
                $seconds += (int)$m[1];
            }
            if (preg_match('/^P(\d+)D/', $duration, $m)) {
                $seconds += (int)$m[1] * 86400;
            }
        } elseif (preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $duration, $m)) {
            $seconds = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        } elseif (is_numeric($duration)) {
            $seconds = (int)floor((float)$duration);
        }
        return max(0, $seconds);
    }
}

if (!function_exists('scormSecondsToDuration')) {
    /**
     * Convert seconds to an ISO 8601 duration (PT1H30M45S) for resume replies.
     */
    function scormSecondsToDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return 'PT' . $h . 'H' . $m . 'M' . $s . 'S';
    }
}

if (!function_exists('scormSuspendDataLimit')) {
    /**
     * Edition-aware suspend_data character limit.
     *
     * SCORM 1.2:        4,096 characters
     * SCORM 2004 2nd Ed: 4,000 characters
     * SCORM 2004 3rd/4th: 64,000 characters
     *
     * @param string $edition One of '1.2', '2004 2nd Edition', '2004 3rd Edition',
     *                        '2004 4th Edition', '' (unknown).
     */
    function scormSuspendDataLimit(string $edition): int
    {
        $edition = strtolower(trim($edition));
        if ($edition === '1.2') {
            return 4096;
        }
        if (strpos($edition, '2nd') !== false) {
            return 4000;
        }
        if (strpos($edition, '2004') !== false) {
            return 64000;
        }
        // Unknown edition: assume 1.2-compatible (most conservative).
        return 4096;
    }
}

if (!function_exists('scormSanitizeSuspendData')) {
    /**
     * Truncate suspend_data to the edition's limit, recording when truncated.
     */
    function scormSanitizeSuspendData(string $suspend, string $edition): array
    {
        $limit = scormSuspendDataLimit($edition);
        $truncated = mb_strlen($suspend) > $limit;
        if ($truncated) {
            $suspend = mb_substr($suspend, 0, $limit);
        }
        return ['value' => $suspend, 'truncated' => $truncated];
    }
}

if (!function_exists('scormScoreToScaled')) {
    /**
     * Convert a raw score to a 0..1 scaled score using min/max bounds.
     * Returns null when bounds are missing or unusable.
     */
    function scormScoreToScaled($raw, $min, $max): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = (float)$raw;
        if ($min === null || $min === '' || $max === null || $max === '' || (float)$max <= (float)$min) {
            return null;
        }
        $min = (float)$min;
        $max = (float)$max;
        return max(0.0, min(1.0, ($raw - $min) / ($max - $min)));
    }
}


if (!function_exists('scormNormalizeStatuses')) {
    /**
     * Derive normalized completion/success/attempt_state from raw SCORM values.
     *
     * @param array  $values   Normalized element map (lowercased keys), already
     *                         read for lesson_status/completion_status/success_status
     *                         and score fields.
     * @param string $version  '1.2' or '2004'.
     * @param float|null $masteryScore     scorm_packages/mastery threshold (1.2 adlcp:masteryscore).
     * @param float|null $scaledPassingScore 2004 cmi.scaled_passing_score.
     * @param bool   $terminating Whether the payload carries terminating=true.
     * @return array{completion:string, success:string, source:string, state:string}
     */
    function scormNormalizeStatuses(array $values, string $version, ?float $masteryScore = null, ?float $scaledPassingScore = null, bool $terminating = false): array
    {
        $lessonStatus = strtolower(trim((string)($values['cmi.core.lesson_status'] ?? '')));
        $completion   = strtolower(trim((string)($values['cmi.completion_status'] ?? '')));
        $success      = strtolower(trim((string)($values['cmi.success_status'] ?? '')));
        $rawScore     = $values['cmi.core.score.raw'] ?? $values['cmi.score.raw'] ?? null;
        $scoreScaled  = $values['cmi.score.scaled'] ?? null;
        $scoreRawVal  = ($rawScore !== null && $rawScore !== '') ? (float)$rawScore : null;
        $scoreScaledVal = ($scoreScaled !== null && $scoreScaled !== '' && is_numeric($scoreScaled)) ? (float)$scoreScaled : null;

        // 1.2 uses lesson_status only. 2004 uses completion_status + success_status,
        // but many exporters still send lesson_status, so honour it as a fallback.
        $is2004 = $version === '2004';

        if ($is2004) {
            if ($completion === '') {
                $completion = $lessonStatus;
            }
            if ($success === '') {
                if ($lessonStatus === 'passed') {
                    $success = 'passed';
                } elseif ($lessonStatus === 'failed') {
                    $success = 'failed';
                }
            }
        } else {
            // SCORM 1.2 has ONE status: lesson_status. completion/success are
            // derived views over it, never separate native signals.
            if ($completion === '') {
                $completion = $lessonStatus;
            }
            if ($success === '') {
                if (in_array($lessonStatus, ['passed', 'failed'], true)) {
                    $success = $lessonStatus;
                }
            }
        }

        // ---- Normalized completion ----
        $normalizedCompletion = '';
        if (in_array($completion, ['completed', 'passed', 'failed'], true)) {
            $normalizedCompletion = 'completed';
        } elseif ($completion === 'incomplete') {
            $normalizedCompletion = 'incomplete';
        } elseif ($completion === 'browsed') {
            $normalizedCompletion = 'browsed';
        } elseif ($completion === 'not attempted' || $completion === 'not_attempted') {
            $normalizedCompletion = 'not_attempted';
        } elseif ($completion === 'unknown') {
            $normalizedCompletion = 'unknown';
        } elseif ($completion === '') {
            $normalizedCompletion = '';
        } else {
            $normalizedCompletion = 'unknown';
        }

        // ---- Normalized success ----
        // 'passed' | 'failed' | 'unknown' | ''  ('' = not reported)
        $normalizedSuccess = '';
        if ($success === 'passed') {
            $normalizedSuccess = 'passed';
        } elseif ($success === 'failed') {
            $normalizedSuccess = 'failed';
        } elseif (in_array($lessonStatus, ['passed', 'failed'], true) && $success === '') {
            $normalizedSuccess = $lessonStatus === 'passed' ? 'passed' : 'failed';
        } elseif ($success === 'unknown') {
            $normalizedSuccess = 'unknown';
        }

        // ---- Score-based pass inference (documented, derived) ----
        // `source` is only recorded when a pass is ACTUALLY derived from the
        // score — a completed-but-below-threshold attempt keeps source='status'.
        $source = 'status';
        if ($normalizedSuccess === '' && $normalizedCompletion === 'completed') {
            $passThreshold = null;
            if ($is2004 && $scaledPassingScore !== null && $scaledPassingScore !== '') {
                $passThreshold = (float)$scaledPassingScore;
                if ($scoreScaledVal !== null && $scoreScaledVal >= $passThreshold) {
                    $normalizedSuccess = 'passed';
                    $source = 'scaled_passing_score';
                }
            } elseif ($masteryScore !== null && $masteryScore !== '') {
                $passThreshold = (float)$masteryScore;
                if ($scoreRawVal !== null && $scoreRawVal >= $passThreshold) {
                    $normalizedSuccess = 'passed';
                    $source = 'mastery_score';
                }
            }
        }

        // ---- Attempt state (composite view) ----
        // 'passed' > 'failed' > 'completed' > 'browsed' > 'incomplete'
        // > 'not_attempted' > 'in_progress' > '' (no signal at all)
        $state = '';
        if ($normalizedSuccess === 'passed') {
            $state = 'passed';
        } elseif ($normalizedSuccess === 'failed') {
            $state = 'failed';
        } elseif ($normalizedCompletion === 'completed') {
            $state = 'completed';
        } elseif ($normalizedCompletion === 'browsed') {
            $state = 'browsed';
        } elseif ($normalizedCompletion === 'incomplete' && $terminating) {
            $state = 'incomplete';
        } elseif ($normalizedCompletion === 'incomplete') {
            $state = 'in_progress';
        } elseif ($normalizedCompletion === 'not_attempted') {
            $state = 'not_attempted';
        } elseif ($normalizedCompletion === 'unknown') {
            $state = 'in_progress';
        } elseif ($lessonStatus !== '' || $completion !== '' || $success !== '') {
            $state = 'in_progress';
        }

        return [
            'completion' => $normalizedCompletion,
            'success'    => $normalizedSuccess,
            'source'     => $source,
            'state'      => $state,
        ];
    }
}

if (!function_exists('scormProgressFromSuspendData')) {
    /**
     * Best-effort progress decoder for Articulate Storyline 360 / Rise suspend_data.
     *
     * Storyline 360 SCORM 2004 (and Rise 360) persist their `story` object in
     * cmi.suspend_data as base64-encoded JSON:
     *   {"story":{"state":[{"id":"...","name":"Slide 1","type":"slide",
     *                       "visited":true,"slide":"...","time":123}, ...]}}
     * Each story.state entry is one slide (or layer) carrying a `visited` flag.
     * We count visited / total slide-like entries and return a 0..1 float.
     *
     * Returns null when the format is not recognisable — callers must fall back
     * to other signals rather than inventing a percentage.
     *
     * @param string $raw Raw cmi.suspend_data value.
     * @return float|null 0..1 progress, or null when unparseable.
     */
    function scormProgressFromSuspendData(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $candidates = [$raw];
        // Some Rise exports URL-encode the JSON before storing it.
        if (strpos($raw, '%') !== false) {
            $candidates[] = rawurldecode($raw);
        }
        // Storyline base64-encodes the JSON blob.
        $b64 = base64_decode($raw, true);
        if ($b64 !== false && $b64 !== '') {
            $candidates[] = $b64;
        }

        foreach ($candidates as $cand) {
            $data = json_decode($cand, true);
            if (!is_array($data)) {
                continue;
            }
            $state = $data['story']['state'] ?? null;
            if (!is_array($state) || $state === []) {
                $state = scormFindSlideStateArray($data);
            }
            $amount = scormCountVisitedSlides($state);
            if ($amount !== null) {
                return $amount;
            }
        }
        return null;
    }
}

if (!function_exists('scormFindSlideStateArray')) {
    /**
     * Recursively locate the first array whose entries carry a visited-like
     * flag (the slide-state list). Storyline nests it under story.state; some
     * Rise exports place it elsewhere, so we scan defensively.
     *
     * @param mixed $node
     * @return array|null
     */
    function scormFindSlideStateArray($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        foreach ($node as $val) {
            if (!is_array($val)) {
                continue;
            }
            if (scormCountVisitedSlides($val) !== null) {
                return $val;
            }
            $found = scormFindSlideStateArray($val);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}

if (!function_exists('scormCountVisitedSlides')) {
    /**
     * Count visited / total entries in a slide-state list.
     *
     * Only entries that expose a visited-like flag are counted (filters out
     * non-slide children), and explicit layer/dialog/popup entries are excluded
     * so pop-up layers don't inflate the denominator. Returns null when nothing
     * slide-like is present.
     *
     * @param mixed $state
     * @return float|null
     */
    function scormCountVisitedSlides($state): ?float
    {
        if (!is_array($state) || $state === []) {
            return null;
        }
        $total = 0;
        $visited = 0;
        foreach ($state as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = isset($entry['type']) ? strtolower((string)$entry['type']) : '';
            if (in_array($type, ['layer', 'dialog', 'popup'], true)) {
                continue; // non-slide overlay — not part of slide progress
            }
            $flag = $entry['visited'] ?? $entry['visitedData'] ?? $entry['isVisited'] ?? $entry['viewed'] ?? null;
            if ($flag === null) {
                continue; // not a slide-state entry
            }
            $total++;
            if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
                $visited++;
            }
        }
        if ($total === 0) {
            return null;
        }
        return round($visited / $total, 4);
    }
}

if (!function_exists('scormDetectEdition')) {
    /**
     * Detect SCORM standard + edition from a manifest XML string.
     *
     * Returns '1.2' or '2004 2nd Edition' / '2004 3rd Edition' / '2004 4th Edition'.
     * Unknown/custom manifests return '' (caller treats as 1.2-safe).
     */
    function scormDetectEdition(string $manifestXml): string
    {
        $xml = trim($manifestXml);
        if ($xml === '') {
            return '';
        }

        // schemaversion is the most reliable signal exporters write.
        if (preg_match('#<schemaversion[^>]*>\s*([^<]+?)\s*</schemaversion>#is', $xml, $m)) {
            $sv = strtolower(trim($m[1]));
            if (strpos($sv, '1.2') !== false) {
                return '1.2';
            }
            if (strpos($sv, '4th') !== false) {
                return '2004 4th Edition';
            }
            if (strpos($sv, '3rd') !== false) {
                return '2004 3rd Edition';
            }
            if (strpos($sv, '2nd') !== false) {
                return '2004 2nd Edition';
            }
            if (strpos($sv, '2004') !== false) {
                return '2004 3rd Edition';
            }
        }

        // Namespace signals.
        if (strpos($xml, 'adlnet.org/xsd/adlcp_rootv1p2') !== false) {
            return '1.2';
        }
        if (strpos($xml, 'adlnet.org/xsd/adlcp_v1p3') !== false) {
            // adlcp_v1p3 is used by all 2004 editions; imscp namespace narrows it.
            if (strpos($xml, 'imscp_rootv1p1p2') !== false || strpos($xml, 'adlcp_v1p3_2ed') !== false) {
                return '2004 2nd Edition';
            }
            return '2004 3rd Edition';
        }
        if (strpos($xml, 'imsproject.org/xsd/adlcp') !== false) {
            return '1.2';
        }
        return '';
    }
}

if (!function_exists('scormDetectAdapter')) {
    /**
     * Detect the authoring-tool adapter family from an extracted package's file
     * listing. Returns ['family', 'version', 'parser', 'runtime_files'].
     *
     * Families: storyline | rise | captivate | ispring | generic.
     * Only families with a validated parser (progress-adapter phase) may have
     * their opaque suspend_data decoded; unknown packages are 'generic' and are
     * never parsed opaquely.
     */
    function scormDetectAdapter(array $files): array
    {
        $flat = array_map('strtolower', $files);
        $has = function (string $needle) use ($flat): bool {
            foreach ($flat as $f) {
                if (strpos($f, $needle) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($has('story_content/story.js') || $has('story_content/story_html5.html')) {
            return ['family' => 'storyline', 'version' => 'html5', 'parser' => 'storyline-html5-v1', 'runtime_files' => ['story_content/story.js', 'story_content/story_html5.html']];
        }
        if ($has('scormcontent/index.html')) {
            return ['family' => 'rise', 'version' => '1', 'parser' => 'rise-scormcontent-v1', 'runtime_files' => ['scormcontent/index.html']];
        }
        if ($has('captivate.js')) {
            return ['family' => 'captivate', 'version' => '', 'parser' => 'captivate-v1', 'runtime_files' => ['captivate.js']];
        }
        if ($has('isplayer.js')) {
            return ['family' => 'ispring', 'version' => '', 'parser' => 'ispring-v1', 'runtime_files' => ['isplayer.js']];
        }
        return ['family' => 'generic', 'version' => '', 'parser' => '', 'runtime_files' => []];
    }
}
