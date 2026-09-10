<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam in progress · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="quiz-page">
    <main class="quiz">

        <header class="quiz__head">
            <span class="quiz__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                    <path d="M7 8.5l1.5 1.5L11 7"></path>
                    <path d="M7 15.5L8.5 17 11 14"></path>
                    <path d="M14 9h4M14 16h4"></path>
                </svg>
            </span>
            <h1 class="quiz__title">
                <?= htmlspecialchars($attempt['course_code']) ?> &ndash; <?= htmlspecialchars($attempt['exam_title']) ?>
            </h1>
        </header>

        <?php if (!empty($attempt['instructions'])): ?>
        <div class="quiz__instructions">
            <?= nl2br(htmlspecialchars($attempt['instructions'])) ?>
        </div>
        <?php endif; ?>

        <div class="quiz__body">

            <div class="quiz__panel">

                <?php /* Answers the browser still has outstanding at submit time.
                         Kept current by the save queue. It never blocks the
                         submission: a failed save must not become a zero. */ ?>
                <div id="save-banner" class="savebar" role="status" aria-live="polite" hidden></div>

                <form id="exam-form" method="POST"
                      action="<?= BASE_URL ?>student/submitExam/<?= (int) $attempt['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                    <input type="hidden" name="unsaved_count" id="unsaved-count" value="0">

                    <div id="quiz-questions">

                    <a class="qbtn qbtn--ghost" href="<?= BASE_URL ?>student/dashboard">Back</a>

                    <?php foreach ($questions as $q): ?>
                    <?php $qid = (int) $q['question_id']; $num = (int) $q['display_order']; ?>
                    <div class="question-card" id="q<?= $num ?>"
                         data-question-id="<?= $qid ?>" data-qnum="<?= $num ?>">

                        <div class="question-card__meta">
                            <p class="qmeta__num">Question <b><?= $num ?></b></p>
                            <?php /* Written ONLY from what the server said about this
                                     answer. See public/assets/js/save-status.js. */ ?>
                            <p class="qmeta__state qmeta__state--clean">Not yet answered</p>
                            <p class="qmeta__marks">Marked out of <?= htmlspecialchars($q['marks']) ?></p>
                            <button type="button" class="qmeta__flag"
                                    data-flag="<?= $num ?>" aria-pressed="false">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>
                                    <line x1="4" y1="22" x2="4" y2="15"></line>
                                </svg>
                                Flag question
                            </button>
                        </div>

                        <div class="question-card__body">
                            <p class="question-card__text">
                                <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                            </p>

                            <?php if ($q['question_type'] === 'mcq'): ?>
                                <p class="qbody__select">Select one:</p>
                                <div class="opts">
                                    <?php foreach ($q['options'] as $i => $opt): ?>
                                    <label class="opt">
                                        <input type="radio"
                                               name="answer[<?= $qid ?>]"
                                               value="<?= (int) $opt['id'] ?>"
                                               data-question="<?= $qid ?>"
                                               <?= (int) $q['selected_option_id'] === (int) $opt['id'] ? 'checked' : '' ?>>
                                        <span class="opt__letter"><?= chr(97 + $i) ?>.</span>
                                        <span class="opt__text"><?= htmlspecialchars($opt['text']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <textarea name="answer[<?= $qid ?>]"
                                          data-question="<?= $qid ?>"
                                          rows="8" placeholder="Type your answer&hellip;"
                                          class="essay-input"><?= htmlspecialchars($q['essay_text'] ?? '') ?></textarea>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>

                    <div class="quiz__finish">
                        <button type="button" id="to-summary" class="qbtn qbtn--primary">Finish attempt &hellip;</button>
                    </div>

                    </div><!-- /#quiz-questions -->

                    <?php /* The step between "Finish attempt" and the real submission.
                             Built from this page rather than a round trip, so no new
                             route is needed. The answers stay inside the form the whole
                             time: hidden, but still submitted. */ ?>
                    <section id="quiz-summary" class="quiz__summary" hidden>

                        <button type="button" class="qbtn qbtn--ghost js-return">Back</button>

                        <h2 class="quiz__title">
                            <?= htmlspecialchars($attempt['course_code']) ?> &ndash; <?= htmlspecialchars($attempt['exam_title']) ?>
                        </h2>
                        <h2>Summary of attempt</h2>

                        <table class="summary-table">
                            <thead>
                                <tr><th class="sum-q">Question</th><th>Status</th></tr>
                            </thead>
                            <tbody id="summary-rows"></tbody>
                        </table>

                        <div class="quiz__summary-actions">
                            <button type="button" class="qbtn qbtn--ghost js-return">Return to attempt</button>
                            <button type="submit" class="qbtn qbtn--primary">Submit all and finish</button>
                        </div>
                    </section>
                </form>
            </div>

            <aside class="quiz__nav" aria-label="Quiz navigation">
                <h2>Quiz navigation</h2>

                <div class="qnav__grid">
                    <?php foreach ($questions as $q): ?>
                    <?php $n = (int) $q['display_order']; ?>
                    <a class="qnav__box" href="#q<?= $n ?>" data-nav="<?= $n ?>"><?= $n ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="qnav__meta">
                    <p class="qnav__label">Time remaining</p>
                    <div id="timer">--:--</div>

                    <button type="button" id="fs-btn"
                        onclick="document.documentElement.requestFullscreen &amp;&amp; document.documentElement.requestFullscreen()">
                        Enter fullscreen
                    </button>
                </div>
            </aside>

        </div>
    </main>

   <script type="module">
        import { createSaveQueue } from '<?= BASE_URL ?>assets/js/save-queue.js';
        import { renderQuestionState, renderBanner, LABELS } from '<?= BASE_URL ?>assets/js/save-status.js';

        const remaining = <?= (int) $remaining ?>;
        const deadline  = Date.now() + remaining * 1000;
        const timerEl   = document.getElementById('timer');
        const form      = document.getElementById('exam-form');
        const bannerEl  = document.getElementById('save-banner');
        const countEl   = document.getElementById('unsaved-count');
        const saveUrl   = '<?= BASE_URL ?>student/saveAnswer/<?= (int) $attempt['id'] ?>';
        let   csrf      = form.querySelector('input[name="csrf_token"]').value;

        // Answers already on the server when this page was rendered. On a
        // resume these came back out of the database, so they are genuinely
        // saved and may honestly say so before anything is typed.
        const initialSaved = new Set(<?= json_encode(array_values(array_map(
            fn($q) => (int) $q['question_id'],
            array_filter(
                $questions,
                fn($q) => $q['selected_option_id'] !== null
                       || ($q['essay_text'] !== null && trim($q['essay_text']) !== '')
            )
        ))) ?>);

        // ---- Timer ----
        function tick() {
            const secs = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            const m = String(Math.floor(secs / 60)).padStart(2, '0');
            const s = String(secs % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;
            if (secs <= 60) timerEl.classList.add('timer--urgent');
            if (secs <= 0) form.submit();
        }
        tick();
        setInterval(tick, 1000);

        // ---- Auto-save ----
        //
        // The queue owns retries, the debounce ceiling and the per-question
        // state. This file only carries values into it and paints what comes
        // back out. Nothing below may say an answer is saved on its own
        // authority; only a 200 from the server produces that word.

        // The queue keys by question id, but a student thinks in the numbers
        // printed on the paper, so the banner has to translate.
        const numById = new Map();
        document.querySelectorAll('.question-card').forEach(card => {
            numById.set(Number(card.dataset.questionId), Number(card.dataset.qnum));
        });

        let sessionExpired = false;

        async function transport(payload, opts) {
            const body = new URLSearchParams();
            Object.keys(payload).forEach(k => {
                if (payload[k] !== null && payload[k] !== undefined) body.append(k, payload[k]);
            });

            // keepalive is set by flush() on blur and visibilitychange, so the
            // request can outlive a page that is about to stop running.
            const res = await fetch(saveUrl, {
                method: 'POST',
                body,
                keepalive: !!(opts && opts.keepalive),
            });

            // A dead session can be answered by a redirect to the login page,
            // which is HTML, not JSON. Treat an unparseable body as no body
            // rather than letting the throw become a silent network failure.
            let parsed = null;
            try { parsed = await res.json(); } catch (e) { parsed = null; }

            return { status: res.status, body: parsed };
        }

        const queue = createSaveQueue({
            transport,
            onChange: paint,
            onClosed: () => form.submit(),   // server deadline passed
            onCsrf: () => { sessionExpired = true; paint(); },
        });

        // What a question's status line should say right now: the queue's view
        // once it has one, and otherwise whether the server had an answer when
        // this page was built.
        function displayState(qid) {
            const s = queue.stateOf(qid);
            if (s !== queue.STATES.CLEAN) return s;
            return initialSaved.has(qid) ? queue.STATES.SAVED : queue.STATES.CLEAN;
        }

        function paint() {
            document.querySelectorAll('.question-card').forEach(card => {
                const qid   = Number(card.dataset.questionId);
                const num   = card.dataset.qnum;
                const state = displayState(qid);

                renderQuestionState(card.querySelector('.qmeta__state'), state);

                const box = document.querySelector('[data-nav="' + num + '"]');
                if (box) {
                    box.classList.toggle('qnav__box--answered', state === queue.STATES.SAVED);
                    box.classList.toggle('qnav__box--unsaved',  state === queue.STATES.UNSAVED);
                }
            });

            const outstanding = queue.unsavedQuestions().map(qid => numById.get(qid) || qid);

            renderBanner(bannerEl, outstanding, { sessionExpired });
            countEl.value = String(outstanding.length);

            if (sessionExpired) ensureRetryButton();
        }

        // Offered only when the session has died, because that is the one
        // failure the queue cannot retry its way out of on its own.
        function ensureRetryButton() {
            if (bannerEl.querySelector('.js-retry')) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qbtn qbtn--ghost js-retry';
            btn.textContent = 'Retry';
            btn.addEventListener('click', async () => {
                // Pick up whatever token the restored session now issues.
                try {
                    const res = await fetch('<?= BASE_URL ?>student/sessionToken', { cache: 'no-store' });
                    const data = await res.json();
                    if (data && data.ok && data.csrf_token) {
                        csrf = data.csrf_token;
                        form.querySelector('input[name="csrf_token"]').value = csrf;
                        sessionExpired = false;
                        queue.resumeAfterCsrf(csrf);
                        paint();
                    }
                } catch (e) {
                    // Still down. The banner stays up and nothing is lost.
                }
            });
            bannerEl.appendChild(btn);
        }

        // MCQ radios: save immediately on change
        document.querySelectorAll('input[type=radio][data-question]').forEach(r => {
            r.addEventListener('change', () => {
                queue.saveNow(Number(r.dataset.question), {
                    csrf_token:  csrf,
                    question_id: r.dataset.question,
                    option_id:   r.value,
                });
            });
        });

        // Essays: debounced, with the queue's ceiling behind it so steady
        // typing cannot outrun the save the way it used to.
        document.querySelectorAll('textarea[data-question]').forEach(t => {
            t.addEventListener('input', () => {
                queue.saveDebounced(Number(t.dataset.question), {
                    csrf_token:  csrf,
                    question_id: t.dataset.question,
                    essay_text:  t.value,
                });
            });
        });

        // The page may be about to stop running JavaScript: the student has
        // alt-tabbed, or the machine is going down. Send what is outstanding
        // now rather than waiting out a debounce that may never finish.
        window.addEventListener('blur', () => queue.flush());
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) queue.flush();
        });

        paint();

        // ---------- Summary of attempt ----------
        // A view over the same form, not a separate page. Hiding the questions
        // leaves their inputs in the form, so the submission is unchanged.
        const questionsWrap = document.getElementById('quiz-questions');
        const summaryEl     = document.getElementById('quiz-summary');
        const summaryRows   = document.getElementById('summary-rows');

        // The last screen before submitting, so it above all must not overstate
        // what is safe. It reads the same state the status lines do.
        function buildSummary() {
            summaryRows.textContent = '';
            document.querySelectorAll('.question-card').forEach(card => {
                const state = displayState(Number(card.dataset.questionId));

                const tr = document.createElement('tr');
                const q  = document.createElement('td');
                const s  = document.createElement('td');
                q.className = 'sum-q';
                q.textContent = card.dataset.qnum;
                s.textContent = LABELS[state];
                if (state === queue.STATES.UNSAVED) s.className = 'sum-unsaved';
                tr.append(q, s);
                summaryRows.appendChild(tr);
            });
        }

        function showSummary(on) {
            if (on) { buildSummary(); }
            questionsWrap.hidden = on;
            summaryEl.hidden     = !on;
            window.scrollTo(0, 0);
        }

        document.getElementById('to-summary').addEventListener('click', () => showSummary(true));
        document.querySelectorAll('.js-return').forEach(b => {
            b.addEventListener('click', () => showSummary(false));
        });

        // ---------- Flagging ----------
        // Kept on this device only, so a reload does not lose it. It is a reading
        // aid for the candidate and is never transmitted or graded.
        const flagKey = 'exam-flags-<?= (int) $attempt['id'] ?>';
        let flags = [];
        try { flags = JSON.parse(localStorage.getItem(flagKey) || '[]'); } catch (e) { flags = []; }

        function paintFlags() {
            document.querySelectorAll('[data-flag]').forEach(btn => {
                const n  = btn.dataset.flag;
                const on = flags.includes(n);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                btn.lastChild.textContent = on ? ' Remove flag' : ' Flag question';
                const box = document.querySelector('[data-nav="' + n + '"]');
                if (box) box.classList.toggle('qnav__box--flagged', on);
            });
        }
        document.querySelectorAll('[data-flag]').forEach(btn => {
            btn.addEventListener('click', () => {
                const n = btn.dataset.flag;
                flags = flags.includes(n) ? flags.filter(x => x !== n) : flags.concat(n);
                try { localStorage.setItem(flagKey, JSON.stringify(flags)); } catch (e) {}
                paintFlags();
            });
        });
        paintFlags();

      // ---------- Anti-cheat monitor ----------
        const logUrl = '<?= BASE_URL ?>student/logActivity/<?= (int) $attempt['id'] ?>';

        async function logEvent(type) {
            const body = new URLSearchParams();
            body.append('csrf_token', csrf);
            body.append('event_type', type);
            try { await fetch(logUrl, { method: 'POST', body }); } catch (e) {}
        }

        // Tab switch / minimise
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) logEvent('tab_switch');
        });

        // Window loses focus (alt-tab to another app)
        window.addEventListener('blur', () => logEvent('window_blur'));

        // Copy / paste / right-click
        document.addEventListener('copy',  () => logEvent('copy'));
        document.addEventListener('paste', () => logEvent('paste'));
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            logEvent('right_click');
        });

        // Fullscreen: notice if they leave it
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) logEvent('fullscreen_exit');
        });

        // Heartbeat: detect a frozen/backgrounded tab by an oversized interval gap
        let lastPing = Date.now();
        setInterval(() => {
            const gap = Date.now() - lastPing;
            lastPing = Date.now();
            if (gap > 25000) logEvent('heartbeat_gap');
        }, 15000);
    </script>
</body>
</html>
