<?php
/**
 * The paper leaves the page: question text reaches a browser only through
 * POST student/paper/{id}, never in the exam page's HTML.
 *
 *   php tests/paper_test.php
 *
 * Runs against the test database only - bootstrap.php refuses anything else -
 * and drives the REAL application over HTTP through PHP's built-in server on
 * port 8097. A response fetched here with curl is exactly what a browser with
 * JavaScript turned off receives, so "no question text in the page" is
 * asserted on the real bytes.
 *
 * Every absence check has a presence check in front of it: the same strings
 * are first shown to come back from the paper endpoint. Without that, a broken
 * fixture with no questions would pass every "not in the page" assertion.
 *
 * This file resets the test database and builds everything from nothing. The
 * reset also removes the users fixture: run tests/setup_users_fixture.php again
 * afterwards before running tests/enrollments_list_test.php.
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

// ---- The app, served for real ---------------------------------------------

$host = '127.0.0.1';
$port = 8097;
$base = "http://$host:$port/";

test_start_server($host, $port);
test_reset_database();

// ---- A cookie-carrying HTTP client, one jar per student --------------------

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
});

/**
 * @return array{status:int, body:string, json:?array, headers:array<string,string>}
 */
function http(string $who, string $method, string $path, array $post = []): array
{
    global $base, $jars;

    $jars[$who] ??= (string) tempnam(sys_get_temp_dir(), 'exam_cookies_');

    $headers = [];
    $ch = curl_init($base . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jars[$who],
        CURLOPT_COOKIEFILE     => $jars[$who],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $json = json_decode($body, true);

    return [
        'status'  => $status,
        'body'    => $body,
        'json'    => is_array($json) ? $json : null,
        'headers' => $headers,
    ];
}

/** Sign a student in and return the CSRF token their session holds. */
function sign_in(string $who, string $admissionNo, string $password): string
{
    $login = http($who, 'GET', 'auth/login');
    preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
    $token = $m[1] ?? '';

    $res = http($who, 'POST', 'auth/authenticate', [
        'csrf_token' => $token,
        'identifier' => $admissionNo,
        'password'   => $password,
    ]);
    check("$who signed in", $res['status'] === 302, 'status ' . $res['status']);

    return $token;
}

/** Every string, and its HTML-escaped form, that must not appear in $body. */
function leaks(string $body, array $strings): array
{
    $found = [];
    foreach ($strings as $s) {
        if (strpos($body, $s) !== false
            || strpos($body, htmlspecialchars($s)) !== false
            || strpos($body, json_encode($s)) !== false) {
            $found[] = $s;
        }
    }
    return $found;
}

// ---- Fixture ---------------------------------------------------------------

$db = Database::getInstance();

$db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?, 'lecturer')")
   ->execute(['Paper Teacher', 'paper@exam.local', Password::hash('lecturer-pass-123')]);
$lecturerId = (int) $db->lastInsertId();

$classId = (int) $db->query("SELECT id FROM classes ORDER BY id LIMIT 1")->fetchColumn();

$pass = 'student-pass-123';
$students = [];
foreach (['ada' => 'ADM/PAPER/1', 'bola' => 'ADM/PAPER/2'] as $who => $adm) {
    $db->prepare(
        "INSERT INTO users (full_name, admission_no, password_hash, role, class_id, status)
         VALUES (?,?,?, 'student', ?, 'active')"
    )->execute([ucfirst($who), $adm, Password::hash($pass), $classId]);
    $students[$who] = ['id' => (int) $db->lastInsertId(), 'adm' => $adm];
}

$db->prepare("INSERT INTO courses (course_code, title, lecturer_id) VALUES (?,?,?)")
   ->execute(['PAPR101', 'Paper Subject', $lecturerId]);
$courseId = (int) $db->lastInsertId();

foreach ($students as $s) {
    $db->prepare("INSERT INTO enrollments (student_id, course_id) VALUES (?,?)")
       ->execute([$s['id'], $courseId]);
}

$examTitle = 'Leaky Title Paper';
$db->prepare(
    "INSERT INTO exams
        (course_id, title, instructions, duration_minutes, questions_per_attempt,
         shuffle_options, window_start, window_end, pass_mark, status)
     VALUES (?,?,?,?,?,1, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 6 HOUR), 40, 'published')"
)->execute([$courseId, $examTitle, 'Read carefully.', 60, 2]);
$examId = (int) $db->lastInsertId();

