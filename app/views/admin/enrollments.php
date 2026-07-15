<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enrollments — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 700px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>admin/courses">&larr; Courses</a></p>
        <h1><?= htmlspecialchars($course['course_code']) ?> — Enrollments</h1>
        <p style="color:#65676b;"><?= htmlspecialchars($course['title']) ?></p>

        <h2 style="margin-top:24px; font-size:1.05rem;">Enroll a student</h2>
        <?php if (empty($available)): ?>
            <p>No available students to enroll.</p>
        <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>admin/enroll/<?= (int) $course['id'] ?>"
              style="display:flex; gap:10px; align-items:center; margin-top:8px;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <select name="student_id" required style="flex:1;">
                <option value="">— Select student —</option>
                <?php foreach ($available as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-small btn-success" style="white-space:nowrap;">Enroll</button>
        </form>
        <?php endif; ?>

        <h2 style="margin-top:28px; font-size:1.05rem;">Enrolled students</h2>
        <?php if (empty($enrolled)): ?>
            <p>No students enrolled yet.</p>
        <?php else: ?>
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
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td><?= htmlspecialchars($s['status']) ?></td>
                    <td>
                        <form method="POST" action="<?= BASE_URL ?>admin/unenroll/<?= (int) $course['id'] ?>"
                              style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn-small btn-danger">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>