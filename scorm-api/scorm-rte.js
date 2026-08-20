/**
 * PURSUIT PATHWAYS LMS
 * NATIVE SCORM READER — Run-Time Environment (Phase 2)
 *
 * This script implements both the SCORM 1.2 API (window.API) and the
 * SCORM 2004 API (window.API_1484_11).
 *
 * It is injected into every HTML page served from a SCORM package
 * (via scorm-content/serve.php). Because content is same-origin
 * (served from /scorm-content/), tracking data is POSTed directly
 * via fetch() to /scorm-api/store.php.
 *
 * Data captured:
 *   - cmi.core.* (1.2) and cmi.* (2004) — status, score, time, location
 *   - cmi.interactions.n.* — question-level answers/results
 *   - cmi.objectives.n.* — objective-level outcomes
 *   - suspend_data — bookmarks
 *   - Every LMSCommit() triggers a full-state persist
 *
 * @package  PP_LMS
 * @version  1.0.0
 */

(function () {
    'use strict';

    // ── Configuration injected by serve.php ──
    var cfg = window.SCORM_PACKAGE_CONFIG || {};
    var API_ENDPOINT = cfg.apiEndpoint || '/scorm-api/store.php';
    var PACKAGE_ID = parseInt(cfg.pkg || 0, 10);
    var SCO_ID = parseInt(cfg.sco || 0, 10);
    var SCORM_VERSION = cfg.version || '1.2';
    // The serve token authorises the store.php tracking POST without relying on
    // the session cookie (SameSite=Lax is not sent from inside the iframe).
    if (cfg.token) {
        API_ENDPOINT += (API_ENDPOINT.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(cfg.token);
    }
    // Where the TOP window should navigate when the course exits. We take
    // over the exit navigation from Storyline because Storyline's own exit
    // trigger opens the exit URL in a frame, which either trips
    // X-Frame-Options or results in a nested iframe inside the player.
    var EXIT_URL = cfg.exitUrl || '/course-page/';

    // ── State ──
    var initialized = false;
    var terminated = false;
    var lastError = '0';
    var dirty = false;          // any values changed since last commit
    var commitQueue = Promise.resolve();
    var commitInFlight = false;
    var pendingAfterCommit = null;
    var sessionStartedAt = Date.now();
    var lastPersistAt = Date.now();
    var lastValue = {};         // element -> last value written by SCO
    var initialState = null;    // data loaded from server on init
    var attemptId = null;       // set after first successful persist
    var coalesceTimer = null;
    var SESSION_TERMINATE_ON_UNLOAD = true;

    // ── SCORM 1.2 Error Codes ──
    var ERR = {
        NO_ERROR: '0',
        GENERAL_EXCEPTION: '101',
        INVALID_ARGUMENT: '201',
        ELEMENT_NOT_ACCESSIBLE: '202',
        ELEMENT_NOT_WRITABLE: '203',
        ELEMENT_NOT_READABLE: '204'
    };
    if (SCORM_VERSION === '2004') {
        // SCORM 2004 codes
        ERR = {
            NO_ERROR: '0',
            GENERAL_EXCEPTION: '101',
            INITIALIZATION_FAILED: '102',
            INVALID_ARGUMENT: '301',
            ELEMENT_NOT_ACCESSIBLE: '351',
            ELEMENT_NOT_WRITABLE: '351',
            ELEMENT_NOT_READABLE: '351'
        };
    }

    // ── Readable / writable element whitelists ──
    // SCORM 1.2
    var READABLE_12 = {
        'cmi.core._children': 1, 'cmi.core.student_id': 1, 'cmi.core.student_name': 1,
        'cmi.core.lesson_location': 1, 'cmi.core.credit': 1, 'cmi.core.lesson_status': 1,
        'cmi.core.entry': 1, 'cmi.core.score.raw': 1, 'cmi.core.score.max': 1,
        'cmi.core.score.min': 1, 'cmi.core.total_time': 1, 'cmi.core.lesson_mode': 1,
        'cmi.core.exit': 1, 'cmi.core.session_time': 1, 'cmi.suspend_data': 1,
        'cmi.launch_data': 1, 'cmi.comments': 1, 'cmi.comments_from_lms': 1,
        'cmi.student_data._children': 1, 'cmi.student_data.mastery_score': 1,
        'cmi.student_data.max_time_allowed': 1, 'cmi.student_data.time_limit_action': 1,
        'cmi.student_preference._children': 1, 'cmi.student_preference.audio': 1,
        'cmi.student_preference.language': 1, 'cmi.student_preference.speed': 1,
        'cmi.student_preference.text': 1
    };
    var WRITABLE_12 = {
        'cmi.core.lesson_location': 1, 'cmi.core.lesson_status': 1, 'cmi.core.exit': 1,
        'cmi.core.score.raw': 1, 'cmi.core.score.max': 1, 'cmi.core.score.min': 1,
        'cmi.core.session_time': 1, 'cmi.suspend_data': 1, 'cmi.comments': 1,
        'cmi.student_preference.audio': 1, 'cmi.student_preference.language': 1,
        'cmi.student_preference.speed': 1, 'cmi.student_preference.text': 1
    };

    // SCORM 2004
    var READABLE_2004 = {
        'cmi._version': 1, 'cmi.comments_from_learner._children': 1,
        'cmi.comments_from_lms._children': 1, 'cmi.completion_status': 1,
        'cmi.completion_threshold': 1, 'cmi.credit': 1, 'cmi.entry': 1,
        'cmi.exit': 1, 'cmi.interactions._children': 1, 'cmi.interactions._count': 1,
        'cmi.launch_data': 1, 'cmi.learner_id': 1, 'cmi.learner_name': 1,
        'cmi.learner_preference._children': 1, 'cmi.learner_preference.audio_level': 1,
        'cmi.learner_preference.language': 1, 'cmi.learner_preference.delivery_speed': 1,
        'cmi.learner_preference.audio_captioning': 1, 'cmi.location': 1,
        'cmi.max_time_allowed': 1, 'cmi.mode': 1, 'cmi.objectives._children': 1,
        'cmi.objectives._count': 1, 'cmi.progress_measure': 1, 'cmi.scaled_passing_score': 1,
        'cmi.score._children': 1, 'cmi.score.scaled': 1, 'cmi.score.raw': 1,
        'cmi.score.min': 1, 'cmi.score.max': 1, 'cmi.session_time': 1,
        'cmi.success_status': 1, 'cmi.suspend_data': 1, 'cmi.time_limit_action': 1,
        'cmi.total_time': 1
    };
    var WRITABLE_2004 = {
        'cmi.comments_from_learner._children': 1, 'cmi.completion_status': 1,
        'cmi.exit': 1, 'cmi.interactions._children': 1, 'cmi.interactions._count': 1,
        'cmi.learner_preference.audio_level': 1, 'cmi.learner_preference.language': 1,
        'cmi.learner_preference.delivery_speed': 1, 'cmi.learner_preference.audio_captioning': 1,
        'cmi.location': 1, 'cmi.objectives._children': 1, 'cmi.objectives._count': 1,
        'cmi.progress_measure': 1, 'cmi.score.scaled': 1, 'cmi.score.raw': 1,
        'cmi.score.min': 1, 'cmi.score.max': 1, 'cmi.session_time': 1,
        'cmi.success_status': 1, 'cmi.suspend_data': 1
    };
    // All interaction/objective sub-elements are writable
    for (var i = 0; i < 200; i++) {
        var baseI = 'cmi.interactions.' + i + '.';
        var baseO = 'cmi.objectives.' + i + '.';
        ['id', 'type', 'timestamp', 'weighting', 'learner_response', 'result',
         'latency', 'description', 'correct_responses._count'].forEach(function (f) {
            WRITABLE_2004[baseI + f] = 1;
            READABLE_2004[baseI + f] = 1;
        });
        for (var c = 0; c < 20; c++) {
            WRITABLE_2004[baseI + 'correct_responses.' + c + '.pattern'] = 1;
            READABLE_2004[baseI + 'correct_responses.' + c + '.pattern'] = 1;
            READABLE_2004[baseI + 'correct_responses.' + c + '.id'] = 1;
            WRITABLE_2004[baseI + 'correct_responses.' + c + '.id'] = 1;
        }
        ['id', 'score.scaled', 'score.raw', 'score.min', 'score.max',
         'completion_status', 'success_status', 'progress_measure', 'description'].forEach(function (f) {
            WRITABLE_2004[baseO + f] = 1;
            READABLE_2004[baseO + f] = 1;
        });
    }

    var is2004 = SCORM_VERSION === '2004';
    // Union of both versions' whitelists so either API (window.API for SCORM
    // 1.2 or window.API_1484_11 for SCORM 2004) can read/write its elements
    // regardless of the detected package version. Rise 360 / Storyline
    // sometimes probe the API that doesn't match the declared version, and a
    // single-version whitelist would make every Get/Set fail -> no tracking.
    var READABLE = {};
    var WRITABLE = {};
    (function (src, dst) { for (var k in src) dst[k] = 1; })(READABLE_12, READABLE);
    (function (src, dst) { for (var k in src) dst[k] = 1; })(READABLE_2004, READABLE);
    (function (src, dst) { for (var k in src) dst[k] = 1; })(WRITABLE_12, WRITABLE);
    (function (src, dst) { for (var k in src) dst[k] = 1; })(WRITABLE_2004, WRITABLE);

    // ── Session computation ──
    function pad2(n) { return n < 10 ? '0' + n : String(n); }
    function formatDuration(ms) {
        var s = Math.floor(ms / 1000);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return 'PT' + h + 'H' + m + 'M' + sec + 'S';
    }

    // ── Persist ──
    function buildStatePayload(terminating) {
        // session_delta_ms is the time elapsed since the last persist —
        // incremental so the server can accumulate total time accurately
        // without double-counting on repeated commits.
        var now = Date.now();
        var delta = Math.floor(now - lastPersistAt);
        lastPersistAt = now;
        var payload = {
            pkg: PACKAGE_ID,
            sco: SCO_ID,
            version: SCORM_VERSION,
            attempt: attemptId,
            terminating: !!terminating,
            session_delta_ms: delta,
            values: lastValue
        };
        return payload;
    }

    function persist(terminating) {
        if (!initialized || PACKAGE_ID === 0) {
            return Promise.resolve();
        }
        if (commitInFlight) {
            // Queue a follow-up persist after the current one finishes
            pendingAfterCommit = pendingAfterCommit || { terminating: terminating };
            return commitQueue;
        }
        commitInFlight = true;
        dirty = false;

        var payload = buildStatePayload(terminating);

        return fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
        .then(function (res) {
            return res.json().catch(function () { return {}; });
        })
        .then(function (data) {
            if (data && data.ok && data.attempt_id) {
                attemptId = data.attempt_id;
            }
            if (data && data.initial && !initialState) {
                initialState = data.initial;
                // Hydrate values so GetValue returns stored state
                applyInitialState(data.initial);
            }
            commitInFlight = false;
            if (pendingAfterCommit) {
                var p = pendingAfterCommit;
                pendingAfterCommit = null;
                return persist(p.terminating);
            }
        })
        .catch(function (err) {
            console.warn('[SCORM-RTE] persist failed:', err);
            commitInFlight = false;
            // Re-mark dirty so a later commit retries
            dirty = true;
        });
    }

    // Load the stored state synchronously during Initialize() so the very first
    // GetValue('cmi.core.lesson_location' / 'cmi.suspend_data') returns the saved
    // resume state. SCORM content expects a synchronous LMS - an async fetch can
    // finish after the content has already read its values (which silently resets
    // the course). Falls back to async persist if sync XHR is unavailable.
    function loadInitialStateSync() {
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', API_ENDPOINT, false);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify(buildStatePayload(false)));
            if (xhr.status >= 200 && xhr.status < 300) {
                var data = {};
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                if (data && data.ok && data.attempt_id) attemptId = data.attempt_id;
                if (data && data.initial) {
                    initialState = data.initial;
                    applyInitialState(data.initial);
                }
            }
        } catch (e) {
            persist(false);
        }
    }

    function applyInitialState(initial) {
        if (!initial || typeof initial !== 'object') return;
        // Values array: [{ element, value }]
        if (Array.isArray(initial.values)) {
            initial.values.forEach(function (v) {
                if (v && v.element) lastValue[v.element] = v.value;
            });
        }
        // Direct map form also supported
        if (initial.map && typeof initial.map === 'object') {
            Object.keys(initial.map).forEach(function (k) {
                lastValue[k] = initial.map[k];
            });
        }
    }

    // ── Helpers ──
    function setError(code) { lastError = String(code); }

    function isReadable(el) { return READABLE[el] === 1; }
    function isWritable(el) { return WRITABLE[el] === 1; }
    function isArrayElement(el) {
        return /\.\d+\./.test(el);
    }

    function normalize(el) {
        // Trim + lowercase for lookup; but keep original case for storage
        return String(el || '').trim();
    }

    function isValidElement(el) {
        // Either in whitelist, or a numbered interaction/objective element
        return READABLE[el] === 1 || WRITABLE[el] === 1 || isArrayElement(el);
    }

    // ── SCORM 1.2 API ──
    var API12 = {
        LMSInitialize: function (param) {
            if (initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            initialized = true;
            setError(ERR.NO_ERROR);
            // Load stored state synchronously so GetValue() returns resume data
            // immediately after Initialize() (SCORM expects a synchronous LMS).
            loadInitialStateSync();
            return 'true';
        },

        LMSFinish: function (param) {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            terminated = true;
            // Compute session_time if SCO didn't set it
            if (!lastValue['cmi.core.session_time']) {
                lastValue['cmi.core.session_time'] = formatDuration(Date.now() - sessionStartedAt);
            }
            // Commit the final state, then take the user out of the player.
            persist(true).then(redirectToExit);
            setError(ERR.NO_ERROR);
            return 'true';
        },

        LMSGetValue: function (element) {
            if (!initialized && element !== 'cmi.core._children') {
                setError(ERR.GENERAL_EXCEPTION);
                return '';
            }
            var el = normalize(element);
            var key = el;

            if (!isReadable(key) && !isArrayElement(key)) {
                setError(ERR.ELEMENT_NOT_READABLE);
                return '';
            }

            // Children lists
            if (key === 'cmi.core._children') {
                setError(ERR.NO_ERROR);
                return 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time';
            }
            if (key === 'cmi.student_data._children') {
                setError(ERR.NO_ERROR);
                return 'mastery_score,max_time_allowed,time_limit_action';
            }
            if (key === 'cmi.student_preference._children') {
                setError(ERR.NO_ERROR);
                return 'audio,language,speed,text';
            }

            // Score children
            if (key === 'cmi.core.score._children') {
                setError(ERR.NO_ERROR);
                return 'raw,max,min';
            }

            // Computed total_time: session accumulation
            if (key === 'cmi.core.total_time') {
                var stored = lastValue['cmi.core.total_time'] || 'PT0H0M0S';
                setError(ERR.NO_ERROR);
                return stored;
            }
            if (key === 'cmi.core.session_time') {
                var sv = lastValue['cmi.core.session_time'] || formatDuration(Date.now() - sessionStartedAt);
                setError(ERR.NO_ERROR);
                return sv;
            }

            // Student id/name are provided by the LMS session
            if (key === 'cmi.core.student_id') {
                setError(ERR.NO_ERROR);
                return window.SCORM_USER && SCORM_USER.id ? SCORM_USER.id : '';
            }
            if (key === 'cmi.core.student_name') {
                setError(ERR.NO_ERROR);
                return window.SCORM_USER && SCORM_USER.name ? SCORM_USER.name : '';
            }

            var storedVal = lastValue[key];
            setError(ERR.NO_ERROR);
            return storedVal !== undefined ? String(storedVal) : '';
        },

        LMSSetValue: function (element, value) {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            var el = normalize(element);
            var val = String(value);

            if (!isWritable(el) && !isArrayElement(el)) {
                setError(ERR.ELEMENT_NOT_WRITABLE);
                return 'false';
            }

            // Validate session_time format
            if (el === 'cmi.core.session_time' && !/^PT(\d+H)?(\d+M)?(\d+(\.\d+)?S)?$/.test(val)) {
                setError(ERR.INVALID_ARGUMENT);
                return 'false';
            }
            // Validate lesson_status values
            if (el === 'cmi.core.lesson_status') {
                var validStatus = ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'];
                if (validStatus.indexOf(val) === -1) {
                    setError(ERR.INVALID_ARGUMENT);
                    return 'false';
                }
            }

            lastValue[el] = val;
            dirty = true;
            setError(ERR.NO_ERROR);

            // Coalesce rapid writes — persist shortly after the last write
            if (coalesceTimer) clearTimeout(coalesceTimer);
            coalesceTimer = setTimeout(function () {
                if (dirty) persist(false);
            }, 2000);

            return 'true';
        },

        LMSCommit: function (param) {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            persist(false);
            setError(ERR.NO_ERROR);
            return 'true';
        },

        LMSGetLastError: function () { return lastError; },

        LMSGetErrorString: function (code) {
            var map = {
                '0': 'No error', '101': 'General exception', '201': 'Invalid argument',
                '202': 'Element cannot be accessed', '203': 'Element cannot be written to',
                '204': 'Element cannot be read from'
            };
            return map[String(code)] || 'Unknown error';
        },

        LMSGetDiagnostic: function (code) {
            return this.LMSGetErrorString(code);
        }
    };

    // ── SCORM 2004 API ──
    var API2004 = {
        Initialize: function () {
            if (initialized) {
                setError(ERR.INITIALIZATION_FAILED);
                return 'false';
            }
            initialized = true;
            setError(ERR.NO_ERROR);
            // Load stored state synchronously so GetValue() returns resume data
            // immediately after Initialize() (SCORM expects a synchronous LMS).
            loadInitialStateSync();
            return 'true';
        },

        Terminate: function () {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            terminated = true;
            if (!lastValue['cmi.session_time']) {
                lastValue['cmi.session_time'] = formatDuration(Date.now() - sessionStartedAt);
            }
            // Commit the final state, then take the user out of the player.
            persist(true).then(redirectToExit);
            setError(ERR.NO_ERROR);
            return 'true';
        },

        GetValue: function (element) {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return '';
            }
            var el = normalize(element);

            if (!READABLE[el] && el !== 'cmi._version') {
                setError(ERR.ELEMENT_NOT_READABLE);
                return '';
            }

            if (el === 'cmi._version') { setError(ERR.NO_ERROR); return '1.0'; }
            if (el === 'cmi.learner_id') {
                setError(ERR.NO_ERROR);
                return window.SCORM_USER && SCORM_USER.id ? SCORM_USER.id : '';
            }
            if (el === 'cmi.learner_name') {
                setError(ERR.NO_ERROR);
                return window.SCORM_USER && SCORM_USER.name ? SCORM_USER.name : '';
            }
            if (el.indexOf('cmi.interactions._count') === 0) {
                var c = 0;
                for (var k in lastValue) {
                    if (/^cmi\.interactions\.\d+\.id$/.test(k)) c++;
                }
                setError(ERR.NO_ERROR);
                return String(c);
            }
            if (el.indexOf('cmi.objectives._count') === 0) {
                var o = 0;
                for (var k2 in lastValue) {
                    if (/^cmi\.objectives\.\d+\.id$/.test(k2)) o++;
                }
                setError(ERR.NO_ERROR);
                return String(o);
            }
            if (el === 'cmi.total_time' || el === 'cmi.session_time') {
                var v = lastValue[el] || formatDuration(Date.now() - sessionStartedAt);
                setError(ERR.NO_ERROR);
                return v;
            }

            var sv = lastValue[el];
            setError(ERR.NO_ERROR);
            return sv !== undefined ? String(sv) : '';
        },

        SetValue: function (element, value) {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            var el = normalize(element);
            var val = String(value);

            if (!WRITABLE[el]) {
                setError(ERR.ELEMENT_NOT_WRITABLE);
                return 'false';
            }

            // Validate completion_status
            if (el === 'cmi.completion_status') {
                var vs = ['completed', 'incomplete', 'not attempted', 'unknown'];
                if (vs.indexOf(val) === -1) { setError(ERR.INVALID_ARGUMENT); return 'false'; }
            }
            if (el === 'cmi.success_status') {
                var vss = ['passed', 'failed', 'unknown'];
                if (vss.indexOf(val) === -1) { setError(ERR.INVALID_ARGUMENT); return 'false'; }
            }
            if (el === 'cmi.exit') {
                var ve = ['normal', 'suspend', 'logout', 'time-out'];
                if (ve.indexOf(val) === -1) { setError(ERR.INVALID_ARGUMENT); return 'false'; }
            }
            if (el === 'cmi.session_time' && !/^PT(\d+H)?(\d+M)?(\d+(\.\d+)?S)?$/.test(val)) {
                setError(ERR.INVALID_ARGUMENT);
                return 'false';
            }

            lastValue[el] = val;
            dirty = true;
            setError(ERR.NO_ERROR);

            if (coalesceTimer) clearTimeout(coalesceTimer);
            coalesceTimer = setTimeout(function () {
                if (dirty) persist(false);
            }, 2000);

            return 'true';
        },

        Commit: function () {
            if (!initialized) {
                setError(ERR.GENERAL_EXCEPTION);
                return 'false';
            }
            persist(false);
            setError(ERR.NO_ERROR);
            return 'true';
        },

        GetLastError: function () { return lastError; },

        GetErrorString: function (code) {
            var map = {
                '0': 'No error', '101': 'General exception', '102': 'Initialization failed',
                '301': 'Invalid argument', '351': 'Element is not available'
            };
            return map[String(code)] || 'Unknown error';
        },

        GetDiagnostic: function (code) {
            return this.GetErrorString(code);
        }
    };

    // ── Navigate the TOP window out of the SCORM player on exit ──
    // Storyline 360's "Exit Course" trigger, after calling LMSFinish()/
    // Terminate(), tries to open the exit URL in a frame — which either gets
    // blocked by X-Frame-Options or produces a nested-iframe mess. We take
    // over the navigation ourselves: once the final commit has resolved,
    // redirect the TOP window so the user actually leaves the player.
    // Using window.top.location.href navigates the entire browser window
    // (not a sub-frame), so the exit URL never gets loaded inside the iframe.
    function redirectToExit() {
        var url = EXIT_URL;
        try {
            if (window.top && window.top !== window.self) {
                // We are inside the SCORM iframe — navigate the parent window.
                window.top.location.href = url;
            } else {
                // Not framed (e.g. opened directly) — navigate ourselves.
                window.location.href = url;
            }
        } catch (e) {
            // Cross-origin top access is blocked — fall back to same-frame nav.
            try { window.location.href = url; } catch (e2) {}
        }
    }

    // ── Install API objects into the page ──
    function install() {
        // Expose BOTH APIs. Rise 360 / Storyline packages may probe either
        // window.API (SCORM 1.2) or window.API_1484_11 (SCORM 2004). If only
        // one is exposed and the course probes the other, it never finds the
        // API, never calls Initialize(), and tracking silently records nothing
        // (progress stays 0%).
        if (!window.API) window.API = API12;
        if (!window.API_1484_11) window.API_1484_11 = API2004;
    }

    install();

    // ── Persist on unload (best-effort final save) ──
    function handleUnload() {
        if (!initialized || terminated) return;
        terminated = true;
        var key = is2004 ? 'cmi.session_time' : 'cmi.core.session_time';
        if (!lastValue[key]) {
            lastValue[key] = formatDuration(Date.now() - sessionStartedAt);
        }
        // navigator.sendBeacon is more reliable on unload than fetch
        if (navigator.sendBeacon) {
            var blob = new Blob([JSON.stringify(buildStatePayload(true))], {
                type: 'application/json'
            });
            navigator.sendBeacon(API_ENDPOINT, blob);
        } else {
            persist(true);
        }
    }
    window.addEventListener('beforeunload', handleUnload);
    // SCORM 1.2 packages sometimes use window.onunload
    window.onunload = handleUnload;

    // Expose internals for debugging
    window.__SCORM_RTE__ = {
        version: SCORM_VERSION,
        initialized: function () { return initialized; },
        getState: function () { return lastValue; },
        getAttemptId: function () { return attemptId; }
    };

})();
