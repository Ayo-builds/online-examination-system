/**
 * The drawn paper, in a real browser: is question text that arrives by fetch
 * actually unselectable?
 *
 *   node --test tests/browser_render_test.mjs
 *
 * The stage 1 selection rule is `.quiz-page { user-select: none }`. A paper
 * drawn anywhere outside that container would be selectable again, and no
 * stand-in document can compute CSS. So this builds a page from the real
 * style.css, the real clipboard guard and the real renderer, draws a paper
 * into it with headless Chrome, and reads back what the browser computed.
 *
 * No server and no port: the page is a file, with the modules inlined, and
 * Chrome prints the finished DOM and exits.
 *
 * It needs Chrome or Edge. Without one it FAILS and says so, rather than
 * skipping: a skipped check that reports green is the vacuity this suite
 * exists to avoid. Set CHROME_PATH to use a browser somewhere else.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

function findBrowser() {
    const candidates = [
        process.env.CHROME_PATH,
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
    ];
    return candidates.find((p) => p && existsSync(p)) || null;
}

/** A module's source with its exports turned into plain declarations. */
function inline(relPath) {
    return readFileSync(join(ROOT, relPath), 'utf8').replace(/^export /gm, '');
}

function harness() {
    const css = readFileSync(join(ROOT, 'public/assets/css/style.css'), 'utf8');

    // What the page does once the paper arrives, and what it then measures.
    // The control paragraph sits OUTSIDE .quiz-page: it must come back
    // selectable, which proves the probe can tell the difference at all.
    const probe = `
        installClipboardGuard(document);

        const hostile = '<img src=x onerror="window.__xss = 1"> & friends';
        renderPaper(document, document.getElementById('question-list'), document.getElementById('qnav-grid'), [
            { question_id: 1, display_order: 1, question_type: 'mcq', question_text: 'Line one\\nLine two',
              marks: '2.00', options: [{ id: 5, text: 'Option text' }], selected_option_id: null, essay_text: null },
            { question_id: 2, display_order: 2, question_type: 'essay', question_text: hostile,
              marks: '5.00', options: [], selected_option_id: null, essay_text: 'Draft' },
        ]);

        const q    = (sel) => document.querySelector(sel);
        const us   = (el) => getComputedStyle(el).userSelect;
        const text = q('.question-card__text');

        const r = {
            questionText:   us(text),
            optionText:     us(q('.opt__text')),
            questionNumber: us(q('.qmeta__num')),
            navBox:         us(q('.qnav__box')),
            essay:          us(q('textarea')),
            control:        us(q('#control')),
            whiteSpace:     getComputedStyle(text).whiteSpace,
            lineBreakKept:  text.textContent === 'Line one\\nLine two',
            hostileIsText:  document.querySelectorAll('.question-card__text')[1].textContent === hostile,
            imgCreated:     document.querySelectorAll('#question-list img').length,
            // A drag over question text starts a selection on its text node.
            selectOnText:   !text.firstChild.dispatchEvent(new Event('selectstart', { bubbles: true, cancelable: true })),
            selectInEssay:  !q('textarea').dispatchEvent(new Event('selectstart', { bubbles: true, cancelable: true })),
        };

        const json = JSON.stringify(r);
        document.getElementById('out').textContent = 'RESULT:' + btoa(unescape(encodeURIComponent(json))) + ':END';
    `;

    return `<!doctype html><html lang="en"><head><meta charset="utf-8">
<style>${css}</style></head>
<body>
  <p id="control">Outside the quiz container</p>
  <div class="quiz-page">
    <main class="quiz"><div id="question-list"></div><div id="qnav-grid"></div></main>
  </div>
  <pre id="out"></pre>
  <script>
${inline('public/assets/js/clipboard-guard.js')}
${inline('public/assets/js/paper-render.js')}
${probe}
  </script>
</body></html>`;
}

function runInBrowser(browser, html) {
    const dir = mkdtempSync(join(tmpdir(), 'exam-browser-'));
    try {
        const page = join(dir, 'harness.html');
        writeFileSync(page, html);

        const run = spawnSync(browser, [
            '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--user-data-dir=' + join(dir, 'profile'),
            '--dump-dom', pathToFileURL(page).href,
        ], { encoding: 'utf8', timeout: 60000 });

        const m = /RESULT:([A-Za-z0-9+/=]+):END/.exec(run.stdout || '');
        if (!m) {
            throw new Error('the browser produced no result.\n--- stdout ---\n'
                + (run.stdout || '').slice(0, 2000) + '\n--- stderr ---\n' + (run.stderr || '').slice(0, 2000));
        }
        return JSON.parse(Buffer.from(m[1], 'base64').toString('utf8'));
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}

test('question text that arrives by fetch cannot be selected', () => {
    const browser = findBrowser();
    assert.ok(browser, 'No Chrome or Edge found. Install one, or set CHROME_PATH. This test does not skip.');

    const r = runInBrowser(browser, harness());

    // The probe can see selectable text: otherwise every "none" below is vacuous.
    assert.equal(r.control, 'auto', 'the control outside .quiz-page should be selectable');

    assert.equal(r.questionText,   'none', 'question text is selectable');
    assert.equal(r.optionText,     'none', 'option text is selectable');
    assert.equal(r.questionNumber, 'none', 'question number is selectable');
    assert.equal(r.navBox,         'none', 'navigation box is selectable');
    assert.equal(r.selectOnText,   true,   'a selection starting on question text was not cancelled');

    assert.equal(r.essay,          'text', 'the essay box must stay selectable for editing');
    assert.equal(r.selectInEssay,  false,  'a selection inside the essay box was cancelled');
});

test('the drawn paper keeps line breaks and shows markup as text in a real browser', () => {
    const browser = findBrowser();
    assert.ok(browser, 'No Chrome or Edge found. Install one, or set CHROME_PATH. This test does not skip.');

    const r = runInBrowser(browser, harness());

    assert.equal(r.whiteSpace, 'pre-line');
    assert.equal(r.lineBreakKept, true);
    assert.equal(r.hostileIsText, true);
    assert.equal(r.imgCreated, 0);
});
