<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Attempt · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'grading'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = htmlspecialchars($attempt['student_name']);
$page_lead_class = 'small';
ob_start(); ?><?= htmlspecialchars($attempt['course_code']) ?> / <?= htmlspecialchars($attempt['exam_title']) ?> &middot; submitted <?= htmlspecialchars($attempt['submitted_at']) ?><?php $page_lead = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="grid grid--2">
        <div class="stat">
            <span class="stat__label">Score</span>
            <span class="stat__value">
                <?= $attempt['total_score'] === null ? '&mdash;' : htmlspecialchars($attempt['total_score']) ?>
                <span class="stat__unit">/ <?= htmlspecialchars($maxMarks) ?></span>
            </span>
        </div>
        <div class="stat">
            <span class="stat__label">Grading</span>
            <span class="stat__value stat__value--text">
                <?php if ($attempt['grading_status'] === 'complete'): ?>
                    <span class="tag tag--ok">Complete</span>
                <?php else: ?>
                    <span class="tag tag--warn"><?= htmlspecialchars($attempt['grading_status']) ?></span>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php foreach ($answers as $ans): ?>
    <div class="question-card stack-md">
        <div class="question-card__meta">
            <span>Q<?= (int) $ans['display_order'] ?> &middot; <?= htmlspecialchars(strtoupper($ans['question_type'])) ?></span>
            <span><?= htmlspecialchars($ans['marks']) ?> mark(s)</span>
        </div>

        <p class="question-card__text"><?= nl2br(htmlspecialchars($ans['question_text'])) ?></p>

        <?php if ($ans['question_type'] === 'mcq'): ?>
            <div class="picklist">
                <?php foreach ($ans['options'] as $o): ?>
                <?php
                    $isChosen  = (int) $ans['selected_option_id'] === (int) $o['id'];
                    $isCorrect = (int) $o['is_correct'] === 1;
                    $rowClass  = $isCorrect ? ' picklist__row--correct'
                               : ($isChosen ? ' picklist__row--wrong' : '');
                ?>
                <div class="picklist__row<?= $rowClass ?>">
                    <span><?= htmlspecialchars($o['option_text']) ?></span>
                    <?php if ($isCorrect): ?><span class="tag tag--ok">Correct</span><?php endif; ?>
                    <?php if ($isChosen): ?><span class="tag<?= $isCorrect ? ' tag--ok' : ' tag--flag' ?>">Student chose</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="help">
                Auto-awarded: <?= $ans['awarded_marks'] === null ? '0' : htmlspecialchars($ans['awarded_marks']) ?> mark(s)
            </p>

        <?php else: ?>
            <div class="essay-answer">
<?= htmlspecialchars($ans['essay_text'] ?? '') ?: '<em class="muted">No answer submitted</em>' ?>
            </div>

            <form method="POST" action="<?= BASE_URL ?>lecturer/saveEssayGrade/<?= (int) $attempt['id'] ?>"
                  class="award">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="question_id" value="<?= (int) $ans['question_id'] ?>">
                <label for="marks-<?= (int) $ans['question_id'] ?>">Award</label>
                <input type="number" id="marks-<?= (int) $ans['question_id'] ?>" name="marks"
                       step="0.5" min="0" max="<?= htmlspecialchars($ans['marks']) ?>"
                       value="<?= $ans['awarded_marks'] !== null ? htmlspecialchars($ans['awarded_marks']) : '' ?>"
                       required>
                <span class="muted">/ <?= htmlspecialchars($ans['marks']) ?></span>
                <button type="submit" class="btn btn--ok btn--sm">Save grade</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

</main>
</body>
</html>
