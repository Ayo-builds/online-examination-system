<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Class Passwords · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = 'Reset a class';
$page_lead  = 'Issue every active student in one class a new password.';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="alert alert--warn">
        <strong>Every current password in the class stops working.</strong>
        Do this at the start of a term or before an exam, not during one. Students
        mid-exam keep their session, but any who sign in afterwards need the new slip.
    </div>

    <form method="POST" action="<?= BASE_URL ?>admin/resetClassPasswords">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" required>
                <option value="">Select a class</option>
                <?php foreach ($classes as $c): ?>
                    <?php $n = (int) $c['student_count']; ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $n === 0 ? 'disabled' : '' ?>>
                        <?= htmlspecialchars(SchoolClass::labelFor($c)) ?>
                        &mdash; <?= $n ?> active student<?= $n === 1 ? '' : 's' ?>
                        <?= $n === 0 ? ' (nobody to reset)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="help">
                Suspended accounts are skipped: reissuing a credential for an account that
                cannot sign in only wastes a slip.
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"
                    onclick="return confirm('Reset every active password in this class?\n\nThe current passwords stop working immediately, and the new ones are shown once.');">
                Reset class passwords
            </button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/users">Cancel</a>
        </div>
    </form>

    <h2 class="section">What you get</h2>

    <ul class="muted small">
        <li>A new random password for each active student in the class.</li>
        <li>The same printable slip page as a CSV import, laid out for cutting up.</li>
        <li><strong>Shown once.</strong> Passwords are stored hashed, so print before you
            navigate away &mdash; a lost one can only be reset again, never recovered.</li>
    </ul>

</main>
</body>
</html>
