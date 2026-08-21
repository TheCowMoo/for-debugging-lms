<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * SCORM VENDOR FIXTURE REGISTRY
 *
 * A data-only registry of known authoring-tool export behaviours. This is NOT
 * code — no vendor-specific branches live in the runtime. Each fixture records:
 *
 *   - authoring tool + version
 *   - export format / SCORM edition
 *   - expected launch file (manifest-driven; never hardcoded)
 *   - expected status behaviour (completion / success semantics)
 *   - expected score / pass rule
 *   - expected interactions / objectives
 *   - required cases (see the compatibility contract)
 *
 * The integration test harness consumes this registry to verify upload →
 * manifest parse → correct launch href → API initialisation → commits →
 * termination → resume → status/score/time/interactions/objectives →
 * multi-SCO isolation → duplicate beacons → concurrent commits.
 *
 * @package  PP_LMS
 */

return [

    'storyline-360-scorm12' => [
        'vendor'          => 'Articulate Storyline 360',
        'version'         => '360 (build 84+, SCORM 1.2 export)',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['story_content/story.html', 'story.html'],   // manifest href
        'launch_never'    => ['index.html', 'indexapi.html', 'index_lms.html'],
        'status_behavior' => 'lesson_status completed/passed on course completion; passed when mastery_score met or quiz passed; browse mode sets browsed.',
        'score_pass_rule' => 'score.raw from quiz; pass inferred from lesson_status=passed or score >= mastery_score.',
        'interactions'    => ['cmi.interactions.n.id/type/learner_response/result/timestamp', 'correct_responses.n.pattern'],
        'objectives'      => ['cmi.objectives.n.id/score.raw/completion_status'],
        'suspend_data'    => 'slide bookmark JSON; under 4096 for 1.2.',
        'required_cases'  => ['quiz', 'survey', 'freeform question', 'question text', 'completion by slides', 'completion by quiz', 'completion by trigger', 'resume'],
        'resume'          => true,
        'notes'           => 'Synchronous API discovery inside iframe; calls LMSInitialize()/LMSCommit()/LMSFinish().',
    ],

    'storyline-360-scorm2004' => [
        'vendor'          => 'Articulate Storyline 360',
        'version'         => '360 (SCORM 2004 export)',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['story_content/story.html'],
        'launch_never'    => ['index.html', 'indexapi.html', 'index_lms.html'],
        'status_behavior' => 'cmi.completion_status=completed and cmi.success_status=passed/failed; completion can come from slides, quiz, or trigger.',
        'score_pass_rule' => 'cmi.score.scaled or cmi.score.raw; pass from success_status=passed or score >= cmi.scaled_passing_score.',
        'interactions'    => ['cmi.interactions.n.id/type/learner_response/result/latency/timestamp/description', 'correct_responses.n.pattern'],
        'objectives'      => ['cmi.objectives.n.id/score.scaled/completion_status/success_status'],
        'suspend_data'    => 'slide bookmark JSON; up to 64000 for 3rd Ed.',
        'required_cases'  => ['quiz', 'freeform question', 'question text', 'completion by trigger', 'resume'],
        'resume'          => true,
    ],

    'rise-360-scorm12' => [
        'vendor'          => 'Articulate Rise 360',
        'version'         => 'latest',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['indexapi.html', 'scormcontent/index.html'],  // manifest href
        'launch_never'    => ['index.html', 'index_lms.html', 'story.html'],
        'status_behavior' => 'lesson_status=completed set from completion percentage (100%) OR Storyline block completion OR quiz result.',
        'score_pass_rule' => 'score.raw from quiz result; passed when quiz passed or completion reached 100%.',
        'interactions'    => ['cmi.interactions.n.id/learner_response/result/correct_responses.n.pattern'],
        'objectives'      => ['cmi.objectives.n.id'],
        'suspend_data'    => 'large JSON bookmark — must respect 4096 truncation.',
        'required_cases'  => ['tracking by completion percentage', 'Storyline block', 'quiz result', 'indexapi.html', 'long resume'],
        'resume'          => true,
        'notes'           => 'Publishes as 1.2 or 2004 with the SCORM wrapper in scormcontent/.',
    ],

    'rise-360-scorm2004' => [
        'vendor'          => 'Articulate Rise 360',
        'version'         => 'latest',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['indexapi.html', 'scormcontent/index.html'],
        'launch_never'    => ['index.html', 'story.html'],
        'status_behavior' => 'cmi.completion_status=completed from 100% progress; cmi.success_status from quiz result.',
        'score_pass_rule' => 'cmi.score.scaled from quiz result.',
        'interactions'    => ['cmi.interactions.n.id/type/learner_response/result'],
        'objectives'      => [],
        'suspend_data'    => 'large JSON bookmark; 64000 limit for 3rd Ed.',
        'required_cases'  => ['tracking by completion percentage', 'Storyline block', 'quiz result', 'long resume'],
        'resume'          => true,
    ],

    'captivate-scorm12' => [
        'vendor'          => 'Adobe Captivate',
        'version'         => '2019 / 2023',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['index.html', 'index_lms.html'],   // manifest href
        'launch_never'    => ['story.html'],
        'status_behavior' => 'lesson_status=completed/passed/failed/incomplete depending on reporting settings; completion/success configurable in publish dialog.',
        'score_pass_rule' => 'score.raw from quiz; pass threshold configurable (default 80% via mastery in some exports).',
        'interactions'    => ['cmi.interactions.n.id/learner_response/result'],
        'objectives'      => [],
        'suspend_data'    => 'bookmark JSON.',
        'required_cases'  => ['reporting enabled/disabled', 'quiz/no quiz', 'completion/success settings', 'resume'],
        'resume'          => true,
        'notes'           => 'Reporting can be disabled entirely — package then sends only basic progress or nothing.',
    ],

    'captivate-scorm2004' => [
        'vendor'          => 'Adobe Captivate',
        'version'         => '2019 / 2023',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['index.html', 'index_lms.html'],
        'launch_never'    => ['story.html'],
        'status_behavior' => 'cmi.completion_status/success_status per publish settings.',
        'score_pass_rule' => 'cmi.score.scaled.',
        'interactions'    => ['cmi.interactions.n.id/type/learner_response/result'],
        'objectives'      => [],
        'required_cases'  => ['reporting enabled/disabled', 'quiz/no quiz', 'completion/success settings', 'resume'],
        'resume'          => true,
    ],

    'ispring-scorm12' => [
        'vendor'          => 'iSpring Suite',
        'version'         => '10/11',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['index_lms.html', 'index.html'],   // manifest href
        'launch_never'    => ['story.html'],
        'status_behavior' => 'lesson_status completed/passed/failed from quiz; sets suspend_data frequently.',
        'score_pass_rule' => 'score.raw; pass = score >= mastery score.',
        'interactions'    => ['cmi.interactions.n.id/type/learner_response/result/correct_responses.n.pattern'],
        'objectives'      => [],
        'suspend_data'    => 'JSON state — must respect 4096.',
        'required_cases'  => ['quiz scoring', 'interactions', 'suspend-data limit', 'repeated attempts'],
        'resume'          => true,
        'notes'           => 'Writes suspend_data on every slide change; commit-driven.',
    ],

    'ispring-scorm2004' => [
        'vendor'          => 'iSpring Suite',
        'version'         => '10/11',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['index_lms.html', 'index.html'],
        'launch_never'    => ['story.html'],
        'status_behavior' => 'cmi.completion_status + cmi.success_status.',
        'score_pass_rule' => 'cmi.score.scaled.',
        'interactions'    => ['cmi.interactions.n.*'],
        'required_cases'  => ['quiz scoring', 'interactions', 'repeated attempts'],
        'resume'          => true,
    ],

    'lectora-scorm12' => [
        'vendor'          => 'Lectora',
        'version'         => '17+ / Lectora Online',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['index_lms.html', 'index.html'],   // manifest href
        'launch_never'    => ['story.html'],
        'status_behavior' => 'lesson_status completed/passed; manual completion via trigger; exit action sets exit=normal/….',
        'score_pass_rule' => 'mastery score in manifest (adlcp:masteryscore) — pass = score >= mastery.',
        'interactions'    => ['cmi.interactions.n.id/learner_response/result'],
        'objectives'      => [],
        'prerequisites'   => 'adlcp:prerequisites on SCO items (detect + report; runtime sequencing not certified).',
        'required_cases'  => ['one SCO', 'multiple SCO/AU', 'mastery score', 'prerequisites', 'manual completion', 'exit action'],
        'resume'          => true,
        'notes'           => 'Multi-SCO packages must isolate attempts per SCO.',
    ],

    'lectora-scorm2004' => [
        'vendor'          => 'Lectora',
        'version'         => '17+',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['index_lms.html', 'index.html'],
        'launch_never'    => ['story.html'],
        'status_behavior' => 'cmi.completion_status/success_status; completion via manual trigger or activity complete.',
        'score_pass_rule' => 'cmi.score.scaled vs cmi.scaled_passing_score.',
        'interactions'    => ['cmi.interactions.n.*'],
        'required_cases'  => ['multiple SCO/AU', 'prerequisites', 'manual completion', 'exit action'],
        'resume'          => true,
    ],

    'dominknow-scorm12' => [
        'vendor'          => 'domiKnow',
        'version'         => 'Claro / Flow (downloaded ZIP)',
        'edition'         => '1.2',
        'api'             => 'API',
        'expected_launch' => ['index.html', 'course/index.html'],  // manifest href
        'launch_never'    => ['story.html'],
        'status_behavior' => 'lesson_status completed/passed/failed/incomplete; completion percentage via cmi.core.lesson_location or suspend_data in some exports.',
        'score_pass_rule' => 'score.raw; pass from lesson_status=passed.',
        'interactions'    => ['cmi.interactions.n.*'],
        'objectives'      => [],
        'required_cases'  => ['downloaded ZIP', 'multi-SCO', 'completion', 'score', 'interactions'],
        'resume'          => true,
    ],

    'dominknow-scorm2004' => [
        'vendor'          => 'domiKnow',
        'version'         => 'Claro / Flow',
        'edition'         => '2004 3rd Edition',
        'api'             => 'API_1484_11',
        'expected_launch' => ['index.html'],
        'launch_never'    => ['story.html'],
        'status_behavior' => 'cmi.completion_status/success_status; progress_measure.',
        'score_pass_rule' => 'cmi.score.scaled.',
        'interactions'    => ['cmi.interactions.n.*'],
        'required_cases'  => ['multi-SCO', 'completion', 'score', 'interactions'],
        'resume'          => true,
    ],

    'unknown-custom' => [
        'vendor'          => 'Unknown / custom export',
        'version'         => '—',
        'edition'         => '1.2 or 2004 (detected from manifest)',
        'api'             => 'API or API_1484_11 (probed)',
        'expected_launch' => 'manifest resource href (never hardcoded); fallback probing only when the manifest href is missing from storage',
        'launch_never'    => [],
        'status_behavior' => 'whichever status fields the content writes; unsupported fields are explicitly refused/not-reported, never silently accepted.',
        'score_pass_rule' => 'score fields retained raw; pass derived only when a clear signal exists.',
        'interactions'    => ['structured or flat cmi.interactions.n.*'],
        'objectives'      => ['structured or flat cmi.objectives.n.*'],
        'required_cases'  => ['delayed API discovery', 'nested frames', 'custom launch href', 'missing optional fields', 'repeated commits'],
        'resume'          => true,
        'notes'           => 'Delayed API discovery = API found late (setTimeout/frame load); nested frames = API installed in the topmost accessible frame.',
    ],

];