// Distinctive text, with markup characters and a line break, so a leak cannot
// hide behind escaping and a match cannot be a coincidence.
$mcqText   = "Which river flows through Lokoja? <b>Pick one</b> & explain";
$essayText = "Describe the water cycle.\nMention evaporation.";
$optionTexts = ['Niger & Benue', 'Thames <river>', 'Nile "the long"', 'Volta'];

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', ?, 4, ?)")
   ->execute([$courseId, $mcqText, $lecturerId]);
$mcqId = (int) $db->lastInsertId();

$optionIds = [];
foreach ($optionTexts as $i => $text) {
    $db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,?)")
       ->execute([$mcqId, $text, $i === 0 ? 1 : 0]);
    $optionIds[] = (int) $db->lastInsertId();
}

$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'essay', ?, 6, ?)")
   ->execute([$courseId, $essayText, $lecturerId]);
$essayId = (int) $db->lastInsertId();

foreach ([$mcqId, $essayId] as $qid) {
    $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")->execute([$examId, $qid]);
}

$secretStrings = array_merge([$mcqText, $essayText], $optionTexts);

// ---- Start both attempts ---------------------------------------------------

section('Starting attempts');

$tokens = [];
$attemptIds = [];
foreach ($students as $who => $s) {
    $tokens[$who] = sign_in($who, $s['adm'], $pass);
    $res = http($who, 'POST', 'student/startExam/' . $examId, ['csrf_token' => $tokens[$who]]);
    check("$who started an attempt", $res['status'] === 302, 'status ' . $res['status']);
    $attemptIds[$who] = (int) (new Attempt())->findByExamAndStudent($examId, $s['id'])['id'];
}

$ada = $attemptIds['ada'];

// Freeze Ada's options in a known order that is NOT database order, so the
// test can tell a paper that honours option_order from one that ignores it.
$frozen = array_reverse($optionIds);
$db->prepare("UPDATE attempt_questions SET option_order = ? WHERE attempt_id = ? AND question_id = ?")
   ->execute([json_encode($frozen), $ada, $mcqId]);

// Answers already saved, as on a resume.
$db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id) VALUES (?,?,?)")
   ->execute([$ada, $mcqId, $frozen[1]]);
$db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, essay_text) VALUES (?,?,?)")
   ->execute([$ada, $essayId, 'Water rises, cools and falls.']);

// ---- P1: the paper endpoint returns the whole paper -------------------------

section('The paper endpoint');

$paper = http('ada', 'POST', 'student/paper/' . $ada, ['csrf_token' => $tokens['ada']]);
same('POST paper returns 200', 200, $paper['status']);
same('and says ok', true, $paper['json']['ok'] ?? null);

$byId = [];
foreach ($paper['json']['questions'] ?? [] as $q) {
    $byId[(int) $q['question_id']] = $q;
}
$ids = array_keys($byId);
sort($ids);
same('with both questions', [$mcqId, $essayId], $ids);
same('the MCQ text, unescaped', $mcqText, $byId[$mcqId]['question_text'] ?? null);
same('the essay text, line break intact', $essayText, $byId[$essayId]['question_text'] ?? null);
same('the options in the frozen order, not database order',
    $frozen, array_map(static fn($o) => $o['id'], $byId[$mcqId]['options'] ?? []));
same('with their text',
    array_reverse($optionTexts), array_map(static fn($o) => $o['text'], $byId[$mcqId]['options'] ?? []));
same('the saved choice', $frozen[1], $byId[$mcqId]['selected_option_id'] ?? null);
same('the saved essay', 'Water rises, cools and falls.', $byId[$essayId]['essay_text'] ?? null);
same('marks as stored', '6.00', $byId[$essayId]['marks'] ?? null);
same('display order', [1, 2], array_values(array_map(static fn($q) => $q['display_order'],
    $paper['json']['questions'] ?? [])));
$remaining = $paper['json']['remaining'] ?? -1;
check('remaining time comes from the server and is within the duration',
    is_int($remaining) && $remaining > 0 && $remaining <= 60 * 60, 'remaining ' . var_export($remaining, true));
check('every secret string really is in the paper (the absence checks below rely on it)',
    count(leaks($paper['body'], $secretStrings)) === count($secretStrings));

