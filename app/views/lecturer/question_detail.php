<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Question — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 700px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">&larr; Question bank</a></p>

        <h1><?= htmlspecialchars(strtoupper($question['question_type'])) ?> ·
            <?= htmlspecialchars($question['marks']) ?> mark(s)</h1>

        <p style="margin-top:14px; font-size:1.05rem; line-height:1.5;">
            <?= nl2br(htmlspecialchars($question['question_text'])) ?>
        </p>

        <?php if ($question['question_type'] === 'mcq'): ?>
            <h2 style="margin-top:22px; font-size:1rem;">Options</h2>
            <ul style="margin-top:8px; list-style:none;">
                <?php foreach ($question['options'] as $i => $opt): ?>
                <li style="padding:8px 12px; margin-top:6px; border-radius:6px;
                           background: <?= $opt['is_correct'] ? '#e6f4ea' : '#fff' ?>;
                           border: 1px solid <?= $opt['is_correct'] ? '#198754' : '#eef0f2' ?>;">
                    <strong><?= chr(65 + $i) ?>.</strong>
                    <?= htmlspecialchars($opt['option_text']) ?>
                    <?= $opt['is_correct'] ? ' ✓' : '' ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div style="margin-top:28px;">
            <?php if ($inUse): ?>
                <p style="color:#65676b; font-size:.9rem;">
                    This question is used in an exam or has been answered by students — it cannot be deleted.
                </p>
            <?php else: ?>
                <form method="POST"
                      action="<?= BASE_URL ?>lecturer/deleteQuestion/<?= (int) $course['id'] ?>/<?= (int) $question['id'] ?>"
                      onsubmit="return confirm('Delete this question permanently?');">
                    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                    <button type="submit" class="btn-small btn-danger">Delete question</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>