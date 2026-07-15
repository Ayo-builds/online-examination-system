<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="padding: 30px; max-width: 900px; margin: 0 auto;">
        <p><a href="<?= BASE_URL ?>admin/dashboard">&larr; Dashboard</a></p>
        <h1>Users</h1>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td><?= htmlspecialchars($u['status']) ?></td>
                    <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td>
                        <?php if ((int) $u['id'] !== (int) Auth::user()['id']): ?>
                        <form method="POST"
                              action="<?= BASE_URL ?>admin/toggleStatus/<?= (int) $u['id'] ?>"
                              style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <button type="submit" class="btn-small <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-success' ?>">
                                <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span style="color:#65676b; font-size:.85rem;">(you)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>