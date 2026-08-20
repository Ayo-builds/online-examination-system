<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($course['course_code']) ?> Exams — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/dashboard">&larr; My courses</a>

    <div class="page__head">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($course['course_code']) ?></p>
            <h1 class="page__title">Exams</h1>
        </div>
        <div class="page__actions">
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>lecturer/createExam/<?= (int) $course['id'] ?>">Create exam</a>
        </div>
    </div>

    <?php if (empty($exams)): ?>
        <div class="empty">
            <p>No exams yet.</p>
            <p class="small stack-sm">Create one, then fill its question pool before publishing.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Window</th>
                    <th class="right">Duration</th>
                    <th>Pool</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($exams as $e): ?>
                <?php
                    $poolReady = (int) $e['pool_count'] >= (int) $e['questions_per_attempt'];
                    $statusTag = $e['status'] === 'published' ? 'tag--ok'
                               : ($e['status'] === 'draft' ? 'tag--warn' : 'tag--muted');
                ?>
                <tr>
                    <td><?= htmlspecialchars($e['title']) ?></td>
                    <td><span class="tag <?= $statusTag ?>"><?= htmlspecialchars($e['status']) ?></span></td>
                    <td class="small nowrap">
                        <?= htmlspecialchars($e['window_start']) ?><br>
                        &rarr; <?= htmlspecialchars($e['window_end']) ?>
                    </td>
                    <td class="num"><?= (int) $e['duration_minutes'] ?> min</td>
                    <td class="nowrap">
                        <span class="mono <?= $poolReady ? 'ok' : 'danger' ?>"><?= (int) $e['pool_count'] ?></span>
                        <span class="muted">/ <?= (int) $e['questions_per_attempt'] ?> needed</span>
                    </td>
                    <td class="actions">
                        <a href="<?= BASE_URL ?>lecturer/examPool/<?= (int) $course['id'] ?>/<?= (int) $e['id'] ?>">Pool</a>
                        <a href="<?= BASE_URL ?>lecturer/analytics/<?= (int) $course['id'] ?>/<?= (int) $e['id'] ?>">Analytics</a>
                        <?php if ($e['status'] === 'published'): ?>
                        <form method="POST"
                              action="<?= BASE_URL ?>lecturer/closeExam/<?= (int) $course['id'] ?>/<?= (int) $e['id'] ?>"
                              onsubmit="return confirm('Close this exam? Students will no longer be able to start it.');">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <button type="submit" class="btn btn--danger-quiet btn--sm">Close</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
