<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($course['course_code']) ?> Questions — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 900px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/dashboard">&larr; My Courses</a></p>
        <h1><?= htmlspecialchars($course['course_code']) ?> — Question Bank</h1>
        <p style="color:#65676b;"><?= htmlspecialchars($course['title']) ?></p>

        <p style="margin: 14px 0;">
            <a href="<?= BASE_URL ?>lecturer/createMcq/<?= (int) $course['id'] ?>">+ Add MCQ</a>
            &nbsp;·&nbsp;
            <a href="<?= BASE_URL ?>lecturer/createEssay/<?= (int) $course['id'] ?>">+ Add essay question</a>
        </p>

        <?php if (empty($questions)): ?>
            <p>No questions yet — add your first one above.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Question</th>
                    <th>Marks</th>
                    <th>Added</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($questions as $q): ?>
                <tr>
                    <td><?= htmlspecialchars(strtoupper($q['question_type'])) ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 90, '…')) ?></td>
                    <td><?= htmlspecialchars($q['marks']) ?></td>
                    <td><?= htmlspecialchars($q['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>