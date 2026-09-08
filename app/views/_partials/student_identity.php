<?php
/**
 * One student's identifying line, for any surface where a teacher acts on a
 * named person.
 *
 * A name alone is not an identifier here. A school running SS3A/SS3B/SS3C will
 * have two students who share a name, and a lecturer marking an essay or
 * reading an invigilation timeline must never have to guess which one is in
 * front of them. Admission number is unique by constraint; the class says who
 * they are at a glance.
 *
 * Presentation only. It reads state and never changes it.
 *
 * Set this before requiring it:
 *
 *   $student   required. A row carrying admission_no, and year_group + arm
 *              where the query joined classes.
 *
 * Usage:
 *   <?= htmlspecialchars($a['student_name']) ?>
 *   <?php $student = $a; require APP_ROOT . '/app/views/_partials/student_identity.php'; ?>
 *
 * $student is cleared afterwards so a second identity line on the same request
 * cannot inherit the previous row.
 */

$si_admission = $student['admission_no'] ?? null;
$si_class     = SchoolClass::labelFor($student);
?>
<span class="ident small muted nowrap">
<?php if ($si_admission !== null && $si_admission !== ''): ?>
    <span class="ident__adm"><?= htmlspecialchars($si_admission) ?></span>
<?php else: ?>
    <span class="ident__adm">&mdash;</span>
<?php endif; ?>
<?php if ($si_class === 'Unassigned'): ?>
    <span class="tag tag--flag">Unassigned</span>
<?php elseif ($si_class !== ''): ?>
    <span class="tag"><?= htmlspecialchars($si_class) ?></span>
<?php endif; ?>
</span>
<?php
unset($student, $si_admission, $si_class);
