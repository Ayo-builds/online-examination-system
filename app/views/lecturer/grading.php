<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grading — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 950px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>lecturer/dashboard">&larr; My Courses</a></p>
        <h1>Grading Queue</h1>

        <?php if (empty($attempts)): ?>
            <p style="margin-top:16px;">No submitted attempts yet.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Exam</th>
                    <th>Score</th>
                    <th>Grading</th>
                    <th>Flag</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attempts as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['student_name']) ?></td>
                    <td>
                        <?= htmlspecialchars($a['course_code']) ?> —
                        <?= htmlspecialchars($a['exam_title']) ?>
                    </td>
                    <td><?= $a['total_score'] === null ? '—' : htmlspecialchars($a['total_score']) ?></td>
                    <td>
                        <?php if ($a['grading_status'] === 'partial'): ?>
                            <span style="color:#b26a00; font-weight:600;">Needs grading</span>
                        <?php else: ?>
                            <span style="color:#198754;">Complete</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            <span style="color:#dc3545; font-weight:600;">⚑ Flagged</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.85rem;"><?= htmlspecialchars($a['submitted_at']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>lecturer/gradeAttempt/<?= (int) $a['id'] ?>">
                            <?= $a['grading_status'] === 'partial' ? 'Grade' : 'Review' ?>
                        </a>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            · <a href="<?= BASE_URL ?>lecturer/activity/<?= (int) $a['id'] ?>"
                                 style="color:#dc3545;">Activity ⚑</a>
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