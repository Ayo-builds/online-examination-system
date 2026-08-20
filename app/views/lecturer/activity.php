<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Review — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'grading'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>lecturer/grading">&larr; Grading queue</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">
                <?= htmlspecialchars($attempt['course_code']) ?>
                &middot; <?= htmlspecialchars($attempt['exam_title']) ?>
            </p>
            <h1 class="page__title">Activity review</h1>
            <p class="page__lead"><?= htmlspecialchars($attempt['student_name']) ?></p>
        </div>
    </div>

    <?php if ((int) $attempt['is_flagged'] === 1): ?>
        <div class="alert alert--danger">
            <strong>&#9873; Flagged for review.</strong>
            The events below crossed the threshold. Read the timeline before deciding — the
            system logs evidence, it does not judge.
        </div>
    <?php else: ?>
        <div class="alert alert--ok">Not flagged. This attempt stayed within the event threshold.</div>
    <?php endif; ?>

    <h2 class="section">Event summary</h2>
    <?php if (empty($counts)): ?>
        <div class="empty"><p>No activity events recorded &mdash; a clean attempt.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Event type</th><th class="right">Count</th></tr></thead>
            <tbody>
                <?php foreach ($counts as $c): ?>
                <tr>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $c['event_type'])) ?></td>
                    <td class="num"><?= (int) $c['total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($events)): ?>
    <h2 class="section">Timeline</h2>
    <ol class="timeline">
        <?php foreach ($events as $ev): ?>
        <li class="timeline__row">
            <span class="timeline__at"><?= htmlspecialchars($ev['created_at']) ?></span>
            <span class="timeline__what"><?= htmlspecialchars(str_replace('_', ' ', $ev['event_type'])) ?></span>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <?php if ((int) $attempt['is_flagged'] === 1): ?>
    <hr class="divider">
    <form method="POST" action="<?= BASE_URL ?>lecturer/reviewFlag/<?= (int) $attempt['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="decision" value="clear">
        <button type="submit" class="btn btn--ok btn--sm">Clear flag &mdash; reviewed, no concern</button>
    </form>
    <p class="help">Clearing removes the flag but keeps the event log for the record.</p>
    <?php endif; ?>

</main>
</body>
</html>
