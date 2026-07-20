<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam in progress — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 760px; margin: 0 auto; padding: 20px;">

        <div id="exam-bar" style="position:sticky; top:0; background:#fff; padding:14px 18px;
             border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.08);
             display:flex; justify-content:space-between; align-items:center; z-index:10;">
            <div>
                <strong><?= htmlspecialchars($attempt['course_code']) ?></strong> —
                <?= htmlspecialchars($attempt['exam_title']) ?>
            </div>
            <div id="timer" style="font-size:1.2rem; font-weight:700; font-variant-numeric:tabular-nums;">
                --:--
            </div>
        </div>

        <?php if (!empty($attempt['instructions'])): ?>
        <div style="background:#fff8e1; border:1px solid #ffe082; border-radius:8px;
                    padding:12px 16px; margin-top:14px; font-size:.9rem;">
            <?= nl2br(htmlspecialchars($attempt['instructions'])) ?>
        </div>
        <?php endif; ?>

        <form id="exam-form" method="POST"
              action="<?= BASE_URL ?>student/submitExam/<?= (int) $attempt['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <?php foreach ($questions as $i => $q): ?>
            <div class="question-card" style="background:#fff; border:1px solid #eef0f2;
                 border-radius:8px; padding:18px 20px; margin-top:16px;">
                <div style="display:flex; justify-content:space-between; font-size:.82rem; color:#65676b;">
                    <span>Question <?= (int) $q['display_order'] ?></span>
                    <span><?= htmlspecialchars($q['marks']) ?> mark(s)</span>
                </div>

                <p style="margin-top:8px; font-size:1.02rem; line-height:1.5;">
                    <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                </p>

                <?php if ($q['question_type'] === 'mcq'): ?>
                    <div style="margin-top:12px;">
                        <?php foreach ($q['options'] as $opt): ?>
                        <label style="display:flex; gap:10px; align-items:flex-start;
                                      padding:10px 12px; margin-top:6px; border:1px solid #eef0f2;
                                      border-radius:6px; cursor:pointer; font-weight:normal;">
                            <input type="radio"
                                   name="answer[<?= (int) $q['question_id'] ?>]"
                                   value="<?= (int) $opt['id'] ?>"
                                   style="width:auto; margin-top:3px;"
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
                              style="margin-top:12px;"><?= htmlspecialchars($q['essay_text'] ?? '') ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <button type="submit" style="margin-top:20px;">Submit exam</button>
        </form>
    </div>

    <script>
        // Server is the source of truth: this many seconds remained at page load.
        const remaining = <?= (int) $remaining ?>;
        const deadline = Date.now() + remaining * 1000;
        const timerEl = document.getElementById('timer');

        function tick() {
            const secs = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            const m = String(Math.floor(secs / 60)).padStart(2, '0');
            const s = String(secs % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;

            if (secs <= 60) timerEl.style.color = '#dc3545';   // last minute: red

            if (secs <= 0) {
                // Time's up — submit whatever's there
                document.getElementById('exam-form').submit();
            }
        }

        tick();
        setInterval(tick, 1000);
    </script>
</body>
</html>