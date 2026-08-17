<?php
require __DIR__ . '/_partials/bootstrap.php';

/*
 * The address this deployment publishes for enquiries.
 * Referenced once here so there is a single place to change it.
 */
const CONTACT_EMAIL = 'examsystemsupport@gmail.com';

$page_title       = 'Contact — ' . APP_NAME;
$page_description = 'Who to contact about exam access, marks and accounts — and the things '
                  . 'you can resolve without contacting anyone.';
$page_key         = 'contact';

require __DIR__ . '/_partials/head.php';
require __DIR__ . '/_partials/nav.php';
?>

<main id="main">

  <!-- ============================ HEADER ============================ -->
  <section class="phead">
    <div class="wrap">
      <div class="phead__inner">
        <p class="eyebrow rise rise--1">Contact</p>
        <h1 class="phead__title rise rise--2">Start with the person who can actually fix it.</h1>
        <p class="phead__lead rise rise--3">
          Most exam problems are held by someone at your own institution, not by whoever
          maintains the software. Sending them to the wrong place costs a day you may not
          have. Here is who holds what.
        </p>
      </div>
    </div>
  </section>

  <!-- ============================ ROUTING ============================ -->
  <section class="bay bay--paper">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">Who holds what</p>
        <h2 class="h2">Three doors, and they are not interchangeable.</h2>
      </div>

      <div class="roles">

        <article class="role">
          <p class="role__who">If you are a student</p>
          <h3 class="role__title">Ask your lecturer first</h3>
          <p class="role__body">
            Anything about a paper — a missing exam, a mark you want to query, an essay
            still unmarked — sits with the lecturer who owns the course. They can see the
            exam's window, whether it is published, and your attempt's record. For
            problems signing in or an account that will not open, your institution's
            administrator is the one with the controls.
          </p>
        </article>

        <article class="role">
          <p class="role__who">If you are a lecturer</p>
          <h3 class="role__title">Ask your administrator</h3>
          <p class="role__body">
            Accounts, course records and enrolment are administrator territory. If a
            student is missing from a course, or a colleague needs an account, that is the
            route. Authoring, assembling and marking papers you can do yourself, and a
            student who is not enrolled will not see your exam no matter how it is
            configured.
          </p>
        </article>

        <article class="role">
          <p class="role__who">If you are an administrator</p>
          <h3 class="role__title">Come to us</h3>
          <p class="role__body">
            Anything that is not a matter of configuration — behaviour that looks wrong,
            a question about how something is enforced, or an institution evaluating the
            platform — comes to whoever maintains this deployment. Details are below.
          </p>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ SELF-SERVICE ============================ -->
  <section class="bay bay--tint">
    <div class="wrap">

      <div class="bay__head">
        <p class="eyebrow">Before you write to anyone</p>
        <h2 class="h2">Five things with answers already.</h2>
        <p class="lead">
          These come up constantly, and four of them do not need a human at all.
        </p>
      </div>

      <div class="principles">

        <article class="principle">
          <p class="principle__label">Lockout</p>
          <div class="principle__text">
            <h3 class="principle__title">You have been locked out after failed sign-ins.</h3>
            <p class="principle__body">
              Five failed attempts on the same account trigger a fifteen-minute lockout,
              and the sign-in page will tell you how long is left. It clears itself — nobody
              needs to unlock anything, and writing to an administrator will not make it
              expire sooner.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Missing exam</p>
          <div class="principle__text">
            <h3 class="principle__title">An exam is not showing on your dashboard.</h3>
            <p class="principle__body">
              There are three reasons, and only three. The exam has not been published yet;
              the current time is outside its availability window; or you are not enrolled
              on the course it belongs to. Your lecturer can tell you which of the three it
              is in a moment — the first two resolve themselves, the third does not.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Mid-exam</p>
          <div class="principle__text">
            <h3 class="principle__title">Something went wrong during an attempt.</h3>
            <p class="principle__body">
              Do not start by writing an email — go back to the exam. Answers are saved as
              you enter them, so a dropped connection or a closed browser leaves your work
              intact and the attempt still open. The clock will have kept running. If the
              deadline passed while you were away, the attempt closes and grades on whatever
              was answered.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Marks</p>
          <div class="principle__text">
            <h3 class="principle__title">Your result is showing but looks incomplete.</h3>
            <p class="principle__body">
              Multiple-choice answers score immediately; essay answers wait for your
              lecturer to mark them by hand. A score that seems low straight after
              submitting usually means the written answers have not been marked yet. It
              will change when they are.
            </p>
          </div>
        </article>

        <article class="principle">
          <p class="principle__label">Passwords</p>
          <div class="principle__text">
            <h3 class="principle__title">You have forgotten your password.</h3>
            <p class="principle__body">
              This one does need a person. There is no self-service password reset in the
              platform, so a forgotten password has to be handled by your institution's
              administrator directly. The same applies to an account that has been
              deactivated — only an administrator can turn it back on.
            </p>
          </div>
        </article>

      </div>
    </div>
  </section>

  <!-- ============================ ENQUIRIES ============================ -->
  <section class="bay bay--ink">
    <div class="wrap">
      <div class="split">

        <div class="split__copy">
          <p class="eyebrow">Everything else</p>
          <h2 class="h2">For the questions no administrator can answer.</h2>
          <div class="prose">
            <p>
              Behaviour that looks wrong, a question about how something is actually
              enforced, or an institution weighing the platform up — write to whoever
              maintains this deployment.
            </p>
          </div>
          <a class="addr" href="mailto:<?= e(CONTACT_EMAIL) ?>"><?= e(CONTACT_EMAIL) ?></a>
        </div>

        <div class="spec">
          <div class="spec__row">
            <span class="spec__k">Include</span>
            <span class="spec__v">Your role, and the institution you are writing from</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">If an exam</span>
            <span class="spec__v">The course code and the exam's title</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">If an attempt</span>
            <span class="spec__v">Roughly when it was sat, and what you saw</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Never send</span>
            <span class="spec__v">Your password — nobody here will ever ask for it</span>
          </div>
          <div class="spec__row">
            <span class="spec__k">Not here</span>
            <span class="spec__v">Password resets and enrolment — your administrator holds those</span>
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
        <h2 class="cta__title">If you can sign in, start there.</h2>
        <p class="lead">
          Your dashboard shows your courses, your exams and their windows — which answers
          most questions faster than asking will.
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
