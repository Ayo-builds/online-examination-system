<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Exam — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 600px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">&larr; Exams</a></p>
        <h1>Create Exam — <?= htmlspecialchars($course['course_code']) ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>lecturer/storeExam/<?= (int) $course['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <label for="title">Title</label>
            <input type="text" id="title" name="title" required
                   placeholder="e.g. Mid-Semester Test"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>">

            <label for="instructions">Instructions (shown to students before starting)</label>
            <textarea id="instructions" name="instructions" rows="3"><?= htmlspecialchars($old['instructions'] ?? '') ?></textarea>

            <label for="duration_minutes">Duration (minutes)</label>
            <input type="number" id="duration_minutes" name="duration_minutes" min="5" max="300" required
                   value="<?= htmlspecialchars((string) ($old['duration_minutes'] ?? 30)) ?>">

            <label for="window_start">Window opens</label>
            <input type="datetime-local" id="window_start" name="window_start" required
                   value="<?= htmlspecialchars($old['window_start'] ?? '') ?>">

            <label for="window_end">Window closes</label>
            <input type="datetime-local" id="window_end" name="window_end" required
                   value="<?= htmlspecialchars($old['window_end'] ?? '') ?>">

            <label for="questions_per_attempt">Questions per attempt</label>
            <input type="number" id="questions_per_attempt" name="questions_per_attempt" min="1" required
                   value="<?= htmlspecialchars((string) ($old['questions_per_attempt'] ?? 10)) ?>">

            <label for="pass_mark">Pass mark (%)</label>
            <input type="number" id="pass_mark" name="pass_mark" min="0" max="100" step="0.5" required
                   value="<?= htmlspecialchars((string) ($old['pass_mark'] ?? 50)) ?>">

            <button type="submit">Create exam &rarr; build pool</button>
        </form>
    </div>
</body>
</html>