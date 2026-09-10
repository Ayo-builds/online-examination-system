/**
 * The autosave queue.
 *
 * On this deployment's LAN the power cuts and the network blips, so a save
 * failing is a normal event, not an exceptional one. Everything here exists to
 * make that failure survivable and, above all, VISIBLE: a student must never
 * be told an answer is saved when the server has not said so.
 *
 * No DOM and no globals. The transport and the clock are injected, so a test
 * can fail a save on demand and run a ten-second debounce ceiling instantly -
 * neither of which can be produced by clicking around in a browser.
 */

// Per-question lifecycle. SAVED is reachable ONLY from a server ok.
const CLEAN   = 'clean';    // never touched
const PENDING = 'pending';  // edited, save not yet sent
const SAVING  = 'saving';   // in flight
const SAVED   = 'saved';    // the server said ok
const UNSAVED = 'unsaved';  // the server said no, or never answered

// Essays save 1s after typing stops, but a fluent writer can type for minutes
// without ever pausing that long, and a pure debounce would save none of it.
// The ceiling forces a save every 10s of continuous typing regardless.
const DEBOUNCE_MS = 1000;
const MAX_WAIT_MS = 10000;

// Retry for as long as the attempt is open: a power cut lasts minutes, and
// giving up after N tries would discard work the student can still submit.
const BACKOFF_MS = [1000, 2000, 4000, 8000, 15000];

