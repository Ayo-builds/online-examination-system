<?php
/**
 * Step 5b: the enrolments list and bulk enrolment by class.
 *
 *   php tests/setup_users_fixture.php        (once - imports the 250 students)
 *   php tests/enrollments_list_test.php
 *   php tests/teardown_users_fixture.php     (when finished)
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and against the 250-row fixture imported through the real CSV importer, so
 * the rows carry a genuine batch id and the teardown can remove them by it.
 *
 * What this file creates of its own, and removes again at the end:
 *
 *   one lecturer      the courses table needs a lecturer_id foreign key
 *   one course        the roll under test
 *   enrolments        deleted with the course, by the ON DELETE CASCADE
 *
 * Those three are deleted by the ids recorded when they were created, and by
 * nothing else. No DELETE by role, by name or by date, and never a bare
 * DELETE FROM users: the fixture students are the teardown's business, not
 * this script's, and the only rows it may remove are the ones it made.
 *
 * Two students have their status flipped to exercise the suspended path. Their
 * ids and original statuses are recorded and restored, so a run that completes
 * leaves the fixture exactly as it found it.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// ---- Assertions -----------------------------------------------------------

$passed = 0;
$failed = [];

function check(string $what, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        return;
    }

    $failed[] = $what . ($detail === '' ? '' : '  (' . $detail . ')');
    echo "  FAIL  $what" . ($detail === '' ? '' : "  ($detail)") . "\n";
}

function same(string $what, $expected, $actual): void
{
    check(
        $what,
        $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

// ---- The fixture has to be there ------------------------------------------

$db = Database::getInstance();

$marker = APP_ROOT . '/tests/.batch_id';
if (!is_file($marker)) {
    fwrite(STDERR, "No tests/.batch_id. Run: php tests/setup_users_fixture.php\n");
    exit(1);
}

$batchId  = trim((string) file_get_contents($marker));
$students = (new ImportBatch())->members($batchId);

if (count($students) < 100) {
    fwrite(STDERR, "Fixture looks wrong: " . count($students) . " students in batch $batchId.\n");
    exit(1);
}

printf("fixture: %d students in batch %s\n", count($students), $batchId);

// ---- Which classes to work with -------------------------------------------
//
// Chosen before anything is created, so the "not enough classes" exit below
// has nothing to clean up. exit() does not run finally blocks, and every bail
// out after this point would leave a stray course behind if it did not.
//
// The two fullest classes in the fixture, picked by query rather than
// hardcoded, so a change to the generator does not silently pick an empty one.
$classRows = $db->prepare(
    "SELECT c.id, c.year_group, c.arm, COUNT(u.id) AS n
       FROM classes c
       JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active'
      WHERE u.import_batch_id = ?
   GROUP BY c.id, c.year_group, c.arm
   ORDER BY n DESC, c.id ASC"
);
$classRows->execute([$batchId]);
$classes = $classRows->fetchAll();

if (count($classes) < 2) {
    fwrite(STDERR, "Fixture spans fewer than two classes; nothing to test.\n");
    exit(1);
}

$classA     = $classes[0];
$classB     = $classes[1];
$classAId   = (int) $classA['id'];
$classBId   = (int) $classB['id'];
$classASize = (int) $classA['n'];
$classBSize = (int) $classB['n'];

printf("classes: %s (%d active), %s (%d active)\n\n",
    SchoolClass::labelFor($classA), $classASize,
    SchoolClass::labelFor($classB), $classBSize);

// ---- Scaffolding this script owns and removes ------------------------------

$db->prepare(
    "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'lecturer')"
)->execute(['Test Lecturer', 'testlecturer@exam.local', Password::hash('test-lecturer-pass-123')]);
$lecturerId = (int) $db->lastInsertId();

$courseModel = new Course();
$courseId    = $courseModel->create('TST/5B', 'Enrolment List Test Course', $lecturerId);
$course      = $courseModel->find($courseId);

$enrollments = new Enrollment();

// Statuses this script changes, so they can be put back.
$statusRestore = [];

// Everything below runs inside try/finally: a failed assertion must not leave
// a stray course, a stray lecturer or a suspended fixture student behind.
try {

// ===========================================================================
section('EnrollmentListQuery: request values never reach SQL as text');

// A sort key that is not on the allowlist falls back to the default rather
// than erroring, so a stale bookmark still shows a sensible list.
$junk = new EnrollmentListQuery($courseId, ['sort' => 'u.password_hash', 'dir' => 'up']);
same('unknown sort key falls back to default', null, $junk->sort);
check('unknown sort key leaves no request text in ORDER BY',
    strpos($junk->orderBy(), 'password_hash') === false, $junk->orderBy());
same('a direction that is not "desc" is ASC', 'ASC', $junk->dir);

// The classic injection attempt, through both identifier params at once.
$inject = new EnrollmentListQuery($courseId, [
    'sort' => "name; DROP TABLE users --",
    'dir'  => "ASC; DROP TABLE users --",
]);
$injectedOrder = $inject->orderBy();
check('injected sort is discarded', strpos($injectedOrder, 'DROP') === false, $injectedOrder);
check('injected dir is discarded', strpos($injectedOrder, '--') === false, $injectedOrder);
check('ORDER BY always ends with the id tiebreaker',
    substr($injectedOrder, -10) === ', u.id ASC', $injectedOrder);

foreach (array_keys(EnrollmentListQuery::SORTS) as $key) {
    $sorted = new EnrollmentListQuery($courseId, ['sort' => $key, 'dir' => 'desc']);
    check("sort '$key' ends with the id tiebreaker",
        substr($sorted->orderBy(), -10) === ', u.id ASC', $sorted->orderBy());
}

// LIMIT and OFFSET are interpolated, so their inputs are the thing to pin down.
$sizes = new EnrollmentListQuery($courseId, ['per_page' => '999', 'page' => '-4']);
same('per_page off the allowlist falls back to 50', 50, $sizes->perPage);
same('a negative page is floored at 1', 1, $sizes->page);
same('offset of page 1 is 0', 0, $sizes->offset());

$sized = new EnrollmentListQuery($courseId, ['per_page' => '100', 'page' => '3']);
same('an allowlisted per_page is honoured', 100, $sized->perPage);
same('offset is page-1 times per_page', 200, $sized->offset());

$longQ = new EnrollmentListQuery($courseId, ['q' => str_repeat('a', 400)]);
same('an over-long search term is truncated', 100, mb_strlen($longQ->q));

$badStatus = new EnrollmentListQuery($courseId, ['status' => 'deleted']);
same('a status off the allowlist is dropped', '', $badStatus->status);

// ===========================================================================
section('EnrollmentListQuery: the course scope and the shared WHERE');

$scoped = new EnrollmentListQuery($courseId, []);
[$where, $bindings] = $scoped->where();

check('the course clause is always present', strpos($where, 'e.course_id = ?') !== false, $where);
same('the course id is the first binding', $courseId, $bindings[0]);
same('an unfiltered WHERE binds only the course', 1, count($bindings));

$filtered = new EnrollmentListQuery($courseId, [
    'q'        => 'Bola',
    'status'   => 'active',
    'class_id' => (string) $classAId,
]);
[$fWhere, $fBindings] = $filtered->where();
same('every placeholder has a binding',
    substr_count($fWhere, '?'), count($fBindings));
check('the course clause survives the other filters',
    strpos($fWhere, 'e.course_id = ?') !== false, $fWhere);

// The course lives in the path, so it must not also appear in the query string
// where a hand-edited link could make the two disagree.
check('course_id stays out of the link params',
    strpos($filtered->urlWith(), 'course_id') === false, $filtered->urlWith());

// ===========================================================================
section('EnrollmentListQuery: links carry the filters');

$linked = new EnrollmentListQuery($courseId, [
    'q'        => 'Ada',
    'status'   => 'active',
    'class_id' => (string) $classAId,
    'per_page' => '25',
    'page'     => '2',
]);

foreach ([
    'a page link'  => $linked->urlWith(['page' => 3]),
    'a sort link'  => $linked->sortUrl('name'),
    'a size link'  => $linked->urlWith(['per_page' => 100, 'page' => 1]),
] as $label => $url) {
    check("$label keeps the search term", strpos($url, 'q=Ada') !== false, $url);
    check("$label keeps the status filter", strpos($url, 'status=active') !== false, $url);
    check("$label keeps the class filter",
        strpos($url, 'class_id=' . $classAId) !== false, $url);
}

check('a sort link returns to page 1',
    strpos($linked->sortUrl('name'), 'page=1') !== false, $linked->sortUrl('name'));

$onName = new EnrollmentListQuery($courseId, ['sort' => 'name', 'dir' => 'asc']);
check('clicking the active ascending column asks for descending',
    strpos($onName->sortUrl('name'), 'dir=desc') !== false, $onName->sortUrl('name'));
same('the active column shows an ascending mark', '▲', $onName->sortIndicator('name'));
same('an inactive column shows no mark', '', $onName->sortIndicator('class'));

// ===========================================================================
section('Bulk enrolment: the preview counts without writing');

$before = (int) $db->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();

$preview = $enrollments->bulkPreview($courseId, $classAId);
same('preview: everyone in the class is eligible on an empty roll', $classASize, $preview['eligible']);
same('preview: nobody is already enrolled', 0, $preview['already']);
same('preview: no suspended students in the fixture', 0, $preview['suspended']);

same('the preview wrote nothing',
    $before, (int) $db->query("SELECT COUNT(*) FROM enrollments")->fetchColumn());

// ===========================================================================
section('Bulk enrolment: the first run');

$first = $enrollments->enrollClass($courseId, $classAId);

same('no error', null, $first['error']);
same('every active student in the class was enrolled', $classASize, $first['enrolled']);
same('none were already enrolled', 0, $first['already']);
same('the candidate count is the class size', $classASize, $first['candidates']);
same('nothing is unaccounted for', 0, $first['unaccounted']);

same('the roll holds exactly the class',
    $classASize,
    (int) $db->query("SELECT COUNT(*) FROM enrollments WHERE course_id = $courseId")->fetchColumn());

// ===========================================================================
section('Bulk enrolment: idempotence');

$second = $enrollments->enrollClass($courseId, $classAId);

same('a second run enrols nobody', 0, $second['enrolled']);
same('a second run reports the whole class as already enrolled', $classASize, $second['already']);
same('a second run accounts for every candidate', 0, $second['unaccounted']);
same('a second run is not an error', null, $second['error']);

same('the roll did not grow',
    $classASize,
    (int) $db->query("SELECT COUNT(*) FROM enrollments WHERE course_id = $courseId")->fetchColumn());

// One student removed by hand, then a re-run: the gap is filled and nothing
// else is touched. This is the case the whole thing exists for - a student
// joins the class after the first enrolment.
$oneStudent = $db->prepare(
    "SELECT id FROM users WHERE class_id = ? AND role = 'student' AND status = 'active'
      ORDER BY id LIMIT 1"
);
$oneStudent->execute([$classAId]);
$rejoinerId = (int) $oneStudent->fetchColumn();

$enrollments->unenroll($rejoinerId, $courseId);
$third = $enrollments->enrollClass($courseId, $classAId);

same('a re-run enrols exactly the one missing student', 1, $third['enrolled']);
same('a re-run skips the rest', $classASize - 1, $third['already']);
same('a re-run accounts for every candidate', 0, $third['unaccounted']);

// ===========================================================================
section('Bulk enrolment: suspended students are left out');

$suspendMe = $db->prepare(
    "SELECT id, status FROM users
      WHERE class_id = ? AND role = 'student' ORDER BY id DESC LIMIT 2"
);
$suspendMe->execute([$classBId]);
$toSuspend = $suspendMe->fetchAll();

foreach ($toSuspend as $row) {
    $statusRestore[(int) $row['id']] = $row['status'];
    (new User())->setStatus((int) $row['id'], 'suspended');
}

$previewB = $enrollments->bulkPreview($courseId, $classBId);
same('preview: the suspended pair is counted separately', 2, $previewB['suspended']);
same('preview: they are not eligible', $classBSize - 2, $previewB['eligible']);
same('preview: nobody in this class is enrolled yet', 0, $previewB['already']);

$runB = $enrollments->enrollClass($courseId, $classBId);
same('suspended students are not enrolled', $classBSize - 2, $runB['enrolled']);
same('they are not counted as candidates either', $classBSize - 2, $runB['candidates']);
same('and so nothing is unaccounted for', 0, $runB['unaccounted']);

$stillOut = $db->prepare(
    "SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND student_id IN (?, ?)"
);
$stillOut->execute([$courseId, (int) $toSuspend[0]['id'], (int) $toSuspend[1]['id']]);
same('neither suspended student reached the roll', 0, (int) $stillOut->fetchColumn());

// Reactivating and re-running picks them up, which is the documented way back.
foreach ($toSuspend as $row) {
    (new User())->setStatus((int) $row['id'], 'active');
}
$runB2 = $enrollments->enrollClass($courseId, $classBId);
same('reactivated students are picked up by a re-run', 2, $runB2['enrolled']);
same('the rest are still skipped', $classBSize - 2, $runB2['already']);
same('nothing unaccounted for', 0, $runB2['unaccounted']);

foreach ($toSuspend as $row) {
    (new User())->setStatus((int) $row['id'], $statusRestore[(int) $row['id']]);
}
$statusRestore = [];

// A class that does not exist is a no-op, not a crash: the confirm step can be
// replayed after the class has been deleted in another tab.
$ghost = $enrollments->enrollClass($courseId, 999999);
same('an unknown class enrols nobody', 0, $ghost['enrolled']);
same('an unknown class is not an error', null, $ghost['error']);
same('an unknown class accounts for nothing', 0, $ghost['unaccounted']);

$rollSize = $classASize + $classBSize;
same('the roll is exactly the two classes',
    $rollSize,
    (int) $db->query("SELECT COUNT(*) FROM enrollments WHERE course_id = $courseId")->fetchColumn());

// ===========================================================================
section('The list: the count and the page describe the same rows');

$plain = new EnrollmentListQuery($courseId, []);
$total = $enrollments->countForList($plain);
same('the unfiltered count is the whole roll', $rollSize, $total);

// Walk every page and collect the ids. This is the assertion that catches an
// unstable sort: with a tiebreaker, the pages partition the roll exactly; with
// nothing but full_name to order by, the fixture's repeated names would let a
// row appear on two pages and another appear on none.
$seen  = [];
$pages = 0;
for ($page = 1; ; $page++) {
    $walk = new EnrollmentListQuery($courseId, ['per_page' => '25', 'page' => (string) $page]);
    $rows = $enrollments->forList($walk);
    if ($rows === []) {
        break;
    }
    $pages++;
    foreach ($rows as $row) {
        $seen[] = (int) $row['id'];
    }
    if ($page > 60) {
        check('the page walk terminates', false, 'ran past 60 pages');
        break;
    }
}

same('every enrolled student appeared once', $rollSize, count($seen));
same('no student appeared on two pages', count($seen), count(array_unique($seen)));
same('the page count matches the total', (int) ceil($rollSize / 25), $pages);

// A page past the end is pulled back to the last real one rather than served
// empty, which is what a stale bookmark hits.
$far = new EnrollmentListQuery($courseId, ['per_page' => '25', 'page' => '400']);
$far->clampToTotal($enrollments->countForList($far));
same('a page past the end clamps to the last page', (int) ceil($rollSize / 25), $far->page);
check('the clamped page returns rows', $enrollments->forList($far) !== []);

// ===========================================================================
section('The list: filters');

$byClass = new EnrollmentListQuery($courseId, ['class_id' => (string) $classAId, 'per_page' => '100']);
same('the class filter counts only that class', $classASize, $enrollments->countForList($byClass));

$classRowsBack = $enrollments->forList($byClass);
$otherClass    = array_filter(
    $classRowsBack,
    static fn(array $r): bool => SchoolClass::labelFor($r) !== SchoolClass::labelFor($classA)
);
same('the class filter returns only that class', 0, count($otherClass));

$suspendedFilter = new EnrollmentListQuery($courseId, ['status' => 'suspended']);
same('nothing is suspended on this roll', 0, $enrollments->countForList($suspendedFilter));

$activeFilter = new EnrollmentListQuery($courseId, ['status' => 'active']);
same('everyone on this roll is active', $rollSize, $enrollments->countForList($activeFilter));

// The count and the page are built from the same WHERE, so a filter that
// changes one has to change the other by the same amount.
//
// The term is taken from a student who is actually on this roll rather than
// written in by hand: the roll is two classes out of ten, and a hardcoded name
// that happens to sit in one of the other eight makes this assert nothing.
$firstNames = array_map(
    static fn(array $r): string => strtok($r['full_name'], ' '),
    $enrollments->forList($plain)
);
$term      = $firstNames[0];
$search    = new EnrollmentListQuery($courseId, ['q' => $term, 'per_page' => '100']);
$searchHit = $enrollments->countForList($search);
$searchRow = $enrollments->forList($search);

check('the search matches somebody', $searchHit > 0, "count $searchHit");
same('the first page of a search holds min(count, per_page) rows',
    min($searchHit, 100), count($searchRow));

$mismatched = array_filter(
    $searchRow,
    static fn(array $r): bool => stripos($r['full_name'], $term) === false
                             && stripos((string) $r['admission_no'], $term) === false
);
same('every search row actually contains the term', 0, count($mismatched));

// ===========================================================================
section('The list: LIKE metacharacters match themselves');

// '%' as a wildcard would match every row. As a literal it matches none, since
// no fabricated name contains a percent sign.
$percent = new EnrollmentListQuery($courseId, ['q' => '%']);
same('a bare % is a literal, not a wildcard', 0, $enrollments->countForList($percent));

$underscore = new EnrollmentListQuery($courseId, ['q' => '_']);
same('a bare _ is a literal, not a single-character wildcard',
    0, $enrollments->countForList($underscore));

// The escape character itself, which double-escaping would break.
$bang = new EnrollmentListQuery($courseId, ['q' => '!']);
same('the escape character is searchable as a literal', 0, $enrollments->countForList($bang));

$bangPercent = new EnrollmentListQuery($courseId, ['q' => '!%']);
same('an escape followed by a wildcard is still a literal',
    0, $enrollments->countForList($bangPercent));

// A term that is all metacharacters must not match the whole roll.
$allMeta = new EnrollmentListQuery($courseId, ['q' => '%_%']);
check('a string of metacharacters does not match everything',
    $enrollments->countForList($allMeta) < $rollSize);

// ===========================================================================
section('The list: sorting');

$asc  = $enrollments->forList(new EnrollmentListQuery($courseId, ['sort' => 'name', 'dir' => 'asc',  'per_page' => '100']));
$desc = $enrollments->forList(new EnrollmentListQuery($courseId, ['sort' => 'name', 'dir' => 'desc', 'per_page' => '100']));

$ascNames = array_column($asc, 'full_name');
$sorted   = $ascNames;
sort($sorted, SORT_FLAG_CASE | SORT_STRING);
same('ascending by name really is sorted', $sorted, $ascNames);

check('descending is not the same page as ascending',
    array_column($desc, 'id') !== array_column($asc, 'id'));

// Every sortable column has to survive a real query, not just build a string.
foreach (array_keys(EnrollmentListQuery::SORTS) as $key) {
    foreach (['asc', 'desc'] as $dir) {
        $q    = new EnrollmentListQuery($courseId, ['sort' => $key, 'dir' => $dir, 'per_page' => '25']);
        $rows = $enrollments->forList($q);
        check("sorting by $key $dir returns a full page", count($rows) === 25, (string) count($rows));
    }
}

// ===========================================================================
section('The list: helper queries');

check('anyInCourse is true for a populated roll', $enrollments->anyInCourse($courseId));
check('anyInCourse is false for a course with nobody on it',
    !$enrollments->anyInCourse(999999));

$filterClasses = $enrollments->classesInCourse($courseId);
same('the class filter offers exactly the classes on the roll', 2, count($filterClasses));

$offeredIds = array_map('intval', array_column($filterClasses, 'id'));
sort($offeredIds);
$expectedIds = [$classAId, $classBId];
sort($expectedIds);
same('and they are the right two classes', $expectedIds, $offeredIds);

// ===========================================================================
section('The views render, and escape what they are given');

// Views read the session directly - Csrf::token(), and the top bar's
// Auth::user() - so a signed-in admin is faked here rather than driven over
// HTTP. Nothing in these two views writes, so this exercises the real markup.
$_SESSION['user_id']   = 1;
$_SESSION['user_name'] = 'Test Admin';
$_SESSION['role']      = 'admin';
$_SESSION['csrf_token'] = str_repeat('a', 64);

test_diagnostics();   // clear anything from earlier

$hostile = '"><script>alert(1)</script>';

$renderQuery = new EnrollmentListQuery($courseId, [
    'q'        => $hostile,
    'class_id' => (string) $classAId,
    'sort'     => 'name',
    'dir'      => 'desc',
    'per_page' => '25',
]);
$renderTotal = $enrollments->countForList($renderQuery);

$listHtml = render_view(APP_ROOT . '/app/views/admin/enrollments.php', [
    'course'      => $course,
    'enrolled'    => $enrollments->forList($renderQuery),
    'query'       => $renderQuery,
    'total'       => $renderTotal,
    'available'   => $enrollments->studentsNotInCourse($courseId),
    'classes'     => $filterClasses,
    'bulkClasses' => (new SchoolClass())->selectableWithCounts(),
    'bulkResult'  => null,
    'anyEnrolled' => true,
]);

same('the list view raised no diagnostics', [], test_diagnostics());
check('the list view rendered a page', strpos($listHtml, '</html>') !== false);
check('the hostile search term is escaped',
    strpos($listHtml, '<script>') === false,
    'unescaped <script> reached the output');
check('the hostile term is present, escaped',
    strpos($listHtml, '&lt;script&gt;') !== false);
check('the empty-state is not shown for a populated roll',
    strpos($listHtml, 'No students enrolled yet') === false);

// The result banner, in the case that matters: what happened differs from what
// the confirmation screen promised.
$divergedHtml = render_view(APP_ROOT . '/app/views/admin/enrollments.php', [
    'course'      => $course,
    'enrolled'    => $enrollments->forList($plain),
    'query'       => $plain,
    'total'       => $total,
    'available'   => [],
    'classes'     => $filterClasses,
    'bulkClasses' => (new SchoolClass())->selectableWithCounts(),
    'bulkResult'  => [
        'class_label' => SchoolClass::labelFor($classA),
        'promised'    => ['eligible' => 30, 'already' => 0, 'suspended' => 0],
        'actual'      => ['candidates' => 30, 'enrolled' => 28, 'already' => 1,
                          'unaccounted' => 1, 'error' => null],
    ],
    'anyEnrolled' => true,
]);

same('the diverged result view raised no diagnostics', [], test_diagnostics());
check('the result reports what actually happened',
    strpos($divergedHtml, '>28</strong>') !== false);
check('the result also reports what was promised',
    strpos($divergedHtml, 'differs from the confirmation screen') !== false
    && strpos($divergedHtml, '>30</strong>') !== false);
check('an unaccounted row is surfaced, not folded into skipped',
    strpos($divergedHtml, 'neither enrolled nor found already enrolled') !== false);

$confirmHtml = render_view(APP_ROOT . '/app/views/admin/bulk_enroll_confirm.php', [
    'course'  => $course,
    'class'   => $classA,
    'preview' => ['eligible' => 25, 'already' => 3, 'suspended' => 2],
]);

same('the confirm view raised no diagnostics', [], test_diagnostics());
check('the confirm view shows the count it is about to affect',
    strpos($confirmHtml, 'Enrol 25 students') !== false);
check('the confirm view shows the already-enrolled count',
    strpos($confirmHtml, '<strong>3</strong> already on this roll') !== false);
check('the confirm view shows the suspended count',
    strpos($confirmHtml, '<strong>2</strong> suspended') !== false);
check('the confirm view posts to the confirm route',
    strpos($confirmHtml, 'admin/confirmBulkEnroll/' . $courseId) !== false);
check('the confirm view carries no class field for the browser to change',
    strpos($confirmHtml, 'name="class_id"') === false);

// Nobody left to enrol, but the class is not empty - everyone in it is already
// on the roll. A different branch from the empty class below: the screen still
// renders its form, and it is the button that is unavailable rather than the
// whole page that changes.
$nothingToDo = render_view(APP_ROOT . '/app/views/admin/bulk_enroll_confirm.php', [
    'course'  => $course,
    'class'   => $classA,
    'preview' => ['eligible' => 0, 'already' => 28, 'suspended' => 2],
]);
same('the nothing-to-do confirm view raised no diagnostics', [], test_diagnostics());
check('with nobody to enrol the submit button is disabled',
    preg_match('/<button type="submit"[^>]*\sdisabled/s', $nothingToDo) === 1);
check('with nobody to enrol Cancel takes the primary styling',
    preg_match('/<a class="btn btn--primary"[^>]*>\s*Cancel/s', $nothingToDo) === 1);

$emptyConfirm = render_view(APP_ROOT . '/app/views/admin/bulk_enroll_confirm.php', [
    'course'  => $course,
    'class'   => $classA,
    'preview' => ['eligible' => 0, 'already' => 0, 'suspended' => 0],
]);
same('the empty confirm view raised no diagnostics', [], test_diagnostics());
check('an empty class offers no button to press',
    strpos($emptyConfirm, 'admin/confirmBulkEnroll/') === false);

} finally {

    // ---- Cleanup: only what this script created, by recorded id ------------

    foreach ($statusRestore as $id => $status) {
        (new User())->setStatus((int) $id, (string) $status);
    }

    // The enrolments go with the course, by ON DELETE CASCADE.
    $drop = $db->prepare("DELETE FROM courses WHERE id = ?");
    $drop->execute([$courseId]);

    $dropLecturer = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'lecturer'");
    $dropLecturer->execute([$lecturerId]);

    $left = (int) $db->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
    $students = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();

    printf("\ncleanup: course %d and lecturer %d removed; %d enrolments left, %d students untouched\n",
        $courseId, $lecturerId, $left, $students);
}

// ---- Result ---------------------------------------------------------------

printf("\n%d passed, %d failed\n", $passed, count($failed));

if ($failed !== []) {
    echo "\nFailures:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

/**
 * Render a view file the way Controller::view() does - extract the data into
 * local scope and require the file - and hand back the HTML.
 */
function render_view(string $file, array $data): string
{
    extract($data);

    ob_start();
    require $file;
    return (string) ob_get_clean();
}
