<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create User — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 500px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>admin/users">&larr; Users</a></p>
        <h1>Create User</h1>

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

            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required
                   value="<?= htmlspecialchars($old['full_name'] ?? '') ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($old['email'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8">

            <label for="role">Role</label>
            <select id="role" name="role" required>
                <?php $oldRole = $old['role'] ?? ''; ?>
                <option value="student"  <?= $oldRole === 'student'  ? 'selected' : '' ?>>Student</option>
                <option value="lecturer" <?= $oldRole === 'lecturer' ? 'selected' : '' ?>>Lecturer</option>
                <option value="admin"    <?= $oldRole === 'admin'    ? 'selected' : '' ?>>Admin</option>
            </select>

            <button type="submit">Create user</button>
        </form>
    </div>
</body>
</html>