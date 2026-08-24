<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($course['course_code']) ?> Questions · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/dashboard">&larr; My courses</a>

    <div class="page__head">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($course['course_code']) ?></p>
            <h1 class="page__title">Question bank</h1>
            <p class="page__lead"><?= htmlspecialchars($course['title']) ?></p>
        </div>
        <div class="page__actions">
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>lecturer/createMcq/<?= (int) $course['id'] ?>">Add MCQ</a>
            <a class="btn btn--secondary btn--sm" href="<?= BASE_URL ?>lecturer/createEssay/<?= (int) $course['id'] ?>">Add essay</a>
        </div>
    </div>

    <?php if (empty($questions)): ?>
        <div class="empty">
            <p>No questions yet.</p>
            <p class="small stack-sm">Add your first one using the buttons above.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Question</th>
                    <th class="right">Marks</th>
                    <th>Added</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $q): ?>
                <tr>
                    <td><span class="tag"><?= htmlspecialchars(strtoupper($q['question_type'])) ?></span></td>
                    <td>
                        <a href="<?= BASE_URL ?>lecturer/question/<?= (int) $course['id'] ?>/<?= (int) $q['id'] ?>">
                            <?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 90, '…')) ?>
                        </a>
                    </td>
                    <td class="num"><?= htmlspecialchars($q['marks']) ?></td>
                    <td class="small nowrap"><?= htmlspecialchars($q['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
