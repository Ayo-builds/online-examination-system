<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Batches · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php
$page_title = 'Import batches';
$page_lead  = 'Every bulk import, and what it created.';
ob_start(); ?>
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/importStudents">Import students</a>
<?php $page_actions = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if ($result !== null): ?>
        <?php $skipped = $result['skipped']; $nSkipped = count($skipped); ?>

        <?php if ($result['error'] !== null): ?>
            <div class="alert alert-error"><?= htmlspecialchars($result['error']) ?></div>
        <?php else: ?>
            <div class="alert <?= $nSkipped > 0 ? 'alert--warn' : 'alert--ok' ?>">
                <strong>
                    <?= (int) $result['deleted'] ?>
                    account<?= (int) $result['deleted'] === 1 ? '' : 's' ?>
                    deleted from <?= htmlspecialchars($result['filename']) ?>.
                </strong>
                <?php if ($nSkipped > 0): ?>
                    <?= $nSkipped ?> <?= $nSkipped === 1 ? 'was' : 'were' ?> kept because
                    <?= $nSkipped === 1 ? 'it has' : 'they have' ?> already sat an exam.
                    Deleting <?= $nSkipped === 1 ? 'it' : 'them' ?> would destroy submitted work.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($nSkipped > 0): ?>
            <h2 class="section">Kept: <?= $nSkipped ?> account<?= $nSkipped === 1 ? '' : 's' ?> with exam attempts</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Name</th><th>Admission no.</th><th>Class</th><th class="right">Attempts</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($skipped as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td class="small nowrap"><?= htmlspecialchars($s['admission_no']) ?></td>
                            <td>
                                <?php $label = SchoolClass::labelFor($s); ?>
                                <?php if ($label === ''): ?>
                                    <span class="muted">&mdash;</span>
                                <?php else: ?>
                                    <span class="tag"><?= htmlspecialchars($label) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= (int) $s['attempts'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small">
                These accounts are still in the system. Suspend them from the
                <a href="<?= BASE_URL ?>admin/users">Users</a> page if they should not be
                used, which keeps their scripts intact.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (empty($batches)): ?>

        <div class="empty">
            <p>No students have been imported yet.</p>
        </div>

    <?php else: ?>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Imported</th>
                        <th>By</th>
                        <th class="right">Created</th>
                        <th class="right">Remaining</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $b): ?>
                    <?php $remaining = (int) $b['remaining']; $locked = (int) $b['locked']; ?>
                    <tr>
                        <td><?= htmlspecialchars($b['filename']) ?></td>
                        <td class="small nowrap"><?= htmlspecialchars($b['created_at']) ?></td>
                        <td class="small">
                            <?php if ($b['imported_by_name'] !== null): ?>
                                <?= htmlspecialchars($b['imported_by_name']) ?>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $b['row_count'] ?></td>
                        <td class="num">
                            <?= $remaining ?>
                            <?php if ($locked > 0): ?>
                                <br><span class="tag tag--warn"><?= $locked ?> sat an exam</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php $deletable = $remaining - $locked; ?>
                            <?php if ($remaining === 0): ?>
                                <span class="muted small">Nothing to delete</span>
                            <?php elseif ($deletable === 0): ?>
                                <!-- Everyone left has sat an exam, so there is no
                                     button to offer: a "Delete 0" would do nothing
                                     and read like a fault. -->
                                <span class="muted small">All kept &mdash; every one has sat an exam</span>
                            <?php else: ?>
                            <form method="POST"
                                  action="<?= BASE_URL ?>admin/deleteImportBatch/<?= htmlspecialchars($b['id']) ?>"
                                  onsubmit="return confirm('Delete <?= $deletable ?> account(s) created by <?= htmlspecialchars(addslashes($b['filename'])) ?>?<?= $locked > 0 ? '\n\n' . $locked . ' account(s) will be kept because they have sat an exam.' : '' ?>\n\nThis cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                                <button type="submit" class="btn btn--danger-quiet btn--sm">
                                    Delete <?= $deletable ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="muted small">
            Deleting a batch removes the accounts it created, and their course enrolments
            with them. An account that has started or submitted an exam is never deleted,
            because its script would go too. The batch itself stays listed either way, as
            the record of what was imported.
        </p>

    <?php endif; ?>

</main>
</body>
</html>
