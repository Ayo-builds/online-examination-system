<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrolments · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php $nav_current = 'courses'; require APP_ROOT . '/app/views/_partials/topbar.php'; ?>

<main class="shell shell--wide">

<?php
$page_title = 'Enrolments';
$page_lead  = htmlspecialchars($course['course_code'] . ' · ' . $course['title']);
require APP_ROOT . '/app/views/_partials/page_head.php';

$courseId = (int) $course['id'];
$listUrl  = BASE_URL . 'admin/enrollments/' . $courseId;
$lastPage = $query->lastPage($total);
$firstRow = $total === 0 ? 0 : $query->offset() + 1;
$lastRow  = min($query->offset() + $query->perPage, $total);

// A sortable column header. The href comes from the query object, so it carries
// every filter currently in force rather than resetting the list.
$sortable = static function (string $key, string $label) use ($query, $listUrl): void {
    printf(
        '<a class="th-sort" href="%s">%s<span class="th-sort__mark">%s</span></a>',
        e($listUrl . $query->sortUrl($key)),
        e($label),
        e($query->sortIndicator($key))
    );
};
?>

    <?php if ($bulkResult !== null): ?>
        <?php
        $promised = $bulkResult['promised'];
        $actual   = $bulkResult['actual'];

        // Preview and confirm were two requests. If the class shifted under
        // them, both numbers are shown rather than the actual one alone - an
        // admin who was promised 30 and got 28 needs to know that, not to be
        // told 28 as though it had always been the plan.
        $enrolledDiffers = (int) $actual['enrolled'] !== (int) $promised['eligible'];
        $alreadyDiffers  = (int) $actual['already']  !== (int) $promised['already'];
        ?>

        <?php if ($actual['error'] !== null): ?>
            <div class="alert alert-error"><?= e($actual['error']) ?></div>
        <?php else: ?>
            <div class="alert alert--ok">
                <p>
                    <strong><?= (int) $actual['enrolled'] ?></strong>
                    student<?= (int) $actual['enrolled'] === 1 ? '' : 's' ?>
                    enrolled from <?= e($bulkResult['class_label']) ?>,
                    <strong><?= (int) $actual['already'] ?></strong>
                    already on the roll and skipped.
                </p>

                <?php if ($enrolledDiffers || $alreadyDiffers): ?>
                    <!-- Not an error. The class changed between the screen and
                         the write, which is allowed to happen; saying so is the
                         difference between a report and a guess. -->
                    <p class="small">
                        This differs from the confirmation screen, which showed
                        <?php if ($enrolledDiffers): ?>
                            <strong><?= (int) $promised['eligible'] ?></strong> to enrol<?= $alreadyDiffers ? ' and ' : '' ?>
                        <?php endif; ?>
                        <?php if ($alreadyDiffers): ?>
                            <strong><?= (int) $promised['already'] ?></strong> already enrolled
                        <?php endif; ?>.
                        The class changed between confirming and running &mdash; a student
                        added, suspended, or enrolled from another screen.
                    </p>
                <?php endif; ?>

                <?php if ((int) $actual['unaccounted'] !== 0): ?>
                    <!-- Deliberately not folded into "skipped". These rows were
                         counted as candidates and then neither enrolled nor
                         found already enrolled, and calling them skipped would
                         be inventing a reason. -->
                    <p class="small">
                        <strong><?= (int) $actual['unaccounted'] ?></strong>
                        of the <?= (int) $actual['candidates'] ?> students counted
                        were neither enrolled nor found already enrolled. Re-run the
                        enrolment to pick them up, and if the number persists, check
                        the roll by hand before the next exam.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h2 class="section">Enrol a whole class</h2>

    <!-- POST, not GET: it leads to a confirmation screen that counts what the
         run would do. The count has to come from the server, so this cannot be
         a link. -->
    <form method="POST" action="<?= BASE_URL ?>admin/bulkEnroll/<?= $courseId ?>" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <label class="sr-only" for="bulk_class_id">Class</label>
        <select id="bulk_class_id" name="class_id" required>
            <option value="">&mdash; Select a class &mdash;</option>
            <?php foreach ($bulkClasses as $c): ?>
                <?php $n = (int) $c['student_count']; ?>
                <option value="<?= (int) $c['id'] ?>" <?= $n === 0 ? 'disabled' : '' ?>>
                    <?= e(SchoolClass::labelFor($c)) ?>
                    &mdash; <?= $n ?> active student<?= $n === 1 ? '' : 's' ?>
                    <?= $n === 0 ? ' (nobody to enrol)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary btn--sm">Review&hellip;</button>
    </form>
    <p class="help">
        You will see how many would be enrolled, and how many are already on this
        roll, before anything is written. Students already enrolled are skipped, so
        running it twice is safe.
    </p>

    <h2 class="section">Enrol one student</h2>
    <?php if (empty($available)): ?>
        <p class="muted small">Every active student is already enrolled in this course.</p>
    <?php else: ?>
    <form method="POST" action="<?= BASE_URL ?>admin/enroll/<?= $courseId ?>" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
        <label class="sr-only" for="student_id">Student</label>
        <select id="student_id" name="student_id" required>
            <option value="">&mdash; Select student &mdash;</option>
            <?php foreach ($available as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--ok btn--sm">Enrol</button>
    </form>
    <?php endif; ?>

    <h2 class="section">Enrolled students</h2>

    <!-- GET, deliberately. The filters have to end up in the URL so that every
         sort link and page link can carry them, and so a filtered roll can be
         bookmarked or sent to a colleague. No CSRF token: this is a read, and a
         token in a query string is exactly the thing that gets shared. -->
    <form class="filters" method="GET" action="<?= e($listUrl) ?>">
        <div class="filters__row">
            <div class="field field--inline filters__search">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?= e($query->q) ?>"
                       placeholder="Name or admission number"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="field field--inline">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Any status</option>
                    <?php foreach (EnrollmentListQuery::STATUSES as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>"
                            <?= $query->status === $statusOption ? 'selected' : '' ?>>
                            <?= e(ucfirst($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($classes !== []): ?>
            <!-- Only the classes actually on this roll. Offering every class in
                 the school would let an admin pick one that can only ever
                 return an empty list. -->
            <div class="field field--inline">
                <label for="class_id">Class</label>
                <select id="class_id" name="class_id">
                    <option value="">Any class</option>
                    <?php foreach ($classes as $classRow): ?>
                        <option value="<?= (int) $classRow['id'] ?>"
                            <?= $query->classId === (int) $classRow['id'] ? 'selected' : '' ?>>
                            <?= e(SchoolClass::labelFor($classRow)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filters__actions">
                <button type="submit" class="btn btn--primary btn--sm">Apply</button>
                <?php if ($query->hasActiveFilters()): ?>
                    <a class="btn btn--quiet btn--sm" href="<?= e($listUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sort, direction and page size ride along as hidden fields so that
             applying a filter keeps the column you were sorted by and the page
             size you chose. Page deliberately does not: a new filter starts at
             page 1. -->
        <input type="hidden" name="per_page" value="<?= (int) $query->perPage ?>">
        <?php if ($query->sort !== null): ?>
            <input type="hidden" name="sort" value="<?= e($query->sort) ?>">
            <input type="hidden" name="dir" value="<?= e(strtolower($query->dir)) ?>">
        <?php endif; ?>
    </form>

    <p class="list-summary">
        <?php if ($total === 0): ?>
            <span class="muted">No matching students.</span>
        <?php else: ?>
            Showing <strong><?= (int) $firstRow ?>&ndash;<?= (int) $lastRow ?></strong>
            of <strong><?= (int) $total ?></strong> enrolled
        <?php endif; ?>

        <?php if ($query->q !== ''): ?>
            <span class="tag">matching &ldquo;<?= e($query->q) ?>&rdquo;</span>
        <?php endif; ?>

        <?php if ($query->status !== ''): ?>
            <span class="tag"><?= e(ucfirst($query->status)) ?></span>
        <?php endif; ?>

        <?php if ($query->classId !== null): ?>
            <?php foreach ($classes as $classRow): ?>
                <?php if ((int) $classRow['id'] === $query->classId): ?>
                    <span class="tag"><?= e(SchoolClass::labelFor($classRow)) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($query->sort !== null): ?>
            <span class="muted small">sorted by <?= e($query->sort) ?>,
                <?= $query->dir === 'ASC' ? 'ascending' : 'descending' ?></span>
        <?php endif; ?>
    </p>

    <?php if ($enrolled === []): ?>

        <?php if ($anyEnrolled): ?>
            <!-- Filtered to nothing. There ARE students on this roll; these
                 criteria just miss them all. -->
            <div class="empty empty--tight">
                <p>No enrolled students match these filters.</p>
                <a class="btn btn--primary btn--sm" href="<?= e($listUrl) ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <!-- Genuinely empty roll. Nothing to clear; the way out is to enrol
                 somebody, which is what the forms above are for. -->
            <div class="empty">
                <p><strong>No students enrolled yet.</strong></p>
                <p class="muted small">Enrol a whole class, or add students one at a time.</p>
            </div>
        <?php endif; ?>

    <?php else: ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php $sortable('name', 'Name'); ?></th>
                    <th><?php $sortable('admission_no', 'Admission no.'); ?></th>
                    <th><?php $sortable('class', 'Class'); ?></th>
                    <th><?php $sortable('status', 'Status'); ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrolled as $s): ?>
                <?php $classLabel = SchoolClass::labelFor($s); ?>
                <tr>
                    <td><?= e($s['full_name']) ?></td>
                    <td class="small nowrap"><?= e($s['admission_no'] ?? '') ?></td>

                    <td class="small nowrap">
                        <?php if ($classLabel === ''): ?>
                            <span class="muted">&mdash;</span>
                        <?php elseif ($classLabel === 'Unassigned'): ?>
                            <span class="tag tag--flag">Unassigned</span>
                        <?php else: ?>
                            <span class="tag"><?= e($classLabel) ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- Only the exception is marked. Active is the norm on a
                         roll; a column of green pills would bury the one
                         suspended account it exists to surface. -->
                    <td>
                        <?php if ($s['status'] !== 'active'): ?>
                            <span class="tag tag--flag">Suspended</span>
                        <?php endif; ?>
                    </td>

                    <td class="actions actions--links">
                        <form method="POST" action="<?= BASE_URL ?>admin/unenroll/<?= $courseId ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                            <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="act-link act-link--danger">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Page size and pager share a bar under the table. Both build their
         hrefs from the same query object as the sort links, so neither can drop
         a filter that is in force. -->
    <div class="pager-bar">

        <div class="per-page">
            <span class="per-page__label">Per page</span>
            <?php foreach (EnrollmentListQuery::PER_PAGES as $size): ?>
                <?php if ($size === $query->perPage): ?>
                    <span class="per-page__opt per-page__opt--on" aria-current="true"><?= (int) $size ?></span>
                <?php else: ?>
                    <!-- Back to page 1: page 6 of 50-per-page is not page 6 of
                         25-per-page, so carrying the number over would land
                         somewhere arbitrary. -->
                    <a class="per-page__opt"
                       href="<?= e($listUrl . $query->urlWith(['per_page' => $size, 'page' => 1])) ?>"><?= (int) $size ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

    <?php if ($lastPage > 1): ?>
    <nav class="pager" aria-label="Pagination">
        <?php if ($query->page > 1): ?>
            <a class="pager__step" href="<?= e($listUrl . $query->urlWith(['page' => $query->page - 1])) ?>"
               rel="prev">&larr; Previous</a>
        <?php else: ?>
            <span class="pager__step pager__step--off">&larr; Previous</span>
        <?php endif; ?>

        <span class="pager__pages">
            <?php
            // A window around the current page, with the first and last always
            // reachable, so a 6-page list and a 60-page list look the same.
            $window = 2;
            $shown  = [];
            for ($p = 1; $p <= $lastPage; $p++) {
                if ($p === 1 || $p === $lastPage || abs($p - $query->page) <= $window) {
                    $shown[] = $p;
                }
            }
            $previous = 0;
            foreach ($shown as $p):
                if ($previous !== 0 && $p > $previous + 1): ?>
                    <span class="pager__gap">&hellip;</span>
                <?php endif;
                $previous = $p; ?>

                <?php if ($p === $query->page): ?>
                    <span class="pager__page pager__page--on" aria-current="page"><?= (int) $p ?></span>
                <?php else: ?>
                    <a class="pager__page" href="<?= e($listUrl . $query->urlWith(['page' => $p])) ?>"><?= (int) $p ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </span>

        <?php if ($query->page < $lastPage): ?>
            <a class="pager__step" href="<?= e($listUrl . $query->urlWith(['page' => $query->page + 1])) ?>"
               rel="next">Next &rarr;</a>
        <?php else: ?>
            <span class="pager__step pager__step--off">Next &rarr;</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    </div>

    <?php endif; ?>

</main>
</body>
</html>
