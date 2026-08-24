<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'dashboard'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php
$page_title = 'Dashboard';
ob_start(); ?>Signed in as <?= htmlspecialchars($user['name']) ?>.<?php $page_lead = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="grid grid--3">
        <a class="tile" href="<?= BASE_URL ?>admin/users">
            <span class="tile__title">Users</span>
            <span class="tile__body">Create accounts, suspend or reactivate people.</span>
        </a>
        <a class="tile" href="<?= BASE_URL ?>admin/courses">
            <span class="tile__title">Courses</span>
            <span class="tile__body">Set up courses, assign lecturers, manage enrolments.</span>
        </a>
        <a class="tile" href="<?= BASE_URL ?>admin/analytics">
            <span class="tile__title">System analytics</span>
            <span class="tile__body">Usage across every course, and exam integrity rates.</span>
        </a>
    </div>

</main>
</body>
</html>
