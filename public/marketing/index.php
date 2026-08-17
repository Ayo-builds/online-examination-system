<?php
require __DIR__ . '/_partials/bootstrap.php';

$page_title       = APP_NAME . ' — timed, invigilated online exams';
$page_description = 'A timed, invigilated online examination platform for universities and colleges. '
                  . 'Server-side clocks, per-attempt integrity logging, role-based dashboards and published results.';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/nav.php';
?>

<main id="main">

  <!-- ============================ HERO ============================ -->
  <section class="hero">

    <div class="hero__media">
      <img
        src="<?= e(asset('img/exam-hall-1600.jpg')) ?>"
        srcset="<?= e(asset('img/exam-hall-900.jpg')) ?> 900w,
                <?= e(asset('img/exam-hall-1600.jpg')) ?> 1600w,
                <?= e(asset('img/exam-hall-2400.jpg')) ?> 2400w"
        sizes="100vw"
        width="2400" height="1600"
        alt="Students seated at spaced, partitioned desks writing an examination, with an invigilator standing at the back of the hall."
        fetchpriority="high" decoding="async">
    </div>
    <div class="hero__wash"></div>

    <div class="hero__inner">

      <div class="hero__copy">
        <h1 class="hero__title rise rise--1">
          <span class="hero__line">Every attempt on the clock.</span>
          <span class="hero__line">Every action <em>on the record.</em></span>
        </h1>

        <p class="hero__lead rise rise--2">
          A timed, invigilated examination platform for universities and colleges.
          Set the paper once — delivery, supervision, marking and results are handled,
          and every candidate leaves an audit trail.
        </p>

        <div class="hero__actions rise rise--3">
          <a class="btn btn--primary btn--onink" href="<?= e(url(LOGIN_URL_PATH)) ?>">
            Sign in
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M5 12h13M13 6l6 6-6 6"></path>
            </svg>
          </a>
          <a class="btn btn--ghost btn--onink" href="#process">See how it works</a>
        </div>

        <p class="hero__meta rise rise--4">
          <span>Timed delivery</span>
          <span>Integrity logging</span>
          <span>Role-based access</span>
        </p>
      </div>

      <!-- Signature: the panel a candidate actually sits in front of.
           Illustrative, so it is hidden from assistive tech rather than
           announcing a new time every second. -->
      <aside class="clock rise rise--5" aria-hidden="true">
        <div class="clock__top">
          <span>Time remaining</span>
          <span class="clock__paper">BIO 214 · Paper 2</span>
        </div>

        <p class="clock__time" id="clockTime">01:47:23</p>

        <div class="clock__bar">
          <span class="clock__fill" id="clockFill"></span>
        </div>

        <div class="clock__foot">
          <span>Question 14 of 40</span>
          <span class="clock__save">
            <span class="clock__dot" id="clockDot"></span>
            Autosaved
          </span>
        </div>
      </aside>

    </div>
  </section>

  <!-- ============================ ROLES ============================ -->
  <section class="bay bay--paper" id="roles">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">Who it is for</p>
        <h2 class="h2">Three roles, three different jobs.</h2>
        <p class="lead">
          Everyone signs in through the same door and lands somewhere built for what
          they actually do — no shared dashboard that half-fits everybody.
        </p>
      </div>

      <div class="roles">

        <article class="role">
          <p class="role__who">Students</p>
          <h3 class="role__title">Sit the paper</h3>
          <p class="role__body">
            One screen holding the question, the timer and nothing else. Answers save
            as you go, so a dropped connection costs you time rather than the attempt.
          </p>
        </article>

        <article class="role">
          <p class="role__who">Lecturers</p>
          <h3 class="role__title">Set and mark</h3>
          <p class="role__body">
            Write multiple-choice and essay questions into a per-course pool, assemble
            them into a paper, then mark what needs a human eye. Objective questions
            score themselves.
          </p>
        </article>

        <article class="role">
          <p class="role__who">Administrators</p>
          <h3 class="role__title">Run the institution</h3>
          <p class="role__body">
            Create accounts, enrol cohorts onto courses, and read integrity signals
            across every exam sitting — including the ones happening right now.
          </p>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ FEATURES ============================ -->
  <section class="bay bay--tint" id="features">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">What it does</p>
        <h2 class="h2">Built around the four things an exam has to get right.</h2>
      </div>

      <div class="features">

        <article class="feature">
          <div class="feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
              <circle cx="12" cy="12" r="9"></circle>
              <path d="M12 6.6V12l3.6 2.1"></path>
            </svg>
          </div>
          <h3 class="feature__title">Timed delivery</h3>
          <p class="feature__body">
            Every paper runs against a clock the server owns, not the browser. The
            deadline is written by the database when the attempt starts, so closing the
            tab or changing the system time does not buy a candidate extra minutes.
          </p>
          <ul class="feature__list">
            <li>Server-authoritative countdown</li>
            <li>Deadline computed by the database at start</li>
            <li>Auto-submit once the clock expires</li>
          </ul>
        </article>

        <article class="feature">
          <div class="feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 3.2 4.6 6.2v5.3c0 4.3 3 8.2 7.4 9.3 4.4-1.1 7.4-5 7.4-9.3V6.2Z"></path>
              <path d="M9.4 12.1h1.7l1 2.4 1.4-4.4 1 2h1.1"></path>
            </svg>
          </div>
          <h3 class="feature__title">Integrity logging</h3>
          <p class="feature__body">
            Meaningful actions during an attempt are timestamped and written to an
            activity log. Cross the threshold and the attempt is flagged for a person
            to look at.
          </p>
          <ul class="feature__list">
            <li>Focus, tab-switch, copy and paste logged</li>
            <li>Automatic flag past the event threshold</li>
            <li>Randomised paper drawn per attempt</li>
            <li>Full timeline retained per attempt</li>
          </ul>
        </article>

        <article class="feature">
          <div class="feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 3.6 3.5 8l8.5 4.4L20.5 8Z"></path>
              <path d="M3.5 12.4 12 16.8l8.5-4.4"></path>
              <path d="M3.5 16.6 12 21l8.5-4.4"></path>
            </svg>
          </div>
          <h3 class="feature__title">Role-based access</h3>
          <p class="feature__body">
            Student, lecturer and administrator are separated at the route, not just
            hidden in the interface. Signing in puts you on your own dashboard and
            nowhere else.
          </p>
          <ul class="feature__list">
            <li>Three roles, guarded server-side</li>
            <li>CSRF protection on every form</li>
            <li>Login throttling with account lockout</li>
          </ul>
        </article>

        <article class="feature">
          <div class="feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 20V4"></path><path d="M4 20h16"></path>
              <path d="M8 20v-5.5"></path><path d="M12.7 20V8.4"></path><path d="M17.4 20v-8.2"></path>
            </svg>
          </div>
          <h3 class="feature__title">Results and analytics</h3>
          <p class="feature__body">
            Scores land the moment marking finishes. Beyond the grade, you can see
            which questions the cohort actually struggled with — and which ones were
            simply badly written.
          </p>
          <ul class="feature__list">
            <li>Per-question difficulty breakdown</li>
            <li>Cohort score distribution</li>
            <li>Course and institution summaries</li>
          </ul>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ PROCESS ============================ -->
  <section class="bay bay--ink" id="process">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">How it works</p>
        <h2 class="h2">One exam, start to finish.</h2>
        <p class="lead">
          Four stages, in order, each one owned by a different role.
        </p>
      </div>

      <ol class="process">

        <li class="step">
          <h3 class="step__title">Enrol the cohort</h3>
          <p class="step__body">
            An administrator creates accounts and enrols students onto the courses
            they are registered for. Enrolment is what decides who can open a paper.
          </p>
        </li>

        <li class="step">
          <h3 class="step__title">Author the paper</h3>
          <p class="step__body">
            A lecturer writes questions into the course pool, assembles them into an
            exam, and sets its duration and availability window.
          </p>
        </li>

        <li class="step">
          <h3 class="step__title">Sit the exam</h3>
          <p class="step__body">
            Students sign in and the paper opens against the server clock. Answers
            save continuously while the activity log records what happens.
          </p>
        </li>

        <li class="step">
          <h3 class="step__title">Mark and publish</h3>
          <p class="step__body">
            Objective answers score on submission. Essays queue for the lecturer, and
            results publish to students once marking is complete.
          </p>
        </li>

      </ol>
    </div>
  </section>

  <!-- ============================ INTEGRITY ============================ -->
  <section class="bay bay--paper" id="integrity">
    <div class="wrap">
      <div class="integrity">

        <div class="integrity__copy">
          <p class="eyebrow">Integrity</p>
          <h2 class="h2">Detection only matters if someone can check it later.</h2>
          <p class="lead">
            The system raises signals; it does not pass judgement. Every attempt carries
            a timestamped record, and one that crosses the flag threshold is surfaced for
            a human decision — with the evidence attached, so that decision can be defended.
          </p>
        </div>

        <div class="ledger">
          <div class="ledger__bar">
            <span>Attempt 4127 · BIO 214</span>
            <span class="ledger__flagged">Flagged for review</span>
          </div>
          <div class="ledger__rows">

            <div class="ledger__row">
              <span class="ledger__at">00:00:04</span>
              <span class="ledger__what">Attempt opened — 40 questions, 120 minutes</span>
              <span class="tag tag--ok">Logged</span>
            </div>

            <div class="ledger__row">
              <span class="ledger__at">00:14:52</span>
              <span class="ledger__what">Answer saved — question 9</span>
              <span class="tag tag--ok">Logged</span>
            </div>

            <div class="ledger__row">
              <span class="ledger__at">00:31:07</span>
              <span class="ledger__what">Window lost focus — 6 seconds</span>
              <span class="tag tag--warn">Event 1</span>
            </div>

            <div class="ledger__row">
              <span class="ledger__at">00:31:41</span>
              <span class="ledger__what">Window lost focus — 11 seconds</span>
              <span class="tag tag--warn">Event 2</span>
            </div>

            <div class="ledger__row">
              <span class="ledger__at">00:32:15</span>
              <span class="ledger__what">Window lost focus — 4 seconds</span>
              <span class="tag tag--flag">Threshold met</span>
            </div>

            <div class="ledger__row">
              <span class="ledger__at">01:58:30</span>
              <span class="ledger__what">Submitted — 2 answers pending manual marking</span>
              <span class="tag tag--ok">Logged</span>
            </div>

          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ============================ CTA ============================ -->
  <section class="bay bay--tint">
    <div class="wrap">
      <div class="cta">
        <p class="eyebrow">Already registered</p>
        <h2 class="cta__title">Sign in and pick up where your role starts.</h2>
        <p class="lead">
          Students, lecturers and administrators all use the same sign-in page. You will
          land on the dashboard for your role.
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

<script>
/* The hero clock. Illustrative only — no data leaves or enters this page. */
(function () {
  var time = document.getElementById('clockTime');
  var fill = document.getElementById('clockFill');
  var dot  = document.getElementById('clockDot');
  if (!time || !fill) { return; }

  var total = 7200;   // a 120-minute paper
  var left  = 6443;   // 01:47:23 remaining

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function paint() {
    var h = Math.floor(left / 3600);
    var m = Math.floor((left % 3600) / 60);
    var s = left % 60;
    time.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
    fill.style.width = ((left / total) * 100).toFixed(2) + '%';
  }

  paint();

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) { return; }

  setInterval(function () {
    if (left <= 0) { return; }
    left -= 1;
    paint();

    // Mirror the autosave heartbeat a real attempt runs on.
    if (left % 15 === 0 && dot) {
      dot.classList.remove('is-saving');
      void dot.offsetWidth;          // restart the animation
      dot.classList.add('is-saving');
    }
  }, 1000);
}());
</script>

<?php require __DIR__ . '/_partials/footer.php'; ?>
