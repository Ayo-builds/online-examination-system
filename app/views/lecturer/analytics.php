<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">&larr; Exams</a>

    <div class="page__head">
        <div>
            <p class="eyebrow"><?= htmlspecialchars($course['course_code']) ?></p>
            <h1 class="page__title"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="page__lead">Analytics</p>
        </div>
    </div>

    <?php $attempts = (int) ($stats['attempts'] ?? 0); ?>

    <?php if ($attempts === 0): ?>
        <div class="empty">
            <p>No submitted attempts yet.</p>
            <p class="small stack-sm">Analytics appear once students sit this exam.</p>
        </div>
    <?php else: ?>

    <div class="grid grid--4">
        <div class="stat">
            <span class="stat__label">Attempts</span>
            <span class="stat__value"><?= $attempts ?></span>
        </div>
        <div class="stat">
            <span class="stat__label">Average</span>
            <span class="stat__value"><?= number_format((float) $stats['avg_score'], 2) ?></span>
        </div>
        <div class="stat stat--ok">
            <span class="stat__label">Highest</span>
            <span class="stat__value"><?= number_format((float) $stats['max_score'], 2) ?></span>
        </div>
        <div class="stat">
            <span class="stat__label">Lowest</span>
            <span class="stat__value"><?= number_format((float) $stats['min_score'], 2) ?></span>
        </div>
        <div class="stat<?= (int) $stats['flagged_count'] > 0 ? ' stat--danger' : '' ?>">
            <span class="stat__label">Flagged</span>
            <span class="stat__value"><?= (int) $stats['flagged_count'] ?></span>
        </div>
        <div class="stat<?= (int) $stats['auto_submitted_count'] > 0 ? ' stat--warn' : '' ?>">
            <span class="stat__label">Ran out of time</span>
            <span class="stat__value"><?= (int) $stats['auto_submitted_count'] ?></span>
        </div>
        <div class="stat<?= (int) $stats['pending_grading'] > 0 ? ' stat--warn' : '' ?>">
            <span class="stat__label">Awaiting grading</span>
            <span class="stat__value"><?= (int) $stats['pending_grading'] ?></span>
        </div>
    </div>

    <h2 class="section">Question performance</h2>
    <p class="small muted">
        Hardest questions first. A very low success rate may mean the question is unclear or mis-keyed.
    </p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Question</th>
                    <th>Type</th>
                    <th class="right">Answered</th>
                    <th class="right">Full marks</th>
                    <th class="right">Success rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <?php
                    $answered = (int) $it['times_answered'];
                    $full     = (int) $it['full_marks_count'];
                    $rate     = $answered > 0 ? round($full / $answered * 100) : null;
                ?>
                <tr>
                    <td><?= htmlspecialchars(mb_strimwidth($it['question_text'], 0, 60, '…')) ?></td>
                    <td><span class="tag"><?= htmlspecialchars(strtoupper($it['question_type'])) ?></span></td>
                    <td class="num"><?= $answered ?></td>
                    <td class="num"><?= $full ?></td>
                    <td class="num">
                        <?php if ($rate === null): ?>
                            <span class="muted">&mdash;</span>
                        <?php else: ?>
                            <strong class="<?= $rate < 40 ? 'danger' : ($rate < 70 ? 'warn' : 'ok') ?>"><?= $rate ?>%</strong>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="section">Scores</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th class="right">Score</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($scores as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td class="num"><?= $s['total_score'] === null ? '&mdash;' : htmlspecialchars($s['total_score']) ?></td>
                    <td>
                        <?php if ($s['grading_status'] === 'partial'): ?>
                            <span class="tag tag--warn">Awaiting essay grading</span>
                        <?php else: ?>
                            <span class="tag tag--ok">Final</span>
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
