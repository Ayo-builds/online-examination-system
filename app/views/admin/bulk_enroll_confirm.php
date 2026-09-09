<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Class Enrolment · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'courses'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--tight">

<?php
$classLabel = SchoolClass::labelFor($class);

$page_title = 'Enrol ' . $classLabel;
$page_lead  = htmlspecialchars($course['course_code'] . ' · ' . $course['title']);
require APP_ROOT . '/app/views/_partials/page_head.php';

$eligible  = (int) $preview['eligible'];
$already   = (int) $preview['already'];
$suspended = (int) $preview['suspended'];
$inClass   = $eligible + $already + $suspended;
?>

    <?php if ($inClass === 0): ?>

        <!-- Nothing to confirm. Offering a button here would only produce a
             run that reports three zeroes. -->
        <div class="empty">
            <p><strong>There are no students in <?= e($classLabel) ?>.</strong></p>
            <p class="muted small">Import or create students into the class first.</p>
            <p>
                <a class="btn btn--quiet btn--sm"
                   href="<?= BASE_URL ?>admin/enrollments/<?= (int) $course['id'] ?>">Back to the roll</a>
            </p>
        </div>

    <?php else: ?>

        <!-- The whole point of this screen: the counts, before anything is
             written. Each line says what will happen to it, so the number in
             the result afterwards is one the admin has already seen. -->
        <div class="confirm-counts">
            <p class="confirm-counts__lead">
                <?= (int) $inClass ?> student<?= $inClass === 1 ? '' : 's' ?>
                in <strong><?= e($classLabel) ?></strong>.
            </p>

            <ul class="confirm-counts__list">
                <li>
                    <strong><?= (int) $eligible ?></strong>
                    <?= $eligible === 1 ? 'student' : 'students' ?> will be enrolled.
                </li>
                <li class="muted">
                    <strong><?= (int) $already ?></strong> already on this roll
                    &mdash; skipped, not re-added.
                </li>
                <li class="muted">
                    <strong><?= (int) $suspended ?></strong> suspended
                    &mdash; left out, as a suspended account cannot sit the exam.
                    Reactivate and run this again to pick them up.
                </li>
            </ul>
        </div>

        <?php if ($eligible === 0): ?>
            <div class="alert alert--warn">
                <strong>Nothing to do.</strong>
                Every student in <?= e($classLabel) ?> who could be enrolled already is.
                Running it would write nothing, which is harmless &mdash; but there is
                no need.
            </div>
        <?php endif; ?>

        <p class="help">
            Enrolling the same class twice is safe: students already on the roll are
            skipped rather than duplicated. Nobody is removed from the roll by this.
        </p>

        <!-- The class is NOT a field here. It is held server-side from the
             preview, so this button can only commit the class whose numbers are
             on the screen above. -->
        <form method="POST"
              action="<?= BASE_URL ?>admin/confirmBulkEnroll/<?= (int) $course['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">

            <!-- With nobody to enrol, the run would write nothing. The button
                 is disabled rather than hidden, so the page still reads as the
                 same screen with its action unavailable, and Cancel takes the
                 primary styling because leaving is now the only thing worth
                 doing here. -->
            <div class="form-actions">
                <button type="submit"
                        class="btn <?= $eligible === 0 ? 'btn--quiet' : 'btn--primary' ?>"
                        <?= $eligible === 0 ? 'disabled' : '' ?>>
                    Enrol <?= (int) $eligible ?> student<?= $eligible === 1 ? '' : 's' ?>
                </button>
                <a class="btn <?= $eligible === 0 ? 'btn--primary' : 'btn--quiet' ?>"
                   href="<?= BASE_URL ?>admin/enrollments/<?= (int) $course['id'] ?>">Cancel</a>
            </div>
        </form>

    <?php endif; ?>

</main>
</body>
</html>
