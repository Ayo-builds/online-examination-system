<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credential Slips · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php if (empty($credentials)): ?>

    <?php
    $page_title = 'Credential slips';
    require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="alert alert--warn">
        <strong>These credentials have already been shown.</strong>
        Passwords are stored hashed, so they cannot be displayed again. The accounts
        themselves are fine &mdash; a student who has lost their password needs a
        new one set by an admin.
    </div>

    <div class="form-actions">
        <a class="btn btn--primary" href="<?= BASE_URL ?>admin/users">Back to users</a>
    </div>

<?php else: ?>

    <?php $count = count($credentials); ?>

    <div class="slips-head">
        <?php
        $page_title = 'Credential slips';
        $page_lead  = $count . ' account' . ($count === 1 ? '' : 's') . ' created';
        $page_lead_class = 'small';
        ob_start(); ?>
                <button type="button" class="btn btn--primary btn--sm" onclick="window.print()">Print</button>
                <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/users">Done</a>
        <?php $page_actions = ob_get_clean();
        require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

        <div class="alert alert--warn">
            <strong>This page will not be shown again.</strong>
            The passwords below are stored hashed, so this is the only time they can be
            read. Print them now. Cut along the lines and hand each student their slip.
        </div>
    </div>

    <div class="slips">
        <?php foreach ($credentials as $c): ?>
        <div class="slip">
            <div class="slip__school"><?= APP_NAME ?></div>

            <div class="slip__name"><?= htmlspecialchars($c['full_name']) ?></div>
            <div class="slip__class"><?= htmlspecialchars($c['class_label']) ?></div>

            <dl class="slip__creds">
                <dt>Admission no.</dt>
                <dd class="slip__mono"><?= htmlspecialchars($c['admission_no']) ?></dd>

                <dt>Password</dt>
                <dd class="slip__mono slip__password"><?= htmlspecialchars($c['password']) ?></dd>
            </dl>

            <div class="slip__foot">
                Sign in with your admission number. Keep this slip safe.
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

</main>
</body>
</html>
