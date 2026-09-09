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

<main class="shell shell--wide">

<?php
$page_title = 'Users';
ob_start(); ?>
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/resetClass">Reset a class</a>
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/importBatches">Import batches</a>
            <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/importStudents">Import students</a>
            <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/createUser">Create user</a>
<?php $page_actions = ob_get_clean();
require APP_ROOT . '/app/views/_partials/page_head.php';

$listUrl   = BASE_URL . 'admin/users';
$lastPage  = $query->lastPage($total);
$firstRow  = $total === 0 ? 0 : $query->offset() + 1;
$lastRow   = min($query->offset() + $query->perPage, $total);

// A sortable column header. The href comes from the query object, so it carries
// every filter currently in force rather than resetting the list.
$sortable = static function (string $key, string $label) use ($query, $listUrl): void {
    $isOn = $query->sortIndicator($key) !== '';
    printf(
        '<a class="th-sort%s" href="%s">%s<span class="th-sort__mark">%s</span></a>',
        $isOn ? ' th-sort--on' : '',
        e($listUrl . $query->sortUrl($key)),
        e($label),
        e($query->sortIndicator($key))
    );
};
?>

    <!-- GET, deliberately. The filters have to end up in the URL so that every
         sort link and page link can carry them, and so a filtered list can be
         bookmarked or sent to a colleague. No CSRF token: this is a read, and a
         token in a query string is exactly the thing that gets shared. -->
    <form class="filters" method="GET" action="<?= e($listUrl) ?>">
        <div class="filters__row">
            <div class="field field--inline">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?= e($query->q) ?>"
                       placeholder="Name, admission number or email"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="field field--inline">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="all" <?= $query->role === '' ? 'selected' : '' ?>>Everyone</option>
                    <?php foreach (UserListQuery::ROLES as $roleOption): ?>
                        <option value="<?= e($roleOption) ?>" <?= $query->role === $roleOption ? 'selected' : '' ?>>
                            <?= e(ucfirst($roleOption)) ?><?= $roleOption === 'student' ? 's only' : 's' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!$query->isStaffFilter()): ?>
            <!-- Hidden for a staff role: staff have no class, so the filter
                 could only ever return nothing. The query object drops it
                 server-side too, so a hand-edited URL gains nothing. -->
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

            <div class="field field--inline">
                <label for="per_page">Per page</label>
                <select id="per_page" name="per_page">
                    <?php foreach (UserListQuery::PER_PAGES as $size): ?>
                        <option value="<?= (int) $size ?>" <?= $query->perPage === $size ? 'selected' : '' ?>>
                            <?= (int) $size ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filters__actions">
                <button type="submit" class="btn btn--primary btn--sm">Apply</button>
                <?php if ($query->hasActiveFilters()): ?>
                    <a class="btn btn--quiet btn--sm" href="<?= e($listUrl) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sort and direction ride along as hidden fields so applying a filter
             keeps the column you were sorted by. Page deliberately does not:
             a new filter starts at page 1. -->
        <?php if ($query->sort !== null): ?>
            <input type="hidden" name="sort" value="<?= e($query->sort) ?>">
            <input type="hidden" name="dir" value="<?= e(strtolower($query->dir)) ?>">
        <?php endif; ?>
    </form>

    <!-- The default view hides staff, which is not obvious from a table of
         students. Say so, in words, every time a filter is narrowing the list. -->
    <p class="list-summary">
        <?php if ($total === 0): ?>
            <span class="muted">No matching users.</span>
        <?php else: ?>
            Showing <strong><?= (int) $firstRow ?>&ndash;<?= (int) $lastRow ?></strong>
            of <strong><?= (int) $total ?></strong>
        <?php endif; ?>

        <?php if ($query->role === ''): ?>
            <span class="tag tag--muted">All roles</span>
        <?php else: ?>
            <span class="tag"><?= e(ucfirst($query->role)) ?>s only</span>
        <?php endif; ?>

        <?php if ($query->q !== ''): ?>
            <span class="tag">matching &ldquo;<?= e($query->q) ?>&rdquo;</span>
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

    <?php if ($users === []): ?>

        <?php if ($anyUsers): ?>
            <!-- Filtered to nothing. There ARE users; these criteria just miss
                 them all, so the way out is to widen the filters. -->
            <div class="empty">
                <p><strong>No users match these filters.</strong></p>
                <p class="muted small">
                    Try a shorter search, a different role, or clear the filters to start again.
                </p>
                <p><a class="btn btn--primary btn--sm" href="<?= e($listUrl) ?>">Clear filters</a></p>
            </div>
        <?php else: ?>
            <!-- Genuinely empty table. Nothing to clear; the way out is to
                 create or import somebody. -->
            <div class="empty">
                <p><strong>There are no users yet.</strong></p>
                <p class="muted small">Create one by hand, or import a class list.</p>
                <p>
                    <a class="btn btn--primary btn--sm" href="<?= BASE_URL ?>admin/createUser">Create user</a>
                    <a class="btn btn--quiet btn--sm" href="<?= BASE_URL ?>admin/importStudents">Import students</a>
                </p>
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
                    <th class="col--secondary"><?php $sortable('role', 'Role'); ?></th>
                    <th><?php $sortable('status', 'Status'); ?></th>
                    <th class="col--optional"><?php $sortable('joined', 'Joined'); ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <?php $classLabel = SchoolClass::labelFor($u); ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>

                    <!-- Staff have no admission number, so the cell carries an
                         explicit em dash rather than sitting empty. -->
                    <td class="small nowrap">
                        <?php if ($u['admission_no'] !== null): ?>
                            <?= e($u['admission_no']) ?>
                        <?php else: ?>
                            <span class="muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <td class="small nowrap">
                        <?php if ($classLabel === ''): ?>
                            <span class="muted">&mdash;</span>
                        <?php elseif ($classLabel === 'Unassigned'): ?>
                            <span class="tag tag--flag">Unassigned</span>
                        <?php else: ?>
                            <span class="tag"><?= e($classLabel) ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="col--secondary"><span class="tag"><?= e($u['role']) ?></span></td>

                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="tag tag--ok">Active</span>
                        <?php else: ?>
                            <span class="tag tag--flag">Suspended</span>
                        <?php endif; ?>
                    </td>

                    <td class="small nowrap col--optional"><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>

                    <td class="actions actions--links">
                        <?php $isSelf = (int) $u['id'] === (int) Auth::user()['id']; ?>

                        <form method="POST" action="<?= BASE_URL ?>admin/resetPassword/<?= (int) $u['id'] ?>"
                              onsubmit="return confirm(<?= $isSelf
                                  ? '\'Reset your OWN password?\\n\\nYou will need the new one to sign in again. It is shown once - write it down before leaving the page.\''
                                  : '\'Reset the password for ' . e(addslashes($u['full_name'])) . '?\\n\\nTheir current password stops working immediately.\'' ?>);">
                            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                            <button type="submit" class="act-link">Reset password</button>
                        </form>

                        <?php if (!$isSelf): ?>
                            <span class="act-sep" aria-hidden="true">&middot;</span>
                            <form method="POST" action="<?= BASE_URL ?>admin/toggleStatus/<?= (int) $u['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
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

    <?php if ($lastPage > 1): ?>
    <!-- Every href is built by the query object from the current params, so
         paging never silently drops the search or the sort. -->
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

    <?php endif; ?>

</main>
</body>
</html>
