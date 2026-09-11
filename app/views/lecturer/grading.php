<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'grading'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--wide">

<?php
$page_title = 'Grading queue';
$page_lead = 'Finished attempts awaiting marking or review, including papers the system closed after their deadline because the candidate never submitted. Flagged attempts carry an activity log.';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (empty($attempts)): ?>
        <div class="empty">
            <p>No submitted attempts yet.</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Exam</th>
                    <th class="right">Score</th>
                    <th>Grading</th>
                    <th>Flag</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attempts as $a): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($a['student_name']) ?>
                        <?php $student = $a; require APP_ROOT . '/app/views/_partials/student_identity.php'; ?>
                    </td>
                    <td>
                        <span class="code"><?= htmlspecialchars($a['course_code']) ?></span>
                        <span class="muted">/</span>
                        <?= htmlspecialchars($a['exam_title']) ?>
                    </td>
                    <td class="num"><?= $a['total_score'] === null ? '&mdash;' : htmlspecialchars($a['total_score']) ?></td>
                    <td>
                        <?php if ($a['grading_status'] === 'partial'): ?>
                            <span class="tag tag--warn">Needs grading</span>
                        <?php else: ?>
                            <span class="tag tag--ok">Complete</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            <span class="tag tag--flag">&#9873; Flagged</span>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="small nowrap">
                        <?= htmlspecialchars($a['submitted_at']) ?>
                        <?php /* Nobody pressed Submit: the deadline passed and the
                                 system closed the paper. The time above is the
                                 deadline. See Attempt::autoSubmit(). */ ?>
                        <?php if ($a['closed_by_system_at'] !== null): ?>
                            <br><span class="tag tag--warn"
                                      title="Closed by the system at <?= htmlspecialchars($a['closed_by_system_at']) ?>">Not submitted</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="<?= BASE_URL ?>lecturer/gradeAttempt/<?= (int) $a['id'] ?>">
                            <?= $a['grading_status'] === 'partial' ? 'Grade' : 'Review' ?>
                        </a>
                        <?php if ((int) $a['is_flagged'] === 1): ?>
                            <a class="danger" href="<?= BASE_URL ?>lecturer/activity/<?= (int) $a['id'] ?>">Activity &#9873;</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
