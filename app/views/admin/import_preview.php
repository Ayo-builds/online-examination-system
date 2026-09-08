<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Preview · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--narrow">

<?php
$page_title = 'Import preview';
$page_lead  = htmlspecialchars($filename);
$page_lead_class = 'small';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php $valid = count($rows); $bad = count($errors); ?>

    <div class="grid grid--2">
        <div class="stat">
            <span class="stat__label">Will be created</span>
            <span class="stat__value"><?= $valid ?></span>
        </div>
        <div class="stat">
            <span class="stat__label">Rows with problems</span>
            <span class="stat__value <?= $bad > 0 ? 'danger' : '' ?>"><?= $bad ?></span>
        </div>
    </div>

    <?php if ($bad > 0): ?>
        <div class="alert alert-error">
            <strong><?= $bad ?> row<?= $bad === 1 ? '' : 's' ?> will be skipped.</strong>
            Fix them in the file and upload again if you want them included. Confirming
            now imports only the <?= $valid ?> valid row<?= $valid === 1 ? '' : 's' ?> below.
        </div>

        <h2 class="section">Problems</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th class="right">Line</th><th>Problem</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $e): ?>
                    <tr>
                        <td class="num"><?= $e['line'] > 0 ? (int) $e['line'] : '&mdash;' ?></td>
                        <td class="small"><?= htmlspecialchars($e['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($valid === 0): ?>

        <div class="alert alert--warn">
            There is nothing to import. Fix the file and upload it again.
        </div>
        <div class="form-actions">
            <a class="btn btn--primary" href="<?= BASE_URL ?>admin/importStudents">Upload another file</a>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/users">Cancel</a>
        </div>

    <?php else: ?>

        <h2 class="section">Accounts that will be created</h2>

        <p class="muted small">
            An admission number shown as <span class="tag">generated</span> was not in your
            file; it continues the school's existing sequence. Passwords are generated on
            confirm, not now.
        </p>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="right">Line</th>
                        <th>Name</th>
                        <th>Admission no.</th>
                        <th>Class</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="num muted"><?= (int) $r['line'] ?></td>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td class="small nowrap">
                            <?= htmlspecialchars($r['admission_no']) ?>
                            <?php if (!empty($r['generated'])): ?>
                                <span class="tag tag--muted">generated</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="tag"><?= htmlspecialchars($r['class_label']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert--info">
            Confirming creates <?= $valid ?> account<?= $valid === 1 ? '' : 's' ?> and then shows
            the printable credential slips <strong>once</strong>. Have a printer ready.
            <?php if ($valid > 100): ?>
                A file this size takes around <?= (int) ceil($valid * 0.11) ?> seconds to
                process &mdash; leave the page alone until it finishes.
            <?php endif; ?>
        </div>

        <form method="POST" action="<?= BASE_URL ?>admin/confirmImport">
            <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    Create <?= $valid ?> student account<?= $valid === 1 ? '' : 's' ?>
                </button>
                <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/importStudents">Upload a different file</a>
            </div>
        </form>

    <?php endif; ?>

</main>
</body>
</html>
