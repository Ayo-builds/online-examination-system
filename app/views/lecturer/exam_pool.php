<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exam Pool — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 700px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">&larr; Exams</a></p>
        <h1><?= htmlspecialchars($exam['title']) ?> — Question Pool</h1>
        <p style="color:#65676b;">
            Status: <strong><?= htmlspecialchars($exam['status']) ?></strong> ·
            Draws <?= (int) $exam['questions_per_attempt'] ?> question(s) per attempt ·
            Pool currently holds <?= count($poolIds) ?>
        </p>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error" style="margin-top:12px;">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($questions)): ?>
            <p style="margin-top:16px;">
                The question bank is empty —
                <a href="<?= BASE_URL ?>lecturer/questions/<?= (int) $course['id'] ?>">add questions</a> first.
            </p>
        <?php elseif ($exam['status'] !== 'draft'): ?>
            <p style="margin-top:16px; color:#65676b;">
                This exam is <?= htmlspecialchars($exam['status']) ?> — the pool is locked.
            </p>
            <ul style="margin-top:10px; list-style:none;">
                <?php foreach ($questions as $q): ?>
                    <?php if (in_array((int) $q['id'], $poolIds, true)): ?>
                    <li style="padding:8px 12px; margin-top:6px; background:#fff; border:1px solid #eef0f2; border-radius:6px;">
                        <strong><?= htmlspecialchars(strtoupper($q['question_type'])) ?></strong> ·
                        <?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?>
                    </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>lecturer/savePool/<?= (int) $course['id'] ?>/<?= (int) $exam['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <div style="margin-top:14px;">
                <?php foreach ($questions as $q): ?>
                <label style="display:flex; gap:10px; align-items:flex-start;
                              padding:10px 12px; margin-top:6px; background:#fff;
                              border:1px solid #eef0f2; border-radius:6px;
                              font-weight:normal; cursor:pointer;">
                    <input type="checkbox" name="question_ids[]"
                           value="<?= (int) $q['id'] ?>"
                           style="width:auto; margin-top:3px;"
                           <?= in_array((int) $q['id'], $poolIds, true) ? 'checked' : '' ?>>
                    <span>
                        <strong><?= htmlspecialchars(strtoupper($q['question_type'])) ?></strong>
                        (<?= htmlspecialchars($q['marks']) ?> mk) ·
                        <?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>

            <button type="submit">Save pool</button>
        </form>
        <form method="POST"
              action="<?= BASE_URL ?>lecturer/publishExam/<?= (int) $course['id'] ?>/<?= (int) $exam['id'] ?>"
              onsubmit="return confirm('Publish this exam? The pool will be locked and students will be able to start it during the window.');"
              style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <button type="submit" class="btn-small btn-success">Publish exam</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>