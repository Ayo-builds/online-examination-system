<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Course — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'courses'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>admin/courses">&larr; Courses</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">Admin</p>
            <h1 class="page__title">Create a course</h1>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($lecturers)): ?>
        <div class="empty">
            <p>No active lecturers exist yet.</p>
            <p class="small stack-sm">
                <a href="<?= BASE_URL ?>admin/createUser">Create a lecturer account</a> before adding a course.
            </p>
        </div>
    <?php else: ?>
    <form method="POST" action="<?= BASE_URL ?>admin/storeCourse">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="course_code">Course code</label>
            <input type="text" id="course_code" name="course_code" required
                   placeholder="e.g. CSC301"
                   value="<?= htmlspecialchars($old['course_code'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="lecturer_id">Lecturer</label>
            <select id="lecturer_id" name="lecturer_id" required>
                <option value="">&mdash; Select lecturer &mdash;</option>
                <?php foreach ($lecturers as $l): ?>
                <option value="<?= (int) $l['id'] ?>"
                    <?= ((int) ($old['lecturer_id'] ?? 0)) === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create course</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/courses">Cancel</a>
        </div>
    </form>
    <?php endif; ?>

</main>
</body>
</html>
