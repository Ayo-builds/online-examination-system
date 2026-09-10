/**
 * The autosave queue, driven through failures that cannot be produced by hand.
 *
 *   node --test tests/save_queue_test.mjs
 *
 * Node's own test runner. No packages are installed and none are needed.
 *
 * The point of this file is the question a browser cannot answer on demand:
 * when a save genuinely fails, does the student get told? Clicking around a
 * working LAN never produces a failed save, and pulling the plug produces one
 * that nobody is left to observe. So the transport and the clock are injected
 * and the failure is dealt out deliberately.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createSaveQueue } from '../public/assets/js/save-queue.js';
import { renderQuestionState, renderBanner, LABELS } from '../public/assets/js/save-status.js';

// ---- A clock the test drives ----------------------------------------------
// setTimeout callbacks fire when the test advances time, not when the machine
// gets round to it, so a ten-second ceiling is exercised in microseconds and
// the result never depends on how loaded the box is.
function fakeClock() {
    let now = 0;
    let seq = 0;
    const timers = new Map();

    return {
        now: () => now,
        setTimeout(fn, delay) {
            const id = ++seq;
            timers.set(id, { at: now + delay, fn });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },

        /** Advance time, firing due timers in order, including ones they add. */
        async advance(ms) {
            const target = now + ms;
            for (;;) {
                let next = null;
                timers.forEach((t, id) => {
                    if (t.at <= target && (next === null || t.at < timers.get(next).at)) next = id;
                });
                if (next === null) break;
                const timer = timers.get(next);
                timers.delete(next);
                now = timer.at;
                timer.fn();
                await settle();
            }
            now = target;
            await settle();
        },
        pending: () => timers.size,
    };
}

/** Let queued promise callbacks run. The queue is promise-driven throughout. */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

// ---- Canned responses ------------------------------------------------------
const OK       = { status: 200, body: { ok: true, saved_at: '10:00:00' } };
const CLOSED   = { status: 409, body: { ok: false, error: 'closed' } };
const CSRF     = { status: 403, body: { ok: false, error: 'csrf' } };
const BADQ     = { status: 422, body: { ok: false, error: 'bad_question' } };

/** A transport that records what it was asked to send and replies to order. */
function recorder(script) {
    const sent = [];
    let call = 0;
    const fn = async (payload) => {
        sent.push(JSON.parse(JSON.stringify(payload)));
        const reply = typeof script === 'function' ? script(call++) : script;
        if (reply instanceof Error) throw reply;
        return reply;
    };
    fn.sent = sent;
    return fn;
}

function build(transport, clock, extra) {
    const states = [];
    const queue = createSaveQueue({
        transport,
        now: clock.now,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
        onChange: (qid, state) => states.push([qid, state]),
        ...(extra || {}),
    });
    return { queue, states };
}

const answer = (id, value) => ({ csrf_token: 't', question_id: String(id), essay_text: value });

// ---------------------------------------------------------------------------

test('a save that succeeds is the only thing that reports "saved"', async () => {
    const clock = fakeClock();
    const { queue } = build(recorder(OK), clock);

    queue.saveNow(7, answer(7, 'a'));
    await settle();

    assert.equal(queue.stateOf(7), 'saved');
    assert.equal(queue.hasUnsaved(), false);
});

test('a network failure leaves the question unsaved and says so', async () => {
    const clock = fakeClock();
    const { queue, states } = build(recorder(new Error('network down')), clock);

    queue.saveNow(7, answer(7, 'a'));
    await settle();

    // This is the case the old code swallowed: the fetch threw, nothing was
    // shown, and the student read "Answer saved" underneath it anyway.
    assert.equal(queue.stateOf(7), 'unsaved');
    assert.deepEqual(queue.unsavedQuestions(), [7]);
    assert.deepEqual(states.map(s => s[1]), ['pending', 'saving', 'unsaved']);

    // And what the student is actually shown says the same thing.
    const el = fakeEl();
    renderQuestionState(el, queue.stateOf(7));
    assert.equal(el.textContent, LABELS.unsaved);
    assert.equal(el.textContent, 'NOT SAVED');
    assert.ok(el.classes.has('qmeta__state--unsaved'));
});

