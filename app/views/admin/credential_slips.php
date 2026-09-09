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
        themselves are fine &mdash; anyone who has lost their password needs a new one
        issued from the <a href="<?= BASE_URL ?>admin/users">Users</a> page.
    </div>

    <div class="form-actions">
        <a class="btn btn--primary" href="<?= BASE_URL ?>admin/users">Back to users</a>
    </div>

<?php else: ?>

    <?php
    $count = count($credentials);

    // One page serves the import and both reset paths. Only the wording differs;
    // the slips themselves come from the shared partial either way.
    switch ($context ?? 'import') {
        case 'reset':
            $lead = $count . ' password' . ($count === 1 ? '' : 's') . ' reset';
            break;
        case 'reset_class':
            $lead = $count . ' password' . ($count === 1 ? '' : 's') . ' reset in ' . $slip_label;
            break;
        default:
            $lead = $count . ' account' . ($count === 1 ? '' : 's') . ' created'
                  . ($slip_label !== '' ? ' from ' . $slip_label : '');
    }
    ?>

    <div class="slips-head">
        <?php
        $page_title = 'Credential slips';
        $page_lead  = htmlspecialchars($lead);
        $page_lead_class = 'small';
        ob_start(); ?>
                <button type="button" class="btn btn--primary btn--sm" onclick="window.print()">Print</button>
                <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/users">Done</a>
        <?php $page_actions = ob_get_clean();
        require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

        <div class="alert alert--warn">
            <strong>This page will not be shown again.</strong>
            The password<?= $count === 1 ? '' : 's' ?> below
            <?= $count === 1 ? 'is' : 'are' ?> stored hashed, so this is the only time
            <?= $count === 1 ? 'it' : 'they' ?> can be read.
            <?php if ($count === 1): ?>
                Write it down before you navigate away.
            <?php else: ?>
                Print them now. Cut along the lines and hand each person their slip.
            <?php endif; ?>
        </div>
    </div>

    <?php require APP_ROOT . '/app/views/_partials/credential_slips.php'; ?>

<?php endif; ?>

</main>
</body>
</html>
