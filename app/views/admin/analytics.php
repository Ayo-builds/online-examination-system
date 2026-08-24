<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Analytics · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'analytics'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell">

<?php
$page_title = 'System analytics';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <h2 class="section">Overview</h2>
    <div class="grid grid--3">
        <?php
        $cards = [
            'Active students'    => (int) $counts['students'],
            'Active lecturers'   => (int) $counts['lecturers'],
            'Suspended users'    => (int) $counts['suspended'],
            'Courses'            => (int) $counts['courses'],
            'Questions in bank'  => (int) $counts['questions'],
            'Published exams'    => (int) $counts['published_exams'],
            'Draft exams'        => (int) $counts['draft_exams'],
            'Completed attempts' => (int) $counts['completed_attempts'],
            'Exams in progress'  => (int) $counts['live_attempts'],
        ];
        foreach ($cards as $label => $value): ?>
        <div class="stat">
            <span class="stat__label"><?= htmlspecialchars($label) ?></span>
            <span class="stat__value"><?= $value ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <h2 class="section">Exam integrity</h2>
    <?php
        $total    = (int) ($integrity['total'] ?? 0);
        $flagged  = (int) ($integrity['flagged'] ?? 0);
        $timedOut = (int) ($integrity['timed_out'] ?? 0);
        $flagRate = $total > 0 ? round($flagged / $total * 100) : 0;
        $toRate   = $total > 0 ? round($timedOut / $total * 100) : 0;
    ?>
    <div class="grid grid--3">
        <div class="stat<?= $flagRate > 25 ? ' stat--danger' : '' ?>">
            <span class="stat__label">Flagged attempts</span>
            <span class="stat__value"><?= $flagRate ?>%</span>
            <span class="stat__note"><?= $flagged ?> of <?= $total ?> completed</span>
        </div>
        <div class="stat<?= $toRate > 30 ? ' stat--warn' : '' ?>">
            <span class="stat__label">Ran out of time</span>
            <span class="stat__value"><?= $toRate ?>%</span>
            <span class="stat__note">
                <?= $timedOut ?> of <?= $total ?><?= $toRate > 30 ? '. Exams may be too long for their duration' : '' ?>
            </span>
        </div>
        <div class="stat">
            <span class="stat__label">Activity events</span>
            <span class="stat__value"><?= (int) ($integrity['total_events'] ?? 0) ?></span>
            <span class="stat__note">logged in total</span>
        </div>
    </div>

    <?php if (!empty($events)): ?>
    <h2 class="section">Activity events</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Event type</th><th class="right">Occurrences</th></tr></thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                <tr>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $e['event_type'])) ?></td>
                    <td class="num"><?= (int) $e['total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <h2 class="section">Courses</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Lecturer</th>
                    <th class="right">Students</th>
                    <th class="right">Exams</th>
                    <th class="right">Attempts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td class="code"><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= htmlspecialchars($c['lecturer_name']) ?></td>
                    <td class="num"><?= (int) $c['students'] ?></td>
                    <td class="num"><?= (int) $c['exams'] ?></td>
                    <td class="num"><?= (int) $c['attempts'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>