test('a failed save keeps retrying and recovers on its own', async () => {
    const clock = fakeClock();
    // Fails four times - a power cut lasting some seconds - then the LAN is back.
    const transport = recorder(i => (i < 4 ? new Error('network down') : OK));
    const { queue } = build(transport, clock);

    queue.saveNow(7, answer(7, 'a'));
    await settle();
    assert.equal(queue.stateOf(7), 'unsaved');

    // Backoff is 1s, 2s, 4s, 8s: 15s covers the four retries.
    await clock.advance(15000);

    assert.equal(queue.stateOf(7), 'saved');
    assert.equal(queue.hasUnsaved(), false);
    assert.equal(transport.sent.length, 5);
});

test('a retry never resurrects a stale answer', async () => {
    const clock = fakeClock();
    const transport = recorder(i => (i === 0 ? new Error('network down') : OK));
    const { queue } = build(transport, clock);

    queue.saveNow(7, answer(7, 'first'));
    await settle();
    assert.equal(queue.stateOf(7), 'unsaved');

    // The student edits again before the retry fires.
    queue.saveNow(7, answer(7, 'second'));
    await settle();

    await clock.advance(20000);

    // Whatever else happened, the last thing the server was told must be the
    // text now on screen. A queue of every attempt would have replayed 'first'
    // over the top of it.
    assert.equal(transport.sent[transport.sent.length - 1].essay_text, 'second');
    assert.ok(!transport.sent.slice(1).some(p => p.essay_text === 'first'),
        'a stale value was re-sent after a newer one existed');
    assert.equal(queue.stateOf(7), 'saved');
});

test('steady typing cannot outrun the save: the ceiling fires', async () => {
    const clock = fakeClock();
    const transport = recorder(OK);
    const { queue } = build(transport, clock);

    // 900ms between keystrokes: never a 1s pause, so a pure debounce saves
    // nothing at all. This is the student writing a fluent paragraph.
    for (let i = 0; i < 12; i++) {
        queue.saveDebounced(7, answer(7, 'word '.repeat(i + 1)));
        await clock.advance(900);
    }

    assert.ok(transport.sent.length >= 1,
        'a debounce with no ceiling would have saved nothing in 10.8s of typing');

    // And it fired at the ceiling, not merely at some point.
    assert.ok(transport.sent[0].essay_text.length > 0);
});

test('the ceiling fires DURING typing, not after it stops', async () => {
    const clock = fakeClock();
    const sendTimes = [];
    const transport = async (payload) => { sendTimes.push(clock.now()); return OK; };
    const { queue } = build(transport, clock);

    // 17 keystrokes at 900ms: 15.3s of typing that never pauses for a second.
    for (let i = 0; i < 17; i++) {
        queue.saveDebounced(7, answer(7, 'x'.repeat(i + 1)));
        await clock.advance(900);
    }

    assert.ok(sendTimes.length > 0, 'nothing was saved during 15s of continuous typing');

    // Under the 10s ceiling, and not before it either - a ceiling that fires
    // early is just a shorter debounce and defeats the point of having one.
    assert.ok(sendTimes[0] >= 9000 && sendTimes[0] <= 11000,
        'first save landed at ' + sendTimes[0] + 'ms, expected the 10s ceiling');

    // Nothing was sent in the first 8s: the ordinary debounce is still doing
    // its job of not hammering the server on every keystroke.
    assert.equal(sendTimes.filter(t => t < 8000).length, 0);
});

test('a quiet pause still saves on the ordinary debounce', async () => {
    const clock = fakeClock();
    const transport = recorder(OK);
    const { queue } = build(transport, clock);

    queue.saveDebounced(7, answer(7, 'done typing'));
    await clock.advance(1000);

    assert.equal(transport.sent.length, 1);
    assert.equal(queue.stateOf(7), 'saved');
});

test('a dead session is reported, not swallowed, and retries stop', async () => {
    const clock = fakeClock();
    const transport = recorder(CSRF);
    let csrfCalls = 0;
    const { queue } = build(transport, clock, { onCsrf: () => { csrfCalls++; } });

    queue.saveNow(7, answer(7, 'a'));
    await settle();

    assert.equal(csrfCalls, 1);
    assert.equal(queue.stateOf(7), 'unsaved');

    // Retrying a token the server has already rejected can only fail forever.
    await clock.advance(60000);
    assert.equal(transport.sent.length, 1);
});

