<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Courses — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 900px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>admin/dashboard">&larr; Dashboard</a></p>
        <h1>Courses</h1>
        <p style="margin: 12px 0;">
            <a href="<?= BASE_URL ?>admin/createCourse">+ Create course</a>
        </p>

        <?php if (empty($courses)): ?>
            <p>No courses yet.</p>
        <?php else: ?>
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
                    <td><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= htmlspecialchars($c['lecturer_name']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>admin/enrollments/<?= (int) $c['id'] ?>">Enrollments</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>