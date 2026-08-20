<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrolments — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'courses'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>admin/courses">&larr; Courses</a>

    <div class="page__head">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($course['course_code']) ?></p>
            <h1 class="page__title">Enrolments</h1>
            <p class="page__lead"><?= htmlspecialchars($course['title']) ?></p>
        </div>
    </div>

    <h2 class="section">Enrol a student</h2>
    <?php if (empty($available)): ?>
        <p class="muted small">Every active student is already enrolled in this course.</p>
    <?php else: ?>
    <form method="POST" action="<?= BASE_URL ?>admin/enroll/<?= (int) $course['id'] ?>" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <label class="sr-only" for="student_id">Student</label>
        <select id="student_id" name="student_id" required>
            <option value="">&mdash; Select student &mdash;</option>
            <?php foreach ($available as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--ok btn--sm">Enrol</button>
    </form>
    <?php endif; ?>

    <h2 class="section">Enrolled students</h2>
    <?php if (empty($enrolled)): ?>
        <div class="empty"><p>No students enrolled yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrolled as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td class="small"><?= htmlspecialchars($s['email']) ?></td>
                    <td>
                        <?php if ($s['status'] === 'active'): ?>
                            <span class="tag tag--ok">Active</span>
                        <?php else: ?>
                            <span class="tag tag--flag">Suspended</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="POST" action="<?= BASE_URL ?>admin/unenroll/<?= (int) $course['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn btn--danger-quiet btn--sm">Remove</button>
                        </form>
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
