<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'courses'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

    <a class="backlink" href="<?= BASE_URL ?>admin/dashboard">&larr; Dashboard</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">Admin</p>
            <h1 class="page__title">Courses</h1>
        </div>
        <div class="page__actions">
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/createCourse">Create course</a>
        </div>
    </div>

    <?php if (empty($courses)): ?>
        <div class="empty">
            <p>No courses yet.</p>
            <p class="small stack-sm">Create one and assign it to a lecturer.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Lecturer</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td class="code"><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= htmlspecialchars($c['lecturer_name']) ?></td>
                    <td class="actions">
                        <a href="<?= BASE_URL ?>admin/enrollments/<?= (int) $c['id'] ?>">Enrolments</a>
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
