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
                <label for="identifier">Admission number or email</label>
                <!-- Deliberately type="text", not type="email": the browser
                     would reject an admission number before the form was ever
                     submitted. autocapitalize is off so a phone keyboard does
                     not mangle the identifier the server normalises itself. -->
                <input type="text" id="identifier" name="identifier" required autofocus
                       autocomplete="username" autocapitalize="off" spellcheck="false"
                       maxlength="150" placeholder="ADM/2026/0004"
                       value="<?= htmlspecialchars($old["identifier"] ?? "") ?>">
                <p class="help">Students sign in with their admission number. Staff use their email address.</p>
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
