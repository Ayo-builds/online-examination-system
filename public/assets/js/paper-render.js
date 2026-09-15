/**
 * The paper, drawn in the browser.
 *
 * Question and option text are never in the page's HTML. They arrive from
 * POST student/paper/{id} only after the fullscreen gate has been passed, so a
 * candidate with JavaScript turned off, or reading the page source, sees no
 * questions at all. This file owns the two halves of that: getting through the
 * gate to the paper, and turning the paper into the same markup the server
 * used to print.
 *
 * Every piece of candidate-visible text goes in through textContent, never
 * innerHTML. The question bank is typed by staff; rendering it as markup would
 * turn a stray "<" into a broken page and a deliberate one into script.
 *
 * The document and the fullscreen calls are passed in, so a test can draw a
 * paper without a browser and can refuse fullscreen or fail the network on
 * demand.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

// ---- Getting through the gate ----------------------------------------------

/**
 * What to do with the paper endpoint's answer.
 *
 *   'ok'     draw it
 *   'locked' the attempt is paused; nothing is drawn until it is unlocked
 *   'retry'  the server is there but failed; trying again can help
 *   'reload' anything else: the page itself is stale
 *
 * A pause is not a stale page. Reloading would only ask again and be told the
 * same thing, so it gets its own answer.
 *
 * A body that is not JSON is almost always an expired session answered with
 * the login page, and a 403 is a dead CSRF token. Retrying either sends the
 * same dead credentials again and fails forever, so both reload instead and
 * let the server hand out a fresh token or the sign-in screen. A 409 means
 * the attempt is closed, and the reloaded page is where the server says so.
 */
export function paperOutcome(res) {
    const body = res && res.body;
    if (body === null || typeof body !== 'object') return 'reload';
    if (res.status === 200 && body.ok === true && Array.isArray(body.questions)) return 'ok';
    if (res.status === 423 && body.error === 'locked') return 'locked';
    if (res.status >= 500) return 'retry';
    return 'reload';
}

/**
 * Enter fullscreen, then fetch the paper. Resolves to what happened:
 * 'ok', 'refused', 'locked', 'retry' or 'reload'.
 *
 * The fetch waits for fullscreen to be both granted and actually in force.
 * Nothing about the paper is requested before that.
 */
export async function openPaper(deps) {
    try {
        await deps.requestFullscreen();
    } catch (e) {
        deps.onRefused();
        return 'refused';
    }
    if (!deps.fullscreenElement()) {
        deps.onRefused();
        return 'refused';
    }

    let res;
    try {
        res = await deps.loadPaper();
    } catch (e) {
        // No answer at all: the cable, the switch, the server's power.
        deps.onNetworkFailure();
        return 'retry';
    }

    const outcome = paperOutcome(res);
    if (outcome === 'ok')     deps.onPaper(res.body);
    if (outcome === 'locked') deps.onLocked();
    if (outcome === 'retry')  deps.onNetworkFailure();
    if (outcome === 'reload') deps.reload();
    return outcome;
}

// ---- Drawing it ------------------------------------------------------------

/** The questions the server already holds an answer for. */
export function savedQuestionIds(questions) {
    const ids = new Set();
    questions.forEach((q) => {
        if (q.selected_option_id !== null
            || (q.essay_text !== null && String(q.essay_text).trim() !== '')) {
            ids.add(Number(q.question_id));
        }
    });
    return ids;
}

/**
 * Draw every question into list and one navigation box per question into
 * nav. Both must already be inside the page's .quiz-page container: that is
 * what keeps the paper unselectable.
 */
export function renderPaper(doc, list, nav, questions) {
    const ordered = questions.slice().sort((a, b) => a.display_order - b.display_order);

    ordered.forEach((q) => {
        list.appendChild(questionCard(doc, q));

        const box = el(doc, 'a', 'qnav__box');
        box.setAttribute('href', '#q' + q.display_order);
        box.setAttribute('data-nav', String(q.display_order));
        box.textContent = String(q.display_order);
        nav.appendChild(box);
    });
}

