<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">&larr; Question bank</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">
                <?= htmlspecialchars(strtoupper($question['question_type'])) ?>
                &middot; <?= htmlspecialchars($question['marks']) ?> mark(s)
            </p>
            <h1 class="page__title">Question</h1>
        </div>
    </div>

    <div class="card">
        <p class="question-card__text">
            <?= nl2br(htmlspecialchars($question['question_text'])) ?>
        </p>
    </div>

    <?php if ($question['question_type'] === 'mcq'): ?>
        <h2 class="section">Options</h2>
        <div class="picklist">
            <?php foreach ($question['options'] as $i => $opt): ?>
            <div class="picklist__row<?= $opt['is_correct'] ? ' picklist__row--correct' : '' ?>">
                <span class="mono"><?= chr(65 + $i) ?></span>
                <span><?= htmlspecialchars($opt['option_text']) ?></span>
                <?php if ($opt['is_correct']): ?>
                    <span class="tag tag--ok">Correct</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr class="divider">

    <?php if ($inUse): ?>
        <div class="alert alert--info">
            This question is used in an exam or has been answered by students, so it cannot be deleted.
            Deleting it would change papers that have already been graded.
        </div>
    <?php else: ?>
        <form method="POST"
              action="<?= BASE_URL ?>lecturer/deleteQuestion/<?= (int) $course['id'] ?>/<?= (int) $question['id'] ?>"
              onsubmit="return confirm('Delete this question permanently?');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <button type="submit" class="btn btn--danger btn--sm">Delete question</button>
        </form>
    <?php endif; ?>

</main>
</body>
</html>
