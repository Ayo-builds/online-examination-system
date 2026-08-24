<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam in progress · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <main class="exam-shell">

        <div id="exam-bar">
            <div class="exam-bar__id">
                <span class="exam-bar__code"><?= htmlspecialchars($attempt['course_code']) ?></span>
                <span class="exam-bar__title"><?= htmlspecialchars($attempt['exam_title']) ?></span>

                <button type="button" id="fs-btn"
                    onclick="document.documentElement.requestFullscreen && document.documentElement.requestFullscreen()">
                Fullscreen
            </button>
            </div>

            <div id="timer">
                --:--
            </div>
        </div>

        <?php if (!empty($attempt['instructions'])): ?>
        <div class="exam-instructions">
            <?= nl2br(htmlspecialchars($attempt['instructions'])) ?>
        </div>
        <?php endif; ?>

        <form id="exam-form" method="POST"
              action="<?= BASE_URL ?>student/submitExam/<?= (int) $attempt['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <?php foreach ($questions as $i => $q): ?>
            <div class="question-card">
                <?php /* Must remain the FIRST child div: flashSaved() appends the saved tag here. */ ?>
                <div class="question-card__meta">
                    <span>Question <?= (int) $q['display_order'] ?></span>
                    <span><?= htmlspecialchars($q['marks']) ?> mark(s)</span>
                </div>

                <p class="question-card__text">
                    <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                </p>

                <?php if ($q['question_type'] === 'mcq'): ?>
                    <div class="opts">
                        <?php foreach ($q['options'] as $opt): ?>
                        <label class="opt">
                            <input type="radio"
                                   name="answer[<?= (int) $q['question_id'] ?>]"
                                   value="<?= (int) $opt['id'] ?>"
                                   data-question="<?= (int) $q['question_id'] ?>"
                                   <?= (int) $q['selected_option_id'] === (int) $opt['id'] ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($opt['text']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <textarea name="answer[<?= (int) $q['question_id'] ?>]"
                              data-question="<?= (int) $q['question_id'] ?>"
                              rows="6" placeholder="Type your answer…"
                              class="essay-input"><?= htmlspecialchars($q['essay_text'] ?? '') ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn--primary exam-submit">Submit exam</button>
        </form>
    </main>

   <script>
        const remaining = <?= (int) $remaining ?>;
        const deadline  = Date.now() + remaining * 1000;
        const timerEl   = document.getElementById('timer');
        const form      = document.getElementById('exam-form');
        const csrf      = form.querySelector('input[name="csrf_token"]').value;
        const saveUrl   = '<?= BASE_URL ?>student/saveAnswer/<?= (int) $attempt['id'] ?>';

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
        async function saveAnswer(questionId, optionId, essayText) {
            const body = new URLSearchParams();
            body.append('csrf_token', csrf);
            body.append('question_id', questionId);
            if (optionId !== null)  body.append('option_id', optionId);
            if (essayText !== null) body.append('essay_text', essayText);

            try {
                const res = await fetch(saveUrl, { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    flashSaved(questionId);
                } else if (data.error === 'closed') {
                    form.submit();   // deadline passed server-side, so submit now
                }
            } catch (e) {
                // Network blip. The answer stays in the DOM; next change retries.
            }
        }

        function flashSaved(questionId) {
            const card = document.querySelector('[data-question="' + questionId + '"]')
                             ?.closest('.question-card');
            if (!card) return;
            let tag = card.querySelector('.saved-tag');
            if (!tag) {
                tag = document.createElement('span');
                tag.className = 'saved-tag';
                card.querySelector('div').appendChild(tag);
            }
            tag.textContent = '✓ saved';
        }

        // MCQ radios: save immediately on change
        document.querySelectorAll('input[type=radio][data-question]').forEach(r => {
            r.addEventListener('change', () => {
                saveAnswer(r.dataset.question, r.value, null);
            });
        });

        // Essays: debounce, saving ~1s after typing stops
        document.querySelectorAll('textarea[data-question]').forEach(t => {
            let timer = null;
            t.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    saveAnswer(t.dataset.question, null, t.value);
                }, 1000);
            });
        });


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