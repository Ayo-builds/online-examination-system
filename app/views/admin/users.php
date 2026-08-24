<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php
$page_title = 'Users';
ob_start(); ?>
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/createUser">Create user</a>
<?php $page_actions = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="table-wrap">
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
                    <td class="small"><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="tag"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="tag tag--ok">Active</span>
                        <?php else: ?>
                            <span class="tag tag--flag">Suspended</span>
                        <?php endif; ?>
                    </td>
                    <td class="small nowrap"><?= htmlspecialchars($u['created_at']) ?></td>
                    <td class="actions">
                        <?php if ((int) $u['id'] !== (int) Auth::user()['id']): ?>
                        <form method="POST" action="<?= BASE_URL ?>admin/toggleStatus/<?= (int) $u['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <button type="submit" class="btn btn--sm <?= $u['status'] === 'active' ? 'btn--danger-quiet' : 'btn--ok' ?>">
                                <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="muted small">(you)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>
