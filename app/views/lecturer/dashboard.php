<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Courses — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 900px; margin: 0 auto;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>My Courses</h1>
            <p>
                <?= htmlspecialchars($user['name']) ?> ·
                <a href="<?= BASE_URL ?>auth/logout">Log out</a>
            </p>
        </div>

        <?php if (empty($courses)): ?>
            <p style="margin-top:16px;">You have no courses assigned yet. An administrator assigns courses to lecturers.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Students</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= (int) $c['student_count'] ?></td>
                  <td>
                        <a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $c['id'] ?>">Question bank</a>
                        &nbsp;·&nbsp;
                        <a href="<?= BASE_URL ?>lecturer/exams/<?= (int) $c['id'] ?>">Exams</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>