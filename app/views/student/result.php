<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = htmlspecialchars($attempt['exam_title']);
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="card center">
        <?php if ($attempt['grading_status'] === 'partial'): ?>

            <p class="stat__label">MCQ score so far</p>
            <p class="score"><?= htmlspecialchars($attempt['total_score']) ?></p>

            <div class="alert alert--warn stack-md">
                This exam has essay questions awaiting grading by your lecturer.
                Your final score will be available once grading is complete.
            </div>

        <?php else: ?>
            <?php
                $score = (float) $attempt['total_score'];
                $pass  = (float) $exam['pass_mark'];
                // pass_mark is a %, so compare against percentage. But we need max marks.
            ?>
            <p class="stat__label">Your score</p>
            <p class="score"><?= htmlspecialchars($attempt['total_score']) ?></p>

            <p class="card__meta">
                <?php if ($attempt['status'] === 'auto_submitted'): ?>
                    <span class="tag tag--warn">Auto-submitted</span>
                    <span class="small">time expired</span>
                <?php else: ?>
                    <span class="tag tag--ok">Submitted</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <p class="small muted center stack-md">
        Submitted <?= htmlspecialchars($attempt['submitted_at']) ?>
    </p>

</main>
</body>
</html>
