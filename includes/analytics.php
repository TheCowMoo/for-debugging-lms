<?php
/**
 * PURSUIT PATHWAYS LMS
 * ANALYTICS HELPERS — Native SCORM Data
 *
 * Provides analytics/aggregation functions used by:
 *   - analytics/organization/index.php
 *   - analytics/super-admin/index.php
 *   - analytics/user/index.php
 *
 * All queries are org-scoped (non-super admins only see their org's data)
 * and read exclusively from the native scorm_* tables.
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

if (!function_exists('analyticsOrgScope')) {
    /**
     * Build an org-scoping SQL fragment + params for a table alias prefix.
     *
     * @param string $alias Table alias, e.g. 'u', 'sa', 'sp'.
     * @return array{sql: string, params: array}
     */
    function analyticsOrgScope(string $alias = ''): array
    {
        $orgId = getOrgId();
        if ($orgId === null || isSuperAdmin()) {
            return ['sql' => '', 'params' => []];
        }
        $prefix = $alias !== '' ? $alias . '.' : '';
        return [
            'sql' => " AND {$prefix}organization_id = " . (int)$orgId,
            'params' => [],
        ];
    }
}

if (!function_exists('getOrganizationOverview')) {
    /**
     * Organization-level KPI overview.
     *
     * @return array{total_learners:int, active_enrollments:int, completion_rate:float, avg_score:?float, total_hours:int, course_count:int}
     */
    function getOrganizationOverview(): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');

        // Learners (users in the org)
        $learners = 0;
        try {
            $lq = "SELECT COUNT(*) FROM users u WHERE 1=1" . analyticsOrgScope('u')['sql'];
            $learners = (int)$pdo->query($lq)->fetchColumn();
        } catch (PDOException $e) {
            error_log('[ANALYTICS] learners: ' . $e->getMessage());
        }

        // Active enrollments (attempts)
        $enroll = 0;
        $completed = 0;
        $scoreSum = 0.0;
        $scoreCount = 0;
        $seconds = 0;
        $courses = 0;
        try {
            $sql = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE
                            WHEN sa.normalized_completion IN ('completed') THEN 1
                            WHEN sa.normalized_completion = '' AND (
                                LOWER(COALESCE(sa.lesson_status,'')) IN ('completed','passed') OR
                                LOWER(COALESCE(sa.completion_status,'')) IN ('completed','passed')
                            ) THEN 1 ELSE 0 END) AS completed_signal,
                        SUM(CASE
                            WHEN sa.normalized_success IN ('passed') THEN 1
                            WHEN sa.normalized_success = '' AND (
                                LOWER(COALESCE(sa.lesson_status,'')) = 'passed' OR
                                LOWER(COALESCE(sa.success_status,'')) = 'passed'
                            ) THEN 1 ELSE 0 END) AS passed_signal,
                        SUM(CASE WHEN COALESCE(sa.lesson_status,'') = '' AND COALESCE(sa.completion_status,'') = '' AND COALESCE(sa.success_status,'') = '' THEN 1 ELSE 0 END) AS no_signal,
                        ROUND(AVG(CASE WHEN sa.score_raw IS NOT NULL THEN sa.score_raw END), 1) AS avg_score,
                        COALESCE(SUM(sa.total_time_seconds), 0) AS seconds,
                        COUNT(DISTINCT sa.package_id) AS course_count
                    FROM scorm_attempts sa
                    WHERE 1=1" . $scope['sql'];
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            $enroll = (int)($row['total'] ?? 0);
            $completed = (int)($row['completed_signal'] ?? 0);
            $passed = (int)($row['passed_signal'] ?? 0);
            $noSignal = (int)($row['no_signal'] ?? 0);
            $scoreSum = (float)($row['avg_score'] ?? 0);
            $scoreCount = $row['avg_score'] !== null ? 1 : 0;
            $seconds = (int)($row['seconds'] ?? 0);
            $courses = (int)($row['course_count'] ?? 0);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] overview: ' . $e->getMessage());
        }

        return [
            'total_learners'     => $learners,
            'active_enrollments' => $enroll,
            'completion_rate'    => $enroll > 0 ? round(($completed / $enroll) * 100, 1) : 0,
            // Pass rate is SEPARATE from completion rate. SCORM 1.2 derives
            // success from lesson_status (never claimed as a native signal);
            // SCORM 2004 reports success_status natively.
            'pass_rate'          => $enroll > 0 ? round(($passed / $enroll) * 100, 1) : 0,
            'not_reported'       => $noSignal,
            'not_reported_pct'   => $enroll > 0 ? round(($noSignal / $enroll) * 100, 1) : 0,
            'avg_score'          => $scoreCount > 0 ? $scoreSum : null,
            'total_hours'        => (int)round($seconds / 3600),
            'course_count'       => $courses,
        ];
    }
}

