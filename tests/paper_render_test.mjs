/**
 * The paper as the browser draws it, and the gate in front of it.
 *
 *   node --test tests/paper_render_test.mjs
 *
 * Node's own test runner, no packages. The document is a stand-in that
 * throws the moment anything reaches for innerHTML, so a renderer that ever
 * treats question text as markup fails here rather than in a lab. Fullscreen
 * and the network are stand-ins too, so a refusal, a dead cable and an expired
 * session can each be dealt out on demand.
 *
 * That the drawn paper is actually unselectable in a real browser is checked
 * by tests/browser_render_test.mjs.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { openPaper, paperOutcome, renderPaper, savedQuestionIds }
    from '../public/assets/js/paper-render.js';

// ---- A document that refuses markup ----------------------------------------

function fakeDoc() {
    function element(tag, ns = null) {
        const n = {
            nodeType: 1,
            tagName: tag.toUpperCase(),
            namespace: ns,
            attrs: {},
            children: [],
            _text: '',
            setAttribute(k, v) { this.attrs[k] = String(v); },
            appendChild(c) { this.children.push(c); return c; },
            get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); },
            set textContent(v) { this._text = String(v); this.children = []; },
            insertAdjacentHTML() { throw new Error('insertAdjacentHTML used'); },
        };
        ['innerHTML', 'outerHTML'].forEach((p) => Object.defineProperty(n, p, {
            get() { throw new Error(p + ' read'); },
            set() { throw new Error(p + ' used'); },
        }));
        return n;
    }

    return {
        body: element('body'),
        createElement: (tag) => element(tag),
        createElementNS: (ns, tag) => element(tag, ns),
        createTextNode: (text) => ({ nodeType: 3, textContent: String(text) }),
    };
}

/** Every element under root, depth first, that satisfies pred. */
function findAll(root, pred) {
    const out = [];
    (function walk(n) {
        if (n.nodeType !== 1) return;
        if (pred(n)) out.push(n);
        n.children.forEach(walk);
    })(root);
    return out;
}

const byClass = (cls) => (n) => (n.attrs.class || '').split(' ').includes(cls);
const byTag   = (tag) => (n) => n.tagName === tag.toUpperCase();

// ---- A paper as student/paper/{id} returns it -------------------------------

function paper() {
    return [
        // Out of order on purpose: the renderer must sort.
        {
            question_id: 70, display_order: 2, question_type: 'essay',
            question_text: 'Explain photosynthesis.\nUse two paragraphs.', marks: '10.00',
            options: [], selected_option_id: null, essay_text: 'Plants make food.',
        },
        {
            question_id: 50, display_order: 1, question_type: 'mcq',
            question_text: 'Which is a prime number?', marks: '2.00',
            options: [{ id: 9, text: 'Nine' }, { id: 7, text: 'Seven' }, { id: 4, text: 'Four' }],
            selected_option_id: 7, essay_text: null,
        },
    ];
}

function draw(questions = paper()) {
    const doc  = fakeDoc();
    const list = doc.createElement('div');
    const nav  = doc.createElement('div');
    renderPaper(doc, list, nav, questions);
    return { doc, list, nav };
}

// ---- Drawing ---------------------------------------------------------------

test('questions are drawn in display order with their numbers and marks', () => {
    const { list } = draw();
    const cards = findAll(list, byClass('question-card'));

    assert.deepEqual(cards.map((c) => c.attrs['data-qnum']), ['1', '2']);
    assert.deepEqual(cards.map((c) => c.attrs['data-question-id']), ['50', '70']);
    assert.deepEqual(cards.map((c) => c.attrs.id), ['q1', 'q2']);
    assert.equal(findAll(cards[0], byClass('qmeta__num'))[0].textContent, 'Question 1');
    assert.equal(findAll(cards[0], byClass('qmeta__marks'))[0].textContent, 'Marked out of 2.00');
    assert.equal(findAll(cards[1], byClass('question-card__text'))[0].textContent,
        'Explain photosynthesis.\nUse two paragraphs.');
});

