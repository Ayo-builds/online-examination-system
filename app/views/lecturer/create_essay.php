<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Essay Question — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 600px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">&larr; Question bank</a></p>
        <h1>Add Essay Question — <?= htmlspecialchars($course['course_code']) ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>lecturer/storeEssay/<?= (int) $course['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <label for="question_text">Question</label>
            <textarea id="question_text" name="question_text" rows="5" required><?= htmlspecialchars($old['question_text'] ?? '') ?></textarea>

            <label for="marks">Marks</label>
            <input type="number" id="marks" name="marks" step="0.5" min="0.5" max="100" required
                   value="<?= htmlspecialchars((string) ($old['marks'] ?? 10)) ?>">

            <button type="submit">Add question</button>
        </form>
    </div>
</body>
</html>