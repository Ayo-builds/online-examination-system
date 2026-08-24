<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

    <div class="page__head">
        <div>
            <p class="eyebrow">Lecturer</p>
            <h1 class="page__title">My courses</h1>
        </div>
        <div class="page__actions">
            <a class="btn btn--secondary btn--sm" href="<?= BASE_URL ?>lecturer/grading">Grading queue</a>
        </div>
    </div>

    <?php if (empty($courses)): ?>
        <div class="empty">
            <p>No courses assigned yet.</p>
            <p class="small stack-sm">An administrator assigns courses to lecturers.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th class="right">Students</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td class="code"><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td class="num"><?= (int) $c['student_count'] ?></td>
                    <td class="actions">
                        <a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $c['id'] ?>">Question bank</a>
                        <a href="<?= BASE_URL ?>lecturer/exams/<?= (int) $c['id'] ?>">Exams</a>
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
