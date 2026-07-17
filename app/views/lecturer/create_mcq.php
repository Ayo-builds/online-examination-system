<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add MCQ — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 600px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">&larr; Question bank</a></p>
        <h1>Add MCQ — <?= htmlspecialchars($course['course_code']) ?></h1>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>lecturer/storeMcq/<?= (int) $course['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <label for="question_text">Question</label>
            <textarea id="question_text" name="question_text" rows="3" required><?= htmlspecialchars($old['question_text'] ?? '') ?></textarea>

            <label for="marks">Marks</label>
            <input type="number" id="marks" name="marks" step="0.5" min="0.5" max="100" required
                   value="<?= htmlspecialchars((string) ($old['marks'] ?? 1)) ?>">

            <p style="margin-top:18px; font-weight:600; font-size:.9rem;">
                Options — select the correct one:
            </p>

            <?php for ($i = 0; $i < 4; $i++): ?>
            <div style="display:flex; gap:10px; align-items:center; margin-top:8px;">
                <input type="radio" name="correct" value="<?= $i ?>" required
                       style="width:auto;"
                       <?= ((int) ($old['correct'] ?? -1)) === $i ? 'checked' : '' ?>>
                <input type="text" name="options[]" required
                       placeholder="Option <?= chr(65 + $i) ?>"
                       value="<?= htmlspecialchars($old['options'][$i] ?? '') ?>">
            </div>
            <?php endfor; ?>

            <button type="submit">Add question</button>
        </form>
    </div>
</body>
</html>