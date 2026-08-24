<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exam · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = 'Create an exam';
$page_lead = 'You will choose which questions go in the pool on the next screen.';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>lecturer/storeExam/<?= (int) $course['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required
                   placeholder="e.g. Mid-Semester Test"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="instructions">Instructions</label>
            <textarea id="instructions" name="instructions" rows="3"><?= htmlspecialchars($old['instructions'] ?? '') ?></textarea>
            <p class="help">Shown at the top of the paper while the exam is running.</p>
        </div>

        <fieldset class="fieldset">
            <legend>Timing</legend>

            <div class="grid grid--2">
                <div class="field">
                    <label for="window_start">Window opens</label>
                    <input type="datetime-local" id="window_start" name="window_start" required
                           value="<?= htmlspecialchars($old['window_start'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="window_end">Window closes</label>
                    <input type="datetime-local" id="window_end" name="window_end" required
                           value="<?= htmlspecialchars($old['window_end'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label for="duration_minutes">Duration (minutes)</label>
                <input type="number" id="duration_minutes" name="duration_minutes" min="5" max="300" required
                       value="<?= htmlspecialchars((string) ($old['duration_minutes'] ?? 30)) ?>">
                <p class="help">The countdown starts when a student begins, and is enforced server-side.</p>
            </div>
        </fieldset>

        <fieldset class="fieldset">
            <legend>Marking</legend>

            <div class="grid grid--2">
                <div class="field">
                    <label for="questions_per_attempt">Questions per attempt</label>
                    <input type="number" id="questions_per_attempt" name="questions_per_attempt" min="1" required
                           value="<?= htmlspecialchars((string) ($old['questions_per_attempt'] ?? 10)) ?>">
                    <p class="help">Drawn at random from the pool.</p>
                </div>

                <div class="field">
                    <label for="pass_mark">Pass mark (%)</label>
                    <input type="number" id="pass_mark" name="pass_mark" min="0" max="100" step="0.5" required
                           value="<?= htmlspecialchars((string) ($old['pass_mark'] ?? 50)) ?>">
                </div>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create exam &rarr; build pool</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">Cancel</a>
        </div>
    </form>

</main>
</body>
</html>
