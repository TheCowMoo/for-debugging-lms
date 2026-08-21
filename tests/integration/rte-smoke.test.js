// Smoke test for scorm-rte.js — exercises the full RTE flow with mocked
// browser APIs: Initialize (sync resume), SetValue (scalars + interactions +
// objectives + comments), Commit, Terminate, and double-terminate protection.
'use strict';
const fs = require('fs');
const path = require('path');

const rteSource = fs.readFileSync(path.join(__dirname, '..', '..', 'scorm-api', 'scorm-rte.js'), 'utf8');

// ── Mock browser environment ──
let captured = [];
let sentBeacons = [];
let persistCount = 0;

const windowObj = {
    SCORM_PACKAGE_CONFIG: {
        pkg: 42, sco: 7, version: '1.2', edition: '1.2',
        apiEndpoint: 'http://lms.test/scorm-api/store.php', token: 'tok123',
        exitUrl: 'http://lms.test/course-page/', debugRte: true
    },
    SCORM_USER: { id: 'u1', name: 'Alice' },
    addEventListener: function (evt, fn) { (this._listeners = this._listeners || {})[evt] = (this._listeners[evt] || []).concat([fn]); },
    top: null,
    location: { href: 'http://lms.test/course-page/' }
};
windowObj.top = windowObj;

global.window = windowObj;
const navMock = {
    sendBeacon: function (url, data) { sentBeacons.push(JSON.parse(String(data))); return true; }
};
if (typeof global.Blob === 'undefined') { global.Blob = require('buffer').Blob; }

global.XMLHttpRequest = class {
    constructor() { this.status = 0; this.responseText = ''; }
    open() {}
    setRequestHeader() {}
    send(body) {
        this.status = 200;
        this.responseText = JSON.stringify({ ok: true, attempt_id: 101, initial: { values: [{ element: 'cmi.core.lesson_location', value: 'slide5' }] } });
        try {
            captured.push(JSON.parse(body));
            persistCount++;
        } catch (e) {}
    }
};

global.fetch = function (url, opts) {
    persistCount++;
    const payload = JSON.parse(opts.body);
    captured.push(payload);
    return Promise.resolve({ status: 200, json: () => Promise.resolve({ ok: true, attempt_id: payload.attempt || 101, saved: { total_seconds: 120 }, initial: null }) });
};

// Load the RTE into the mocked window. navigator is passed explicitly so the
// mock is used regardless of Node's built-in (non-configurable) navigator.
new Function('window', 'navigator', 'Blob', 'XMLHttpRequest', 'fetch', 'document', rteSource + '\nreturn true;')(
    windowObj, navMock, global.Blob, global.XMLHttpRequest, global.fetch, { addEventListener: () => {} });

const api = windowObj.API;
const api2004 = windowObj.API_1484_11;
const delay = (ms) => new Promise((res) => setTimeout(res, ms));
let failures = 0;
function assert(cond, msg) {
    if (cond) { console.log('  ok  - ' + msg); }
    else { failures++; console.log('  FAIL- ' + msg); }
}