function questionCard(doc, q) {
    const qid = String(q.question_id);
    const num = String(q.display_order);

    const card = el(doc, 'div', 'question-card');
    card.setAttribute('id', 'q' + num);
    card.setAttribute('data-question-id', qid);
    card.setAttribute('data-qnum', num);

    // ---- meta column
    const meta = el(doc, 'div', 'question-card__meta');

    const numLine = el(doc, 'p', 'qmeta__num');
    const bold = el(doc, 'b');
    bold.textContent = num;
    numLine.appendChild(doc.createTextNode('Question '));
    numLine.appendChild(bold);

    // Written only from what the server said about this answer; see
    // save-status.js. The page repaints it as soon as the paper is drawn.
    const state = el(doc, 'p', 'qmeta__state qmeta__state--clean');
    state.textContent = 'Not yet answered';

    const marks = el(doc, 'p', 'qmeta__marks');
    marks.textContent = 'Marked out of ' + q.marks;

    const flag = el(doc, 'button', 'qmeta__flag');
    flag.setAttribute('type', 'button');
    flag.setAttribute('data-flag', num);
    flag.setAttribute('aria-pressed', 'false');
    flag.appendChild(flagIcon(doc));
    // The flag code rewrites this last text node, so it must stay last.
    flag.appendChild(doc.createTextNode(' Flag question'));

    meta.appendChild(numLine);
    meta.appendChild(state);
    meta.appendChild(marks);
    meta.appendChild(flag);

    // ---- body column
    const body = el(doc, 'div', 'question-card__body');

    const text = el(doc, 'p', 'question-card__text');
    text.textContent = q.question_text;
    body.appendChild(text);

    if (q.question_type === 'mcq') {
        const select = el(doc, 'p', 'qbody__select');
        select.textContent = 'Select one:';
        body.appendChild(select);

        const opts = el(doc, 'div', 'opts');
        q.options.forEach((opt, i) => {
            const label = el(doc, 'label', 'opt');

            const input = el(doc, 'input');
            input.setAttribute('type', 'radio');
            input.setAttribute('name', 'answer[' + qid + ']');
            input.setAttribute('value', String(opt.id));
            input.setAttribute('data-question', qid);
            if (q.selected_option_id !== null && Number(q.selected_option_id) === Number(opt.id)) {
                input.setAttribute('checked', '');
            }

            const letter = el(doc, 'span', 'opt__letter');
            letter.textContent = String.fromCharCode(97 + i) + '.';

            const optText = el(doc, 'span', 'opt__text');
            optText.textContent = opt.text;

            label.appendChild(input);
            label.appendChild(letter);
            label.appendChild(optText);
            opts.appendChild(label);
        });
        body.appendChild(opts);
    } else {
        const area = el(doc, 'textarea', 'essay-input');
        area.setAttribute('name', 'answer[' + qid + ']');
        area.setAttribute('data-question', qid);
        area.setAttribute('rows', '8');
        area.setAttribute('placeholder', 'Type your answer…');
        area.setAttribute('spellcheck', 'false');
        area.setAttribute('autocomplete', 'off');
        area.setAttribute('autocorrect', 'off');
        area.setAttribute('autocapitalize', 'off');
        area.textContent = q.essay_text === null ? '' : q.essay_text;
        body.appendChild(area);
    }

    card.appendChild(meta);
    card.appendChild(body);
    return card;
}

function flagIcon(doc) {
    const svg = doc.createElementNS(SVG_NS, 'svg');
    [['viewBox', '0 0 24 24'], ['fill', 'none'], ['stroke', 'currentColor'], ['stroke-width', '2'],
     ['stroke-linecap', 'round'], ['stroke-linejoin', 'round'], ['aria-hidden', 'true']]
        .forEach(([k, v]) => svg.setAttribute(k, v));

    const path = doc.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', 'M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z');
    const line = doc.createElementNS(SVG_NS, 'line');
    [['x1', '4'], ['y1', '22'], ['x2', '4'], ['y2', '15']].forEach(([k, v]) => line.setAttribute(k, v));

    svg.appendChild(path);
    svg.appendChild(line);
    return svg;
}

function el(doc, tag, className) {
    const node = doc.createElement(tag);
    if (className) node.setAttribute('class', className);
    return node;
}
