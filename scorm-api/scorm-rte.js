/**
 * PURSUIT PATHWAYS LMS
 * CROSS-VERSION SCORM RUN-TIME ENVIRONMENT (v3)
 *
 * Exposes BOTH standards adapters:
 *   - window.API          — SCORM 1.2 (LMSInitialize/LMSFinish/LMSGetValue/...)
 *   - window.API_1484_11  — SCORM 2004 (Initialize/Terminate/GetValue/...)
 *
 * Both adapters route into ONE normalized state model, so status, score, time,
 * location, suspend data, interactions, objectives, and learner comments are
 * captured consistently regardless of which API the authoring tool probes.
 *
 * Persistence: every Commit()/Terminate()/unload-beacon POSTs to
 * scorm-api/store.php with a client-generated `request_id`. The server uses
 * the request_id for exact-once handling, so retries, concurrent commits, and
 * beacon+Terminate pairs never double-count time or duplicate attempts.
 *
 * Injected via scorm-content/serve.php into every content page.
 *
 * @package  PP_LMS
 * @version  3.0.0
 */

(function () {
    'use strict';

    // ── Configuration (injected by serve.php) ──
    var cfg = window.SCORM_PACKAGE_CONFIG || {};
    var API_ENDPOINT = cfg.apiEndpoint || '/scorm-api/store.php';
    // Compatibility mode accepts cross-version spellings (1.2 elements on 2004
    // packages and vice versa). Enabled by default (serve.php sends
    // compatMode:true unless SCORM_COMPAT_MODE=0 in .env). Strict mode requires
    // content to use exactly the declared SCORM API.
    var COMPAT_MODE = !cfg || cfg.compatMode !== false;
    if (COMPAT_MODE) {
        console.warn('[SCORM-RTE] Compatibility mode ON — accepting cross-version SCORM spellings.');
    }
    var PACKAGE_ID = parseInt(cfg.pkg || 0, 10);
    var SCO_ID = parseInt(cfg.sco || 0, 10);
    var SCORM_VERSION = cfg.version === '2004' ? '2004' : '1.2';
    var SCORM_EDITION = cfg.edition || (SCORM_VERSION === '2004' ? '2004 3rd Edition' : '1.2');
    var RTE_VERSION = '3.0.0';
    var EXIT_URL = cfg.exitUrl || '/course-page/';

    function editionSuspendLimit(version, edition) {
        if (version === '2004') {
            var e = String(edition || '').toLowerCase();
            if (e.indexOf('2nd') >= 0) return 4000;        // 2004 2nd Ed: 4,000
            if (e.indexOf('3rd') >= 0 || e.indexOf('4th') >= 0) return 64000; // 3rd/4th
            return 64000;
        }
        return 4096; // SCORM 1.2
    }
    var SUSPEND_LIMIT = (parseInt(cfg.suspendLimit || 0, 10) > 0) ? parseInt(cfg.suspendLimit, 10) : editionSuspendLimit(SCORM_VERSION, SCORM_EDITION);

    if (cfg.token) {
        API_ENDPOINT += (API_ENDPOINT.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(cfg.token);
    }

    // ── Version-specific error codes (preserved verbatim per standard) ──
    var ERR12 = {
        NO_ERROR: '0', GENERAL_EXCEPTION: '101', INVALID_ARGUMENT: '201',
        ELEMENT_NOT_ACCESSIBLE: '202', ELEMENT_NOT_WRITABLE: '203', ELEMENT_NOT_READABLE: '204'
    };
    var ERR2004 = {
        NO_ERROR: '0', GENERAL_EXCEPTION: '101', INITIALIZATION_FAILED: '102',
        INVALID_ARGUMENT: '301', ELEMENT_NOT_ACCESSIBLE: '351',
        ELEMENT_NOT_WRITABLE: '351', ELEMENT_NOT_READABLE: '351'
    };
    var ERR = SCORM_VERSION === '2004' ? ERR2004 : ERR12;
    var ERRSTR = SCORM_VERSION === '2004' ? {
        '0': 'No error', '101': 'General exception', '102': 'Initialization failed',
        '301': 'Invalid argument', '351': 'Element is not available'
    } : {
        '0': 'No error', '101': 'General exception', '201': 'Invalid argument',
        '202': 'Element is not accessible', '203': 'Element is not writable', '204': 'Element is not readable'
    };

    // ── Shared normalized state model ──
    var state = {
        initialized: false,
        terminated: false,
        finalized: false,
        dirty: false,
        lastError: '0',
        attemptId: cfg.attempt ? parseInt(cfg.attempt, 10) : null,
        entry: 'ab-initio',
        scalars: {},          // lowercase element -> raw value (1.2 AND 2004 spellings)
        sessionOnly: {},      // accepted for the session only, never persisted
        interactions: {},     // index -> record
        objectives: {},       // index -> record
        comments: [],         // [{index, comment, location, timestamp}]
        resumeApplied: false,
        requestSeq: 0,
        sessionStartedAt: Date.now(),
        lastPersistAt: Date.now(),
        totalSeconds: 0,
        commitCount: 0,
        commitInFlight: false,
        pendingAfterCommit: null,
        pendingRequestId: null,   // request_id of a failed persist, to reuse
        pendingDeltaMs: 0,        // delta carried by that failed persist
        errors: []                // bounded diagnostic log
    };

    function setError(code) { state.lastError = String(code); }
    function is2004() { return SCORM_VERSION === '2004'; }
    function pad2(n) { return n < 10 ? '0' + n : String(n); }
    function formatDuration(ms) {
        var s = Math.floor(Math.max(0, ms) / 1000);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return 'PT' + h + 'H' + m + 'M' + sec + 'S';
    }
    function makeRequestId() {
        state.requestSeq += 1;
        return 'c' + Date.now().toString(36) + '-' + state.requestSeq.toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }
    function logError(type, element, message) {
        state.errors.push({ type: type, element: element, message: message, at: Date.now() });
        if (state.errors.length > 50) state.errors.shift();
        if (console && console.warn) {
            console.warn('[SCORM-RTE] ' + type + ' ' + (element || '') + ': ' + message);
        }
    }
    function serializeValue(v) {
        if (typeof v === 'string' || v === null || v === undefined) return v === null || v === undefined ? '' : v;
        return JSON.stringify(v);
    }

    // ── Element whitelists ──
    // SCORM 1.2 (cmi.core.*) plus the aliases common exporters use, and the
    // SCORM 2004 elements accepted on 1.2 packages (some tools probe both).
    var READ12 = {};
    var WRITE12 = {};
    var READ2004 = {};
    var WRITE2004 = {};

    (function (list) {
        for (var i = 0; i < list.length; i++) { READ12[list[i]] = 1; }
    })([
        'cmi.core._children', 'cmi.core.student_id', 'cmi.core.student_name',
        'cmi.core.lesson_location', 'cmi.core.credit', 'cmi.core.lesson_status',
        'cmi.core.entry', 'cmi.core.score.raw', 'cmi.core.score.max', 'cmi.core.score.min',
        'cmi.core.total_time', 'cmi.core.lesson_mode', 'cmi.core.exit', 'cmi.core.session_time',
        'cmi.core.student_preference.audio', 'cmi.core.student_preference.language',
        'cmi.core.student_preference.speed', 'cmi.core.student_preference.text',
        'cmi.suspend_data', 'cmi.launch_data', 'cmi.comments', 'cmi.comments_from_lms',
        'cmi.student_data.mastery_score', 'cmi.student_data.max_time_allowed',
        'cmi.student_data.time_limit_action', 'cmi.student_data.credit', 'cmi.student_data.lesson_mode',
        'cmi.student_data.attempt_number'
    ]);
    if (COMPAT_MODE) {
        // 2004 spellings accepted on 1.2 packages (cross-version exporters)
        (function (list) {
            for (var i = 0; i < list.length; i++) { READ12[list[i]] = 1; }
        })([
            'cmi.completion_status', 'cmi.success_status', 'cmi.location', 'cmi.score.scaled',
            'cmi.score.raw', 'cmi.score.max', 'cmi.score.min', 'cmi.entry', 'cmi.exit',
            'cmi.mode', 'cmi.credit', 'cmi.session_time', 'cmi.total_time', 'cmi.progress_measure',
            'cmi.completion_threshold', 'cmi.scaled_passing_score', 'cmi.learner_id', 'cmi.learner_name',
            'cmi._version', 'cmi._children', 'cmi.max_time_allowed', 'cmi.time_limit_action',
            'cmi.launch_data'
        ]);
    }
    for (var k in READ12) { WRITE12[k] = 1; }

    (function (list) {
        for (var i = 0; i < list.length; i++) { READ2004[list[i]] = 1; }
    })([
        'cmi._version', 'cmi.comments_from_learner._count', 'cmi.comments_from_lms._count',
        'cmi.completion_status', 'cmi.completion_threshold', 'cmi.credit', 'cmi.entry', 'cmi.exit',
        'cmi.interactions._count', 'cmi.launch_data', 'cmi.learner_id', 'cmi.learner_name',
        'cmi.learner_preference.audio', 'cmi.learner_preference.language',
        'cmi.learner_preference.speed', 'cmi.learner_preference.text',
        'cmi.location', 'cmi.max_time_allowed', 'cmi.mode', 'cmi.objectives._count',
        'cmi.progress_measure', 'cmi.scaled_passing_score', 'cmi.score.scaled', 'cmi.score.raw',
        'cmi.score.min', 'cmi.score.max', 'cmi.session_time', 'cmi.success_status',
        'cmi.suspend_data', 'cmi.time_limit_action', 'cmi.total_time', 'cmi._children'
    ]);
    if (COMPAT_MODE) {
        // 1.2 spellings accepted on 2004 packages (cross-version exporters)
        (function (list) {
            for (var i = 0; i < list.length; i++) { READ2004[list[i]] = 1; }
        })([
            'cmi.core.lesson_status', 'cmi.core.lesson_location', 'cmi.core.entry', 'cmi.core.exit',
            'cmi.core.credit', 'cmi.core.lesson_mode', 'cmi.core.session_time', 'cmi.core.total_time',
            'cmi.core.score.raw', 'cmi.core.score.max', 'cmi.core.score.min', 'cmi.core.student_id',
            'cmi.core.student_name'
        ]);
    }
    for (var k2 in READ2004) { WRITE2004[k2] = 1; }

    // Read-only (LMS-owned) elements — writes are refused with a writable error.
    var READONLY = {
        'cmi.core.student_id': 1, 'cmi.core.student_name': 1, 'cmi.core.lesson_mode': 1,
        'cmi.core.credit': 1, 'cmi.core.entry': 1, 'cmi.core.total_time': 1, 'cmi.core._children': 1,
        'cmi.core.session_time': 1, 'cmi.core.score._children': 1,
        'cmi.learner_id': 1, 'cmi.learner_name': 1, 'cmi.mode': 1, 'cmi.credit': 1, 'cmi.entry': 1,
        'cmi.total_time': 1, 'cmi._version': 1, 'cmi._children': 1, 'cmi.session_time': 1,
        'cmi.launch_data': 1, 'cmi.max_time_allowed': 1, 'cmi.time_limit_action': 1,
        'cmi.score._children': 1
    };
    // Session-only preferences: accepted + readable for the session, never
    // persisted, never claimed as stored (documented in the compatibility
    // contract). Kept out of the persist payload.
    var SESSION_ONLY = {
        'cmi.core.student_preference.audio': 1, 'cmi.core.student_preference.language': 1,
        'cmi.core.student_preference.speed': 1, 'cmi.core.student_preference.text': 1,
        'cmi.learner_preference.audio': 1, 'cmi.learner_preference.language': 1,
        'cmi.learner_preference.speed': 1, 'cmi.learner_preference.text': 1
    };
    // Explicitly unsupported — recognised but refused, never silently accepted.
    var UNSUPPORTED = {
        'cmi.comments_from_lms': 1,
        'cmi.comments_from_learner._count': 1, 'cmi.comments_from_lms._count': 1,
        'cmi.interactions._count': 1, 'cmi.objectives._count': 1,
        'cmi.student_data.attempt_number': 1
    };

    // Cross-version alias lookup for GetValue (1.2 <-> 2004 spellings).
    function aliasesFor(lower) {
        var a = {
            'cmi.core.lesson_status': ['cmi.completion_status'],
            'cmi.completion_status': ['cmi.core.lesson_status'],
            'cmi.core.lesson_location': ['cmi.location'],
            'cmi.location': ['cmi.core.lesson_location'],
            'cmi.core.score.raw': ['cmi.score.raw'],
            'cmi.score.raw': ['cmi.core.score.raw'],
            'cmi.core.score.max': ['cmi.score.max'],
            'cmi.score.max': ['cmi.core.score.max'],
            'cmi.core.score.min': ['cmi.score.min'],
            'cmi.score.min': ['cmi.core.score.min'],
            'cmi.core.entry': ['cmi.entry'],
            'cmi.entry': ['cmi.core.entry'],
            'cmi.core.exit': ['cmi.exit'],
            'cmi.exit': ['cmi.core.exit'],
            'cmi.core.session_time': ['cmi.session_time'],
            'cmi.session_time': ['cmi.core.session_time'],
            'cmi.core.credit': ['cmi.credit'],
            'cmi.credit': ['cmi.core.credit'],
            'cmi.core.lesson_mode': ['cmi.mode'],
            'cmi.mode': ['cmi.core.lesson_mode'],
            'cmi.core.total_time': ['cmi.total_time'],
            'cmi.total_time': ['cmi.core.total_time']
        };
        return a[lower];
    }

    function isArrayElement(el) { return /\.\d+\./.test(el); }

    // ── Structured element storage ──
    // Interactions, objectives, and learner comments are stored as structured
    // records AND mirrored to the flat scalars map (so the payload is
    // compatible with both the structured server parser and the flat fallback).
    function ensureInteraction(idx) {
        if (!state.interactions[idx]) {
            state.interactions[idx] = { index: idx, correct_responses: [], objectives: [] };
        }
        return state.interactions[idx];
    }
    function storeInteractionField(idx, field, val) {
        var rec = ensureInteraction(idx);
        var m;
        if ((m = field.match(/^correct_responses\.(\d+)\.pattern$/))) {
            var ci = parseInt(m[1], 10);
            rec.correct_responses[ci] = rec.correct_responses[ci] || { id: '', pattern: '' };
            rec.correct_responses[ci].pattern = serializeValue(val);
        } else if ((m = field.match(/^correct_responses\.(\d+)\.id$/))) {
            var ci2 = parseInt(m[1], 10);
            rec.correct_responses[ci2] = rec.correct_responses[ci2] || { id: '', pattern: '' };
            rec.correct_responses[ci2].id = serializeValue(val);
        } else if ((m = field.match(/^objectives\.(\d+)\.id$/))) {
            var oi = parseInt(m[1], 10);
            rec.objectives[oi] = { id: serializeValue(val) };
        } else if (field === 'learner_response') {
            rec.learner_response = val; // may be a string or an array (2004)
        } else {
            rec[field] = serializeValue(val);
        }
        return rec;
    }
    function storeObjectiveField(idx, field, val) {
        var rec = state.objectives[idx] || (state.objectives[idx] = { index: idx, score: {} });
        var m;
        if ((m = field.match(/^score\.(raw|scaled|min|max)$/))) {
            rec.score = rec.score || {};
            rec.score[m[1]] = serializeValue(val);
        } else {
            rec[field] = serializeValue(val);
        }
        return rec;
    }
    function storeCommentField(idx, field, val) {
        var rec = null;
        for (var i = 0; i < state.comments.length; i++) {
            if (state.comments[i].index === idx) { rec = state.comments[i]; break; }
        }
        if (!rec) {
            rec = { index: idx };
            state.comments.push(rec);
        }
        if (field === 'comment') rec.comment = serializeValue(val);
        else if (field === 'location') rec.location = serializeValue(val);
        else if (field === 'timestamp') rec.timestamp = serializeValue(val);
        return rec;
    }

    function getInteractionField(idx, field) {
        var rec = state.interactions[idx];
        if (!rec) return { value: null, notFound: true };
        var m;
        if ((m = field.match(/^correct_responses\.(\d+)\.pattern$/))) {
            return { value: rec.correct_responses[m[1]] ? rec.correct_responses[m[1]].pattern : null, notFound: !(rec.correct_responses[m[1]] && rec.correct_responses[m[1]].pattern !== undefined) };
        }
        if ((m = field.match(/^correct_responses\.(\d+)\.id$/))) {
            return { value: rec.correct_responses[m[1]] ? rec.correct_responses[m[1]].id : null, notFound: !(rec.correct_responses[m[1]] && rec.correct_responses[m[1]].id !== undefined) };
        }
        if ((m = field.match(/^objectives\.(\d+)\.id$/))) {
            return { value: rec.objectives[m[1]] ? rec.objectives[m[1]].id : null, notFound: !(rec.objectives[m[1]] && rec.objectives[m[1]].id !== undefined) };
        }
        return { value: rec[field] !== undefined ? rec[field] : null, notFound: rec[field] === undefined };
    }
    function getObjectiveField(idx, field) {
        var rec = state.objectives[idx];
        if (!rec) return { value: null, notFound: true };
        var m = field.match(/^score\.(raw|scaled|min|max)$/);
        if (m) {
            return { value: rec.score && rec.score[m[1]] !== undefined ? rec.score[m[1]] : null, notFound: !(rec.score && rec.score[m[1]] !== undefined) };
        }
        return { value: rec[field] !== undefined ? rec[field] : null, notFound: rec[field] === undefined };
    }
    function getCommentField(idx, field) {
        var rec = null;
        for (var i = 0; i < state.comments.length; i++) {
            if (state.comments[i].index === idx) { rec = state.comments[i]; break; }
        }
        if (!rec) return { value: null, notFound: true };
        return { value: rec[field] !== undefined ? rec[field] : null, notFound: rec[field] === undefined };
    }

    function normalizeInteraction(rec) {
        var r = {};
        ['id', 'type', 'learner_response', 'result', 'weighting', 'latency', 'timestamp', 'description'].forEach(function (k) {
            if (rec[k] !== undefined) r[k] = rec[k];
        });
        r.correct_responses = Array.isArray(rec.correct_responses) ? rec.correct_responses.slice() : [];
        r.objectives = Array.isArray(rec.objectives) ? rec.objectives.slice() : [];
        return r;
    }

    // ── Shared GetValue / SetValue (routed by both adapters) ──
    function setValue(el, val) {
        var name = String(el || '').trim();
        if (name === '') { setError(ERR.INVALID_ARGUMENT); return false; }
        var lower = name.toLowerCase();

        // Explicitly unsupported element — refuse, never silently accept.
        if (UNSUPPORTED[lower]) {
            setError(ERR.ELEMENT_NOT_WRITABLE);
            logError('unsupported-write', name, 'Recognised but not implemented — value NOT stored.');
            return false;
        }
        // LMS-owned read-only elements.
        if (READONLY[lower]) {
            setError(ERR.ELEMENT_NOT_WRITABLE);
            logError('readonly-write', name, 'Element is read-only.');
            return false;
        }
        // Session-only preferences: accepted, readable, never persisted.
        if (SESSION_ONLY[lower]) {
            state.sessionOnly[lower] = serializeValue(val);
            setError(ERR.NO_ERROR);
            return true;
        }

        var writable = WRITE12[lower] === 1 || WRITE2004[lower] === 1 || isArrayElement(lower);
        if (!writable) {
            setError(ERR.INVALID_ARGUMENT);
            logError('unknown-element', name, 'Not a recognised element.');
            return false;
        }

        // Edition-aware suspend_data truncation (4096 / 4000 / 64000).
        if (lower === 'cmi.suspend_data') {
            var sv = serializeValue(val);
            if (sv.length > SUSPEND_LIMIT) {
                sv = sv.slice(0, SUSPEND_LIMIT);
                logError('truncate', name, 'suspend_data truncated to ' + SUSPEND_LIMIT + ' chars.');
            }
            state.scalars[lower] = sv;
            state.dirty = true;
            setError(ERR.NO_ERROR);
            return true;
        }

        // Structured element families.
        var im = lower.match(/^cmi\.interactions\.(\d+)\.(.+)$/);
        if (im) { storeInteractionField(parseInt(im[1], 10), im[2], val); state.dirty = true; setError(ERR.NO_ERROR); return true; }
        var om = lower.match(/^cmi\.objectives\.(\d+)\.(.+)$/);
        if (om) { storeObjectiveField(parseInt(om[1], 10), om[2], val); state.dirty = true; setError(ERR.NO_ERROR); return true; }
        var cm = lower.match(/^cmi\.comments_from_learner\.(\d+)\.(.+)$/);
        if (cm) { storeCommentField(parseInt(cm[1], 10), cm[2], val); state.dirty = true; setError(ERR.NO_ERROR); return true; }

        // Plain scalar.
        state.scalars[lower] = serializeValue(val);
        state.dirty = true;
        setError(ERR.NO_ERROR);
        return true;
    }

    function getValue(el) {
        var name = String(el || '').trim();
        var lower = name.toLowerCase();

        if (!state.initialized) { setError(ERR.GENERAL_EXCEPTION); return null; }

        // LMS-provided identity / mode / credit / entry / totals.
        if (lower === 'cmi.core.student_id' || lower === 'cmi.learner_id') {
            setError(ERR.NO_ERROR);
            return (window.SCORM_USER && window.SCORM_USER.id) || '';
        }
        if (lower === 'cmi.core.student_name' || lower === 'cmi.learner_name') {
            setError(ERR.NO_ERROR);
            return (window.SCORM_USER && window.SCORM_USER.name) || '';
        }
        if (lower === 'cmi.core.lesson_mode' || lower === 'cmi.mode') {
            setError(ERR.NO_ERROR);
            return 'normal';
        }
        if (lower === 'cmi.core.credit' || lower === 'cmi.credit') {
            setError(ERR.NO_ERROR);
            return 'credit';
        }
        if (lower === 'cmi.core.entry' || lower === 'cmi.entry') {
            setError(ERR.NO_ERROR);
            return state.entry || 'ab-initio';
        }
        if (lower === 'cmi.core.total_time' || lower === 'cmi.total_time') {
            setError(ERR.NO_ERROR);
            var elapsed = state.totalSeconds > 0 ? state.totalSeconds : Math.floor((Date.now() - state.sessionStartedAt) / 1000);
            return formatDuration(elapsed * 1000);
        }
        if (lower === 'cmi.core._children') {
            setError(ERR.NO_ERROR);
            return 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time';
        }
        if (lower === 'cmi._children') {
            setError(ERR.NO_ERROR);
            return 'comments_from_learner,completion_status,credit,entry,exit,interactions,launch_data,learner_id,learner_name,learner_preference,location,max_time_allowed,mode,objectives,progress_measure,scaled_passing_score,score,session_time,success_status,suspend_data,time_limit_action,total_time';
        }
        if (lower === 'cmi._version') {
            setError(ERR.NO_ERROR);
            return is2004() ? '1.0' : '1.2';
        }

        // Session-only preference reads.
        if (SESSION_ONLY[lower]) {
            setError(ERR.NO_ERROR);
            return state.sessionOnly[lower] !== undefined ? state.sessionOnly[lower] : '';
        }

        // Explicitly unsupported — return not-reported with a readable error.
        if (UNSUPPORTED[lower]) {
            setError(ERR.ELEMENT_NOT_READABLE);
            return null;
        }

        var readable = READ12[lower] === 1 || READ2004[lower] === 1 || isArrayElement(lower);
        if (!readable) { setError(ERR.INVALID_ARGUMENT); return null; }

        var im = lower.match(/^cmi\.interactions\.(\d+)\.(.+)$/);
        if (im) { var iv = getInteractionField(parseInt(im[1], 10), im[2]); setError(iv.notFound ? ERR.ELEMENT_NOT_READABLE : ERR.NO_ERROR); return iv.value; }
        var om2 = lower.match(/^cmi\.objectives\.(\d+)\.(.+)$/);
        if (om2) { var ov = getObjectiveField(parseInt(om2[1], 10), om2[2]); setError(ov.notFound ? ERR.ELEMENT_NOT_READABLE : ERR.NO_ERROR); return ov.value; }
        var cm2 = lower.match(/^cmi\.comments_from_learner\.(\d+)\.(.+)$/);
        if (cm2) { var cv = getCommentField(parseInt(cm2[1], 10), cm2[2]); setError(cv.notFound ? ERR.ELEMENT_NOT_READABLE : ERR.NO_ERROR); return cv.value; }

        var alias = aliasesFor(lower);
        if (alias) {
            for (var i = 0; i < alias.length; i++) {
                if (state.scalars[alias[i]] !== undefined) {
                    setError(ERR.NO_ERROR);
                    return state.scalars[alias[i]];
                }
            }
        }
        if (state.scalars[lower] !== undefined) {
            setError(ERR.NO_ERROR);
            return state.scalars[lower];
        }

        setError(ERR.ELEMENT_NOT_READABLE);
        return null; // not reported — distinct from an explicit empty/unknown value
    }

    // ── Persistence ──
    // Every payload carries a unique client request_id (exact-once server
    // handling) and an incremental session_delta_ms (no double-counted time).
    // If a persist fails, its request_id + delta are retained so the very next
    // attempt (e.g. the unload beacon) reuses them — the server either dedupes
    // (if the original actually landed) or applies them (if it did not).
    function buildPayload(terminating, reusePending) {
        var now = Date.now();
        var delta;
        var requestId;
        if (reusePending && state.pendingRequestId) {
            requestId = state.pendingRequestId;
            delta = state.pendingDeltaMs;
            state.pendingRequestId = null;
            state.pendingDeltaMs = 0;
        } else {
            delta = Math.floor(now - state.lastPersistAt);
            state.lastPersistAt = now;
            requestId = makeRequestId();
        }

        var interactions = [];
        Object.keys(state.interactions).forEach(function (k) {
            var rec = state.interactions[k];
            interactions.push({
                index: parseInt(k, 10),
                id: rec.id || '',
                type: rec.type || '',
                learner_response: rec.learner_response,
                result: rec.result || '',
                weighting: rec.weighting,
                latency: rec.latency,
                timestamp: rec.timestamp,
                description: rec.description,
                correct_responses: Array.isArray(rec.correct_responses) ? rec.correct_responses : [],
                objectives: Array.isArray(rec.objectives) ? rec.objectives : []
            });
        });
        var objectives = [];
        Object.keys(state.objectives).forEach(function (k) {
            var rec = state.objectives[k];
            objectives.push({
                index: parseInt(k, 10),
                id: rec.id || '',
                score: rec.score || {},
                completion_status: rec.completion_status || '',
                success_status: rec.success_status || '',
                progress_measure: rec.progress_measure,
                description: rec.description
            });
        });
        var comments = [];
        state.comments.forEach(function (c) {
            comments.push({ index: c.index, comment: c.comment || '', location: c.location || '', timestamp: c.timestamp || null });
        });

        return {
            pkg: PACKAGE_ID,
            sco: SCO_ID,
            version: SCORM_VERSION,
            edition: SCORM_EDITION,
            attempt: state.attemptId,
            request_id: requestId,
            terminating: !!terminating,
            session_delta_ms: delta,
            values: state.scalars,
            interactions: interactions,
            objectives: objectives,
            comments: comments
        };
    }

    function applyResume(initial) {
        if (!initial || typeof initial !== 'object') return;
        if (Array.isArray(initial.values)) {
            initial.values.forEach(function (v) {
                if (v && v.element) state.scalars[String(v.element).toLowerCase()] = v.value;
            });
        }
        if (initial.map && typeof initial.map === 'object') {
            Object.keys(initial.map).forEach(function (k) {
                state.scalars[String(k).toLowerCase()] = initial.map[k];
            });
        }
        if (Array.isArray(initial.interactions)) {
            initial.interactions.forEach(function (rec) {
                if (rec && typeof rec.index === 'number') {
                    state.interactions[rec.index] = normalizeInteraction(rec);
                }
            });
        }
        if (Array.isArray(initial.objectives)) {
            initial.objectives.forEach(function (rec) {
                if (rec && typeof rec.index === 'number') state.objectives[rec.index] = rec;
            });
        }
        if (Array.isArray(initial.comments)) {
            state.comments = [];
            initial.comments.forEach(function (rec) {
                if (rec && typeof rec.index === 'number') state.comments.push(rec);
            });
        }
    }

    function handlePersistResponse(data) {
        if (!data || typeof data !== 'object') return;
        // Long-course token refresh: the server returns a fresh serve token when
        // the current one is near expiry. Update it for subsequent commits.
        if (data.refresh_token && typeof data.refresh_token === 'string') {
            cfg.token = data.refresh_token;
            API_ENDPOINT = cfg.apiEndpoint || '/scorm-api/store.php';
            API_ENDPOINT += (API_ENDPOINT.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(cfg.token);
        }
        if (data.ok) {
            if (data.attempt_id) state.attemptId = data.attempt_id;
            if (data.saved && typeof data.saved.total_seconds === 'number') state.totalSeconds = data.saved.total_seconds;
            if (data.initial && !state.resumeApplied) {
                state.resumeApplied = true;
                applyResume(data.initial);
                state.entry = hasResumeData() ? 'resume' : 'ab-initio';
            }
        }
    }

    function hasResumeData() {
        var s = state.scalars;
        return (s['cmi.core.lesson_location'] || s['cmi.location'] || s['cmi.suspend_data']) ? true : false;
    }

    function persist(terminating) {
        if (!state.initialized || PACKAGE_ID === 0) return Promise.resolve();
        if (state.commitInFlight) {
            state.pendingAfterCommit = state.pendingAfterCommit || { terminating: terminating };
            return state.commitQueue;
        }
        state.commitInFlight = true;
        state.dirty = false;

        var payload = buildPayload(terminating, false);
        var previousPersistAt = state.lastPersistAt;
        state.commitCount += 1;

        return fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
        .then(function (res) {
            if (res.status === 409) {
                return res.json().catch(function () { return {}; }).then(function (d) {
                    // Duplicate request_id: 'committed' means the original
                    // landed — treat as success. 'in progress' is transient.
                    if (d && d.committed === true) {
                        return { ok: true, attempt_id: state.attemptId };
                    }
                    return d;
                });
            }
            return res.json().catch(function () { return {}; });
        })
        .then(function (data) {
            handlePersistResponse(data);
            state.commitInFlight = false;
            state.pendingRequestId = null;
            state.pendingDeltaMs = 0;
            if (state.pendingAfterCommit) {
                var p = state.pendingAfterCommit;
                state.pendingAfterCommit = null;
                return persist(p.terminating);
            }
        })
        .catch(function (err) {
            // Preserve this payload's request_id + delta so the next attempt
            // (usually the unload beacon) can deliver it exactly once.
            state.pendingRequestId = payload.request_id;
            state.pendingDeltaMs = payload.session_delta_ms;
            state.lastPersistAt = previousPersistAt;
            state.commitInFlight = false;
            state.dirty = true;
            logError('persist', null, err && err.message ? err.message : 'network error');
        });
    }

    // Synchronous resume-state load during Initialize() so the very first
    // GetValue() returns saved state (SCORM content expects a synchronous LMS).
    // NOTE: synchronous XHR intentionally has NO timeout — the XHR spec forbids
    // setting `timeout` on synchronous requests — and briefly blocks the main
    // thread. The payload here is minimal, and any failure falls back to the
    // async persist() path so tracking is not lost.
    function loadInitialStateSync() {
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', API_ENDPOINT, false);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify(buildPayload(false, false)));
            if (xhr.status >= 200 && xhr.status < 300) {
                var data = {};
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                handlePersistResponse(data);
                if (state.resumeApplied) state.entry = hasResumeData() ? 'resume' : 'ab-initio';
            } else if (xhr.status === 409) {
                // Fresh request_id makes a duplicate impossible here; ignore
                // transient 409s — the async persist path will retry.
            }
        } catch (e) {
            persist(false);
        }
    }

    // ── SCORM 1.2 adapter (window.API) ──
    var API12 = {
        LMSInitialize: function () {
            if (state.initialized) { setError(ERR.GENERAL_EXCEPTION); return 'false'; }
            state.initialized = true;
            state.terminated = false;
            state.finalized = false;
            setError(ERR.NO_ERROR);
            try { loadInitialStateSync(); } catch (e) {}
            return 'true';
        },
        LMSFinish: function () {
            if (!state.initialized) { setError(ERR.GENERAL_EXCEPTION); return 'false'; }
            terminate();
            setError(ERR.NO_ERROR);
            // Take over exit navigation — Storyline would otherwise frame the
            // exit URL inside this iframe (X-Frame-Options: DENY errors).
            redirectToExit();
            return 'true';
        },
        LMSGetValue: function (el) {
            if (!state.initialized) { setError(ERR.GENERAL_EXCEPTION); return ''; }
            var v = getValue(el);
            return v === null ? '' : v;
        },
        LMSSetValue: function (el, val) {
            if (!state.initialized) { setError(ERR.GENERAL_EXCEPTION); return 'false'; }
            return setValue(el, val) ? 'true' : 'false';
        },
        LMSCommit: function () {
            if (!state.initialized) { setError(ERR.GENERAL_EXCEPTION); return 'false'; }
            persist(false);
            setError(ERR.NO_ERROR);
            return 'true';
        },
        LMSGetLastError: function () { return state.lastError; },
        LMSGetErrorString: function (code) { return ERRSTR[String(code)] || 'Unknown error'; },
        LMSGetDiagnostic: function (code) { return ERRSTR[String(code)] || 'Unknown error'; },
        // Some exporters probe the 2004-style method names on the 1.2 object.
        Initialize: function () { return this.LMSInitialize(); },
        Terminate: function () { return this.LMSFinish(); },
        GetValue: function (el) { return this.LMSGetValue(el); },
        SetValue: function (el, val) { return this.LMSSetValue(el, val); },
        Commit: function () { return this.LMSCommit(); },
        GetLastError: function () { return this.LMSGetLastError(); },
        GetErrorString: function (c) { return this.LMSGetErrorString(c); },
        GetDiagnostic: function (c) { return this.LMSGetDiagnostic(c); }
    };

    // ── SCORM 2004 adapter (window.API_1484_11) ──
    var API2004 = {
        Initialize: function () {
            if (state.initialized) { setError(ERR.GENERAL_EXCEPTION); return 'false'; }
            state.initialized = true;
            state.terminated = false;
            state.finalized = false;
            setError(ERR.NO_ERROR);
            try { loadInitialStateSync(); } catch (e) {}
            return 'true';
        },
        Terminate: function () {
            if (!state.initialized) { setError(ERR.INITIALIZATION_FAILED); return 'false'; }
            terminate();
            setError(ERR.NO_ERROR);
            // Take over exit navigation — Storyline would otherwise frame the
            // exit URL inside this iframe (X-Frame-Options: DENY errors).
            redirectToExit();
            return 'true';
        },
        GetValue: function (el) {
            if (!state.initialized) { setError(ERR.INITIALIZATION_FAILED); return ''; }
            var v = getValue(el);
            return v === null ? '' : v;
        },
        SetValue: function (el, val) {
            if (!state.initialized) { setError(ERR.INITIALIZATION_FAILED); return 'false'; }
            return setValue(el, val) ? 'true' : 'false';
        },
        Commit: function () {
            if (!state.initialized) { setError(ERR.INITIALIZATION_FAILED); return 'false'; }
            persist(false);
            setError(ERR.NO_ERROR);
            return 'true';
        },
        GetLastError: function () { return state.lastError; },
        GetErrorString: function (code) { return ERRSTR[String(code)] || 'Unknown error'; },
        GetDiagnostic: function (code) { return ERRSTR[String(code)] || 'Unknown error'; }
    };

    // ── Session lifecycle ──
    function terminate() {
        if (!state.initialized) return;
        // Idempotent: repeated Terminate()/unload pairs never double-persist
        // time (persistSync computes a ~0 delta after this persist).
        if (state.terminated) return;
        state.terminated = true;
        if (state.scalars['cmi.core.session_time'] === undefined && state.scalars['cmi.session_time'] === undefined) {
            var key = is2004() ? 'cmi.session_time' : 'cmi.core.session_time';
            state.scalars[key] = formatDuration(Date.now() - state.sessionStartedAt);
        }
        // Synchronous final persist: lands BEFORE redirectToExit() navigates or
        // blanks the frame, so suspend_data/location always reach the server.
        // Only finalize on success so handleUnload()'s beforeunload persist can
        // still retry (sync + beacon) if this one failed.
        if (persistSync(true)) state.finalized = true;
    }

    // Synchronous final persist: sendBeacon is not reliable across all browsers
    // on iframe + top-window navigation, and window.stop() aborts the async
    // persist fetch. A synchronous XHR in `beforeunload` guarantees the final
    // state (suspend_data/location) reaches the server before the page tears
    // down. One-shot via state.finalized so beforeunload+unload don't double.
    function persistSync(terminating) {
        try {
            var payload = buildPayload(terminating, true); // reuse failed request_id
            state.commitCount += 1;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', API_ENDPOINT, false); // synchronous
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify(payload));
            return true;
        } catch (e) {
            return false;
        }
    }

    function handleUnload() {
        if (!state.initialized) return;
        if (state.finalized) return; // beforeunload + unload both fire
        state.finalized = true;
        state.terminated = true;
        // Synchronous final commit first; beacon only as a fallback.
        if (!persistSync(true)) {
            try { navigator.sendBeacon(API_ENDPOINT, JSON.stringify(buildPayload(true, true))); } catch (e) {}
        }
    }

    // Take over exit navigation (Storyline frames the exit URL otherwise).
    function redirectToExit() {
        var exitTarget = (window.top && window.top !== window.self) ? window.top : window;
        try {
            exitTarget.location.href = EXIT_URL;
        } catch (e) {
            try { window.location.href = EXIT_URL; } catch (e2) {}
        }
        // Immediately blank THIS frame so Storyline's own exit navigation (which
        // often targets '/' or another non-frameable page) can never be framed —
        // X-Frame-Options: DENY errors. The unload beacon still delivers the
        // final commit (same request_id, deduped server-side).
        try { window.stop(); } catch (e3) {}
        try { window.location.replace('about:blank'); } catch (e4) {}
    }

    function install() {
        if (!window.API) window.API = API12;
        if (!window.API_1484_11) window.API_1484_11 = API2004;
    }
    install();

    window.addEventListener('beforeunload', handleUnload);
    // SCORM 1.2 packages sometimes use window.onunload
    window.onunload = handleUnload;

    // Expose diagnostics ONLY when the player explicitly opts in (admin
    // ?diag=1, reflected in cfg.debugRte). Keeps learner state out of the
    // page for normal launches.
    if (cfg && cfg.debugRte) {
        window.__SCORM_RTE__ = {
            rteVersion: RTE_VERSION,
            version: SCORM_VERSION,
            edition: SCORM_EDITION,
            suspendLimit: SUSPEND_LIMIT,
            initialized: function () { return state.initialized; },
            terminated: function () { return state.terminated; },
            getState: function () { return state.scalars; },
            getAttemptId: function () { return state.attemptId; },
            getCommitCount: function () { return state.commitCount; },
            getInteractionCount: function () { return Object.keys(state.interactions).length; },
            getObjectiveCount: function () { return Object.keys(state.objectives).length; },
            getCommentCount: function () { return state.comments.length; },
            getSuspendDataLength: function () { return (state.scalars['cmi.suspend_data'] || '').length; },
            getErrors: function () { return state.errors.slice(); },
            getLastError: function () { return state.lastError; },
            entry: function () { return state.entry; }
        };
    }

})();
