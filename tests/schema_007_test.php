<?php
/**
 * Migration 007: the lock schema, installed fresh and migrated, is the same
 * schema, and it holds the lines the lock code will lean on.
 *
 *   php tests/schema_007_test.php
 *
 * No web server and no port. It builds two throwaway databases next to the
 * test database, both named from DB_NAME so both contain "test":
 *
 *   fresh     database/schema_import.sql as it stands now
 *   migrated  the schema as it was before 007, straight out of git, seeded
 *             with the kind of rows a real school already has, then 007
 *
 * and drops both on every way out. The test database itself is not touched,
 * so this can run between the other suites without resetting anything.
 *
 * The constraint checks run with strict mode switched OFF in the session.
 * That is how this MariaDB is configured, and it is the hard case: outside
 * strict mode an ENUM stores '' for a value it does not list, so only the
 * CHECK constraints stand between a typo and a blank trigger.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// The commit before 007. Its schema_import.sql is the schema every existing
// install has; 007 must turn exactly that into the new one.
const BEFORE_007 = 'd5b5a37';

const MIGRATION = APP_ROOT . '/database/migrations/007_attempt_locks.sql';
const MYSQL     = 'C:/xampp/mysql/bin/mysql.exe';

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

/**
 * Run $fn and return the MySQL error number it raised, or 0 if it raised none.
 * The number, not just "it threw", so a foreign-key failure cannot pass for
 * the CHECK failure a test is looking for.
 */
function sql_error(callable $fn): int
{
    try {
        $fn();
        return 0;
    } catch (PDOException $e) {
        return (int) ($e->errorInfo[1] ?? -1);
    }
}

const ER_BAD_NULL      = 1048;
const ER_DUP_ENTRY     = 1062;
const ER_CHECK_FAILED  = 4025;

// ---- Two throwaway databases ------------------------------------------------

$fresh    = DB_NAME . '_s007_fresh';
$migrated = DB_NAME . '_s007_migrated';

// The same pair again, but on a database whose default character set is
// latin1, the way a school server might be configured. Kept separate so the
// seeded diacritics in the main pair are never at the mercy of latin1.
$freshLatin1    = DB_NAME . '_s007_fresh_l1';
$migratedLatin1 = DB_NAME . '_s007_migrated_l1';

foreach ([$fresh, $migrated, $freshLatin1, $migratedLatin1] as $name) {
    if (strpos($name, 'test') === false || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "REFUSING TO RUN: '$name' is not a safe throwaway database name.\n");
        exit(1);
    }
}

/** Run the mysql client directly, no shell, and return [exit code, output]. */
function mysql_client(array $args): array
{
    $cmd = array_merge([MYSQL, '-u', DB_USER, '--default-character-set=utf8mb4'],
        DB_PASS === '' ? [] : ['-p' . DB_PASS], $args);

    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT);
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), trim($out)];
}

function recreate(string $name, string $charset = 'utf8mb4', string $collation = 'utf8mb4_unicode_ci'): void
{
    [$code, $out] = mysql_client(['-e',
        "DROP DATABASE IF EXISTS `$name`; "
        . "CREATE DATABASE `$name` CHARACTER SET $charset COLLATE $collation;"]);
    if ($code !== 0) {
        fwrite(STDERR, "Could not create $name: $out\n");
        exit(1);
    }
}

/** Load a .sql file with "source", never a shell redirect. */
function source_file(string $db, string $file): array
{
    return mysql_client([$db, '-e', 'source ' . str_replace('\\', '/', $file)]);
}

register_shutdown_function(static function () use ($fresh, $migrated, $freshLatin1, $migratedLatin1): void {
    mysql_client(['-e', "DROP DATABASE IF EXISTS `$fresh`; DROP DATABASE IF EXISTS `$migrated`; "
        . "DROP DATABASE IF EXISTS `$freshLatin1`; DROP DATABASE IF EXISTS `$migratedLatin1`;"]);
});

