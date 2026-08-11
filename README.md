# Online Examination System

**🔗 [Live demo](https://exams.ayoelebiyo.com)** · Log in with the demo accounts below.

A production-style online examination platform built from scratch in **PHP (PDO) and MySQL** using a **hand-rolled MVC architecture** — no framework. Supports timed exams, randomised question banks, automatic and manual grading, real-time anti-cheat monitoring, and analytics for three distinct user roles.

> Built to demonstrate full-stack fundamentals: secure authentication, relational data modelling, database transactions, server-authoritative logic, and defence-in-depth security — the things frameworks usually hide.

## Features

**Three roles, cleanly separated**
- **Admin** — manages users (create, suspend/reactivate), courses, enrolments, and views system-wide analytics
- **Lecturer** — builds question banks (MCQ + essay), configures and publishes exams, grades essays, reviews flagged attempts, and sees per-question item analysis
- **Student** — sits timed exams with auto-save, and views results

**Exam engine**
- **Randomised draw** — each attempt pulls N questions from a larger pool; MCQ options are shuffled per-attempt
- **Frozen snapshot** — the exact question set, order, and option arrangement are frozen at start, so a refresh (or a mid-exam edit by the lecturer) never changes a student's paper
- **Server-authoritative timing** — the deadline is computed and enforced by the database clock; the JavaScript countdown is display-only and cannot be gamed
- **Auto-save** — every answer is persisted via background `fetch()` requests, so a dropped connection or accidental refresh never loses work
- **Automatic + manual grading** — MCQs grade instantly against frozen correct answers; essays queue for lecturer marking, with atomic score recomputation

**Anti-cheating**
- Tab-switch, window-blur, fullscreen-exit, copy/paste, and heartbeat-gap detection, all logged server-side with timestamps
- Attempts crossing a configurable event threshold are flagged for human review
- Philosophy: **log evidence, don't auto-punish** — a lecturer reviews the full event timeline and decides

**Security**
- Bcrypt password hashing; session-fixation defence on login
- CSRF tokens on every state-changing form
- Prepared statements everywhere (real server-side prepares, no emulation)
- Role-based access control + per-object ownership checks (IDOR-resistant)
- Login throttling with per-account lockout
- Security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy)

## Architecture

Custom MVC with a single front controller:
```
public/index.php → front controller: session, autoloader, routing
app/core/ → Router, Database (PDO singleton), base Controller/Model, Auth, Csrf
app/controllers/ → one per role + Auth
app/models/ → one per entity, extending a base Model
app/views/ → organised by role
app/middleware/ → RoleGuard (RBAC)
config/ → environment config (gitignored)
database/ → schema.sql
```

**Key design decisions**
- **Snapshot-on-start** — the most important write in the system creates the attempt, computes the deadline server-side, draws random questions, and freezes their order in a single database transaction. All-or-nothing.
- **`public/` as the only web-accessible directory** — all application code and config sit above the document root.
- **Defence in depth** — every rule is enforced at both the application layer (friendly errors) and the schema layer (correctness guarantees): unique constraints, foreign keys, composite keys.

## Stack

PHP 8 · MySQL · Vanilla JavaScript · Custom MVC · No framework, no build step

## Setup

1. Clone into your web server's document root
2. Create the database and import `database/schema.sql`
3. Copy `config/config.example.php` to `config/config.php` and set your DB credentials
4. Point your browser at the `public/` directory

### Demo accounts

| Role     | Email               | Password      |
|----------|---------------------|---------------|
| Admin    | [REDACTED]    | [REDACTED]    |
| Lecturer | [REDACTED]    | [REDACTED] |
| Student  | [REDACTED]    | [REDACTED]  |

## Possible future work

- Content-Security-Policy headers (currently uses inline styles/scripts)
- Question categories and difficulty weighting
- CSV export of results
- Configurable per-question negative marking

---

Built by [Ayodele Elebiyo](https://github.com/Ayo-builds) as a study in building production-grade systems from first principles.