// ---- P3: no answer key -----------------------------------------------------

check('the paper carries no is_correct anywhere', stripos($paper['body'], 'is_correct') === false);
check('and no option_order either', stripos($paper['body'], 'option_order') === false);
foreach ($byId[$mcqId]['options'] ?? [] as $o) {
    same('each option has only an id and text', ['id', 'text'], array_keys($o));
}

// ---- P2 / P10 / P11: the exam page -----------------------------------------

section('The exam page, as a browser without JavaScript gets it');

$page = http('ada', 'GET', 'student/exam/' . $ada);
same('GET exam returns 200', 200, $page['status']);
same('with no question or option text in it', [], leaks($page['body'], $secretStrings));
check('it says the exam requires JavaScript',
    (bool) preg_match('#<noscript>.*This exam requires JavaScript\..*</noscript>#s', $page['body']));
check('and everything else is hidden until JavaScript reveals it',
    (bool) preg_match('#<main[^>]*id="exam-app"[^>]*hidden#', $page['body']));

preg_match('#<title>(.*?)</title>#s', $page['body'], $t);
check('the tab title does not name the exam', isset($t[1]) && strpos($t[1], $examTitle) === false,
    $t[1] ?? '(no title)');

// The paper is drawn into these two elements. They must sit inside the
// .quiz-page container, or the stage 1 selection CSS does not cover them.
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($page['body']);
libxml_clear_errors();

foreach (['question-list', 'qnav-grid'] as $mountId) {
    $node = null;
    foreach ($dom->getElementsByTagName('*') as $el) {
        if ($el->getAttribute('id') === $mountId) { $node = $el; break; }
    }
    check("#$mountId exists", $node !== null);

    $inside = false;
    for ($n = $node; $n instanceof DOMElement; $n = $n->parentNode) {
        if (in_array('quiz-page', preg_split('/\s+/', $n->getAttribute('class')), true)) {
            $inside = true;
            break;
        }
    }
    check("#$mountId is inside the .quiz-page container", $inside);
}

// ---- P9: never from the browser's cache ------------------------------------

same('the exam page is no-store', 'no-store', $page['headers']['cache-control'] ?? null);
same('the paper is no-store', 'no-store', $paper['headers']['cache-control'] ?? null);

// ---- P4 / P5 / P6: who may read it, and how --------------------------------

section('Refusals');

$res = http('ada', 'GET', 'student/paper/' . $ada);
same('GET paper returns 405', 405, $res['status']);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

$res = http('ada', 'POST', 'student/paper/' . $ada, ['csrf_token' => 'not-the-token']);
same('a bad CSRF token returns 403', 403, $res['status']);
same('with error "csrf"', 'csrf', $res['json']['error'] ?? null);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

$res = http('ada', 'POST', 'student/paper/' . $attemptIds['bola'], ['csrf_token' => $tokens['ada']]);
same("another student's attempt returns 404", 404, $res['status']);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

$res = http('stranger', 'POST', 'student/paper/' . $ada, ['csrf_token' => $tokens['ada']]);
same('with no session, the paper redirects to sign in', 302, $res['status']);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

// ---- P7: a finished attempt ------------------------------------------------

section('Closed attempts');

$res = http('bola', 'POST', 'student/submitExam/' . $attemptIds['bola'], ['csrf_token' => $tokens['bola']]);
same('Bola submits', 302, $res['status']);

$res = http('bola', 'POST', 'student/paper/' . $attemptIds['bola'], ['csrf_token' => $tokens['bola']]);
same('a submitted attempt returns 409', 409, $res['status']);
same('with error "closed"', 'closed', $res['json']['error'] ?? null);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

// ---- P8: past the deadline -------------------------------------------------

$db->prepare("UPDATE exam_attempts SET deadline_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")
   ->execute([$ada]);

$res = http('ada', 'POST', 'student/paper/' . $ada, ['csrf_token' => $tokens['ada']]);
same('past the deadline the paper returns 409', 409, $res['status']);
same('with error "closed"', 'closed', $res['json']['error'] ?? null);
same('and leaks nothing', [], leaks($res['body'], $secretStrings));

$row = $db->prepare("SELECT status, closed_by_system_at FROM exam_attempts WHERE id = ?");
$row->execute([$ada]);
$row = $row->fetch();
same('and the attempt has been closed', 'auto_submitted', $row['status'] ?? null);
check('by the system', ($row['closed_by_system_at'] ?? null) !== null);

