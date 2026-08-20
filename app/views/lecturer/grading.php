<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'grading'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/dashboard">&larr; My courses</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">Lecturer</p>
            <h1 class="page__title">Grading queue</h1>
            <p class="page__lead">Submitted attempts awaiting marking or review. Flagged attempts carry an activity log.</p>
        </div>
    </div>

    <?php if (empty($attempts)): ?>
        <div class="empty">
            <p>No submitted attempts yet.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Exam</th>
                    <th class="right">Score</th>
                    <th>Grading</th>
                    <th>Flag</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attempts as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['student_name']) ?></td>
                    <td>
                        <span class="code"><?= htmlspecialchars($a['course_code']) ?></span>
                        <span class="muted">/</span>
                        <?= htmlspecialchars($a['exam_title']) ?>
                    </td>
                    <td class="num"><?= $a['total_score'] === null ? '&mdash;' : htmlspecialchars($a['total_score']) ?></td>
                    <td>
                        <?php if ($a['grading_status'] === 'partial'): ?>
                            <span class="tag tag--warn">Needs grading</span>
                        <?php else: ?>
                            <span class="tag tag--ok">Complete</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            <span class="tag tag--flag">&#9873; Flagged</span>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="small nowrap"><?= htmlspecialchars($a['submitted_at']) ?></td>
                    <td class="actions">
                        <a href="<?= BASE_URL ?>lecturer/gradeAttempt/<?= (int) $a['id'] ?>">
                            <?= $a['grading_status'] === 'partial' ? 'Grade' : 'Review' ?>
                        </a>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            <a class="danger" href="<?= BASE_URL ?>lecturer/activity/<?= (int) $a['id'] ?>">Activity &#9873;</a>
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
