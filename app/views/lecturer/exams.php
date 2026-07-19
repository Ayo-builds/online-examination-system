<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($course['course_code']) ?> Exams — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 900px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/dashboard">&larr; My Courses</a></p>
        <h1><?= htmlspecialchars($course['course_code']) ?> — Exams</h1>

        <p style="margin: 14px 0;">
            <a href="<?= BASE_URL ?>lecturer/createExam/<?= (int) $course['id'] ?>">+ Create exam</a>
        </p>

        <?php if (empty($exams)): ?>
            <p>No exams yet.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Window</th>
                    <th>Duration</th>
                    <th>Pool</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($exams as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['title']) ?></td>
                    <td><?= htmlspecialchars($e['status']) ?></td>
                    <td style="font-size:.85rem;">
                        <?= htmlspecialchars($e['window_start']) ?><br>
                        &rarr; <?= htmlspecialchars($e['window_end']) ?>
                    </td>
                    <td><?= (int) $e['duration_minutes'] ?> min</td>
                    <td><?= (int) $e['pool_count'] ?> / <?= (int) $e['questions_per_attempt'] ?> needed</td>
                    <td>
                        <a href="<?= BASE_URL ?>lecturer/examPool/<?= (int) $course['id'] ?>/<?= (int) $e['id'] ?>">Pool</a>
                        <?php if ($e['status'] === 'published'): ?>
                        <form method="POST"
                              action="<?= BASE_URL ?>lecturer/closeExam/<?= (int) $course['id'] ?>/<?= (int) $e['id'] ?>"
                              onsubmit="return confirm('Close this exam? Students will no longer be able to start it.');"
                              style="display:inline; margin-left:8px;">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <button type="submit" class="btn-small btn-danger">Close</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>