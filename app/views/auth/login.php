<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="auth-page">

    <main class="auth-card">
        <a class="wordmark" href="<?= BASE_URL ?>marketing/">
            <span class="wordmark__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5.2l3.4 2"></path>
                </svg>
            </span>
            <span class="wordmark__text"><?= APP_NAME ?></span>
        </a>

        <h1>Sign in</h1>
        <p class="auth-subtitle">Use the account your institution issued you.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>auth/authenticate">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus autocomplete="username">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit">Sign in</button>
        </form>

        <p class="auth-foot">
            Trouble signing in? Contact your exam administrator.
        </p>
    </main>

</body>
</html>
