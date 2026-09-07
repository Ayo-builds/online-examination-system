<?php
/**
 * Attempt review.
 *
 * $can_review is decided in the controller: correct answers appear only once
 * the exam window has closed for everyone. Until then this page shows the
 * result and the per-question marks, but never which option was right.
 */
$score    = (float) $attempt['total_score'];
$maxMarks = (float) $max_marks;
$pct      = $maxMarks > 0 ? round($score / $maxMarks * 100) : 0;
$started  = strtotime($attempt['started_at']);
$ended    = $attempt['submitted_at'] ? strtotime($attempt['submitted_at']) : null;

$duration = '';
if ($ended !== null) {
    $secs  = max(0, $ended - $started);
    $mins  = intdiv($secs, 60);
    $rest  = $secs % 60;
    $duration = $mins > 0
        ? $mins . ' min' . ($mins === 1 ? '' : 's') . ' ' . $rest . ' sec'
        : $rest . ' sec';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($attempt['exam_title']) ?> · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="quiz-page">
    <main class="quiz">

        <nav class="quiz__crumbs" aria-label="Breadcrumb">
            <a href="<?= BASE_URL ?>student/dashboard">My exams</a>
            <span aria-hidden="true">/</span>
            <span><?= htmlspecialchars($attempt['course_code']) ?></span>
            <span aria-hidden="true">/</span>
            <span><?= htmlspecialchars($attempt['exam_title']) ?></span>
        </nav>

        <header class="quiz__head">
            <span class="quiz__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                    <path d="M7 8.5l1.5 1.5L11 7"></path>
                    <path d="M7 15.5L8.5 17 11 14"></path>
                    <path d="M14 9h4M14 16h4"></path>
                </svg>
            </span>
            <h1 class="quiz__title"><?= htmlspecialchars($attempt['exam_title']) ?></h1>
        </header>

        <div class="quiz__body">
            <div class="quiz__panel">

                <table class="review-table">
                    <tbody>
                        <tr>
                            <th scope="row">Status</th>
                            <td>
                                <?php if ($attempt['status'] === 'auto_submitted'): ?>
                                    Finished, submitted automatically when time expired
                                <?php else: ?>
                                    Finished
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Started</th>
                            <td><?= date('l, j F Y, g:i A', $started) ?></td>
                        </tr>
                        <?php if ($ended !== null): ?>
                        <tr>
                            <th scope="row">Completed</th>
                            <td><?= date('l, j F Y, g:i A', $ended) ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Duration</th>
                            <td><?= htmlspecialchars($duration) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row">Grade</th>
                            <td>
                                <?php if ($attempt['grading_status'] === 'partial'): ?>
                                    <strong><?= htmlspecialchars($attempt['total_score']) ?></strong>
                                    so far, essays still to be marked
                                <?php else: ?>
                                    <strong><?= htmlspecialchars($attempt['total_score']) ?></strong>
                                    out of <?= htmlspecialchars(rtrim(rtrim(number_format($maxMarks, 2), '0'), '.')) ?>
                                    (<strong><?= $pct ?>%</strong>)
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($attempt['grading_status'] === 'partial'): ?>
                    <p class="quiz__notice">
                        This exam has essay questions awaiting marking by your lecturer.
                        Your final grade appears here once marking is complete.
                    </p>
                <?php endif; ?>

                <?php if (!$can_review): ?>
                    <p class="quiz__notice">
                        Your paper is shown below, but correct answers and per-question
                        marks stay hidden until the exam window closes on
                        <?= date('j F Y, g:i A', strtotime($exam['window_end'])) ?>,
                        because other candidates may still be sitting it.
                    </p>
                <?php endif; ?>

                <?php foreach ($answers as $a): ?>
                <?php
                    $num       = (int) $a['display_order'];
                    $awarded   = $a['awarded_marks'];
                    $isMcq     = $a['question_type'] === 'mcq';
                    $graded    = $awarded !== null;
                    $full      = $graded && (float) $awarded >= (float) $a['marks'];
                    $zero      = $graded && (float) $awarded == 0.0;
                    $answered  = $isMcq
                        ? $a['selected_option_id'] !== null
                        : trim((string) $a['essay_text']) !== '';
                    $outOf     = rtrim(rtrim(number_format((float) $a['marks'], 2), '0'), '.');
                ?>
                <div class="question-card" id="q<?= $num ?>">

                    <div class="question-card__meta">
                        <p class="qmeta__num">Question <b><?= $num ?></b></p>
                        <?php /* Per-question correctness is withheld with the answers.
                                 A candidate who remembers what they picked could
                                 otherwise reconstruct the key from "Correct" alone,
                                 which would make the window gate cosmetic. */ ?>
                        <p class="qmeta__state">
                            <?php if (!$can_review): ?>
                                <?= $answered ? 'Answered' : 'Not answered' ?>
                            <?php elseif (!$graded): ?>
                                Not yet marked
                            <?php elseif ($full): ?>
                                Correct
                            <?php elseif ($zero): ?>
                                Incorrect
                            <?php else: ?>
                                Partially correct
                            <?php endif; ?>
                        </p>
                        <p class="qmeta__marks">
                            <?php if ($can_review && $graded): ?>
                                Mark <?= htmlspecialchars(rtrim(rtrim(number_format((float) $awarded, 2), '0'), '.')) ?>
                                out of <?= htmlspecialchars($outOf) ?>
                            <?php else: ?>
                                Marked out of <?= htmlspecialchars($outOf) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="question-card__body">
                        <p class="question-card__text">
                            <?= nl2br(htmlspecialchars($a['question_text'])) ?>
                        </p>

                        <?php if ($isMcq): ?>
                            <p class="qbody__select">Select one:</p>
                            <div class="opts">
                                <?php foreach ($a['options'] as $i => $opt): ?>
                                <?php
                                    $chosen  = (int) $a['selected_option_id'] === (int) $opt['id'];
                                    $correct = $can_review && $opt['is_correct'];
                                    $wrong   = $can_review && $chosen && !$opt['is_correct'];
                                ?>
                                <div class="opt opt--review<?= $correct ? ' opt--correct' : '' ?><?= $wrong ? ' opt--wrong' : '' ?>">
                                    <span class="opt__radio<?= $chosen ? ' opt__radio--on' : '' ?>" aria-hidden="true"></span>
                                    <span class="opt__letter"><?= chr(97 + $i) ?>.</span>
                                    <span class="opt__text"><?= htmlspecialchars($opt['text']) ?></span>
                                    <?php if ($correct): ?>
                                        <span class="opt__mark opt__mark--ok" title="Correct answer">&#10003;</span>
                                    <?php elseif ($wrong): ?>
                                        <span class="opt__mark opt__mark--no" title="Your answer">&#10007;</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($a['selected_option_id'] === null): ?>
                                <p class="quiz__unanswered">You did not answer this question.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="qbody__select">Your answer:</p>
                            <?php if (trim((string) $a['essay_text']) === ''): ?>
                                <p class="quiz__unanswered">You did not answer this question.</p>
                            <?php else: ?>
                                <div class="review-essay"><?= nl2br(htmlspecialchars($a['essay_text'])) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                </div>
                <?php endforeach; ?>

                <p class="quiz__back">
                    <a href="<?= BASE_URL ?>student/dashboard">Back to my exams</a>
                </p>
            </div>

            <aside class="quiz__nav" aria-label="Quiz navigation">
                <h2>Quiz navigation</h2>
                <div class="qnav__grid">
                    <?php foreach ($answers as $a): ?>
                    <?php
                        $n   = (int) $a['display_order'];
                        $aw  = $a['awarded_marks'];
                        $cls = 'qnav__box';
                        // Colouring these before the window closes would leak the
                        // same correctness the meta rail withholds.
                        if ($can_review && $aw !== null && (float) $aw >= (float) $a['marks']) {
                            $cls .= ' qnav__box--correct';
                        } elseif ($can_review && $aw !== null && (float) $aw == 0.0) {
                            $cls .= ' qnav__box--incorrect';
                        }
                    ?>
                    <a class="<?= $cls ?>" href="#q<?= $n ?>"><?= $n ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="qnav__meta">
                    <p class="qnav__label">Grade</p>
                    <div class="review-grade"><?= $pct ?>%</div>
                </div>
            </aside>
        </div>

    </main>
</body>
</html>