async function run() {
    console.log('1. Initialize (1.2)');
    assert(api.LMSInitialize() === 'true', 'LMSInitialize returns true');
    assert(api.LMSGetValue('cmi.core.lesson_location') === 'slide5', 'resume state hydrated (lesson_location=slide5)');
    assert(api.LMSGetValue('cmi.core.entry') === 'resume', 'entry reflects resume');

    console.log('2. Scalars + statuses');
    assert(api.LMSSetValue('cmi.core.lesson_status', 'passed') === 'true', 'write lesson_status=passed');
    assert(api.LMSSetValue('cmi.core.score.raw', '95') === 'true', 'write score.raw');
    assert(api.LMSSetValue('cmi.suspend_data', 'A'.repeat(5000)) === 'true', 'suspend_data write accepted');
    assert(api.LMSGetValue('cmi.suspend_data').length === 4096, 'suspend_data truncated to 4096 (1.2 limit)');

    console.log('3. Interactions + objectives + comments');
    assert(api.LMSSetValue('cmi.interactions.0.id', 'q1') === 'true', 'interaction id');
    assert(api.LMSSetValue('cmi.interactions.0.type', 'choice') === 'true', 'interaction type');
    assert(api.LMSSetValue('cmi.interactions.0.learner_response', 'B') === 'true', 'learner response');
    assert(api.LMSSetValue('cmi.interactions.0.result', 'correct') === 'true', 'result');
    assert(api.LMSSetValue('cmi.interactions.0.correct_responses.0.pattern', 'B') === 'true', 'correct response pattern');
    assert(api.LMSSetValue('cmi.interactions.0.correct_responses.0.id', 'cr-1') === 'true', 'correct response id');
    assert(api.LMSSetValue('cmi.interactions.0.objectives.0.id', 'obj1') === 'true', 'interaction-objective link');
    assert(api.LMSGetValue('cmi.interactions.0.correct_responses.0.pattern') === 'B', 'read back correct response');
    assert(api.LMSSetValue('cmi.objectives.0.id', 'obj1') === 'true', 'objective id');
    assert(api.LMSSetValue('cmi.objectives.0.score.raw', '90') === 'true', 'objective score.raw');
    assert(api.LMSSetValue('cmi.objectives.0.success_status', 'passed') === 'true', 'objective success');
    assert(api.LMSSetValue('cmi.comments_from_learner.0.comment', 'Great course') === 'true', 'learner comment');
    assert(api.LMSSetValue('cmi.comments_from_learner.0.location', 'slide3') === 'true', 'comment location');

    console.log('4. Unsupported + read-only semantics');
    assert(api.LMSSetValue('cmi.comments_from_lms', 'x') === 'false', 'comments_from_lms refused');
    assert(api.LMSGetLastError() === '203', 'error code 203 (not writable) for refused write');
    assert(api.LMSSetValue('cmi.core.total_time', 'PT1H') === 'false', 'total_time write refused (read-only)');
    assert(api.LMSSetValue('cmi.nonexistent.element', 'x') === 'false', 'unknown element refused');
    assert(api.LMSGetLastError() === '201', 'error code 201 for unknown element');
    assert(api.LMSSetValue('cmi.core.student_preference.audio', '0') === 'true', 'audio preference accepted session-only');
    assert(api.LMSGetValue('cmi.core.student_preference.audio') === '0', 'preference readable');

    console.log('5. Commit payload');
    captured = []; persistCount = 0;
    assert(api.LMSCommit() === 'true', 'LMSCommit returns true');
    await delay(40);
    assert(persistCount >= 1, 'persist dispatched');
    const p = captured.find((c) => c.terminating === false);
    assert(p && p.request_id && p.request_id.length > 0, 'request_id present on commit');
    assert(p && typeof p.session_delta_ms === 'number', 'session_delta_ms present');
    assert(p && p.values['cmi.core.lesson_status'] === 'passed', 'values carry lesson_status');
    assert(p && p.interactions.length === 1 && p.interactions[0].objectives[0].id === 'obj1', 'interaction objectives link in payload');
    assert(p && p.interactions[0].correct_responses[0].id === 'cr-1', 'correct response id in payload');
    assert(p && p.comments.length === 1 && p.comments[0].comment === 'Great course', 'comments in payload');
    assert(p && p.interactions[0].learner_response === 'B', 'learner_response in payload');

    console.log('6. Terminate + double-terminate protection');
    const before = persistCount;
    assert(api.LMSFinish() === 'true', 'LMSFinish returns true');
    assert(api.LMSFinish() === 'true', 'second LMSFinish still returns true');
    await delay(40);
    assert(persistCount >= before + 1, 'terminate persisted (synchronous XHR)');
    const t = captured.filter((c) => c.terminating === true);
    assert(t.length === 1, 'exactly one terminating payload (Terminate finalizes session)');
    assert(t.length > 0 && t[t.length - 1].session_delta_ms <= 1000, 'terminate delta <=1s (no double-counted time)');

    console.log('7. Unload after Terminate (no double-persist)');
    sentBeacons = [];
    const unload = (windowObj._listeners.beforeunload || [])[0];
    try { unload(); } catch (e) { console.log('  [debug] unload threw: ' + e.message); }
    assert(sentBeacons.length === 0, 'no beacon fired (Terminate already finalized session)');
    assert(captured.filter((c) => c.terminating === true).length === 1, 'unload did not double-persist');
    const tp = captured.filter((c) => c.terminating === true)[0];
    assert(tp && tp.request_id && tp.request_id.length > 0, 'terminate payload has request_id');

    console.log('8. Cross-version alias probing (2004 API on 1.2 package)');
    // The session was already initialized via the 1.2 API: a second
    // Initialize on the 2004 object must be refused (single-init semantics).
    assert(api2004.Initialize() === 'false', 'second Initialize refused');
    assert(api2004.GetLastError() === '101', 'general exception on second Initialize');
    assert(api2004.GetValue('cmi.completion_status') === 'passed', '2004 GetValue aliases lesson_status');
    assert(api2004.GetValue('cmi.score.raw') === '95', '2004 GetValue aliases score.raw');

    console.log('9. Diagnostics surface');
    const diag = windowObj.__SCORM_RTE__;
    assert(diag && diag.version === '1.2', 'diag version');
    assert(diag.getInteractionCount() === 1, 'diag interaction count');
    assert(diag.getObjectiveCount() === 1, 'diag objective count');
    assert(diag.getCommitCount() >= 2, 'diag commit count');

    console.log(failures === 0 ? '\nALL PASSED' : '\n' + failures + ' FAILURES');
    process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => { console.error('HARNESS ERROR', e); process.exit(2); });
