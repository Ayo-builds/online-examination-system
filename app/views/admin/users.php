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
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/resetClass">Reset a class</a>
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/importBatches">Import batches</a>
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/importStudents">Import students</a>
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/createUser">Create user</a>
<?php $page_actions = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Admission no.</th>
                    <th>Class</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <?php $classLabel = SchoolClass::labelFor($u); ?>
                <tr>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>

                    <!-- Staff have no admission number and students may have no
                         email, so both columns carry an explicit em dash rather
                         than an empty cell. htmlspecialchars(null) is deprecated
                         from PHP 8.1, which is the other reason for the branch. -->
                    <td class="small nowrap">
                        <?php if ($u['admission_no'] !== null): ?>
                            <?= htmlspecialchars($u['admission_no']) ?>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <td class="small nowrap">
                        <?php if ($classLabel === ''): ?>
                            <span class="muted">&mdash;</span>
                        <?php elseif ($classLabel === 'Unassigned'): ?>
                            <!-- Flagged, not styled as a normal class: these are
                                 the migration's backfilled rows waiting for an
                                 admin to file them under a real class. -->
                            <span class="tag tag--flag">Unassigned</span>
                        <?php else: ?>
                            <span class="tag"><?= htmlspecialchars($classLabel) ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="small">
                        <?php if ($u['email'] !== null): ?>
                            <?= htmlspecialchars($u['email']) ?>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <td><span class="tag"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="tag tag--ok">Active</span>
                        <?php else: ?>
                            <span class="tag tag--flag">Suspended</span>
                        <?php endif; ?>
                    </td>
                    <td class="small nowrap"><?= htmlspecialchars($u['created_at']) ?></td>
                    <td class="actions actions--links">
                        <?php $isSelf = (int) $u['id'] === (int) Auth::user()['id']; ?>

                        <form method="POST" action="<?= BASE_URL ?>admin/resetPassword/<?= (int) $u['id'] ?>"
                              onsubmit="return confirm(<?= $isSelf
                                  ? '\'Reset your OWN password?\n\nYou will need the new one to sign in again. It is shown once - write it down before leaving the page.\''
                                  : '\'Reset the password for ' . htmlspecialchars(addslashes($u['full_name']), ENT_QUOTES) . '?\n\nTheir current password stops working immediately.\'' ?>);">
                            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                            <button type="submit" class="act-link">Reset password</button>
                        </form>

                        <?php if (!$isSelf): ?>
                            <span class="act-sep" aria-hidden="true">&middot;</span>
                            <form method="POST" action="<?= BASE_URL ?>admin/toggleStatus/<?= (int) $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                                <button type="submit" class="act-link <?= $u['status'] === 'active' ? 'act-link--danger' : '' ?>">
                                    <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="act-sep" aria-hidden="true">&middot;</span>
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
