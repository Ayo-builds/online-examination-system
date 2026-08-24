<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= (int) $code ?> · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-card center">
        <p class="error-code"><?= (int) $code ?></p>
        <p class="auth-subtitle"><?= htmlspecialchars($message) ?></p>
        <p class="stack-md">
            <a href="<?= BASE_URL ?>">Return home</a>
        </p>
    </main>
</body>
</html>