test('an MCQ keeps the server\'s option order, lettered, with the saved choice checked', () => {
    const { list } = draw();
    const radios = findAll(list, byTag('input'));

    assert.deepEqual(radios.map((r) => r.attrs.value), ['9', '7', '4']);
    radios.forEach((r) => {
        assert.equal(r.attrs.type, 'radio');
        assert.equal(r.attrs.name, 'answer[50]');
        assert.equal(r.attrs['data-question'], '50');
    });
    assert.deepEqual(radios.map((r) => 'checked' in r.attrs), [false, true, false]);
    assert.deepEqual(findAll(list, byClass('opt__letter')).map((n) => n.textContent), ['a.', 'b.', 'c.']);
    assert.deepEqual(findAll(list, byClass('opt__text')).map((n) => n.textContent), ['Nine', 'Seven', 'Four']);
});

test('an essay box carries its saved text and every stage 1 attribute', () => {
    const { list } = draw();
    const [area] = findAll(list, byTag('textarea'));

    assert.equal(area.textContent, 'Plants make food.');
    assert.equal(area.attrs.name, 'answer[70]');
    assert.equal(area.attrs['data-question'], '70');
    assert.equal(area.attrs.spellcheck, 'false');
    assert.equal(area.attrs.autocomplete, 'off');
    assert.equal(area.attrs.autocorrect, 'off');
    assert.equal(area.attrs.autocapitalize, 'off');
});

test('an essay never answered is an empty box, not the word null', () => {
    const q = paper()[0];
    q.essay_text = null;
    const { list } = draw([q]);
    assert.equal(findAll(list, byTag('textarea'))[0].textContent, '');
});

test('the flag button ends in the text node the flag code rewrites', () => {
    const { list } = draw();
    const [flag] = findAll(list, byClass('qmeta__flag'));
    assert.equal(flag.attrs['data-flag'], '1');
    assert.equal(flag.attrs.type, 'button');
    const last = flag.children[flag.children.length - 1];
    assert.equal(last.nodeType, 3);
    assert.equal(last.textContent, ' Flag question');
});

test('markup in the question bank is shown as text, never parsed', () => {
    const hostile = '<img src=x onerror="alert(1)"> & <b>bold</b>';
    const q = paper()[1];
    q.question_text = hostile;
    q.options = [{ id: 1, text: '</label><script>alert(2)</script>' }];
    q.marks = '<i>5</i>';

    // The fake document throws on innerHTML, so drawing at all is half the test.
    const { list } = draw([q]);

    assert.equal(findAll(list, byClass('question-card__text'))[0].textContent, hostile);
    assert.equal(findAll(list, byClass('opt__text'))[0].textContent, '</label><script>alert(2)</script>');
    assert.equal(findAll(list, byClass('qmeta__marks'))[0].textContent, 'Marked out of <i>5</i>');
    assert.equal(findAll(list, byTag('img')).length, 0);
    assert.equal(findAll(list, byTag('script')).length, 0);
});

test('one navigation box per question, pointing at its card', () => {
    const { nav } = draw();
    const boxes = findAll(nav, byClass('qnav__box'));
    assert.deepEqual(boxes.map((b) => b.attrs['data-nav']), ['1', '2']);
    assert.deepEqual(boxes.map((b) => b.attrs.href), ['#q1', '#q2']);
    assert.deepEqual(boxes.map((b) => b.textContent), ['1', '2']);
});

test('everything lands in the containers passed in, nothing on the body', () => {
    const { doc, list, nav } = draw();
    assert.equal(doc.body.children.length, 0);
    assert.equal(list.children.length, 2);
    assert.equal(nav.children.length, 2);
});

test('an answer counts as already saved when a choice or non-blank essay is held', () => {
    const qs = [
        { question_id: 1, selected_option_id: 3,    essay_text: null },
        { question_id: 2, selected_option_id: null, essay_text: 'An answer' },
        { question_id: 3, selected_option_id: null, essay_text: '   ' },
        { question_id: 4, selected_option_id: null, essay_text: null },
    ];
    assert.deepEqual([...savedQuestionIds(qs)].sort(), [1, 2]);
});

// ---- The gate --------------------------------------------------------------

