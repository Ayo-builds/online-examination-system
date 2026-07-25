<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= (int) $code ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-card" style="text-align:center;">
        <h1 style="font-size:3rem; margin-bottom:8px;"><?= (int) $code ?></h1>
        <p class="auth-subtitle"><?= htmlspecialchars($message) ?></p>
        <p style="margin-top:20px;">
            <a href="<?= BASE_URL ?>">Return home</a>
        </p>
    </div>
</body>
</html>