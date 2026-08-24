<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Essay Question · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = 'Add an essay question';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>lecturer/storeEssay/<?= (int) $course['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="question_text">Question</label>
            <textarea id="question_text" name="question_text" rows="5" required><?= htmlspecialchars($old['question_text'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="marks">Marks</label>
            <input type="number" id="marks" name="marks" step="0.5" min="0.5" max="100" required
                   value="<?= htmlspecialchars((string) ($old['marks'] ?? 10)) ?>">
            <p class="help">Essay answers are marked by hand from the grading queue.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Add question</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">Cancel</a>
        </div>
    </form>

</main>
</body>
</html>