export function createSaveQueue(options) {
    const transport  = options.transport;                  // (payload, opts) => Promise<{status, body}>
    const now        = options.now || (() => Date.now());
    const setTimer   = options.setTimeout || setTimeout;
    const clearTimer = options.clearTimeout || clearTimeout;
    const onChange   = options.onChange || (() => {});     // (questionId, state) => void
    const onClosed   = options.onClosed || (() => {});     // the server deadline has passed
    const onCsrf     = options.onCsrf || (() => {});       // the session died under us

    // One entry per question, REPLACED on each edit rather than appended.
    // A queue of every attempt would let a retry resurrect a stale answer:
    // question 5 saved at t=1 (failed), edited again at t=3, and the t=1 retry
    // overwrites the newer text. Latest value always wins.
    const entries = new Map();

    let sessionDead = false;

    function entryFor(questionId) {
        let e = entries.get(questionId);
        if (!e) {
            e = {
                questionId,
                state: CLEAN,
                payload: null,       // the latest value, whatever else happens
                debounceTimer: null,
                ceilingAt: null,     // when the max-wait ceiling expires
                retryTimer: null,
                attempt: 0,
            };
            entries.set(questionId, e);
        }
        return e;
    }

    function transition(entry, state) {
        entry.state = state;
        onChange(entry.questionId, state);
    }

    function clearTimers(entry) {
        if (entry.debounceTimer !== null) { clearTimer(entry.debounceTimer); entry.debounceTimer = null; }
        if (entry.retryTimer !== null) { clearTimer(entry.retryTimer); entry.retryTimer = null; }
    }

    // An MCQ choice: no debounce, the intent is unambiguous the moment it lands.
    function saveNow(questionId, payload) {
        const entry = entryFor(questionId);
        clearTimers(entry);
        entry.payload   = payload;
        entry.ceilingAt = null;
        entry.attempt   = 0;
        transition(entry, PENDING);
        send(entry);
    }

    // Essay text: debounced, but never past the ceiling.
    function saveDebounced(questionId, payload) {
        const entry = entryFor(questionId);
        entry.payload = payload;

        // A retry holding older text is now moot; the new text supersedes it.
        if (entry.retryTimer !== null) { clearTimer(entry.retryTimer); entry.retryTimer = null; }
        entry.attempt = 0;

        transition(entry, PENDING);

        // The ceiling starts at the FIRST keystroke of a run and is not
        // extended by the ones after it. That is the whole point of it.
        if (entry.ceilingAt === null) {
            entry.ceilingAt = now() + MAX_WAIT_MS;
        }

        if (entry.debounceTimer !== null) clearTimer(entry.debounceTimer);

        const untilCeiling = entry.ceilingAt - now();
        const delay = Math.max(0, Math.min(DEBOUNCE_MS, untilCeiling));

        entry.debounceTimer = setTimer(() => {
            entry.debounceTimer = null;
            entry.ceilingAt = null;
            send(entry);
        }, delay);
    }

    function send(entry, fetchOptions) {
        // Always send the CURRENT payload, never one captured earlier: between
        // queueing and sending, the student may well have typed more.
        const payload = entry.payload;
        if (payload === null) return;

        transition(entry, SAVING);

        Promise.resolve()
            .then(() => transport(payload, fetchOptions || {}))
            .then((res) => {
                // The payload changed while this request was in flight, so the
                // response says nothing about what is currently on screen.
                if (entry.payload !== payload) {
                    if (entry.state === SAVING) transition(entry, PENDING);
                    scheduleRetry(entry, 0);
                    return;
                }

                if (res.status === 200 && res.body && res.body.ok) {
                    entry.attempt = 0;
                    transition(entry, SAVED);
                    return;
                }

                const error = res.body && res.body.error;

                if (error === 'closed') {
                    // The server deadline has passed. Retrying cannot succeed,
                    // and the paper has to be submitted now.
                    transition(entry, UNSAVED);
                    onClosed();
                    return;
                }

                if (res.status === 403 && error === 'csrf') {
                    // The session died under us, so the token in the page is
                    // dead too and retrying it is futile until the student
                    // signs in again. Their answers are still in the DOM.
                    transition(entry, UNSAVED);
                    if (!sessionDead) { sessionDead = true; onCsrf(); }
                    return;
                }

                // 422 and friends are a bug or tampering, not a blip. Retrying
                // would loop forever, so stop and say so plainly instead.
                if (res.status === 422 || res.status === 404 || res.status === 405) {
                    transition(entry, UNSAVED);
                    return;
                }

                failed(entry);
            })
            .catch(() => failed(entry));   // network down, which is the common case here
    }

    function failed(entry) {
        transition(entry, UNSAVED);
        scheduleRetry(entry, BACKOFF_MS[Math.min(entry.attempt, BACKOFF_MS.length - 1)]);
        entry.attempt++;
    }

    function scheduleRetry(entry, delay) {
        if (sessionDead) return;
        if (entry.retryTimer !== null) clearTimer(entry.retryTimer);
        entry.retryTimer = setTimer(() => {
            entry.retryTimer = null;
            send(entry);
        }, delay);
    }

    // Send everything outstanding at once, without waiting on a debounce. Used
    // on blur and on visibilitychange, where the page may be about to stop
    // running JavaScript altogether - keepalive lets the request outlive it -
    // and by Retry once a dead session has been restored.
    function flush() {
        entries.forEach((entry) => {
            if (entry.state === PENDING || entry.state === UNSAVED) {
                if (entry.debounceTimer !== null) { clearTimer(entry.debounceTimer); entry.debounceTimer = null; }
                if (entry.retryTimer !== null) { clearTimer(entry.retryTimer); entry.retryTimer = null; }
                entry.ceilingAt = null;
                send(entry, { keepalive: true });
            }
        });
    }

    // The questions a student would lose if the machine died right now.
    function unsavedQuestions() {
        const out = [];
        entries.forEach((entry) => {
            if (entry.state === PENDING || entry.state === SAVING || entry.state === UNSAVED) {
                out.push(entry.questionId);
            }
        });
        return out;
    }

    function hasUnsaved() {
        return unsavedQuestions().length > 0;
    }

    // Called after the student signs back in elsewhere and presses Retry.
    function resumeAfterCsrf(newToken) {
        sessionDead = false;
        entries.forEach((entry) => {
            if (entry.payload !== null) entry.payload.csrf_token = newToken;
        });
        flush();
    }

    function stateOf(questionId) {
        const e = entries.get(questionId);
        return e ? e.state : CLEAN;
    }

    return {
        saveNow,
        saveDebounced,
        flush,
        hasUnsaved,
        unsavedQuestions,
        resumeAfterCsrf,
        stateOf,
        STATES: { CLEAN, PENDING, SAVING, SAVED, UNSAVED },
        TIMING: { DEBOUNCE_MS, MAX_WAIT_MS, BACKOFF_MS },
    };
}
