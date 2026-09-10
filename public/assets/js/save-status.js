/**
 * The state the save queue reports, rendered as words a candidate can read.
 *
 * This is deliberately the only place that decides what a student is told
 * about whether their work is safe. Before this existed, the page wrote
 * "Answer saved" from DOM state alone - so a student whose every save had
 * failed still read "Answer saved" under every question. Nothing here may
 * claim an answer is saved unless the SERVER said so, which is why the only
 * input is the queue's own state.
 *
 * Element-shaped rather than DOM-bound: everything takes objects with
 * textContent and classList, so the mapping can be asserted in a test without
 * a browser.
 */

// The exact words. Kept as data so a test can assert against these constants
// rather than restating them and drifting from the page.
export const LABELS = {
    clean:   'Not yet answered',
    pending: 'Saving…',
    saving:  'Saving…',
    saved:   'Answer saved',
    unsaved: 'NOT SAVED',
};

export const MODIFIERS = {
    clean:   'qmeta__state--clean',
    pending: 'qmeta__state--saving',
    saving:  'qmeta__state--saving',
    saved:   'qmeta__state--saved',
    unsaved: 'qmeta__state--unsaved',
};

/** Paint one question's status line. */
export function renderQuestionState(el, state) {
    if (!el) return;

    el.textContent = LABELS[state] || LABELS.clean;

    Object.keys(MODIFIERS).forEach((key) => {
        el.classList.toggle(MODIFIERS[key], key === state);
    });
}

/**
 * The banner. Visible whenever anything is outstanding, naming the questions,
 * because "some answers are unsaved" sends a student hunting through the paper
 * at the worst possible moment.
 *
 * It never blocks submission. A failed save must not become a zero.
 */
export function renderBanner(el, unsavedQuestions, opts) {
    if (!el) return;

    const options = opts || {};
    const count   = unsavedQuestions.length;

    if (options.sessionExpired) {
        el.hidden = false;
        el.textContent = 'Your session has expired, so recent answers are NOT saved. '
            + 'Your answers are still on this page. Sign in again in a new tab, '
            + 'then press Retry. You can still submit.';
        el.classList.add('savebar--error');
        return;
    }

    if (count === 0) {
        el.hidden = true;
        el.textContent = '';
        el.classList.remove('savebar--error');
        return;
    }

    const list = unsavedQuestions.slice().sort((a, b) => a - b).join(', ');

    el.hidden = false;
    el.classList.add('savebar--error');
    el.textContent = count === 1
        ? 'Question ' + list + ' is not saved yet. Still trying… you can still submit.'
        : count + ' answers are not saved yet (questions ' + list + '). '
          + 'Still trying… you can still submit.';
}