test('the session-expired banner tells the student their work is not lost', () => {
    const el = fakeEl();
    renderBanner(el, [3], { sessionExpired: true });

    assert.equal(el.hidden, false);
    assert.match(el.textContent, /NOT saved/);
    assert.match(el.textContent, /still on this page/);
    assert.match(el.textContent, /can still submit/);
});

test('a restored session resends everything that was outstanding', async () => {
    const clock = fakeClock();
    let dead = true;
    const transport = recorder(() => (dead ? CSRF : OK));
    const { queue } = build(transport, clock);

    queue.saveNow(7, answer(7, 'a'));
    queue.saveNow(9, answer(9, 'b'));
    await settle();
    assert.deepEqual(queue.unsavedQuestions().sort((a, b) => a - b), [7, 9]);

    dead = false;
    queue.resumeAfterCsrf('fresh-token');
    await settle();

    assert.equal(queue.hasUnsaved(), false);
    // And they went out under the new token, not the dead one.
    const last = transport.sent.slice(-2);
    assert.ok(last.every(p => p.csrf_token === 'fresh-token'));
});

test('a closed attempt stops retrying and asks for submission', async () => {
    const clock = fakeClock();
    const transport = recorder(CLOSED);
    let closed = 0;
    const { queue } = build(transport, clock, { onClosed: () => { closed++; } });

    queue.saveNow(7, answer(7, 'a'));
    await settle();

    assert.equal(closed, 1);
    await clock.advance(60000);
    assert.equal(transport.sent.length, 1, 'retried a deadline that has already passed');
});

test('a rejected question stops rather than looping forever', async () => {
    const clock = fakeClock();
    const transport = recorder(BADQ);
    const { queue } = build(transport, clock);

    queue.saveNow(7, answer(7, 'a'));
    await settle();
    await clock.advance(60000);

    assert.equal(queue.stateOf(7), 'unsaved');
    assert.equal(transport.sent.length, 1);
});

test('flush sends outstanding work without waiting for the debounce', async () => {
    const clock = fakeClock();
    const transport = recorder(OK);
    const { queue } = build(transport, clock);

    queue.saveDebounced(7, answer(7, 'half a sentence'));
    assert.equal(transport.sent.length, 0);   // still inside the debounce

    // This is the blur / visibilitychange path: the page may be about to stop.
    queue.flush();
    await settle();

    assert.equal(transport.sent.length, 1);
    assert.equal(queue.stateOf(7), 'saved');
});

test('the banner names the questions and never tells anyone to wait', () => {
    const el = fakeEl();

    renderBanner(el, [], {});
    assert.equal(el.hidden, true);

    renderBanner(el, [4], {});
    assert.equal(el.hidden, false);
    assert.match(el.textContent, /Question 4 is not saved/);
    assert.match(el.textContent, /can still submit/);

    renderBanner(el, [9, 2], {});
    assert.match(el.textContent, /2 answers are not saved/);
    assert.match(el.textContent, /questions 2, 9/);   // sorted, as printed on the paper
});

test('the status line never says "saved" for any state but saved', () => {
    ['clean', 'pending', 'saving', 'unsaved'].forEach((state) => {
        const el = fakeEl();
        renderQuestionState(el, state);
        assert.ok(!/^Answer saved$/.test(el.textContent),
            state + ' rendered as "Answer saved"');
    });

    const el = fakeEl();
    renderQuestionState(el, 'saved');
    assert.equal(el.textContent, 'Answer saved');
});

// ---- The smallest thing that behaves like an element -----------------------
// Enough for textContent and classList. A real DOM is not needed to assert
// which words a state produces, and this keeps the test free of a browser.
function fakeEl() {
    const classes = new Set();
    return {
        textContent: '',
        hidden: false,
        classes,
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)),
            contains: (c) => classes.has(c),
        },
        querySelector: () => null,
        appendChild: () => {},
    };
}
