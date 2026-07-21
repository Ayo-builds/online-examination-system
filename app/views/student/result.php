<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Result — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 600px; margin: 0 auto; padding: 30px;">
        <p><a href="<?= BASE_URL ?>student/dashboard">&larr; My Exams</a></p>

        <h1><?= htmlspecialchars($attempt['course_code']) ?> — <?= htmlspecialchars($attempt['exam_title']) ?></h1>

        <div style="background:#fff; border:1px solid #eef0f2; border-radius:10px;
                    padding:28px; margin-top:18px; text-align:center;">

            <?php if ($attempt['grading_status'] === 'partial'): ?>
                <p style="font-size:.95rem; color:#65676b;">MCQ score so far</p>
                <p style="font-size:2.4rem; font-weight:700; margin-top:4px;">
                    <?= htmlspecialchars($attempt['total_score']) ?>
                </p>
                <p style="margin-top:14px; padding:10px; background:#fff8e1;
                          border-radius:6px; font-size:.9rem;">
                    This exam has essay questions awaiting grading by your lecturer.
                    Your final score will be available once grading is complete.
                </p>
            <?php else: ?>
                <?php
                    $score = (float) $attempt['total_score'];
                    $pass  = (float) $exam['pass_mark'];
                    // pass_mark is a %, so compare against percentage — but we need max marks.
                ?>
                <p style="font-size:.95rem; color:#65676b;">Your score</p>
                <p style="font-size:2.8rem; font-weight:700; margin-top:4px;">
                    <?= htmlspecialchars($attempt['total_score']) ?>
                </p>
                <p style="margin-top:8px; color:#65676b; font-size:.9rem;">
                    Status:
                    <strong><?= $attempt['status'] === 'auto_submitted' ? 'Auto-submitted (time expired)' : 'Submitted' ?></strong>
                </p>
            <?php endif; ?>
        </div>

        <p style="margin-top:16px; font-size:.85rem; color:#65676b; text-align:center;">
            Submitted <?= htmlspecialchars($attempt['submitted_at']) ?>
        </p>
    </div>
</body>
</html>