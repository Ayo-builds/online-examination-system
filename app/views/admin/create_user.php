<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

    <a class="backlink" href="<?= BASE_URL ?>admin/users">&larr; Users</a>

    <div class="page__head">
        <div>
            <p class="eyebrow">Admin</p>
            <h1 class="page__title">Create a user</h1>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>admin/storeUser">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required
                   value="<?= htmlspecialchars($old['full_name'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($old['email'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8"
                   autocomplete="new-password">
            <p class="help">At least 8 characters. The account holder should change it after first sign-in.</p>
        </div>

        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
                <?php $oldRole = $old['role'] ?? ''; ?>
                <option value="student"  <?= $oldRole === 'student'  ? 'selected' : '' ?>>Student</option>
                <option value="lecturer" <?= $oldRole === 'lecturer' ? 'selected' : '' ?>>Lecturer</option>
                <option value="admin"    <?= $oldRole === 'admin'    ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create user</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/users">Cancel</a>
        </div>
    </form>

</main>
</body>
</html>