if (!function_exists('getOrganizationDepartmentComparison')) {
    /**
     * Per-department comparison for the org analytics page.
     *
     * @return array<int, array{department: string, learners: int, enrollments: int, completions: int, avg_progress: ?int, avg_score: ?float, total_seconds: int}>
     */
    function getOrganizationDepartmentComparison(): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        try {
            $sql = "SELECT
                        COALESCE(sa.department, '—') AS department,
                        COUNT(DISTINCT sa.user_id) AS learners,
                        COUNT(*) AS enrollments,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completions,
                        ROUND(AVG(sa.progress_measure) * 100) AS avg_progress,
                        ROUND(AVG(CASE WHEN sa.score_raw IS NOT NULL THEN sa.score_raw END), 1) AS avg_score,
                        COALESCE(SUM(sa.total_time_seconds), 0) AS total_seconds
                    FROM scorm_attempts sa
                    WHERE 1=1" . $scope['sql'] . "
                    GROUP BY COALESCE(sa.department, '—')
                    ORDER BY learners DESC";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] department comparison: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getDepartmentList')) {
    /**
     * Distinct department names for the org analytics nav.
     */
    function getDepartmentList(): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('u');
        try {
            $sql = "SELECT DISTINCT COALESCE(department, '—') AS department
                    FROM users u
                    WHERE department IS NOT NULL AND department != ''" . $scope['sql'] . "
                    ORDER BY department";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            if (empty($rows)) {
                // Fallback: pull from attempts table
                $sql2 = "SELECT DISTINCT COALESCE(department, '—') FROM scorm_attempts sa WHERE 1=1" . analyticsOrgScope('sa')['sql'] . " AND department IS NOT NULL AND department != '' ORDER BY 1";
                $rows = $pdo->query($sql2)->fetchAll(PDO::FETCH_COLUMN);
            }
            return $rows ?: [];
        } catch (PDOException $e) {
            error_log('[ANALYTICS] department list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getOrganizationTrendData')) {
    /**
     * Monthly attempt/completion trend buckets.
     *
     * @param string $period 'month' (currently only month supported)
     * @param int    $months Number of trailing buckets to include.
     */
    function getOrganizationTrendData(string $period = 'month', int $months = 8): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');

        // Build list of trailing month buckets (Y-m)
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $buckets[date('Y-m', strtotime("-$i months"))] = [
                'bucket' => date('M Y', strtotime("-$i months")),
                'attempts' => 0,
                'completions' => 0,
            ];
        }

        try {
            $sql = "SELECT
                        DATE_FORMAT(sa.started_at, '%Y-%m') AS ym,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completions
                    FROM scorm_attempts sa
                    WHERE sa.started_at IS NOT NULL
                      AND sa.started_at >= DATE_SUB(NOW(), INTERVAL " . (int)$months . " MONTH)" . $scope['sql'] . "
                    GROUP BY DATE_FORMAT(sa.started_at, '%Y-%m')";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (isset($buckets[$r['ym']])) {
                    $buckets[$r['ym']]['attempts'] = (int)$r['attempts'];
                    $buckets[$r['ym']]['completions'] = (int)($r['completions'] ?? 0);
                }
            }
        } catch (PDOException $e) {
            error_log('[ANALYTICS] trend: ' . $e->getMessage());
        }

        return array_values($buckets);
    }
}

