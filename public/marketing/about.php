<?php
require __DIR__ . '/_partials/bootstrap.php';

$page_title       = 'About — ' . APP_NAME;
$page_description = 'Why this examination system is built the way it is: server-owned timing, '
                  . 'signals rather than verdicts, access enforced at the route, and evidence that outlives the attempt.';
$page_key         = 'about';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/nav.php';
?>

<main id="main">

  <!-- ============================ HEADER ============================ -->
  <section class="phead">
    <div class="wrap">
      <div class="phead__inner">
        <p class="eyebrow rise rise--1">About</p>
        <h1 class="phead__title rise rise--2">Built for the questions that come after the exam.</h1>
        <p class="phead__lead rise rise--3">
          Delivering a paper is the easy half. The hard half is being able to explain,
          months later, exactly what happened during one particular attempt — and having
          the record to show for it.
        </p>
      </div>
    </div>
  </section>

  <!-- ============================ WHAT IT IS ============================ -->
  <section class="bay bay--paper">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">The short version</p>
        <h2 class="h2">A timed examination platform that keeps its receipts.</h2>
      </div>

      <div class="prose">
        <p>
          This is a web application for running written examinations inside an institution
          that already knows who its students are. An administrator enrols people onto
          courses, lecturers write and assemble papers, students sit them under a clock,
          and results come back with the working shown.
        </p>
        <p>
          It is <strong>not</strong> a remote-proctoring product. There is no webcam feed,
          no face matching, and no attempt to guess intent from a candidate's behaviour.
          Those systems ask software to make an accusation. This one records what happened,
          marks the moments worth a second look, and leaves the accusation to a human being
          who can be held responsible for making it.
        </p>
        <p>
          The trade is deliberate. A narrower claim is one the institution can actually
          defend at an appeal hearing — and a flagged attempt here comes with a timestamped
          list of what triggered the flag, not a confidence score nobody can interrogate.
        </p>
      </div>

    </div>
  </section>

  <!-- ============================ PRINCIPLES ============================ -->
  <section class="bay bay--tint">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">Why it works this way</p>
        <h2 class="h2">Four decisions everything else follows from.</h2>
      </div>

      <div class="principles">

        <article class="principle">
          <p class="principle__label">Timing</p>
          <div class="principle__text">
            <h3 class="principle__title">The clock belongs to the server.</h3>
            <p class="principle__body">
              A countdown the browser owns is a countdown a candidate can edit. The
              deadline is written by the database the moment an attempt starts, and the
              interface only reports it — which is why closing the tab, reloading, or
              changing the system clock buys nobody a single extra minute. A submission
              that arrives late is still graded rather than discarded; it is simply
              recorded as auto-submitted, because losing someone's work to network lag
              is not the same as enforcing a deadline.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Judgement</p>
          <div class="principle__text">
            <h3 class="principle__title">Signals, not verdicts.</h3>
            <p class="principle__body">
              The system records events and flags an attempt once they pass a threshold.
              It never concludes that someone cheated. Losing window focus three times
              might be a candidate checking their phone, or a laptop throwing a
              notification — the log states what occurred and stops there, because only a
              person with the context can tell those apart.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Access</p>
          <div class="principle__text">
            <h3 class="principle__title">One door, three destinations.</h3>
            <p class="principle__body">
              Everyone signs in at the same page. What changes is where you land and what
              the server will answer — enforced on every request, not hidden in a template.
              A student who types a lecturer's URL is refused by the guard, not merely
              shown a page missing its buttons.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Evidence</p>
          <div class="principle__text">
            <h3 class="principle__title">The record outlives the attempt.</h3>
            <p class="principle__body">
              A grade that cannot be explained six months later is not a defensible grade.
              Attempts keep their answers, their timings and their event history, so a
              query, an appeal, or an audit has something concrete to work from rather
              than a recollection of what the system probably did.
            </p>
          </div>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ BUILT ON ============================ -->
  <section class="bay bay--ink">
    <div class="wrap">
      <div class="split">

        <div class="split__copy">
          <p class="eyebrow">Under it</p>
          <h2 class="h2">Deliberately small.</h2>
          <div class="prose">
            <p>
              The application is plain PHP against MySQL, on a hand-written MVC core —
              a router, a thin controller layer, and models that own their queries. There
              is no framework underneath it.
            </p>
            <p>
              That is a considered choice for something a university has to keep running
              for years. There is no upgrade treadmill, no dependency graph to audit
              before a security patch, and nothing in the request path that a maintainer
              cannot read end to end in an afternoon.
            </p>
          </div>
        </div>

        <div class="spec">
          <div class="spec__row">
            <span class="spec__k">Language</span>
            <span class="spec__v">PHP 8, strict types</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Architecture</span>
            <span class="spec__v">Custom MVC — router, controllers, models</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Database</span>
            <span class="spec__v">MySQL over PDO, prepared statements throughout</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Auth</span>
            <span class="spec__v">Session-based, hashed passwords, role guard per route</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Hardening</span>
            <span class="spec__v">CSRF tokens on every form, login throttling with account lockout</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Dependencies</span>
            <span class="spec__v">None at runtime</span>
          </div>
        </div>

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
