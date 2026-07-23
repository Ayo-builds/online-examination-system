<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Analytics — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 950px; margin: 0 auto; padding: 30px;">
        <p><a href="<?= BASE_URL ?>admin/dashboard">&larr; Dashboard</a></p>
        <h1>System Analytics</h1>

        <!-- Counts -->
        <h2 style="margin-top:20px; font-size:1.05rem;">Overview</h2>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr));
                    gap:12px; margin-top:10px;">
            <?php
            $cards = [
                'Active students'   => (int) $counts['students'],
                'Active lecturers'  => (int) $counts['lecturers'],
                'Suspended users'   => (int) $counts['suspended'],
                'Courses'           => (int) $counts['courses'],
                'Questions in bank' => (int) $counts['questions'],
                'Published exams'   => (int) $counts['published_exams'],
                'Draft exams'       => (int) $counts['draft_exams'],
                'Completed attempts'=> (int) $counts['completed_attempts'],
                'Exams in progress' => (int) $counts['live_attempts'],
            ];
            foreach ($cards as $label => $value): ?>
            <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px; padding:14px;">
                <div style="font-size:.78rem; color:#65676b;"><?= htmlspecialchars($label) ?></div>
                <div style="font-size:1.5rem; font-weight:700; margin-top:4px;"><?= $value ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Integrity -->
        <h2 style="margin-top:28px; font-size:1.05rem;">Exam integrity</h2>
        <?php
            $total    = (int) ($integrity['total'] ?? 0);
            $flagged  = (int) ($integrity['flagged'] ?? 0);
            $timedOut = (int) ($integrity['timed_out'] ?? 0);
            $flagRate = $total > 0 ? round($flagged / $total * 100) : 0;
            $toRate   = $total > 0 ? round($timedOut / $total * 100) : 0;
        ?>
        <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px;
                    padding:16px 18px; margin-top:10px;">
            <p style="font-size:.92rem;">
                <strong><?= $flagged ?></strong> of <strong><?= $total ?></strong> completed attempts flagged
                (<span style="color:<?= $flagRate > 25 ? '#dc3545' : '#65676b' ?>;"><?= $flagRate ?>%</span>)
            </p>
            <p style="font-size:.92rem; margin-top:6px;">
                <strong><?= $timedOut ?></strong> ran out of time
                (<span style="color:<?= $toRate > 30 ? '#b26a00' : '#65676b' ?>;"><?= $toRate ?>%</span>)
                <?php if ($toRate > 30): ?>
                    — a high rate may mean exams are too long for their duration.
                <?php endif; ?>
            </p>
            <p style="font-size:.92rem; margin-top:6px;">
                <strong><?= (int) ($integrity['total_events'] ?? 0) ?></strong> activity events logged in total
            </p>
        </div>

        <!-- Event breakdown -->
        <?php if (!empty($events)): ?>
        <h2 style="margin-top:28px; font-size:1.05rem;">Activity events</h2>
        <table class="data-table">
            <thead><tr><th>Event type</th><th>Occurrences</th></tr></thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                <tr>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $e['event_type'])) ?></td>
                    <td><?= (int) $e['total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Courses -->
        <h2 style="margin-top:28px; font-size:1.05rem;">Courses</h2>
        <table class="data-table">
            <thead>
                <tr><th>Code</th><th>Title</th><th>Lecturer</th><th>Students</th><th>Exams</th><th>Attempts</th></tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['course_code']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= htmlspecialchars($c['lecturer_name']) ?></td>
                    <td><?= (int) $c['students'] ?></td>
                    <td><?= (int) $c['exams'] ?></td>
                    <td><?= (int) $c['attempts'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>