function connect(string $db): PDO
{
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $db . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // The configuration this server really runs with, stated rather than
    // inherited, so the result does not depend on the machine.
    $pdo->exec("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

// ---- Structure, as information_schema reports it ----------------------------

/** Everything about a schema's shape that a migration could get wrong. */
function structure(PDO $pdo, string $db): array
{
    $q = static function (string $sql) use ($pdo, $db): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_fill(0, substr_count($sql, '?'), $db));
        return array_map(static fn(array $r): string => implode(' | ', array_map(
            static fn($v) => $v === null ? 'NULL' : (string) $v, $r)), $stmt->fetchAll());
    };

    return [
        'tables' => $q(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME"),
        'columns' => $q(
            "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    EXTRA, IS_GENERATED, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION"),
        'indexes' => $q(
            "SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"),
        'foreign keys' => $q(
            "SELECT rc.TABLE_NAME, rc.CONSTRAINT_NAME, k.COLUMN_NAME, rc.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND k.TABLE_NAME = rc.TABLE_NAME
              WHERE rc.CONSTRAINT_SCHEMA = ? ORDER BY rc.TABLE_NAME, rc.CONSTRAINT_NAME, k.ORDINAL_POSITION"),
        'checks' => $q(
            "SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME"),
    ];
}

/**
 * Every row of every table, keyed by table, in a stable order. With
 * $onlyColumns, only those columns, and only the ones that still exist: a
 * migration that drops a column must show up as a failed comparison, not as a
 * crash that stops the suite before the check that names it.
 */
function all_rows(PDO $pdo, array $onlyColumns = []): array
{
    $out = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $cols = $onlyColumns[$table] ?? null;
        if ($cols !== null) {
            $cols = array_values(array_intersect($cols, columns_of($pdo, $table)));
        }
        $list = $cols === null ? '*' : implode(', ', array_map(static fn($c) => "`$c`", $cols));
        $colCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"
        )->fetchColumn();
        $order = implode(', ', range(1, $cols === null ? $colCount : count($cols)));
        $out[$table] = $pdo->query("SELECT $list FROM `$table` ORDER BY $order")->fetchAll();
    }
    return $out;
}

