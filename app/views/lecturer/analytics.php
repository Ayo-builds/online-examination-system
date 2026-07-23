<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Analytics — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 900px; margin: 0 auto; padding: 30px;">
        <p><a href="<?= BASE_URL ?>lecturer/exams/<?= (int) $course['id'] ?>">&larr; Exams</a></p>
        <h1><?= htmlspecialchars($exam['title']) ?> — Analytics</h1>
        <p style="color:#65676b;"><?= htmlspecialchars($course['course_code']) ?></p>

        <?php $attempts = (int) ($stats['attempts'] ?? 0); ?>

        <?php if ($attempts === 0): ?>
            <p style="margin-top:18px;">No submitted attempts yet — analytics will appear once students sit this exam.</p>
        <?php else: ?>

        <!-- Headline stats -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr));
                    gap:12px; margin-top:18px;">
            <?php
            $cards = [
                'Attempts'        => $attempts,
                'Average score'   => number_format((float) $stats['avg_score'], 2),
                'Highest'         => number_format((float) $stats['max_score'], 2),
                'Lowest'          => number_format((float) $stats['min_score'], 2),
                'Flagged'         => (int) $stats['flagged_count'],
                'Ran out of time' => (int) $stats['auto_submitted_count'],
                'Awaiting grading'=> (int) $stats['pending_grading'],
            ];
            foreach ($cards as $label => $value): ?>
            <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px; padding:14px;">
                <div style="font-size:.78rem; color:#65676b;"><?= htmlspecialchars($label) ?></div>
                <div style="font-size:1.5rem; font-weight:700; margin-top:4px;"><?= htmlspecialchars((string) $value) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Item analysis: hardest questions first -->
        <h2 style="margin-top:28px; font-size:1.05rem;">Question performance</h2>
        <p style="font-size:.85rem; color:#65676b; margin-top:4px;">
            Hardest questions first. A very low success rate may mean the question is unclear or mis-keyed.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Question</th>
                    <th>Type</th>
                    <th>Answered</th>
                    <th>Full marks</th>
                    <th>Success rate</th>
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
                    <td><?= htmlspecialchars(strtoupper($it['question_type'])) ?></td>
                    <td><?= $answered ?></td>
                    <td><?= $full ?></td>
                    <td>
                        <?php if ($rate === null): ?>
                            —
                        <?php else: ?>
                            <span style="color:<?= $rate < 40 ? '#dc3545' : ($rate < 70 ? '#b26a00' : '#198754') ?>; font-weight:600;">
                                <?= $rate ?>%
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Score list -->
        <h2 style="margin-top:28px; font-size:1.05rem;">Scores</h2>
        <table class="data-table">
            <thead><tr><th>Student</th><th>Score</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($scores as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= $s['total_score'] === null ? '—' : htmlspecialchars($s['total_score']) ?></td>
                    <td style="font-size:.85rem; color:#65676b;">
                        <?= $s['grading_status'] === 'partial' ? 'Awaiting essay grading' : 'Final' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </div>
</body>
</html>