if (!function_exists('getDepartmentCompletionRates')) {
    /**
     * Completion rates by course for a single department.
     */
    function getDepartmentCompletionRates(string $department): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        try {
            $sql = "SELECT
                        COALESCE(sp.title, 'Untitled Course') AS course_title,
                        COUNT(DISTINCT sa.user_id) AS total_learners,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completed_learners,
                        SUM(CASE WHEN sa.passed = 1 THEN 1 ELSE 0 END) AS passed_learners,
                        ROUND(AVG(CASE WHEN sa.score_raw IS NOT NULL THEN sa.score_raw END), 1) AS avg_score
                    FROM scorm_attempts sa
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sa.department = ? AND sa.department IS NOT NULL" . $scope['sql'] . "
                    GROUP BY sp.id, sp.title
                    ORDER BY total_learners DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$department]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] dept completion rates: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getDepartmentKnowledgeGaps')) {
    /**
     * Lowest-accuracy questions for a department.
     */
    function getDepartmentKnowledgeGaps(string $department, int $limit = 12): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        try {
            $sql = "SELECT
                        COALESCE(sp.title, 'Untitled Course') AS course_title,
                        si.interaction_id,
                        si.description,
                        si.result,
                        COUNT(*) AS total_answers,
                        SUM(CASE WHEN LOWER(si.result) = 'correct' THEN 1 ELSE 0 END) AS correct_answers,
                        ROUND(AVG(CASE WHEN si.latency_seconds IS NOT NULL THEN si.latency_seconds END), 2) AS avg_latency_seconds
                    FROM scorm_interactions si
                    JOIN scorm_attempts sa ON sa.id = si.attempt_id
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sa.department = ? AND sa.department IS NOT NULL" . $scope['sql'] . "
                    GROUP BY si.interaction_id, si.description, si.result, sp.title
                    ORDER BY (SUM(CASE WHEN LOWER(si.result) = 'correct' THEN 1 ELSE 0 END) / COUNT(*)) ASC
                    LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$department]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute accuracy_pct per row
            $result = [];
            foreach ($rows as $r) {
                $total = (int)$r['total_answers'];
                $correct = (int)$r['correct_answers'];
                $r['accuracy_pct'] = $total > 0 ? round(($correct / $total) * 100, 1) : 0;
                $result[] = $r;
            }
            return $result;
        } catch (PDOException $e) {
            error_log('[ANALYTICS] knowledge gaps: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getAtRiskLearners')) {
    /**
     * Learners below a progress threshold (default 60%).
     */
    function getAtRiskLearners(int $days = 60): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        try {
            $sql = "SELECT
                        u.first_name, u.last_name, u.email, u.department,
                        COALESCE(sp.title, 'Untitled Course') AS course_title,
                        ROUND(COALESCE(sa.progress_measure, 0) * 100, 1) AS progress_pct,
                        sa.lesson_status,
                        sa.last_accessed_at
                    FROM scorm_attempts sa
                    JOIN users u ON u.id = sa.user_id
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sa.is_complete = 0
                      AND sa.progress_measure IS NOT NULL
                      AND sa.progress_measure < 0.6" . $scope['sql'] . "
                    ORDER BY sa.progress_measure ASC
                    LIMIT 100";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] at-risk learners: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getOrgListForAnalytics')) {
    /**
     * Organization comparison for the super-admin analytics page.
     */
    function getOrgListForAnalytics(): array
    {
        $pdo = getDbConnection();
        try {
            $sql = "SELECT
                        o.id, o.name,
                        COUNT(DISTINCT sa.user_id) AS learners,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completions
                    FROM organizations o
                    LEFT JOIN scorm_attempts sa ON sa.organization_id = o.id
                    GROUP BY o.id, o.name
                    ORDER BY o.name";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] org list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('searchAcrossOrgs')) {
    /**
     * Global search across orgs/courses/learners (super admin only).
     *
     * @param array{org_id?: int, department?: string, search?: string} $filters
     */
    function searchAcrossOrgs(array $filters = []): array
    {
        $pdo = getDbConnection();
        $where = [];
        $params = [];

        $orgId = (int)($filters['org_id'] ?? 0);
        if ($orgId > 0) {
            $where[] = 'sa.organization_id = ?';
            $params[] = $orgId;
        }

        $dept = trim($filters['department'] ?? '');
        if ($dept !== '') {
            $where[] = '(u.department = ? OR sa.department = ?)';
            $params[] = $dept;
            $params[] = $dept;
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR sp.title LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';

        try {
            $sql = "SELECT
                        u.first_name, u.last_name, u.email, u.department,
                        COALESCE(o.name, '—') AS org_name,
                        COALESCE(sp.title, 'Untitled Course') AS course_title,
                        sa.lesson_status,
                        sa.score_raw,
                        sa.progress_measure,
                        sa.last_accessed_at
                    FROM scorm_attempts sa
                    JOIN users u ON u.id = sa.user_id
                    LEFT JOIN organizations o ON o.id = COALESCE(sa.organization_id, u.organization_id)
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    $whereSql
                    ORDER BY sa.last_accessed_at DESC
                    LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] search across orgs: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserCourseSummary')) {
    /**
     * Per-course summary for a single learner.
     */
    function getUserCourseSummary(int $userId): array
    {
        $pdo = getDbConnection();
        try {
            $sql = "SELECT
                        sp.id AS package_id,
                        COALESCE(sp.title, 'Untitled Course') AS title,
                        CASE WHEN MAX(sa.is_complete) = 1 THEN 'Completed' ELSE 'Incomplete' END AS status,
                        ROUND(COALESCE(MAX(sa.progress_measure), 0), 4) AS completion_amount,
                        MAX(CASE WHEN sa.score_raw IS NOT NULL THEN sa.score_raw END) AS score_raw,
                        COALESCE(SUM(sa.total_time_seconds), 0) AS total_seconds,
                        COUNT(sa.id) AS attempts,
                        MAX(sa.last_accessed_at) AS last_accessed_at,
                        MAX(sa.is_complete) AS is_complete
                    FROM scorm_attempts sa
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sa.user_id = ?
                    GROUP BY sp.id, sp.title
                    ORDER BY last_accessed_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] user course summary: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserAttemptHistory')) {
    /**
     * Full attempt history for a learner (optionally filtered by package).
     */
    function getUserAttemptHistory(int $userId, ?int $packageId = null): array
    {
        $pdo = getDbConnection();
        $params = [$userId];
        $pkgSql = '';
        if ($packageId !== null && $packageId > 0) {
            $pkgSql = ' AND sa.package_id = ?';
            $params[] = $packageId;
        }
        try {
            $sql = "SELECT
                        COALESCE(sp.title, 'Untitled Course') AS package_title,
                        sa.attempt_number,
                        sa.lesson_status,
                        sa.completion_status,
                        sa.score_raw,
                        sa.total_time_seconds,
                        sa.started_at
                    FROM scorm_attempts sa
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sa.user_id = ?" . $pkgSql . "
                    ORDER BY sa.started_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] user attempt history: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserQuestionAnalysis')) {
    /**
     * Question-level (interaction) analysis for a learner+package.
     */
    function getUserQuestionAnalysis(int $userId, int $packageId): array
    {
        $pdo = getDbConnection();
        try {
            $sql = "SELECT
                        si.interaction_index,
                        si.interaction_id,
                        si.interaction_type,
                        si.learner_response,
                        si.description,
                        si.result,
                        si.latency_seconds
                    FROM scorm_interactions si
                    JOIN scorm_attempts sa ON sa.id = si.attempt_id
                    WHERE si.user_id = ? AND sa.package_id = ?
                    ORDER BY si.timestamp ASC, si.interaction_index ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $packageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] user question analysis: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserCompetencyMap')) {
    /**
     * Objective (competency) map for a learner+package.
     */
    function getUserCompetencyMap(int $userId, int $packageId): array
    {
        $pdo = getDbConnection();
        try {
            $sql = "SELECT DISTINCT
                        so.objective_index,
                        so.objective_id,
                        so.score_raw,
                        so.completion_status,
                        so.success_status
                    FROM scorm_objectives so
                    JOIN scorm_attempts sa ON sa.id = so.attempt_id
                    WHERE so.user_id = ? AND sa.package_id = ?
                    ORDER BY so.objective_index ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $packageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] user competency map: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserTimeOnTask')) {
    /**
     * Session/total time per SCO for a learner+package.
     */
    function getUserTimeOnTask(int $userId, int $packageId): array
    {
        $pdo = getDbConnection();
        try {
            $sql = "SELECT
                        si.title AS sco_title,
                        sa.sco_item_id,
                        sa.attempt_number,
                        sa.session_time_seconds,
                        sa.total_time_seconds,
                        sa.lesson_status,
                        sa.started_at
                    FROM scorm_attempts sa
                    LEFT JOIN sco_items si ON si.id = sa.sco_item_id
                    WHERE sa.user_id = ? AND sa.package_id = ?
                    ORDER BY sa.started_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $packageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] user time on task: ' . $e->getMessage());
            return [];
        }
    }
}
if (!function_exists('getDailyActivityData')) {
    /**
     * Daily activity: distinct active learners and sessions per day.
     * Used for the daily-activity dashboard chart.
     */
    function getDailyActivityData(int $days = 30): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');

        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $buckets[$day] = [
                'day'         => $day,
                'label'       => date('M j', strtotime($day)),
                'active_users' => 0,
                'sessions'    => 0,
                'completions' => 0,
            ];
        }

        try {
            $sql = "SELECT
                        DATE(sa.last_accessed_at) AS day,
                        COUNT(DISTINCT sa.user_id) AS active_users,
                        COUNT(*) AS sessions,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completions
                    FROM scorm_attempts sa
                    WHERE sa.last_accessed_at IS NOT NULL
                      AND sa.last_accessed_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)" . $scope['sql'] . "
                    GROUP BY DATE(sa.last_accessed_at)
                    ORDER BY day ASC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (isset($buckets[$r['day']])) {
                    $buckets[$r['day']]['active_users'] = (int)$r['active_users'];
                    $buckets[$r['day']]['sessions']     = (int)$r['sessions'];
                    $buckets[$r['day']]['completions']  = (int)($r['completions'] ?? 0);
                }
            }
        } catch (PDOException $e) {
            error_log('[ANALYTICS] daily activity: ' . $e->getMessage());
        }

        return array_values($buckets);
    }
}

if (!function_exists('getCompletionFunnel')) {
    /**
     * Completion funnel: not_started → in_progress → completed → passed.
     * Each stage is a superset of the next (all enrolled attempts).
     */
    function getCompletionFunnel(): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $result = [
            ['stage' => 'Not Started', 'count' => 0, 'color' => '#94a3b8'],
            ['stage' => 'In Progress', 'count' => 0, 'color' => '#f59e0b'],
            ['stage' => 'Completed',   'count' => 0, 'color' => '#3b82f6'],
            ['stage' => 'Passed',      'count' => 0, 'color' => '#10b981'],
        ];

        try {
            $sql = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN sa.lesson_status IN ('not attempted','') THEN 1 ELSE 0 END) AS not_started,
                        SUM(CASE WHEN sa.is_complete = 0 AND sa.lesson_status NOT IN ('not attempted','') THEN 1 ELSE 0 END) AS in_progress,
                        SUM(CASE WHEN sa.is_complete = 1 THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN sa.passed = 1 THEN 1 ELSE 0 END) AS passed
                    FROM scorm_attempts sa
                    WHERE 1=1" . $scope['sql'];
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $result[0]['count'] = (int)($row['not_started'] ?? 0);
                $result[1]['count'] = (int)($row['in_progress'] ?? 0);
                $result[2]['count'] = (int)($row['completed'] ?? 0);
                $result[3]['count'] = (int)($row['passed'] ?? 0);
            }
        } catch (PDOException $e) {
            error_log('[ANALYTICS] funnel: ' . $e->getMessage());
        }

        return $result;
    }
}

if (!function_exists('getQuestionPerformanceHeatmap')) {
    /**
     * Question performance heatmap: accuracy per question across the org,
     * with attempt counts and average latency. Sorted by accuracy ascending
     * so the weakest questions surface first.
     */
    function getQuestionPerformanceHeatmap(int $limit = 20): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        try {
            $sql = "SELECT
                        COALESCE(sp.title, 'Untitled Course') AS course_title,
                        si.interaction_id,
                        LEFT(COALESCE(si.description, si.interaction_id, 'Untitled Question'), 120) AS question_text,
                        si.interaction_type,
                        COUNT(*) AS total_answers,
                        SUM(CASE WHEN LOWER(si.result) = 'correct' THEN 1 ELSE 0 END) AS correct_answers,
                        ROUND(AVG(CASE WHEN si.latency_seconds IS NOT NULL THEN si.latency_seconds END), 1) AS avg_latency_seconds,
                        COUNT(DISTINCT sa.user_id) AS learners_attempted
                    FROM scorm_interactions si
                    JOIN scorm_attempts sa ON sa.id = si.attempt_id
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE 1=1" . $scope['sql'] . "
                    GROUP BY sp.id, si.interaction_id, si.description, si.interaction_type
                    HAVING COUNT(*) >= 1
                    ORDER BY (SUM(CASE WHEN LOWER(si.result) = 'correct' THEN 1 ELSE 0 END) / COUNT(*)) ASC, COUNT(*) DESC
                    LIMIT " . (int)$limit;
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $total = (int)$r['total_answers'];
                $correct = (int)$r['correct_answers'];
                $r['accuracy_pct'] = $total > 0 ? round(($correct / $total) * 100, 1) : 0;
            }
            unset($r);
            return $rows;
        } catch (PDOException $e) {
            error_log('[ANALYTICS] question heatmap: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('parseRiseSuspendData')) {
    /**
     * Best-effort decoder for Rise 360 / Storyline suspend_data.
     * The format varies: JSON, URL-encoded JSON, or base64.
     * Returns a decoded array or null.
     */
    function parseRiseSuspendData(string $raw): ?array
    {
        if ($raw === '') return null;

        $candidates = [$raw];
        // Try URL-decoded variant (Rise commonly double-encodes)
        if (strpos($raw, '%') !== false) {
            $candidates[] = rawurldecode($raw);
        }
        // Try base64 variant
        $b64 = base64_decode($raw, true);
        if ($b64 !== false && $b64 !== '' && preg_match('/^[a-zA-Z0-9_]+/', $b64)) {
            $candidates[] = $b64;
        }

        foreach ($candidates as $cand) {
            $decoded = json_decode($cand, true);
            if (is_array($decoded) || is_object($decoded)) {
                return (array)$decoded;
            }
        }
        return null;
    }
}

if (!function_exists('getSlideTimeBreakdown')) {
    /**
     * Slide-by-slide time breakdown parsed from suspend_data.
     * Rise 360 embeds per-slide timing in suspend_data as a nested
     * structure (commonly under "slideTimes", "timeSpent", "slideState",
     * or similar keys). This scans recursively and returns whatever
     * time-like fields it finds, plus a summary for the dashboard.
     *
     * @param int $limit Max learners to inspect (limit query cost).
     */
    function getSlideTimeBreakdown(int $limit = 50): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');

        $timeKeys = ['time', 'timeOnSlide', 'slideTime', 'duration', 'elapsed', 'seconds', 'timeSpent', 'totalTime', 'viewTime'];

        try {
            $sql = "SELECT sa.id AS attempt_id, sa.user_id, sa.suspend_data
                    FROM scorm_attempts sa
                    WHERE sa.suspend_data IS NOT NULL
                      AND sa.suspend_data <> ''" . $scope['sql'] . "
                    ORDER BY sa.updated_at DESC
                    LIMIT " . (int)$limit;
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] slide time query: ' . $e->getMessage());
            return ['parsed_attempts' => 0, 'slides' => [], 'summary' => []];
        }

        $slides = [];
        $attemptsParsed = 0;

        foreach ($rows as $row) {
            $data = parseRiseSuspendData((string)$row['suspend_data']);
            if ($data === null) continue;
            $attemptsParsed++;

            // Recursively walk the decoded structure looking for slide-like time entries.
            $walker = function ($node, string $path = '') use (&$walker, &$slides, &$row, $timeKeys) {
                if (!is_array($node)) return;
                foreach ($node as $key => $val) {
                    $childPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
                    $keyLower = strtolower((string)$key);

                    // Slide-like node: has an id/name plus a time-like field
                    $isTimeKey = false;
                    foreach ($timeKeys as $tk) {
                        if (strpos($keyLower, strtolower($tk)) !== false) { $isTimeKey = true; break; }
                    }

                    if ($isTimeKey) {
                        // Interpret value as seconds (or ms if very large)
                        $secs = (float)$val;
                        if ($secs > 100000) $secs = $secs / 1000; // ms → s
                        $slides[] = [
                            'attempt_id'    => (int)$row['attempt_id'],
                            'user_id'       => (int)$row['user_id'],
                            'path'          => $childPath,
                            'label'         => $path !== '' ? $path : $childPath,
                            'seconds'       => round($secs, 1),
                        ];
                    } elseif (is_array($val) || is_object($val)) {
                        $walker((array)$val, $childPath);
                    }
                }
            };
            $walker($data);
        }

        // Summary: aggregate by slide label (top 25 by total time)
        $summary = [];
        foreach ($slides as $s) {
            $label = $s['label'];
            if (!isset($summary[$label])) {
                $summary[$label] = ['label' => $label, 'total_seconds' => 0, 'learner_count' => 0];
            }
            $summary[$label]['total_seconds'] += $s['seconds'];
            $summary[$label]['learner_count']++;
        }
        usort($summary, function($a, $b) { return $b['total_seconds'] <=> $a['total_seconds']; });
        $summary = array_slice($summary, 0, 25);

        return [
            'parsed_attempts' => $attemptsParsed,
            'slides'          => $slides,
            'summary'         => $summary,
            'has_data'        => $attemptsParsed > 0 && count($slides) > 0,
        ];
    }
}

