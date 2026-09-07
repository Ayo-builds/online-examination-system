<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($exam['title']) ?> · <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="quiz-page">
    <main class="quiz quiz--narrow">

        <nav class="quiz__crumbs" aria-label="Breadcrumb">
            <a href="<?= BASE_URL ?>student/dashboard">My exams</a>
            <span aria-hidden="true">/</span>
            <span><?= htmlspecialchars($course['course_code'] ?? '') ?></span>
            <span aria-hidden="true">/</span>
            <span><?= htmlspecialchars($exam['title']) ?></span>
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
            <h1 class="quiz__title"><?= htmlspecialchars($exam['title']) ?></h1>
        </header>

        <p class="quiz__todo"><strong>To do:</strong> Make attempts: 1</p>

        <hr class="quiz__rule">

        <p class="quiz__window">
            <strong>Opens:</strong> <?= date('l, j F Y, g:i A', strtotime($exam['window_start'])) ?>
        </p>
        <p class="quiz__window">
            <strong>Closes:</strong> <?= date('l, j F Y, g:i A', strtotime($exam['window_end'])) ?>
        </p>

        <hr class="quiz__rule">

        <?php if (!empty($exam['instructions'])): ?>
            <h2 class="quiz__subhead">Please read the instructions below carefully.</h2>
            <div class="quiz__prose"><?= nl2br(htmlspecialchars($exam['instructions'])) ?></div>
        <?php endif; ?>

        <div class="quiz__panel quiz__start">
            <?php if ($open): ?>
                <form method="POST" action="<?= BASE_URL ?>student/startExam/<?= (int) $exam['id'] ?>"
                      onsubmit="return confirm('Start this exam now? Your <?= (int) $exam['duration_minutes'] ?>-minute timer begins immediately and cannot be paused.');">
                    <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
                    <button type="submit" class="qbtn qbtn--primary">Attempt quiz</button>
                </form>
            <?php elseif ($now < strtotime($exam['window_start'])): ?>
                <p class="quiz__closed">This exam has not opened yet.</p>
            <?php else: ?>
                <p class="quiz__closed">This exam has closed.</p>
            <?php endif; ?>

            <dl class="quiz__facts">
                <div>
                    <dt>Attempts allowed</dt>
                    <dd>1</dd>
                </div>
                <div>
                    <dt>Time limit</dt>
                    <dd><?= (int) $exam['duration_minutes'] ?> minutes</dd>
                </div>
                <div>
                    <dt>Questions</dt>
                    <dd><?= (int) $exam['questions_per_attempt'] ?></dd>
                </div>
                <?php if ($exam['pass_mark'] !== null): ?>
                <div>
                    <dt>Pass mark</dt>
                    <dd><?= htmlspecialchars($exam['pass_mark']) ?>%</dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>

        <p class="quiz__back">
            <a href="<?= BASE_URL ?>student/dashboard">Back to my exams</a>
        </p>

    </main>
</body>
</html>
