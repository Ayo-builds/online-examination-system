<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px;">
        <h1>Admin Dashboard</h1>
        <p>Welcome, <?= htmlspecialchars($user['name']) ?> — role: <strong><?= htmlspecialchars($user['role']) ?></strong></p>
        <p style="margin-top:14px;">
            <a href="<?= BASE_URL ?>admin/users">Users</a> ·
            <a href="<?= BASE_URL ?>admin/courses">Courses</a> ·
            <a href="<?= BASE_URL ?>admin/analytics">System analytics</a>
        </p>
        <p><a href="<?= BASE_URL ?>auth/logout">Log out</a></p>
    </div>
</body>
</html>