if (!function_exists('analyticsV2Enabled')) {
    /**
     * Feature flag for the cross-version analytics dashboards (section 7).
     * Enable by setting ANALYTICS_V2=1 in .env.
     */
    function analyticsV2Enabled(): bool
    {
        return getenv('ANALYTICS_V2') === '1';
    }
}

if (!function_exists('getCompletionPassRates')) {
    /**
     * Completion vs pass rate per package.
     *
     * Grain: LATEST attempt per (user, package, sco). Completion and pass are
     * reported separately (SCORM 1.2 success is derived from lesson_status;
     * SCORM 2004 success is a native success_status). "Not reported" (no
     * status signal at all) is a distinct bucket from explicit 'incomplete'.
     */
    function getCompletionPassRates(int $limit = 100): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $rows = [];
        try {
            $sql = "SELECT sp.id AS package_id, sp.title, sp.scorm_version, sp.scorm_edition,
                        COUNT(*) AS attempts,
                        COUNT(DISTINCT sa.user_id) AS learners,
                        SUM(CASE
                            WHEN sa.normalized_completion IN ('completed') THEN 1
                            WHEN sa.normalized_completion = '' AND (
                                LOWER(COALESCE(sa.lesson_status,'')) IN ('completed','passed') OR
                                LOWER(COALESCE(sa.completion_status,'')) IN ('completed','passed')
                            ) THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE
                            WHEN sa.normalized_success IN ('passed') THEN 1
                            WHEN sa.normalized_success = '' AND (
                                LOWER(COALESCE(sa.lesson_status,'')) = 'passed' OR
                                LOWER(COALESCE(sa.success_status,'')) = 'passed'
                            ) THEN 1 ELSE 0 END) AS passed,
                        SUM(CASE WHEN COALESCE(sa.lesson_status,'') = '' AND COALESCE(sa.completion_status,'') = '' AND COALESCE(sa.success_status,'') = '' THEN 1 ELSE 0 END) AS no_signal
                    FROM (
                        SELECT MAX(id) AS id FROM scorm_attempts
                        GROUP BY user_id, package_id, sco_item_id
                    ) latest
                    JOIN scorm_attempts sa ON sa.id = latest.id
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sp.status = 'active'" . $scope['sql'] . "
                    GROUP BY sp.id, sp.title, sp.scorm_version, sp.scorm_edition
                    ORDER BY sp.title
                    LIMIT " . (int)$limit;
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $total = max(1, (int)$r['attempts']);
                $r['completed_pct'] = round(((int)$r['completed'] / $total) * 100, 1);
                $r['passed_pct']    = round(((int)$r['passed'] / $total) * 100, 1);
                $r['no_signal_pct'] = round(((int)$r['no_signal'] / $total) * 100, 1);
            }
            unset($r);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] completionPassRates: ' . $e->getMessage());
        }
        return $rows;
    }
}

