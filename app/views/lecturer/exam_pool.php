<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Pool · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">&larr; Exams</a>

    <div class="page__head">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($course['course_code']) ?></p>
            <h1 class="page__title"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="page__lead">Question pool</p>
        </div>
    </div>

    <div class="grid grid--3">
        <div class="stat">
            <span class="stat__label">Status</span>
            <span class="stat__value stat__value--text"><?= htmlspecialchars($exam['status']) ?></span>
        </div>
        <div class="stat">
            <span class="stat__label">Drawn per attempt</span>
            <span class="stat__value"><?= (int) $exam['questions_per_attempt'] ?></span>
        </div>
        <div class="stat<?= count($poolIds) >= (int) $exam['questions_per_attempt'] ? ' stat--ok' : ' stat--danger' ?>">
            <span class="stat__label">In pool</span>
            <span class="stat__value"><?= count($poolIds) ?></span>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error stack-md">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($questions)): ?>
        <div class="empty">
            <p>The question bank is empty.</p>
            <p class="small stack-sm">
                <a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">Add questions</a> before building a pool.
            </p>
        </div>

    <?php elseif ($exam['status'] !== 'draft'): ?>
        <div class="alert alert--info stack-md">
            This exam is <?= htmlspecialchars($exam['status']) ?>, so the pool is locked. Attempts
            already in flight keep grading against the questions they were drawn from.
        </div>

        <div class="picklist">
            <?php foreach ($questions as $q): ?>
                <?php if (in_array((int) $q['id'], $poolIds, true)): ?>
                <div class="picklist__row">
                    <span class="tag"><?= htmlspecialchars(strtoupper($q['question_type'])) ?></span>
                    <span><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?></span>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>lecturer/savePool/<?= (int) $course['id'] ?>/<?= (int) $exam['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <h2 class="section">Choose the questions</h2>

            <div class="picklist">
                <?php foreach ($questions as $q): ?>
                <label class="picklist__row picklist__row--pick">
                    <input type="checkbox" name="question_ids[]"
                           value="<?= (int) $q['id'] ?>"
                           <?= in_array((int) $q['id'], $poolIds, true) ? 'checked' : '' ?>>
                    <span class="tag"><?= htmlspecialchars(strtoupper($q['question_type'])) ?></span>
                    <span class="mono small"><?= htmlspecialchars($q['marks']) ?> mk</span>
                    <span><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--secondary">Save pool</button>
            </div>
        </form>

        <hr class="divider">

        <form method="POST"
              action="<?= BASE_URL ?>lecturer/publishExam/<?= (int) $course['id'] ?>/<?= (int) $exam['id'] ?>"
              onsubmit="return confirm('Publish this exam? The pool will be locked and students will be able to start it during the window.');">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <p class="small muted">Publishing locks the pool and opens the exam for its window.</p>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Publish exam</button>
            </div>
        </form>
    <?php endif; ?>

</main>
</body>
</html>
