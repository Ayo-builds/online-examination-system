<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Exams · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php
$page_title = 'My exams';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (empty($exams)): ?>
        <div class="empty">
            <p>No exams available yet.</p>
            <p class="small stack-sm">An exam appears here once a teacher publishes one in a subject you are enrolled in.</p>
        </div>
    <?php else: ?>
        <?php foreach ($exams as $e): ?>
        <?php
            // Display only: the times as stored. Whether the window is open
            // comes from the database's clock (Exam::availableForStudent).
            $startTs    = strtotime($e['window_start']);
            $endTs      = strtotime($e['window_end']);
            $notYetOpen = (int) $e['not_yet_open'] === 1;
            $inWindow   = !$notYetOpen && (int) $e['window_closed'] === 0;
        ?>
        <div class="card">
            <div class="card__head">
                <div>
                    <h2 class="card__title">
                        <span class="mono ok"><?= htmlspecialchars($e['course_code']) ?></span>
                        <span class="muted">/</span>
                        <?= htmlspecialchars($e['title']) ?>
                    </h2>
                    <p class="card__meta">
                        <span class="mono"><?= (int) $e['duration_minutes'] ?></span> minutes ·
                        <span class="mono"><?= (int) $e['questions_per_attempt'] ?></span> questions
                    </p>
                    <p class="card__meta">
                        Open <?= date('j M Y, H:i', $startTs) ?> &rarr; <?= date('j M Y, H:i', $endTs) ?>
                    </p>
                </div>

                <div class="card__actions">
                    <?php if ($e['attempt_status'] === 'in_progress'): ?>
                        <span class="tag tag--warn">In progress</span>
                        <a href="<?= BASE_URL ?>student/exam/<?= (int) $e['attempt_id'] ?>"
                           class="btn btn--primary btn--sm">Continue</a>

                    <?php elseif ($e['attempt_status'] !== null): ?>
                        <span class="tag tag--ok">Submitted</span>
                        <a href="<?= BASE_URL ?>student/result/<?= (int) $e['attempt_id'] ?>"
                           class="btn btn--secondary btn--sm">View result</a>

                    <?php elseif ($notYetOpen): ?>
                        <span class="tag tag--muted">Not open yet</span>

                    <?php elseif (!$inWindow): ?>
                        <span class="tag tag--muted">Window closed</span>

                    <?php else: ?>
                        <?php /* Goes to the instructions page, which is where the
                                 attempt is actually started. startExam still owns
                                 every gate; this link only shows the paper's terms
                                 before the clock begins. */ ?>
                        <a class="btn btn--primary btn--sm"
                           href="<?= BASE_URL ?>student/attempt/<?= (int) $e['id'] ?>">Start exam</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>
</body>
</html>
