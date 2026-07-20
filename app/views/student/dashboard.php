<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Exams — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 800px; margin: 0 auto;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>My Exams</h1>
            <p>
                <?= htmlspecialchars($user['name']) ?> ·
                <a href="<?= BASE_URL ?>auth/logout">Log out</a>
            </p>
        </div>

        <?php if (empty($exams)): ?>
            <p style="margin-top:16px;">No exams available. Exams appear here when a lecturer publishes one in a course you're enrolled in.</p>
        <?php else: ?>
            <?php foreach ($exams as $e): ?>
            <?php
                $startTs  = strtotime($e['window_start']);
                $endTs    = strtotime($e['window_end']);
                $inWindow = ($now >= $startTs && $now <= $endTs);
            ?>
            <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px;
                        padding:18px 20px; margin-top:14px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">
                    <div>
                        <h2 style="font-size:1.05rem;">
                            <?= htmlspecialchars($e['course_code']) ?> — <?= htmlspecialchars($e['title']) ?>
                        </h2>
                        <p style="color:#65676b; font-size:.88rem; margin-top:6px;">
                            <?= (int) $e['duration_minutes'] ?> minutes ·
                            <?= (int) $e['questions_per_attempt'] ?> questions ·
                            window <?= htmlspecialchars($e['window_start']) ?> &rarr; <?= htmlspecialchars($e['window_end']) ?>
                        </p>
                    </div>
                    <div style="white-space:nowrap;">
                        <?php if ($e['attempt_status'] === 'in_progress'): ?>
                            <a href="<?= BASE_URL ?>student/exam/<?= (int) $e['attempt_id'] ?>"
                               class="btn-small btn-success" style="text-decoration:none;">Continue</a>

                        <?php elseif ($e['attempt_status'] !== null): ?>
                            <span style="color:#65676b; font-size:.88rem;">Completed</span>

                        <?php elseif (!$inWindow && $now < $startTs): ?>
                            <span style="color:#65676b; font-size:.88rem;">Not open yet</span>

                        <?php elseif (!$inWindow): ?>
                            <span style="color:#65676b; font-size:.88rem;">Window closed</span>

                        <?php else: ?>
                            <form method="POST" action="<?= BASE_URL ?>student/startExam/<?= (int) $e['id'] ?>"
                                  onsubmit="return confirm('Start this exam now? Your <?= (int) $e['duration_minutes'] ?>-minute timer begins immediately and cannot be paused.');">
                                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                                <button type="submit" class="btn-small btn-success">Start exam</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>