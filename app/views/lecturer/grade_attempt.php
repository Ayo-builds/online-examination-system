<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grade Attempt — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 760px; margin: 0 auto; padding: 30px;">
        <p><a href="<?= BASE_URL ?>lecturer/grading">&larr; Grading queue</a></p>

        <h1><?= htmlspecialchars($attempt['student_name']) ?></h1>
        <p style="color:#65676b;">
            <?= htmlspecialchars($attempt['course_code']) ?> — <?= htmlspecialchars($attempt['exam_title']) ?>
            · Submitted <?= htmlspecialchars($attempt['submitted_at']) ?>
        </p>

        <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px;
                    padding:14px 18px; margin-top:14px; display:flex; justify-content:space-between;">
            <span>Score:
                <strong><?= $attempt['total_score'] === null ? '—' : htmlspecialchars($attempt['total_score']) ?></strong>
                / <?= htmlspecialchars($maxMarks) ?>
            </span>
            <span>Status:
                <strong style="color:<?= $attempt['grading_status'] === 'complete' ? '#198754' : '#b26a00' ?>;">
                    <?= htmlspecialchars($attempt['grading_status']) ?>
                </strong>
            </span>
        </div>

        <?php foreach ($answers as $ans): ?>
        <div class="question-card" style="background:#fff; border:1px solid #eef0f2;
             border-radius:8px; padding:18px 20px; margin-top:16px;">
            <div style="display:flex; justify-content:space-between; font-size:.82rem; color:#65676b;">
                <span>Q<?= (int) $ans['display_order'] ?> · <?= htmlspecialchars(strtoupper($ans['question_type'])) ?></span>
                <span><?= htmlspecialchars($ans['marks']) ?> mark(s)</span>
            </div>
            <p style="margin-top:8px; font-size:1rem;"><?= nl2br(htmlspecialchars($ans['question_text'])) ?></p>

            <?php if ($ans['question_type'] === 'mcq'): ?>
                <ul style="margin-top:10px; list-style:none;">
                    <?php foreach ($ans['options'] as $o): ?>
                        <?php
                            $isChosen  = (int) $ans['selected_option_id'] === (int) $o['id'];
                            $isCorrect = (int) $o['is_correct'] === 1;
                        ?>
                        <li style="padding:8px 12px; margin-top:4px; border-radius:6px;
                                   border:1px solid <?= $isCorrect ? '#198754' : '#eef0f2' ?>;
                                   background:<?= $isChosen ? ($isCorrect ? '#e6f4ea' : '#fdecea') : '#fff' ?>;">
                            <?= htmlspecialchars($o['option_text']) ?>
                            <?= $isCorrect ? ' ✓ correct' : '' ?>
                            <?= $isChosen ? ' — student chose this' : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top:8px; font-size:.85rem; color:#65676b;">
                    Auto-awarded: <?= $ans['awarded_marks'] === null ? '0' : htmlspecialchars($ans['awarded_marks']) ?> mark(s)
                </p>

            <?php else: ?>
                <div style="margin-top:10px; padding:12px; background:#f7f8fa;
                            border-radius:6px; white-space:pre-wrap; font-size:.95rem;">
<?= htmlspecialchars($ans['essay_text'] ?? '') ?: '<em style="color:#999;">No answer submitted</em>' ?>
                </div>

                <form method="POST" action="<?= BASE_URL ?>lecturer/saveEssayGrade/<?= (int) $attempt['id'] ?>"
                      style="margin-top:12px; display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                    <input type="hidden" name="question_id" value="<?= (int) $ans['question_id'] ?>">
                    <label style="margin:0;">Award:</label>
                    <input type="number" name="marks" step="0.5" min="0" max="<?= htmlspecialchars($ans['marks']) ?>"
                           value="<?= $ans['awarded_marks'] !== null ? htmlspecialchars($ans['awarded_marks']) : '' ?>"
                           style="width:100px;" required>
                    <span style="color:#65676b;">/ <?= htmlspecialchars($ans['marks']) ?></span>
                    <button type="submit" class="btn-small btn-success">Save grade</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>