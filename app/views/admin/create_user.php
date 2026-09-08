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

<?php
$page_title = 'Create a user';
require APP_ROOT . '/app/views/_partials/page_head.php'; ?>

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

    <?php
    $oldRole     = $old['role'] ?? 'student';
    $oldClassId  = (int) ($old['class_id'] ?? 0);
    $isStudent   = $oldRole === 'student';
    $classes     = $classes ?? [];
    ?>

    <form method="POST" action="<?= BASE_URL ?>admin/storeUser">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">

        <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required
                   value="<?= htmlspecialchars($old['full_name'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role" required>
                <option value="student"  <?= $oldRole === 'student'  ? 'selected' : '' ?>>Student</option>
                <option value="lecturer" <?= $oldRole === 'lecturer' ? 'selected' : '' ?>>Lecturer</option>
                <option value="admin"    <?= $oldRole === 'admin'    ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>

        <!-- Student identity. Shown only for the student role; the server
             validates the same rule, so a tampered form gains nothing. -->
        <div class="field" data-role-field="student" <?= $isStudent ? '' : 'hidden' ?>>
            <label for="admission_no">Admission number</label>
            <input type="text" id="admission_no" name="admission_no"
                   autocapitalize="characters" spellcheck="false" maxlength="30"
                   placeholder="ADM/2026/0004"
                   <?= $isStudent ? 'required' : '' ?>
                   value="<?= htmlspecialchars($old['admission_no'] ?? '') ?>">
            <p class="help">This is what the student signs in with, so it must be unique. Letters, digits, / and - only.</p>
        </div>

        <div class="field" data-role-field="student" <?= $isStudent ? '' : 'hidden' ?>>
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" <?= $isStudent ? 'required' : '' ?>>
                <option value="">Select a class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $oldClassId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(SchoolClass::labelFor($c)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" data-role-field="staff" <?= $isStudent ? 'hidden' : '' ?>>
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   <?= $isStudent ? '' : 'required' ?>
                   value="<?= htmlspecialchars($old['email'] ?? '') ?>">
            <p class="help">Staff sign in with this address. Students never hold one.</p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8"
                   autocomplete="new-password">
            <p class="help">At least 8 characters. The account holder should change it after first sign-in.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create user</button>
            <a class="btn btn--quiet" href="<?= BASE_URL ?>admin/users">Cancel</a>
        </div>
    </form>

<script>
// Show the student-only fields, and move `required` between admission number
// and email, as the role changes. A hidden input that is still `required`
// blocks submission with a validation message the admin cannot see, so the
// attribute has to move with the visibility.
(function () {
    var role         = document.getElementById('role');
    var studentBits  = document.querySelectorAll('[data-role-field="student"]');
    var staffBits    = document.querySelectorAll('[data-role-field="staff"]');
    var admissionNo  = document.getElementById('admission_no');
    var classId      = document.getElementById('class_id');
    var email        = document.getElementById('email');

    function sync() {
        var isStudent = role.value === 'student';

        studentBits.forEach(function (el) { el.hidden = !isStudent; });
        staffBits.forEach(function (el) { el.hidden = isStudent; });

        admissionNo.required = isStudent;
        classId.required    = isStudent;
        email.required      = !isStudent;
        // A hidden field still submits its value, and the server discards a
        // student's email anyway; clearing it keeps the POST honest and stops a
        // half-typed address reappearing if the admin switches role back.
        if (isStudent) { email.value = ''; }
    }

    role.addEventListener('change', sync);
    sync();
})();
</script>

</main>
</body>
</html>
