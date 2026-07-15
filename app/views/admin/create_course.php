<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Course — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 500px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>admin/courses">&larr; Courses</a></p>
        <h1>Create Course</h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($lecturers)): ?>
            <p>No active lecturers exist yet — <a href="<?= BASE_URL ?>admin/createUser">create one first</a>.</p>
        <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>admin/storeCourse">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <label for="course_code">Course code</label>
            <input type="text" id="course_code" name="course_code" required
                   placeholder="e.g. CSC301"
                   value="<?= htmlspecialchars($old['course_code'] ?? '') ?>">

            <label for="title">Title</label>
            <input type="text" id="title" name="title" required
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>">

            <label for="lecturer_id">Lecturer</label>
            <select id="lecturer_id" name="lecturer_id" required>
                <option value="">— Select lecturer —</option>
                <?php foreach ($lecturers as $l): ?>
                <option value="<?= (int) $l['id'] ?>"
                    <?= ((int) ($old['lecturer_id'] ?? 0)) === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Create course</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>