/** The columns a table has right now, in order. */
function columns_of(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================================
section('Building both databases');

recreate($fresh);
[$code, $out] = source_file($fresh, APP_ROOT . '/database/schema_import.sql');
same('the fresh schema loads without error', 0, $code);
check('and prints nothing', $out === '', $out);

// The pre-007 schema, from git rather than a copy that could be edited.
$git = proc_open(['git', 'show', BEFORE_007 . ':database/schema_import.sql'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $gitPipes, APP_ROOT);
$oldSchema = stream_get_contents($gitPipes[1]);
$gitErr    = stream_get_contents($gitPipes[2]);
fclose($gitPipes[1]);
fclose($gitPipes[2]);
same('git produced the pre-007 schema', 0, proc_close($git));
check('and it really is from before 007',
    strpos($oldSchema, 'closed_by_system_at') !== false && strpos($oldSchema, 'attempt_locks') === false, $gitErr);

$oldFile = (string) tempnam(sys_get_temp_dir(), 'schema_before_007_');
file_put_contents($oldFile, $oldSchema);
register_shutdown_function(static function () use ($oldFile): void {
    if (is_file($oldFile)) unlink($oldFile);
});

recreate($migrated);
[$code, $out] = source_file($migrated, $oldFile);
same('the pre-007 schema loads without error', 0, $code);

// ---- Rows a real school already has, written before 007 --------------------

$old = connect($migrated);

$old->exec("INSERT INTO import_batches (id, filename, row_count, imported_by)
            VALUES ('" . str_repeat('ab', 16) . "', 'ss1.csv', 1, NULL)");
$old->exec("INSERT INTO users (full_name, email, password_hash, role)
            VALUES ('Mrs Adébáyọ̀', 'teacher@school.local', 'x', 'lecturer')");
$teacherId = (int) $old->lastInsertId();
$old->exec("INSERT INTO users (full_name, admission_no, password_hash, role, class_id, import_batch_id)
            VALUES ('Ṣadé Ọlá', 'ADM/2026/0001', 'y', 'student', 1, '" . str_repeat('ab', 16) . "')");
$studentId = (int) $old->lastInsertId();
$old->exec("INSERT INTO courses (course_code, title, lecturer_id) VALUES ('YOR101', 'Yorùbá', $teacherId)");
$courseId = (int) $old->lastInsertId();
$old->exec("INSERT INTO enrollments (student_id, course_id) VALUES ($studentId, $courseId)");
$old->exec("INSERT INTO exams (course_id, title, duration_minutes, window_start, window_end, questions_per_attempt, status)
            VALUES ($courseId, 'Mid-Term', 30, '2026-09-01 08:00:00', '2026-09-01 12:00:00', 1, 'closed')");
$examId = (int) $old->lastInsertId();
$old->exec("INSERT INTO questions (course_id, question_type, question_text, marks, created_by)
            VALUES ($courseId, 'mcq', 'Kí ni ẹ ọ ṣ ń?', 2, $teacherId)");
$questionId = (int) $old->lastInsertId();
$old->exec("INSERT INTO question_options (question_id, option_text, is_correct) VALUES ($questionId, 'Ẹ̀kọ́', 1), ($questionId, 'Ọ̀rọ̀', 0)");
$old->exec("INSERT INTO exam_question_pool (exam_id, question_id) VALUES ($examId, $questionId)");
$old->exec("INSERT INTO exam_attempts
                (exam_id, student_id, started_at, deadline_at, submitted_at, status, total_score,
                 grading_status, is_flagged, unsaved_at_submit, closed_by_system_at)
            VALUES ($examId, $studentId, '2026-09-01 09:00:00', '2026-09-01 09:30:00', '2026-09-01 09:30:00',
                    'auto_submitted', 2.00, 'complete', 1, 2, '2026-09-01 09:36:00')");
$attemptId = (int) $old->lastInsertId();
$old->exec("INSERT INTO attempt_questions (attempt_id, question_id, display_order, option_order)
            VALUES ($attemptId, $questionId, 1, '[2,1]')");
$old->exec("INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id, awarded_marks, graded_at)
            VALUES ($attemptId, $questionId, 1, 2.00, '2026-09-01 09:36:00')");
foreach (['tab_switch', 'fullscreen_exit', 'window_blur', 'copy', 'paste',
          'right_click', 'late_submit', 'multiple_session', 'heartbeat_gap'] as $type) {
    $old->exec("INSERT INTO activity_logs (attempt_id, event_type, event_data, created_at)
                VALUES ($attemptId, '$type', '{\"ua\":\"Edge\"}', '2026-09-01 09:10:00')");
}
$old->exec("INSERT INTO login_attempts (identifier, attempts, last_attempt) VALUES ('ADM/2026/0001', 2, '2026-09-01 07:59:00')");

$oldColumns = [];
foreach ($old->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetchAll() as $c) {
    $oldColumns[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
}
$rowsBefore = all_rows($old);
$old = null;

[$code, $out] = source_file($migrated, MIGRATION);
same('migration 007 applies without error', 0, $code);
check('and prints nothing', $out === '', $out);

$new = connect($migrated);
$db  = connect($fresh);

// ============================================================================
section('M1 the fresh schema has the new structure');

$cols = [];
foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                            EXTRA, GENERATION_EXPRESSION
                       FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetchAll() as $c) {
    $cols[$c['TABLE_NAME']][$c['COLUMN_NAME']] = $c;
}

// [type, nullable, default]. MariaDB reports a nullable column with no default
// as the string 'NULL', and a NOT NULL column with no default as null.
$expected = [
    'exam_attempts' => [
        'locked_at'    => ['datetime', 'YES', 'NULL'],
        'seat_hash'    => ['char(64)', 'YES', 'NULL'],
        'last_seen_at' => ['datetime', 'YES', 'NULL'],
    ],
    'bulk_unlocks' => [
        'id'          => ['bigint(20)', 'NO', null],
        'unlocked_by' => ['int(11)', 'NO', null],
        'reason'      => ['varchar(500)', 'NO', null],
        'lock_count'  => ['int(11)', 'NO', null],
        'created_at'  => ['datetime', 'NO', null],
    ],
    'attempt_locks' => [
        'id'              => ['bigint(20)', 'NO', null],
        'attempt_id'      => ['int(11)', 'NO', null],
        'trigger_type'    => ["enum('window_blur','tab_hidden','fullscreen_exit','new_session')", 'NO', null],
        'detail'          => ['longtext', 'NO', null],
        'locked_at'       => ['datetime', 'NO', null],
        'claimed_by'      => ['int(11)', 'YES', 'NULL'],
        'claimed_at'      => ['datetime', 'YES', 'NULL'],
        'code_hash'       => ['varchar(255)', 'YES', 'NULL'],
        'failed_tries'    => ['tinyint(3) unsigned', 'NO', '0'],
        'unlocked_at'     => ['datetime', 'YES', 'NULL'],
        'unlocked_by'     => ['int(11)', 'YES', 'NULL'],
        'unlock_method'   => ["enum('code','bulk')", 'YES', 'NULL'],
        'bulk_unlock_id'  => ['bigint(20)', 'YES', 'NULL'],
        'open_attempt_id' => ['int(11)', 'YES', 'NULL'],
    ],
    'attempt_events' => [
        'id'         => ['bigint(20)', 'NO', null],
        'attempt_id' => ['int(11)', 'NO', null],
        'lock_id'    => ['bigint(20)', 'YES', 'NULL'],
        'event_type' => ["enum('blur_blip','heartbeat_gap','paste_landed','bulk_insert','typing_burst',"
                       . "'value_jump','claim','reclaim','code_failed','code_exhausted','superseded')", 'NO', null],
        'actor_id'   => ['int(11)', 'YES', 'NULL'],
        'detail'     => ['longtext', 'YES', 'NULL'],
        'created_at' => ['datetime', 'NO', null],
    ],
    'attempt_blocked_actions' => [
        'attempt_id' => ['int(11)', 'NO', null],
        'action'     => ["enum('copy','cut','paste','drop','drag','context_menu','print','save','replace')", 'NO', null],
        'route'      => ["enum('keyboard','mouse','other')", 'NO', null],
        'count'      => ['int(10) unsigned', 'NO', null],
        'first_at'   => ['datetime', 'NO', null],
        'last_at'    => ['datetime', 'NO', null],
    ],
];

foreach ($expected as $table => $columns) {
    if ($table !== 'exam_attempts') {
        same("M1 $table has exactly its columns", array_keys($columns), array_keys($cols[$table] ?? []));
    }
    foreach ($columns as $name => [$type, $nullable, $default]) {
        $c = $cols[$table][$name] ?? null;
        check("M1 $table.$name exists", $c !== null);
        if ($c === null) continue;
        same("M1 $table.$name type", $type, $c['COLUMN_TYPE']);
        same("M1 $table.$name nullable", $nullable, $c['IS_NULLABLE']);
        same("M1 $table.$name default", $default, $c['COLUMN_DEFAULT']);
    }
}

$tableOptions = [];
foreach ($db->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()")->fetchAll() as $t) {
    $tableOptions[$t['TABLE_NAME']] = [$t['ENGINE'], $t['TABLE_COLLATION']];
}
foreach (['bulk_unlocks', 'attempt_locks', 'attempt_events', 'attempt_blocked_actions'] as $t) {
    same("M1 $t is InnoDB, utf8mb4_unicode_ci", ['InnoDB', 'utf8mb4_unicode_ci'], $tableOptions[$t] ?? null);
}

$pos = static fn(string $c): int => (int) ($cols['exam_attempts'][$c]['ORDINAL_POSITION'] ?? 0);
same('M1 the three new attempt columns follow closed_by_system_at, in order',
    [$pos('closed_by_system_at') + 1, $pos('closed_by_system_at') + 2, $pos('closed_by_system_at') + 3],
    [$pos('locked_at'), $pos('seat_hash'), $pos('last_seen_at')]);

same('M1 open_attempt_id is a virtual generated column', 'VIRTUAL GENERATED',
    $cols['attempt_locks']['open_attempt_id']['EXTRA'] ?? null);
same('M1 holding attempt_id only while unlocked_at is NULL',
    'if(`unlocked_at` is null,`attempt_id`,NULL)',
    $cols['attempt_locks']['open_attempt_id']['GENERATION_EXPRESSION'] ?? null);

// ============================================================================
section('M2 migrating produces exactly the fresh schema');

$want = structure($db, $fresh);
$got  = structure($new, $migrated);

foreach ($want as $part => $rows) {
    $missing = array_values(array_diff($rows, $got[$part]));
    $extra   = array_values(array_diff($got[$part], $rows));
    check("M2 $part are identical", $rows === $got[$part],
        'fresh only: ' . implode(' || ', array_slice($missing, 0, 3))
        . '; migrated only: ' . implode(' || ', array_slice($extra, 0, 3)));
}

check('M2 and the comparison is not empty',
    count($want['columns']) > 100 && count($want['checks']) >= 7 && count($want['foreign keys']) > 20);

// The comparison covers engine, table collation and every column's character
// set and collation, not just names and types. Proven on rows that carry them.
check('M2 table engine and collation are part of the comparison',
    in_array('bulk_unlocks | InnoDB | utf8mb4_unicode_ci', $want['tables'], true));
check('M2 column character set and collation are part of the comparison',
    (bool) preg_grep('/^bulk_unlocks \| \d+ \| reason \| varchar\(500\) .*\| utf8mb4 \| utf8mb4_unicode_ci$/', $want['columns']));

// ============================================================================
section('M3 schema.sql and schema_import.sql agree');

$lines = static fn(string $s): array => explode("\n", str_replace("\r\n", "\n", $s));
$full   = $lines((string) file_get_contents(APP_ROOT . '/database/schema.sql'));
$import = $lines((string) file_get_contents(APP_ROOT . '/database/schema_import.sql'));

same('M3 schema.sql starts by creating and selecting the database',
    ['CREATE DATABASE exam_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', 'USE exam_system;'],
    array_slice($full, 0, 2));

$trimLeadingBlank = static function (array $l): array {
    while ($l !== [] && trim($l[0]) === '') array_shift($l);
    return $l;
};
$a = $trimLeadingBlank(array_slice($full, 2));
$b = $trimLeadingBlank($import);
$firstDiff = null;
foreach ($a as $i => $line) {
    if (($b[$i] ?? null) !== $line) { $firstDiff = $i; break; }
}
check('M3 the rest of schema.sql is schema_import.sql, line for line',
    $a === $b,
    $firstDiff === null ? 'lengths differ' : 'first difference: "' . ($a[$firstDiff] ?? '') . '" vs "' . ($b[$firstDiff] ?? '') . '"');

// ============================================================================
section('M4 migrating leaves every existing row as it was');

$lost = [];
foreach ($oldColumns as $table => $columns) {
    foreach (array_diff($columns, columns_of($new, $table)) as $gone) {
        $lost[] = "$table.$gone";
    }
}
same('M4 no existing column was removed', [], $lost);

$rowsAfter = all_rows($new, $oldColumns);
foreach ($rowsBefore as $table => $rows) {
    same("M4 $table: every row and value unchanged", $rows, $rowsAfter[$table] ?? null);
}
check('M4 and there were rows to compare',
    count($rowsBefore['exam_attempts']) === 1 && count($rowsBefore['activity_logs']) === 9);

$attemptNow = $new->query("SELECT locked_at, seat_hash, last_seen_at FROM exam_attempts WHERE id = $attemptId")->fetch();
same('M4 the new columns are NULL on the existing attempt',
    ['locked_at' => null, 'seat_hash' => null, 'last_seen_at' => null], $attemptNow);

foreach (['bulk_unlocks', 'attempt_locks', 'attempt_events', 'attempt_blocked_actions'] as $t) {
    same("M4 $t starts empty", 0, (int) $new->query("SELECT COUNT(*) FROM $t")->fetchColumn());
}

// ============================================================================
section('M5 the previous system\'s record stays readable');

$hasFlag = in_array('is_flagged', columns_of($new, 'exam_attempts'), true);
check('M5 is_flagged is still on the attempt', $hasFlag);
same('M5 and still 1', 1, $hasFlag
    ? (int) $new->query("SELECT is_flagged FROM exam_attempts WHERE id = $attemptId")->fetchColumn()
    : null);
same('M5 activity_logs keeps all nine event types',
    "enum('tab_switch','fullscreen_exit','window_blur','copy','paste','right_click','late_submit','multiple_session','heartbeat_gap')",
    $new->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'event_type'")->fetchColumn());
same('M5 and every logged event is still there', 9,
    (int) $new->query("SELECT COUNT(*) FROM activity_logs WHERE attempt_id = $attemptId")->fetchColumn());
same('M5 with its text intact, diacritics included', 'Kí ni ẹ ọ ṣ ń?',
    $new->query("SELECT question_text FROM questions WHERE id = $questionId")->fetchColumn());

// ============================================================================
// Fixture for the constraint checks: one Teacher, one Student, and one attempt
// per check, so no check depends on what another left behind.

$db->exec("INSERT INTO users (full_name, email, password_hash, role) VALUES ('T', 't@s.local', 'x', 'lecturer')");
$staff = (int) $db->lastInsertId();
$db->exec("INSERT INTO users (full_name, admission_no, password_hash, role, class_id) VALUES ('S', 'ADM/1', 'y', 'student', 1)");
$pupil = (int) $db->lastInsertId();
$db->exec("INSERT INTO courses (course_code, title, lecturer_id) VALUES ('C1', 'C', $staff)");
$course = (int) $db->lastInsertId();

function new_attempt(PDO $db, int $course, int $pupil): int
{
    $db->exec("INSERT INTO exams (course_id, title, duration_minutes, window_start, window_end, questions_per_attempt)
               VALUES ($course, 'E', 30, NOW(), NOW() + INTERVAL 1 HOUR, 1)");
    $exam = (int) $db->lastInsertId();
    $db->exec("INSERT INTO exam_attempts (exam_id, student_id, started_at, deadline_at)
               VALUES ($exam, $pupil, NOW(), NOW() + INTERVAL 30 MINUTE)");
    return (int) $db->lastInsertId();
}

function lock(PDO $db, int $attempt, string $detail = '{"triggers":["window_blur"]}', string $trigger = 'window_blur'): void
{
    $stmt = $db->prepare("INSERT INTO attempt_locks (attempt_id, trigger_type, detail, locked_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$attempt, $trigger, $detail]);
}

// ============================================================================
section('M6 a lock\'s detail is never NULL and always JSON');

$mode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
check('M6 this session is not in strict mode, so the checks below are the hard case',
    strpos($mode, 'STRICT') === false, $mode);

$a6 = new_attempt($db, $course, $pupil);
same('M6 a lock with detail NULL is refused',
    ER_BAD_NULL, sql_error(static fn() => $db->exec(
        "INSERT INTO attempt_locks (attempt_id, trigger_type, detail, locked_at) VALUES ($a6, 'window_blur', NULL, NOW())")));
same('M6 a lock with detail that is not JSON is refused',
    ER_CHECK_FAILED, sql_error(static fn() => lock($db, $a6, 'window_blur, then tab_hidden')));
same('M6 a lock created with {"triggers":[first]} is accepted',
    0, sql_error(static fn() => lock($db, $a6)));
same('M6 and reads back as that array', '["window_blur"]',
    $db->query("SELECT JSON_EXTRACT(detail, '$.triggers') FROM attempt_locks WHERE attempt_id = $a6")->fetchColumn());

// ============================================================================
section('M7 a value outside an ENUM is an error, not a blank');

$a7 = new_attempt($db, $course, $pupil);

same('M7 an unknown trigger_type is refused by its CHECK',
    ER_CHECK_FAILED, sql_error(static fn() => lock($db, $a7, '{"triggers":["alt_tab"]}', 'alt_tab')));
same('M7 and nothing was stored', 0,
    (int) $db->query("SELECT COUNT(*) FROM attempt_locks WHERE attempt_id = $a7")->fetchColumn());

lock($db, $a7);
same('M7 an unknown unlock_method is refused',
    ER_CHECK_FAILED, sql_error(static fn() => $db->exec(
        "UPDATE attempt_locks SET unlock_method = 'desk' WHERE attempt_id = $a7")));
same('M7 a real unlock_method is accepted',
    0, sql_error(static fn() => $db->exec(
        "UPDATE attempt_locks SET unlock_method = 'code' WHERE attempt_id = $a7")));

same('M7 an unknown event_type is refused',
    ER_CHECK_FAILED, sql_error(static fn() => $db->exec(
        "INSERT INTO attempt_events (attempt_id, event_type, created_at) VALUES ($a7, 'blip', NOW())")));
same('M7 a real event_type is accepted',
    0, sql_error(static fn() => $db->exec(
        "INSERT INTO attempt_events (attempt_id, event_type, created_at) VALUES ($a7, 'blur_blip', NOW())")));

same('M7 an unknown blocked action is refused',
    ER_CHECK_FAILED, sql_error(static fn() => $db->exec(
        "INSERT INTO attempt_blocked_actions (attempt_id, action, route, count, first_at, last_at)
         VALUES ($a7, 'screenshot', 'keyboard', 1, NOW(), NOW())")));
same('M7 an unknown route is refused',
    ER_CHECK_FAILED, sql_error(static fn() => $db->exec(
        "INSERT INTO attempt_blocked_actions (attempt_id, action, route, count, first_at, last_at)
         VALUES ($a7, 'paste', 'voice', 1, NOW(), NOW())")));
same('M7 and no blank was stored anywhere', 0, (int) $db->query(
    "SELECT (SELECT COUNT(*) FROM attempt_locks WHERE trigger_type = '' OR unlock_method = '')
          + (SELECT COUNT(*) FROM attempt_events WHERE event_type = '')
          + (SELECT COUNT(*) FROM attempt_blocked_actions WHERE action = '' OR route = '')")->fetchColumn());

// ============================================================================
section('M8 one counted row per attempt, action and route');

$a8 = new_attempt($db, $course, $pupil);
$ins = "INSERT INTO attempt_blocked_actions (attempt_id, action, route, count, first_at, last_at)
        VALUES ($a8, ?, ?, 1, NOW(), NOW())";

same('M8 the first copy by keyboard is stored', 0,
    sql_error(static fn() => $db->prepare($ins)->execute(['copy', 'keyboard'])));
same('M8 a second plain insert for the same action and route is refused', ER_DUP_ENTRY,
    sql_error(static fn() => $db->prepare($ins)->execute(['copy', 'keyboard'])));
same('M8 the same action by another route is its own row', 0,
    sql_error(static fn() => $db->prepare($ins)->execute(['copy', 'mouse'])));

$upsert = $db->prepare(
    "INSERT INTO attempt_blocked_actions (attempt_id, action, route, count, first_at, last_at)
     VALUES ($a8, 'paste', 'keyboard', 1, NOW(), NOW())
     ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_at = NOW()");
for ($i = 0; $i < 20; $i++) {
    $upsert->execute();
}
same('M8 twenty pastes are one row', 1, (int) $db->query(
    "SELECT COUNT(*) FROM attempt_blocked_actions WHERE attempt_id = $a8 AND action = 'paste'")->fetchColumn());
same('M8 with count 20', 20, (int) $db->query(
    "SELECT count FROM attempt_blocked_actions WHERE attempt_id = $a8 AND action = 'paste'")->fetchColumn());

// ============================================================================
section('M9 deleting an attempt takes everything hanging off it');

$a9 = new_attempt($db, $course, $pupil);
$db->exec("INSERT INTO bulk_unlocks (unlocked_by, reason, lock_count, created_at)
           VALUES ($staff, 'Windows update notice on every PC', 1, NOW())");
$bulk = (int) $db->lastInsertId();

lock($db, $a9);
$db->exec("UPDATE attempt_locks SET unlocked_at = NOW(), unlocked_by = $staff, unlock_method = 'bulk',
                                    bulk_unlock_id = $bulk WHERE attempt_id = $a9");
lock($db, $a9, '{"triggers":["tab_hidden"]}', 'tab_hidden');
$openLock = (int) $db->query("SELECT id FROM attempt_locks WHERE attempt_id = $a9 AND unlocked_at IS NULL")->fetchColumn();
$db->exec("UPDATE attempt_locks SET claimed_by = $staff, claimed_at = NOW(), code_hash = 'h' WHERE id = $openLock");
$db->exec("INSERT INTO attempt_events (attempt_id, lock_id, event_type, actor_id, created_at)
           VALUES ($a9, $openLock, 'claim', $staff, NOW()), ($a9, $openLock, 'code_failed', NULL, NOW()),
                  ($a9, NULL, 'blur_blip', NULL, NOW())");
$db->exec("INSERT INTO attempt_blocked_actions (attempt_id, action, route, count, first_at, last_at)
           VALUES ($a9, 'paste', 'keyboard', 3, NOW(), NOW())");

$children = static fn(): array => $db->query(
    "SELECT (SELECT COUNT(*) FROM attempt_locks WHERE attempt_id = $a9) AS locks,
            (SELECT COUNT(*) FROM attempt_events WHERE attempt_id = $a9) AS events,
            (SELECT COUNT(*) FROM attempt_blocked_actions WHERE attempt_id = $a9) AS blocked")->fetch();

same('M9 the attempt has locks, events and blocked actions to lose',
    ['locks' => '2', 'events' => '3', 'blocked' => '1'], array_map('strval', $children()));
same('M9 deleting the attempt raises no error', 0,
    sql_error(static fn() => $db->exec("DELETE FROM exam_attempts WHERE id = $a9")));
same('M9 and every one of them is gone',
    ['locks' => '0', 'events' => '0', 'blocked' => '0'], array_map('strval', $children()));
same('M9 while the bulk unlock record stays', 1,
    (int) $db->query("SELECT COUNT(*) FROM bulk_unlocks WHERE id = $bulk")->fetchColumn());

// ============================================================================
section('M10 staff references are RESTRICT, attempt references CASCADE');

$rules = [];
foreach ($db->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                      WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchAll() as $r) {
    $rules[$r['CONSTRAINT_NAME']] = $r['DELETE_RULE'];
}

foreach (['fk_bulk_unlocks_user', 'fk_attempt_locks_claimed_by', 'fk_attempt_locks_unlocked_by',
          'fk_attempt_events_actor', 'fk_attempt_locks_bulk'] as $fk) {
    same("M10 $fk is RESTRICT", 'RESTRICT', $rules[$fk] ?? null);
}
foreach (['fk_attempt_locks_attempt', 'fk_attempt_events_attempt', 'fk_attempt_events_lock',
          'fk_blocked_actions_attempt'] as $fk) {
    same("M10 $fk is CASCADE", 'CASCADE', $rules[$fk] ?? null);
}

// ============================================================================
section('M11 at most one open lock per attempt');

$a11 = new_attempt($db, $course, $pupil);
$b11 = new_attempt($db, $course, $pupil);

same('M11 the first open lock is accepted', 0, sql_error(static fn() => lock($db, $a11)));
same('M11 a second open lock on the same attempt is refused', ER_DUP_ENTRY,
    sql_error(static fn() => lock($db, $a11, '{"triggers":["tab_hidden"]}', 'tab_hidden')));
same('M11 an open lock on a different attempt at the same time is accepted', 0,
    sql_error(static fn() => lock($db, $b11)));

$db->exec("UPDATE attempt_locks SET unlocked_at = NOW() WHERE attempt_id = $a11 AND unlocked_at IS NULL");
same('M11 once the first is unlocked, a new lock is accepted', 0,
    sql_error(static fn() => lock($db, $a11, '{"triggers":["fullscreen_exit"]}', 'fullscreen_exit')));
$db->exec("UPDATE attempt_locks SET unlocked_at = NOW() WHERE attempt_id = $a11 AND unlocked_at IS NULL");
same('M11 and closed locks can pile up behind a third', 0,
    sql_error(static fn() => lock($db, $a11)));

same('M11 leaving three locks on that attempt, one of them open',
    ['total' => '3', 'open' => '1'],
    array_map('strval', $db->query("SELECT COUNT(*) AS total, SUM(unlocked_at IS NULL) AS open
                                      FROM attempt_locks WHERE attempt_id = $a11")->fetch()));

// ============================================================================
section('M12 on a latin1 database the new tables are still utf8mb4');

recreate($freshLatin1, 'latin1', 'latin1_swedish_ci');
[$code, $out] = source_file($freshLatin1, APP_ROOT . '/database/schema_import.sql');
same('M12 the fresh schema loads into a latin1 database', 0, $code);

recreate($migratedLatin1, 'latin1', 'latin1_swedish_ci');
[$code] = source_file($migratedLatin1, $oldFile);
same('M12 the pre-007 schema loads into a latin1 database', 0, $code);
[$code, $out] = source_file($migratedLatin1, MIGRATION);
same('M12 and 007 applies on top of it', 0, $code);

$reason    = 'Ẹ̀rọ ọ ṣ ń: Windows update notice on every PC';
$reasonHex = strtoupper(bin2hex($reason));

foreach (['fresh' => $freshLatin1, 'migrated' => $migratedLatin1] as $label => $name) {
    $l1 = connect($name);

    same("M12 $label: the database default really is latin1", 'latin1', $l1->query(
        "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()")->fetchColumn());

    // The control: a table that declares nothing takes latin1 here. Without
    // it, "the new tables are utf8mb4" could pass on a server that ignored the
    // database default altogether.
    same("M12 $label: a table without options does come out latin1", 'latin1_swedish_ci', $l1->query(
        "SELECT TABLE_COLLATION FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn());

    foreach (['bulk_unlocks', 'attempt_locks', 'attempt_events', 'attempt_blocked_actions'] as $t) {
        same("M12 $label: $t is utf8mb4_unicode_ci", 'utf8mb4_unicode_ci', $l1->query(
            "SELECT TABLE_COLLATION FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetchColumn());

        $stmt = $l1->prepare("SELECT COLUMN_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME IS NOT NULL
                                 AND CHARACTER_SET_NAME <> 'utf8mb4'");
        $stmt->execute([$t]);
        same("M12 $label: every text column of $t is utf8mb4", [], $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    $l1->exec("INSERT INTO users (full_name, email, password_hash, role) VALUES ('T', 't@s.local', 'x', 'lecturer')");
    $by = (int) $l1->lastInsertId();
    $ins = $l1->prepare("INSERT INTO bulk_unlocks (unlocked_by, reason, lock_count, created_at) VALUES (?, ?, 3, NOW())");
    $ins->execute([$by, $reason]);
    $id = (int) $l1->lastInsertId();

    $back = $l1->query("SELECT reason, HEX(reason) AS hex FROM bulk_unlocks WHERE id = $id")->fetch();
    same("M12 $label: a reason typed with ẹ ọ ṣ ń reads back as typed", $reason, $back['reason'] ?? null);
    same("M12 $label: byte for byte", $reasonHex, $back['hex'] ?? null);

    $l1 = null;
}

// ---- Diagnostics ----------------------------------------------------------

section('Diagnostics');
$diags = test_diagnostics();
check('no notices or deprecations were raised', $diags === [],
    implode(' | ', array_slice($diags, 0, 5)));

// ---- Result ---------------------------------------------------------------

$db = null;
$new = null;

echo "\n";
printf("%d passed, %d failed\n", $passed, count($failed));

if ($failed !== []) {
    echo "\nFailures:\n";
    foreach ($failed as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

exit(0);