if (!function_exists('getInteractionAccuracy')) {
    /**
     * Interaction accuracy + average response latency per question.
     * Scope via scorm_attempts (interactions carry no org column).
     */
    function getInteractionAccuracy(?int $packageId = null, int $limit = 200): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $params = [];
        $pkgSql = '';
        if ($packageId !== null && $packageId > 0) {
            $pkgSql = ' AND sa.package_id = ?';
            $params[] = $packageId;
        }
        try {
            $sql = "SELECT si.interaction_id, si.interaction_type,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN LOWER(COALESCE(si.result,'')) IN ('correct','pass','passed') THEN 1 ELSE 0 END) AS correct,
                        ROUND(AVG(NULLIF(si.latency_seconds, 0)), 2) AS avg_latency_s,
                        SUM(CASE WHEN si.latency_seconds IS NOT NULL AND si.latency_seconds > 0 THEN 1 ELSE 0 END) AS latency_reported
                    FROM scorm_interactions si
                    JOIN scorm_attempts sa ON sa.id = si.attempt_id
                    WHERE si.interaction_id <> ''" . $scope['sql'] . $pkgSql . "
                    GROUP BY si.interaction_id, si.interaction_type
                    ORDER BY attempts DESC
                    LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $total = max(1, (int)$r['attempts']);
                $r['accuracy_pct'] = round(((int)$r['correct'] / $total) * 100, 1);
            }
            unset($r);
            return $rows;
        } catch (PDOException $e) {
            error_log('[ANALYTICS] interactionAccuracy: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getScoreDistribution')) {
    /**
     * Score distribution for latest attempts. 'not_reported' (NULL score) is
     * a distinct bucket from an explicit zero score.
     */
    function getScoreDistribution(?int $packageId = null): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $params = [];
        $pkgSql = '';
        if ($packageId !== null && $packageId > 0) {
            $pkgSql = ' AND sa.package_id = ?';
            $params[] = $packageId;
        }
        try {
            $sql = "SELECT
                        CASE
                            WHEN sa.score_raw IS NULL AND sa.score_scaled IS NULL THEN 'not_reported'
                            WHEN sa.score_raw < 10 THEN '0-9'
                            WHEN sa.score_raw < 20 THEN '10-19'
                            WHEN sa.score_raw < 30 THEN '20-29'
                            WHEN sa.score_raw < 40 THEN '30-39'
                            WHEN sa.score_raw < 50 THEN '40-49'
                            WHEN sa.score_raw < 60 THEN '50-59'
                            WHEN sa.score_raw < 70 THEN '60-69'
                            WHEN sa.score_raw < 80 THEN '70-79'
                            WHEN sa.score_raw < 90 THEN '80-89'
                            WHEN sa.score_raw < 100 THEN '90-99'
                            WHEN sa.score_raw <= 100 THEN '100'
                            ELSE 'raw_gt_100'
                        END AS bucket,
                        COUNT(*) AS n
                    FROM (
                        SELECT MAX(id) AS id FROM scorm_attempts
                        GROUP BY user_id, package_id, sco_item_id
                    ) latest
                    JOIN scorm_attempts sa ON sa.id = latest.id
                    WHERE 1=1" . $scope['sql'] . $pkgSql . "
                    GROUP BY bucket
                    ORDER BY MIN(CASE WHEN sa.score_raw IS NULL AND sa.score_scaled IS NULL THEN -1 ELSE COALESCE(sa.score_raw, sa.score_scaled * 100) END)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] scoreDistribution: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getObjectiveQuestionAnalysis')) {
    /**
     * Objective-to-question analysis using the scorm_interaction_objectives
     * junction table (cmi.interactions.n.objectives.m.id).
     */
    function getObjectiveQuestionAnalysis(?int $packageId = null, int $limit = 200): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $params = [];
        $pkgSql = '';
        if ($packageId !== null && $packageId > 0) {
            $pkgSql = ' AND sa.package_id = ?';
            $params[] = $packageId;
        }
        try {
            $sql = "SELECT sio.objective_id,
                        COUNT(DISTINCT sio.interaction_index) AS linked_interactions,
                        COUNT(*) AS link_count
                    FROM scorm_interaction_objectives sio
                    JOIN scorm_attempts sa ON sa.id = sio.attempt_id
                    WHERE sio.objective_id <> ''" . $scope['sql'] . $pkgSql . "
                    GROUP BY sio.objective_id
                    ORDER BY link_count DESC
                    LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] objectiveQuestion: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getTelemetryCompleteness')) {
    /**
     * Package telemetry completeness: status, score, time, progress,
     * interactions, objectives, suspend-data coverage across attempts.
     */
    function getTelemetryCompleteness(int $limit = 100): array
    {
        $pdo = getDbConnection();
        $scope = analyticsOrgScope('sa');
        $rows = [];
        try {
            $sql = "SELECT sp.id AS package_id, sp.title, sp.scorm_version, sp.scorm_edition,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN COALESCE(sa.lesson_status,'') <> '' OR COALESCE(sa.completion_status,'') <> '' OR COALESCE(sa.success_status,'') <> '' THEN 1 ELSE 0 END) AS status_reported,
                        SUM(CASE WHEN sa.score_raw IS NOT NULL OR sa.score_scaled IS NOT NULL THEN 1 ELSE 0 END) AS score_reported,
                        SUM(CASE WHEN sa.total_time_seconds > 0 THEN 1 ELSE 0 END) AS time_reported,
                        SUM(CASE WHEN sa.progress_measure IS NOT NULL THEN 1 ELSE 0 END) AS progress_reported,
                        SUM(CASE WHEN EXISTS (SELECT 1 FROM scorm_interactions i2 WHERE i2.attempt_id = sa.id) THEN 1 ELSE 0 END) AS interactions_reported,
                        SUM(CASE WHEN EXISTS (SELECT 1 FROM scorm_objectives o2 WHERE o2.attempt_id = sa.id) THEN 1 ELSE 0 END) AS objectives_reported,
                        SUM(CASE WHEN COALESCE(sa.suspend_data,'') <> '' THEN 1 ELSE 0 END) AS suspend_reported,
                        SUM(CASE WHEN EXISTS (SELECT 1 FROM scorm_comments_from_learner c2 WHERE c2.attempt_id = sa.id) THEN 1 ELSE 0 END) AS comments_reported
                    FROM scorm_attempts sa
                    JOIN scorm_packages sp ON sp.id = sa.package_id
                    WHERE sp.status = 'active'" . $scope['sql'] . "
                    GROUP BY sp.id, sp.title, sp.scorm_version, sp.scorm_edition
                    ORDER BY attempts DESC
                    LIMIT " . (int)$limit;
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $total = max(1, (int)$r['attempts']);
                foreach (['status_reported','score_reported','time_reported','progress_reported','interactions_reported','objectives_reported','suspend_reported','comments_reported'] as $k) {
                    $r[$k . '_pct'] = round(((int)$r[$k] / $total) * 100, 1);
                }
            }
            unset($r);
        } catch (PDOException $e) {
            error_log('[ANALYTICS] telemetry: ' . $e->getMessage());
        }
        return $rows;
    }
}