// ---- O1-O7: options when shuffling is off ------------------------------------
//
// The paper and the result page draw a question's options only from
// attempt_questions.option_order. start() used to write one only when
// shuffle_options was exactly 1, so an exam at 0 or NULL showed every
// multiple-choice question with nothing to choose.

section('Options when shuffling is off');

/** The option_order start() froze for one question, decoded; null if none. */
function frozen_order(int $attemptId, int $questionId): ?array
{
    $stmt = Database::getInstance()->prepare(
        "SELECT option_order FROM attempt_questions WHERE attempt_id = ? AND question_id = ?"
    );
    $stmt->execute([$attemptId, $questionId]);
    $json = $stmt->fetchColumn();

    return is_string($json) ? json_decode($json, true) : null;
}

function set_order(int $attemptId, int $questionId, ?string $json): void
{
    Database::getInstance()
        ->prepare("UPDATE attempt_questions SET option_order = ? WHERE attempt_id = ? AND question_id = ?")
        ->execute([$json, $attemptId, $questionId]);
}

/** The paper's options for each question, keyed by question id. */
function paper_options(string $who, int $attemptId): array
{
    global $tokens;

    $res = http($who, 'POST', 'student/paper/' . $attemptId, ['csrf_token' => $tokens[$who]]);
    check("$who's paper for attempt $attemptId returns 200", $res['status'] === 200, 'status ' . $res['status']);

    $out = [];
    foreach ($res['json']['questions'] ?? [] as $q) {
        $out[(int) $q['question_id']] = $q['options'];
    }
    return $out;
}

// A second multiple-choice question. Its options go in with their text in
// reverse alphabetical order, so id order and text order differ.
$mcq2Text = 'Which city is the capital of Nigeria?';
$db->prepare("INSERT INTO questions (course_id, question_type, question_text, marks, created_by) VALUES (?, 'mcq', ?, 2, ?)")
   ->execute([$courseId, $mcq2Text, $lecturerId]);
$mcq2Id = (int) $db->lastInsertId();

$option2Texts = ['Lagos', 'Kano', 'Ibadan', 'Abuja'];
$option2Ids = [];
foreach ($option2Texts as $text) {
    $db->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,?)")
       ->execute([$mcq2Id, $text, $text === 'Abuja' ? 1 : 0]);
    $option2Ids[] = (int) $db->lastInsertId();
}

// $optionIds and $option2Ids are already ascending: AUTO_INCREMENT.
$expected = [
    $mcqId  => ['ids' => $optionIds,  'texts' => $optionTexts],
    $mcq2Id => ['ids' => $option2Ids, 'texts' => $option2Texts],
];

/** An exam drawing all three questions, with shuffle_options as given. */
function unshuffled_exam(string $title, ?int $shuffle): int
{
    global $db, $courseId, $mcqId, $mcq2Id, $essayId;

    $db->prepare(
        "INSERT INTO exams
            (course_id, title, instructions, duration_minutes, questions_per_attempt,
             shuffle_options, window_start, window_end, pass_mark, status)
         VALUES (?,?,?,?,?,?, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 6 HOUR), 40, 'published')"
    )->execute([$courseId, $title, 'Read carefully.', 60, 3, $shuffle]);
    $id = (int) $db->lastInsertId();

    foreach ([$mcqId, $mcq2Id, $essayId] as $qid) {
        $db->prepare("INSERT INTO exam_question_pool (exam_id, question_id) VALUES (?,?)")->execute([$id, $qid]);
    }
    return $id;
}

/** O1 and O2 for one attempt: the frozen order, then the paper. */
function check_in_id_order(string $label, string $who, int $attemptId): void
{
    global $expected, $essayId;

    foreach ($expected as $qid => $e) {
        same("$label: question $qid froze its option ids in ascending order",
            $e['ids'], frozen_order($attemptId, $qid));
    }
    same("$label: the essay still has no option order", null, frozen_order($attemptId, $essayId));

    $options = paper_options($who, $attemptId);
    foreach ($expected as $qid => $e) {
        same("$label: the paper shows every option of question $qid, in id order",
            $e['ids'], array_map(static fn($o) => $o['id'], $options[$qid] ?? []));
        same("$label: with its text",
            $e['texts'], array_map(static fn($o) => $o['text'], $options[$qid] ?? []));
    }
}

