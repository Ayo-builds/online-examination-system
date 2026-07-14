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
        <p><a href="<?= BASE_URL ?>auth/logout">Log out</a></p>
    </div>
</body>
</html>