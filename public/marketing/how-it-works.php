<?php
require __DIR__ . '/_partials/bootstrap.php';

$page_title       = 'How it works · ' . APP_NAME;
$page_description = 'An exam from enrolment to published result: who does what at each stage, '
                  . 'what the system does during an attempt, and what happens when something goes wrong.';
$page_key         = 'how-it-works';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/nav.php';
?>

<main id="main">

  <!-- ============================ HEADER ============================ -->
  <section class="phead">
    <div class="wrap">
      <div class="phead__inner">
        <p class="eyebrow rise rise--1">How it works</p>
        <h1 class="phead__title rise rise--2">From enrolment to published result.</h1>
        <p class="phead__lead rise rise--3">
          An exam passes through four sets of hands. Here is what each one does, what the
          system is doing in between, and what happens when something goes wrong halfway
          through an attempt.
        </p>
      </div>
    </div>
  </section>

  <!-- ============================ STAGES ============================ -->
  <section class="bay bay--paper">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">The four stages</p>
        <h2 class="h2">Each stage hands off to the next.</h2>
        <p class="lead">
          Nothing skips ahead. An exam that has not been published cannot be opened, and a
          student who is not enrolled cannot reach it at all.
        </p>
      </div>

      <ol class="stages">

        <li class="stage">
          <p class="stage__num" aria-hidden="true"></p>
          <div class="stage__body">
            <p class="stage__role">Administrator</p>
            <h3 class="stage__title">Enrol the cohort</h3>
            <p class="stage__text">
              Before an exam can exist, the system has to know who its people are. An
              administrator creates accounts with a role attached, sets up courses, and
              enrols students onto the ones they are registered for. Enrolment is the gate
              that matters: it decides who can reach a paper at all.
            </p>
            <ul class="stage__detail">
              <li>Every account carries one role, either student, lecturer or administrator</li>
              <li>Courses created and assigned to a lecturer</li>
              <li>Students enrolled per course</li>
              <li>A student who is not enrolled is refused, URL or no URL</li>
            </ul>
          </div>
        </li>

        <li class="stage">
          <p class="stage__num" aria-hidden="true"></p>
          <div class="stage__body">
            <p class="stage__role">Lecturer</p>
            <h3 class="stage__title">Build the pool, then the paper</h3>
            <p class="stage__text">
              Questions live in a pool attached to the course rather than inside one
              exam, so a question written once can serve several sittings. The lecturer
              then defines the exam over that pool: how long it runs, when it is open, and
              how many questions each candidate should be given. It stays invisible to
              students until it is published.
            </p>
            <ul class="stage__detail">
              <li>Multiple-choice and essay question types</li>
              <li>Marks set per question</li>
              <li>Duration and availability window set on the exam</li>
              <li>Questions per attempt set on the exam</li>
              <li>Nothing is reachable until the exam is published</li>
            </ul>
          </div>
        </li>

        <li class="stage">
          <p class="stage__num" aria-hidden="true"></p>
          <div class="stage__body">
            <p class="stage__role">Student</p>
            <h3 class="stage__title">Sit the paper</h3>
            <p class="stage__text">
              Inside the window, an enrolled student starts the attempt. This is the
              moment that matters most. The system draws a random subset of the pool and
              freezes it as <em>that candidate's</em> paper, while the database stamps a
              deadline calculated from the exam's duration. Two students sitting the same
              exam are not necessarily answering the same questions. Answers are written
              as they are entered, so work already done survives a closed lid.
            </p>
            <ul class="stage__detail">
              <li>A randomised draw from the pool, frozen to this attempt</li>
              <li>Deadline stamped by the database when the attempt starts</li>
              <li>One attempt per student per exam</li>
              <li>Each answer saved on entry and overwritten if changed</li>
              <li>Whitelisted events written to the attempt's log as they occur</li>
            </ul>
          </div>
        </li>

        <li class="stage">
          <p class="stage__num" aria-hidden="true"></p>
          <div class="stage__body">
            <p class="stage__role">Lecturer, then student</p>
            <h3 class="stage__title">Mark and publish</h3>
            <p class="stage__text">
              On submission, multiple-choice answers are graded against the correct
              answers frozen into that paper, not against whatever the question pool
              says today, which is what keeps an old result reproducible. Essays queue for
              the lecturer. Once nothing is outstanding, the score reaches the student and
              the analytics reach the lecturer.
            </p>
            <ul class="stage__detail">
              <li>Objective questions graded the moment the attempt is submitted</li>
              <li>Graded against answers frozen at draw time, not current ones</li>
              <li>Essay answers queued for manual marking</li>
              <li>Per-question difficulty and cohort distribution for the lecturer</li>
            </ul>
          </div>
        </li>

      </ol>
    </div>
  </section>

  <!-- ============================ ANATOMY ============================ -->
  <section class="bay bay--ink">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">Inside one attempt</p>
        <h2 class="h2">What the system does while a candidate writes.</h2>
        <p class="lead">
          A single 120-minute paper, from first click to published mark. Times are measured
          from the start of the attempt.
        </p>
      </div>

      <div class="timeline">

        <div class="tl">
          <span class="tl__at">00:00:00</span>
          <span class="tl__what">Attempt created; deadline stamped by the database</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl">
          <span class="tl__at">00:00:00</span>
          <span class="tl__what">Random draw from the pool frozen as this candidate's paper</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl">
          <span class="tl__at">00:03:18</span>
          <span class="tl__what">Answer to question 1 saved</span>
          <span class="tl__who">Student</span>
        </div>
        <div class="tl">
          <span class="tl__at">00:15:42</span>
          <span class="tl__what">Question 7 answered again, overwriting the earlier answer</span>
          <span class="tl__who">Student</span>
        </div>
        <div class="tl">
          <span class="tl__at">00:31:07</span>
          <span class="tl__what">Window lost focus, written to the log</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl">
          <span class="tl__at">00:31:41</span>
          <span class="tl__what">Window lost focus, written to the log</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl tl--mark">
          <span class="tl__at">00:32:15</span>
          <span class="tl__what">Third counting event, so the attempt is flagged for review</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl">
          <span class="tl__at">01:58:30</span>
          <span class="tl__what">Submitted, with two essay answers outstanding</span>
          <span class="tl__who">Student</span>
        </div>
        <div class="tl">
          <span class="tl__at">01:58:30</span>
          <span class="tl__what">Objective answers graded against the frozen paper</span>
          <span class="tl__who">Server</span>
        </div>
        <div class="tl">
          <span class="tl__at">Later</span>
          <span class="tl__what">Essays marked; result published to the student</span>
          <span class="tl__who">Lecturer</span>
        </div>

      </div>
    </div>
  </section>

  <!-- ============================ EDGE CASES ============================ -->
  <section class="bay bay--tint">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">When it goes wrong</p>
        <h2 class="h2">The questions people actually ask.</h2>
      </div>

      <div class="principles">

        <article class="principle">
          <p class="principle__label">Connection</p>
          <div class="principle__text">
            <h3 class="principle__title">A student loses their internet mid-paper.</h3>
            <p class="principle__body">
              Everything already saved stays saved, because saving happens on entry rather
              than at submission. When the connection returns the attempt is still open and
              still theirs. The clock, however, has kept running. Time spent offline is not
              refunded, because the server has no way to verify what happened during it.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Expiry</p>
          <div class="principle__text">
            <h3 class="principle__title">The clock runs out before they submit.</h3>
            <p class="principle__body">
              The attempt closes itself with whatever has been answered, recorded as
              auto-submitted rather than submitted, and grades normally from there. A
              submission that arrives a moment after the deadline is treated the same way:
              marked as auto-submitted and graded, not thrown away. The distinction is
              kept in the record so a marker can see which it was.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Closed browser</p>
          <div class="principle__text">
            <h3 class="principle__title">A student closes the browser and comes back.</h3>
            <p class="principle__body">
              They return to the same attempt, with their saved answers and the same
              deadline. Closing a window does not pause an exam. If it did, every
              candidate would close their window. What they lose is the time they were
              away, and if the deadline passed while they were gone the attempt is closed
              on their return.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Second attempt</p>
          <div class="principle__text">
            <h3 class="principle__title">Can a student sit the same exam twice?</h3>
            <p class="principle__body">
              No. One attempt exists per student per exam. Reopening a finished exam sends
              them back to their dashboard rather than starting a fresh paper, and an
              attempt still in progress is resumed rather than restarted.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Flagging</p>
          <div class="principle__text">
            <h3 class="principle__title">An attempt gets flagged.</h3>
            <p class="principle__body">
              Nothing happens to the student automatically. The flag marks the attempt for
              review with the events that caused it attached, and a person decides what it
              means. Note that not every logged event counts toward the flag. Copy and
              paste are recorded for context, while the threshold counts the events that
              suggest a candidate left the paper.
            </p>
          </div>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ CTA ============================ -->
  <section class="bay bay--paper">
    <div class="wrap">
      <div class="cta">
        <p class="eyebrow">Already registered</p>
        <h2 class="cta__title">Sign in and pick up where your role starts.</h2>
        <p class="lead">
          Students, lecturers and administrators all use the same sign-in page.
        </p>
        <a class="btn btn--primary" href="<?= e(url(LOGIN_URL_PATH)) ?>">
          Sign in
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h13M13 6l6 6-6 6"></path>
          </svg>
        </a>
      </div>
    </div>
  </section>

</main>

<?php require __DIR__ . '/_partials/footer.php'; ?>