// O1, O2: shuffle_options = 0.
$exam0 = unshuffled_exam('Unshuffled Zero', 0);
$res = http('bola', 'POST', 'student/startExam/' . $exam0, ['csrf_token' => $tokens['bola']]);
same('Bola starts the shuffle_options = 0 exam', 302, $res['status']);
$bola0 = (int) (new Attempt())->findByExamAndStudent($exam0, $students['bola']['id'])['id'];
check_in_id_order('O1/O2 (shuffle 0)', 'bola', $bola0);

// O5: shuffle_options NULL behaves like 0.
$examNull = unshuffled_exam('Unshuffled Null', null);
$res = http('ada', 'POST', 'student/startExam/' . $examNull, ['csrf_token' => $tokens['ada']]);
same('Ada starts the shuffle_options NULL exam', 302, $res['status']);
$adaNull = (int) (new Attempt())->findByExamAndStudent($examNull, $students['ada']['id'])['id'];
check_in_id_order('O5 (shuffle NULL)', 'ada', $adaNull);

// O3: a row already in a database with no order, as every attempt on an
// unshuffled exam had before this fix. An answer is saved on it, as on a resume.
set_order($bola0, $mcqId, null);
set_order($bola0, $mcq2Id, null);
$db->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_option_id) VALUES (?,?,?)")
   ->execute([$bola0, $mcq2Id, $option2Ids[3]]);

$options = paper_options('bola', $bola0);
foreach ($expected as $qid => $e) {
    same("O3: with option_order NULL, the paper still shows every option of question $qid, in id order",
        $e['ids'], array_map(static fn($o) => $o['id'], $options[$qid] ?? []));
}

// O4: the same attempt, submitted, on its result page.
$res = http('bola', 'POST', 'student/submitExam/' . $bola0, ['csrf_token' => $tokens['bola']]);
same('Bola submits the attempt with no option order', 302, $res['status']);

$result = http('bola', 'GET', 'student/result/' . $bola0);
same('its result page returns 200', 200, $result['status']);
foreach ($expected as $qid => $e) {
    $positions = array_map(static fn($t) => strpos($result['body'], htmlspecialchars($t)), $e['texts']);
    check("O4: the result page lists every option of question $qid",
        !in_array(false, $positions, true), 'positions ' . json_encode($positions));
    $sorted = $positions;
    sort($sorted);
    same("O4: in id order", $sorted, $positions);
}
same('O4: with the one saved choice marked', 1, substr_count($result['body'], 'opt__radio--on'));

// O6: JSON that is not a usable list falls back to id order, like NULL.
// The column is JSON, which both MariaDB and MySQL validate, so invalid
// JSON cannot be stored; the helper is also called with it directly below.
foreach (['[]', 'null', '"abc"', '{}'] as $json) {
    set_order($adaNull, $mcqId, $json);
    $options = paper_options('ada', $adaNull);
    same("O6: option_order $json falls back to id order",
        $optionIds, array_map(static fn($o) => $o['id'], $options[$mcqId] ?? []));
}

$helper = new ReflectionMethod(Attempt::class, 'frozenOptionOrder');
if (PHP_VERSION_ID < 80100) {
    $helper->setAccessible(true); // a no-op from 8.1 and deprecated in 8.5
}
same('O6: invalid JSON, handed to the helper, falls back to id order',
    [3, 5, 9], $helper->invoke(new Attempt(), '[5, 3', [9, 3, 5]));
same('O6: and a valid, non-empty order is kept as it is',
    [9, 3], $helper->invoke(new Attempt(), '[9, 3]', [9, 3, 5]));

// O7: a shuffled exam is unchanged. Bola's first attempt still holds the
// order start() froze (only Ada's was overwritten above).
$shuffled = frozen_order($attemptIds['bola'], $mcqId);
$sortedShuffled = $shuffled ?? [];
sort($sortedShuffled);
same('O7: a shuffled exam still freezes a permutation of every option', $optionIds, $sortedShuffled);

// ---- Diagnostics ----------------------------------------------------------

section('Diagnostics');
$diags = test_diagnostics();
check('no notices or deprecations were raised', $diags === [],
    implode(' | ', array_slice($diags, 0, 5)));

// ---- Result ---------------------------------------------------------------

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