if (!function_exists('getPersistenceMonitoring')) {
    /**
     * Rejected-payload, duplicate-request, and failed-persistence monitoring
     * from the scorm_monitor + idempotency tables (rolling window).
     */
    function getPersistenceMonitoring(int $hours = 24): array
    {
        $pdo = getDbConnection();
        $out = ['rejected' => 0, 'failed' => 0, 'duplicate' => 0, 'duplicate_requests' => 0, 'commits' => 0, 'terminates' => 0];
        try {
            $sql = "SELECT monitor_type, COUNT(*) AS n
                    FROM scorm_monitor
                    WHERE created_at >= (NOW() - INTERVAL ? HOUR)
                    GROUP BY monitor_type";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([(int)$hours]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['monitor_type']] = (int)$r['n'];
            }
            $h = (int)$hours;
            $dup = $pdo->query("SELECT COUNT(*) FROM scorm_request_idempotency WHERE response IS NOT NULL AND created_at >= (NOW() - INTERVAL $h HOUR)");
            $out['duplicate_requests'] = (int)$dup->fetchColumn();
            $ev = $pdo->query("SELECT event_type, COUNT(*) AS n FROM scorm_events WHERE created_at >= (NOW() - INTERVAL $h HOUR) GROUP BY event_type");
            foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['event_type'] === 'terminate') {
                    $out['terminates'] = (int)$r['n'];
                } else {
                    $out['commits'] += (int)$r['n'];
                }
            }
        } catch (PDOException $e) {
            error_log('[ANALYTICS] persistenceMonitoring: ' . $e->getMessage());
        }
        return $out;
    }
}
