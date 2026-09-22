<?php
/*
 * The page for an error nobody caught. Rendered only by ErrorHandler, with
 * $errorId and $errorDetails (null unless config sets DISPLAY_ERRORS true).
 *
 * It may run before config has loaded, or after the database has failed, so
 * it touches neither the database nor the session and checks each constant
 * before using it. Students, Teachers and Admins all see it, so the wording
 * addresses no one role.
 */
$errorAppName = defined('APP_NAME') ? APP_NAME : 'Online Examination System';
$errorBase    = defined('BASE_URL') ? BASE_URL : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something went wrong · <?= htmlspecialchars($errorAppName) ?></title>
    <?php if ($errorBase !== null): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($errorBase) ?>assets/css/style.css">
    <?php endif; ?>
</head>
<body class="auth-page">
    <main class="auth-card center">
        <p class="error-code">500</p>
        <p class="auth-subtitle">Something went wrong on our side. It wasn't anything you did.
            If it keeps happening, report this code: <strong class="error-id"><?= htmlspecialchars($errorId) ?></strong>.</p>
        <?php if ($errorBase !== null): ?>
        <p class="stack-md">
            <a href="<?= htmlspecialchars($errorBase) ?>">Return home</a>
        </p>
        <?php endif; ?>
        <?php if ($errorDetails !== null): ?>
        <pre class="error-details"><?= htmlspecialchars($errorDetails) ?></pre>
        <?php endif; ?>
    </main>
</body>
</html>
