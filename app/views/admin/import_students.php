<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Students · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'users'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$page_title = 'Import students';
$page_lead  = 'Upload a class list and create every account in one pass.';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>admin/previewImport" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="csv">CSV file</label>
            <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            <p class="help">
                Up to <?= StudentImport::MAX_ROWS ?> students per file, 1 MB maximum.
                Nothing is created until you have seen the preview.
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Upload and preview</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/users">Cancel</a>
        </div>
    </form>

    <h2 class="section">What the file should look like</h2>

    <p class="muted small">
        Three columns: name, class, and admission number. A header row is
        optional. Leave the admission number blank and one will be generated,
        continuing the school's existing sequence.
    </p>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>name</th><th>class</th><th>admission_no</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Tunde Bakare</td>
                    <td>SS3A</td>
                    <td class="small nowrap">ADM/2026/0104</td>
                </tr>
                <tr>
                    <td>Chidinma Eze</td>
                    <td>SS3A</td>
                    <td class="muted small">(blank &mdash; one will be generated)</td>
                </tr>
                <tr>
                    <td>Fatima Yusuf</td>
                    <td>JSS1B</td>
                    <td class="muted small">(blank)</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h2 class="section">Classes you can use</h2>

    <p class="muted small">
        Spacing and punctuation do not matter &mdash; <code>SS3A</code>,
        <code>ss3 a</code> and <code>SS3-A</code> all name the same class. A
        class not in this list is reported as an error rather than created.
    </p>

    <p class="ident-list">
        <?php foreach ($classes as $c): ?>
            <span class="tag"><?= htmlspecialchars(SchoolClass::labelFor($c)) ?></span>
        <?php endforeach; ?>
    </p>

    <h2 class="section">What happens on confirm</h2>

    <ul class="muted small">
        <li>Each student gets an account with their admission number as their sign-in name.</li>
        <li>A random password is generated for each one and stored hashed, never in plain text.</li>
        <li>You are shown a printable slip page with every name, admission number and
            password, laid out for cutting up and handing out.</li>
        <li><strong>That page is shown once.</strong> Print it before you navigate away
            &mdash; the passwords cannot be recovered afterwards, only reset.</li>
    </ul>

</main>
</body>
</html>