/** Every dependency openPaper takes, recording calls, with overrides. */
function gate(overrides = {}) {
    const calls = [];
    const deps = {
        requestFullscreen: async () => { calls.push('fullscreen'); },
        fullscreenElement: () => ({}),
        loadPaper: async () => { calls.push('load'); return { status: 200, body: { ok: true, remaining: 60, questions: [] } }; },
        onPaper: () => calls.push('paper'),
        onRefused: () => calls.push('refused'),
        onNetworkFailure: () => calls.push('retry'),
        reload: () => calls.push('reload'),
        ...overrides,
    };
    return { deps, calls };
}

test('the paper is not requested until fullscreen has actually been granted', async () => {
    let grant;
    const granted = new Promise((resolve) => { grant = resolve; });
    const { deps, calls } = gate({
        requestFullscreen: () => { calls.push('fullscreen'); return granted; },
    });

    const done = openPaper(deps);
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(calls, ['fullscreen'], 'the paper was requested before fullscreen was granted');

    grant();
    assert.equal(await done, 'ok');
    assert.deepEqual(calls, ['fullscreen', 'load', 'paper']);
});

test('a refused fullscreen asks again and never requests the paper', async () => {
    const { deps, calls } = gate({ requestFullscreen: async () => { throw new Error('denied'); } });
    assert.equal(await openPaper(deps), 'refused');
    assert.deepEqual(calls, ['refused']);
});

test('fullscreen granted but not in force still does not request the paper', async () => {
    const { deps, calls } = gate({ fullscreenElement: () => null });
    assert.equal(await openPaper(deps), 'refused');
    assert.deepEqual(calls, ['fullscreen', 'refused']);
});

test('no answer from the network offers Retry and draws nothing', async () => {
    const { deps, calls } = gate({
        loadPaper: async () => { calls.push('load'); throw new TypeError('Failed to fetch'); },
    });
    assert.equal(await openPaper(deps), 'retry');
    assert.deepEqual(calls, ['fullscreen', 'load', 'retry']);
});

test('a server error that still answers in JSON offers Retry', async () => {
    const { deps, calls } = gate({
        loadPaper: async () => ({ status: 500, body: { ok: false, error: 'server' } }),
    });
    assert.equal(await openPaper(deps), 'retry');
    assert.deepEqual(calls, ['fullscreen', 'retry']);
});

test('a response that is not JSON reloads the page instead of offering Retry', async () => {
    // An expired session: fetch followed the redirect to the login page.
    const { deps, calls } = gate({ loadPaper: async () => ({ status: 200, body: null }) });
    assert.equal(await openPaper(deps), 'reload');
    assert.deepEqual(calls, ['fullscreen', 'reload']);
});

test('a dead CSRF token reloads the page instead of offering Retry', async () => {
    const { deps, calls } = gate({
        loadPaper: async () => ({ status: 403, body: { ok: false, error: 'csrf' } }),
    });
    assert.equal(await openPaper(deps), 'reload');
    assert.deepEqual(calls, ['fullscreen', 'reload']);
});

test('every stale-page answer reloads, and only real server failures retry', () => {
    const cases = [
        [{ status: 200, body: { ok: true, questions: [] } },          'ok'],
        [{ status: 200, body: null },                                 'reload'],   // login page
        [{ status: 500, body: null },                                 'reload'],   // HTML error page
        [{ status: 401, body: { ok: false } },                        'reload'],
        [{ status: 403, body: { ok: false, error: 'csrf' } },         'reload'],
        [{ status: 404, body: { ok: false, error: 'not_found' } },    'reload'],
        [{ status: 409, body: { ok: false, error: 'closed' } },       'reload'],
        [{ status: 200, body: { ok: false } },                        'reload'],
        [{ status: 200, body: { ok: true } },                         'reload'],   // no questions
        [{ status: 500, body: { ok: false } },                        'retry'],
        [{ status: 503, body: { ok: false } },                        'retry'],
    ];
    cases.forEach(([res, expected]) => {
        assert.equal(paperOutcome(res), expected, JSON.stringify(res));
    });
});
