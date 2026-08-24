<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add MCQ · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = 'Add a multiple-choice question';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>lecturer/storeMcq/<?= (int) $course['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="question_text">Question</label>
            <textarea id="question_text" name="question_text" rows="3" required><?= htmlspecialchars($old['question_text'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="marks">Marks</label>
            <input type="number" id="marks" name="marks" step="0.5" min="0.5" max="100" required
                   value="<?= htmlspecialchars((string) ($old['marks'] ?? 1)) ?>">
        </div>

        <fieldset class="fieldset">
            <legend>Options</legend>
            <p class="help">Select the radio beside the correct answer.</p>

            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="opt-row">
                <input type="radio" name="correct" value="<?= $i ?>" required
                       aria-label="Option <?= chr(65 + $i) ?> is correct"
                       <?= ((int) ($old['correct'] ?? -1)) === $i ? 'checked' : '' ?>>
                <input type="text" name="options[]" required
                       placeholder="Option <?= chr(65 + $i) ?>"
                       value="<?= htmlspecialchars($old['options'][$i] ?? '') ?>">
            </div>
            <?php endfor; ?>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Add question</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">Cancel</a>
        </div>
    </form>

</main>
</body>
</html>
