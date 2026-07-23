<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Review — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="max-width: 720px; margin: 0 auto; padding: 30px;">
        <p><a href="<?= BASE_URL ?>lecturer/grading">&larr; Grading queue</a></p>

        <h1>Activity Review</h1>
        <p style="color:#65676b;">
            <?= htmlspecialchars($attempt['student_name']) ?> ·
            <?= htmlspecialchars($attempt['course_code']) ?> — <?= htmlspecialchars($attempt['exam_title']) ?>
        </p>

        <div style="background:#fff; border:1px solid #eef0f2; border-radius:8px;
                    padding:14px 18px; margin-top:14px;">
            Flag status:
            <strong style="color:<?= (int) $attempt['is_flagged'] === 1 ? '#dc3545' : '#198754' ?>;">
                <?= (int) $attempt['is_flagged'] === 1 ? '⚑ Flagged for review' : 'Not flagged' ?>
            </strong>
        </div>

        <!-- Event counts summary -->
        <h2 style="margin-top:22px; font-size:1rem;">Event summary</h2>
        <?php if (empty($counts)): ?>
            <p style="color:#65676b;">No activity events recorded — a clean attempt.</p>
        <?php else: ?>
        <table class="data-table" style="margin-top:8px;">
            <thead><tr><th>Event type</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($counts as $c): ?>
                <tr>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $c['event_type'])) ?></td>
                    <td><?= (int) $c['total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Full chronological timeline -->
        <?php if (!empty($events)): ?>
        <h2 style="margin-top:22px; font-size:1rem;">Timeline</h2>
        <div style="margin-top:8px;">
            <?php foreach ($events as $ev): ?>
            <div style="display:flex; gap:14px; padding:8px 0; border-bottom:1px solid #f0f2f5; font-size:.9rem;">
                <span style="color:#65676b; white-space:nowrap; font-variant-numeric:tabular-nums;">
                    <?= htmlspecialchars($ev['created_at']) ?>
                </span>
                <span style="font-weight:600;">
                    <?= htmlspecialchars(str_replace('_', ' ', $ev['event_type'])) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Review decision -->
        <?php if ((int) $attempt['is_flagged'] === 1): ?>
        <div style="margin-top:24px; display:flex; gap:10px;">
            <form method="POST" action="<?= BASE_URL ?>lecturer/reviewFlag/<?= (int) $attempt['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                <input type="hidden" name="decision" value="clear">
                <button type="submit" class="btn-small btn-success">Clear flag (reviewed, no concern)</button>
            </form>
        </div>
        <p style="margin-top:8px; font-size:.82rem; color:#65676b;">
            Clearing removes the flag but keeps the event log for the record.
        </p>
        <?php endif; ?>
    </div>
</body>
</html>