# Anti-cheat replacement plan

## Provenance

Recovered on 2026-09-16 from the Claude Code session transcript
`C:\Users\HP\.claude\projects\c--xampp-htdocs-exam-system\baaa5698-64da-4c4f-9c6e-454ba5c2571a.jsonl`.
The plan had never been written to a file; it existed only in that conversation.

Everything in Parts 2 to 4 is copied from that transcript word for word. Nothing
has been paraphrased, tidied or filled in. Where a stage's detail was never
written down, the entry says **NOT FOUND IN TRANSCRIPT** rather than guessing.
Anything not in the transcript at all is quarantined in Part 5, "Added from
review".

File links are repo-root-relative, as they were typed in the transcript. They do
not resolve from this file's directory; read them as paths, not as links.

Source messages are cited as `#n`, the record index in the transcript.

## Status at 2026-09-22

| # | Stage | Status | Commit |
|---|---|---|---|
| 1 | Clipboard and selection guard | **Done** | `4a5f425` |
| 2 | Paper over JSON and the no-JavaScript shell | **Done** | `bb34ea6` |
| — | Hand-check dev script (support, not a numbered stage) | **Done** | `d5b5a37` |
| 3 | Migration 007 | **Done**, applied to local `exam_system` | `21f2d03` |
| 4 | Server lock core | **Done**, hand checks completed 2026-09-21 | `cbdb96e` |
| — | Error handling: an exception handler in the front controller, plus `register_shutdown_function` (before 4b) | **Done**, hand checks completed 2026-09-22 | `c92cf9c` |
| 4b | Every deadline decision on the database clock | **Built and tested**, hand checks pending | see Part 4 |
| 5 | Client lock monitor and overlay | **Not started** | — |
| 6 | Invigilator screen, read only | **Not started** | — |
| 7 | Claim, code, unlock at the seat | **Not started** | — |
| 8 | Seat binding and `new_session` | **Not started** | — |
| 9 | Bulk unlock | **Not started** | — |
| 10 | Paste logging and teacher history | **Not started** | — |
| 11 | Words and docs | **Not started** | — |

Every stage 4 hand check has passed. The results are under stage 4 in Part 4.
The error handling (the error handler from the 16 Sep 1467 brief) is done:
built, tested, and hand-checked on 22 Sep. Its section in Part 4 comes straight
after stage 4. Step 4b is built and tested; its hand checks are next.

Production's commit: unknown, verify on the server before any deploy
(`git log --oneline -1` on the server). Production must not be touched. `main`
is pushed to GitHub, but pushing is not deploying: nothing is deployed until
stage 5 is done and the deployment checklist is followed.

---

# Part 1: the original requirement

From `#29`, the brief that opened the work:

> I'm replacing the current anti-cheat approach. What I want:
>
> A. During an exam: no right-click, no copy, no paste, no text selection on the
>    question area.
> B. Fullscreen is mandatory. The exam does not start until the browser is in
>    fullscreen.
> C. Leaving the exam page LOCKS the attempt. It does not submit it. Leaving
>    includes: losing window focus, switching tab, and exiting fullscreen.
> D. A locked attempt shows a full-screen overlay: exam paused, raise your hand,
>    timer still running. No questions or answers visible.
> E. Only an invigilator can unlock, by typing a code. Every time, no
>    self-dismiss.
> F. The lock lives on the attempt in the database, not in the browser. A
>    refresh, a new tab or a different PC must still be locked. Say how you'd
>    enforce this on every request that serves the exam page and on the save
>    endpoint.
> G. The timer keeps running while locked.
> H. Every lock and unlock is logged: what triggered it, when, which invigilator
>    unlocked it.

A first revision of the plan was written at `#93`. It was superseded by revision
2 below, which is the design of record.

---

# Part 2: the design (revision 2, `#121`)

> # Anti-cheat replacement plan, revision 2
>
> I made no code changes, ran no schema changes and made no commits. Your eleven answers and changes A and B are built in below. Four of your decisions clash with code that already exists; each clash is covered in its section and listed again as a question at the end.
>
> ---
>
> ## 1. What the student sees
>
> `GET /student/exam/{id}` now returns an empty shell with **no question or option text in it**. The shell moves through these states:
>
> | State | On screen | Monitor | Questions in the page |
> |---|---|---|---|
> | **JavaScript off** | Only a `<noscript>` message: "This exam requires JavaScript." Everything else is `hidden` until JavaScript reveals it. | None | Never |
> | **Gate** | Subject code, exam title, timer, and an "Enter fullscreen" button | Off | No |
> | **Armed** | Fullscreen was granted → monitor starts → heartbeat starts → `POST /student/paper/{id}` → questions drawn with `textContent` | On | Yes |
> | **Locked** | Full-screen overlay: "Exam paused. Raise your hand. Your time is still running." Timer and an unlock code box. The question DOM is **removed**, not hidden. | Triggers ignored; heartbeat every 5 s | No |
> | **Unlocked** | Back to the gate. Fullscreen needs a click, so the paper is fetched again. | Off → on | After the gate |
> | **Opened elsewhere** | "This exam is open on another computer." Nothing else. | Stopped | No |
>
> - Unanswered work survives the DOM being removed, because the save queue keeps the latest value for each question ([save-queue.js:40-44](public/assets/js/save-queue.js#L40-L44)). When the paper is drawn again, any value the queue still holds as unsaved wins over what the server sent. That needs a small `valueOf(qid)` accessor on the queue.
> - The `<title>` changes to a generic "Exam in progress". It currently includes the subject and title ([exam.php:6](app/views/student/exam.php#L6)), which is harmless but conflicts with "nothing else".
> - The in-exam Back link ([exam.php:49](app/views/student/exam.php#L49)) is removed.
>
> **Rendering in JavaScript is an XSS boundary.** Today the server escapes with `htmlspecialchars` plus `nl2br` ([exam.php:75](app/views/student/exam.php#L75)). The new page must use only `textContent`, with `white-space: pre-line` for line breaks, and never `innerHTML`.
>
> **What the server can and can't enforce.** The server can't check whether the browser is in fullscreen. "Questions only after the gate" is enforced by the client. The server does enforce four things:
> - the paper endpoint is **POST with CSRF**, so a no-JavaScript visit from the address bar can't read it;
> - the lock;
> - the bound seat;
> - the deadline.
>
> Fetch Metadata headers (`Sec-Fetch-*`) aren't sent over plain HTTP, so they can't be used. A student with DevTools can still call the endpoint while unlocked. Without browser policies, nothing prevents that.
>
> ---
>
> ## 2. Server enforcement, endpoint by endpoint
>
> Every check below is a guard in `StudentController`. The seat check comes first, then lock and deadline.
>
> | Endpoint | Not bound to this seat | Locked | Deadline passed |
> |---|---|---|---|
> | `GET exam/{id}` ([StudentController.php:113-142](app/controllers/StudentController.php#L113-L142)) | This is an **opening**: in one transaction, rebind to this seat, lock with `new_session`, render the shell locked | Shell, locked state | `autoSubmit` (existing, line 132); runs **before** the lock check |
> | `POST paper/{id}` (new) | Opening: rebind, lock, `423` | `423 {error:'locked'}`, no grace | `autoSubmit`, `409 closed` |
> | `POST saveAnswer/{id}` ([145-192](app/controllers/StudentController.php#L145-L192)) | `409 {error:'superseded'}`, **no** rebind | Accept if `locked_at >= NOW() - INTERVAL 5 SECOND`, otherwise `423 locked` | `409 closed` (existing) |
> | `POST submitExam/{id}` ([265-301](app/controllers/StudentController.php#L265-L301)) | Refuse, redirect to exam | **Refuse**, redirect to exam | Existing |
> | `POST lock/{id}` (new) | `409 superseded` | Idempotent `200`; the extra trigger is added to the open lock's `detail` | `409 closed` |
> | `POST heartbeat/{id}` (new) | `409 superseded` | `200 {locked:true, remaining}` | `409 closed` |
> | `POST event/{id}` (new: blips, pastes) | `409 superseded` | Accepted | `409 closed` |
> | `POST unlock/{id}` (new) | `409 superseded` | Checks the code (§4) | `409 closed`; **never** unlocks a closed attempt |
> | `logActivity` ([212-262](app/controllers/StudentController.php#L212-L262)) | **Removed**; the route returns 404, so nothing writes `activity_logs` again | | |
>
> - `attempt()` and `startExam()` only redirect to `exam()` ([44-46](app/controllers/StudentController.php#L44-L46), [100](app/controllers/StudentController.php#L100)), so they are covered.
> - `startExam` binds the seat when it creates the attempt ([Attempt.php:33-37](app/models/Attempt.php#L33-L37)).
> - **The 5 s grace and the lock race.** `saveAnswer` reads `locked_at`, the deadline and the seat with `SELECT … FOR UPDATE`, compares against MySQL's `NOW()`, and writes in the same transaction. That closes the gap between the check (line 166) and the write (line 189), and it uses the same clock that wrote `locked_at`. On the client, `queue.flush()` runs **before** the lock request is sent.
> - **Error names the save queue must handle.** It only knows `closed`, `csrf` and `422/404/405` ([save-queue.js:140-166](public/assets/js/save-queue.js#L140-L166)). Anything else is retried forever.
>   - `423 locked`: mark UNSAVED, stop retrying, call `onLocked`, and flush after unlock.
>   - `409 superseded`: mark UNSAVED, stop, call `onSuperseded`.
>   - Neither may ever reach `onClosed`, which submits the form ([exam.php:241](app/views/student/exam.php#L241)).
> - **Locked students can't submit.** Two places in the client currently submit the form: the timer at zero ([exam.php:194](app/views/student/exam.php#L194)) and `onClosed`. While locked, both instead go to `GET exam/{id}`, which calls `autoSubmit`. A PC that has been switched off is closed by the sweep.
> - All new JSON and the shell send `Cache-Control: no-store`, so Back/Forward can't restore a page that had the paper drawn.
>
> ### Clash 1: `new_session` and the existing re-login flow
>
> Your rule is: a different *session* opening the attempt locks it. But:
> - a sign-in always issues a new session id ([Auth.php:53](app/core/Auth.php#L53));
> - the session cookie only lasts as long as the browser window is open ([index.php](public/index.php) comment, lifetime 0);
> - the existing Retry path ([exam.php:277-302](app/views/student/exam.php#L277-L302), [StudentController.php:195-210](app/controllers/StudentController.php#L195-L210)) exists for a student who signs in again in the same browser.
>
> Binding to the PHP session means a browser crash, or a session that expires followed by sign-in on the **same PC**, locks the attempt. The Retry flow then sends saves from a session that isn't bound and gets `superseded`.
>
> **My recommendation:** bind to a random **seat cookie** (httponly, set at `startExam`, lasting a day), stored hashed in the database. A different PC or browser still locks; signing in again in the same browser doesn't. Question 1 at the end.
>
> **Rebinding happens only on an opening** (shell or paper). A leftover tab on the old PC that is still sending heartbeats gets `superseded` and can't take the attempt back. Without that rule, two PCs would keep locking each other.
>
> ---
>
> ## 3. Lock monitor (client)
>
> Most of this goes in `public/assets/js/lock-monitor.js`, with events, clock and transport passed in, like save-queue.js.
>
> - **Arms** when `requestFullscreen()` resolves with `document.fullscreenElement` set. It disarms when locked, at the gate, or when submitting.
> - **`blur`** starts a 3 s timer.
>   - If `focus` comes back first: cancel the timer and send `POST event` with a `blur_blip` and `{ms}`.
>   - If the timer expires: lock with `window_blur`, `{ms: 3000+}`.
>   - If a hidden or fullscreen-exit event arrives during the grace period: lock immediately with that trigger, noting the blur that came first.
> - **`visibilitychange` → hidden** and **fullscreen exit** lock immediately.
>   - The lock request uses `fetch` with `keepalive`.
>   - The overlay goes up and the DOM is removed **before** the request resolves.
>   - A failed request is retried until the server confirms.
> - **Suppression:** only a real submit (the "Submit all and finish" button) sets `submitting = true`.
> - **Heartbeat** every 15 s (5 s while locked). The server updates `last_seen_at`. If more than 45 s have passed since the last heartbeat, it logs a `heartbeat_gap` event with the gap in seconds. It **never locks**. The response carries `{locked, remaining}`, which is how a student's page learns about a bulk unlock or a `new_session` lock.
> - The old monitor at [exam.php:402-439](app/views/student/exam.php#L402-L439) is deleted.
>
> ### Clash 2: reload
>
> "A page load alone doesn't lock" is honoured: a **cold** load (after a power cut, or reopening the browser) starts at the gate with no lock. But pressing **F5, Ctrl+W or closing the window on an armed page unloads it**, and unloading fires `visibilitychange` → hidden, which your rule 4 says locks immediately.
>
> As planned, **leaving an armed page locks, and arriving at a page doesn't**. If F5 shouldn't lock either, a student can press F5 and sit at the gate unmonitored with the questions still in their head. Question 2.
>
> ---
>
> ## 4. Invigilator screen, claim, code, bulk unlock
>
> - **`InvigilateController`**, protected by `RoleGuard::require(['lecturer','admin'])`. It runs `sweepAbandoned()` in its constructor, like [LecturerController.php:12](app/controllers/LecturerController.php#L12); update the list of callers at [Attempt.php:184-185](app/models/Attempt.php#L184-L185).
> - Nav entry **"Invigilation"** added for both roles in [topbar.php:21-35](app/views/_partials/topbar.php#L21-L35).
> - **The list** shows every `in_progress` attempt across all exams, polled as JSON every 3 s:
>   - student, using [student_identity.php](app/views/_partials/student_identity.php);
>   - Subject code and exam title;
>   - state (Working / **Paused**), trigger in plain words, paused since, last seen, time remaining;
>   - claimed by whom.
>   - Locked rows sort to the top.
> - **Claim:** `POST invigilate/claim/{lockId}`.
>   - Creates a 6-digit `random_int` code and stores `password_hash(code)`, `claimed_by`, `claimed_at` and `failed_tries = 0`.
>   - The plain code goes **only** into the claiming invigilator's own PHP session and onto their screen.
>   - Other staff see "Claimed by Mrs X at 10:32", never the code.
>   - Claiming again (by anyone) replaces the hash, so the old code stops working, and the reclaim is logged.
> - **At the seat:** the invigilator types the code into the overlay → `POST student/unlock/{id}` → `password_verify`.
>   - On success, one transaction clears `locked_at` and sets `unlocked_at`, `unlocked_by = claimed_by`, `unlock_method = 'code'`, and `code_hash = NULL`.
>   - Each failure is logged. The **5th failure** clears the claim, and the invigilator screen shows "5 wrong codes, claim again".
> - **Limit to know about:** `unlocked_by` records the invigilator who **claimed** the lock. If they read the code out to a colleague, the log still names them.
> - **Bulk unlock:** only on the invigilator screen. Rows are picked with checkboxes; there is no "unlock everything" button (see clash 3). A reason is required (not blank, at most 500 characters). It creates one `bulk_unlocks` row, and each lock gets `unlock_method = 'bulk'` and `bulk_unlock_id`. The server only unlocks rows that are still locked and `in_progress`, and reports the count.
>
> ### Clash 3: bulk unlock without sittings
>
> There are no sittings, so "all locked" means every locked student in the school, including another hall's exam. My plan is explicit selection, with a filter by exam to make a hall's rows quick to pick. Question 3.
>
> ---
>
> ## 5. Copy, cut, paste and selection (answer 9)
>
> Handlers go on `document` in the capture phase, and apply everywhere on the page, including essays.
> - `copy`, `cut`, `paste`, `contextmenu`, `dragstart` and `drop` all call `preventDefault`. `dragover` sets `dropEffect = 'none'`.
> - `beforeinput` calls `preventDefault` for `insertFromPaste`, `insertFromPasteAsQuotation`, `insertFromDrop`, `insertFromYank`, `deleteByCut` and `insertReplacementText`.
> - Question and option text gets `user-select: none`. Selecting text **inside the essay box** still works, because editing needs it; copying and cutting it is blocked.
> - Essay boxes get `spellcheck="false" autocomplete="off" autocorrect="off"`.
> - Ctrl+P is blocked on keydown, and `@media print` hides the paper. The existing print rule at [style.css:1302-1306](public/assets/css/style.css#L1302-L1306) only hides `#fs-btn`.
> - **Logging a paste that lands:** an `input` event whose `inputType` is `insertFromPaste`, `insertFromDrop` or `insertFromYank` means the block failed. Send `paste_landed {qid, inputType, chars}`, where `chars` is the change in length, and **never the text**. Also log `bulk_insert {qid, inputType, chars}` for any single `input` event that adds 30 or more characters under another `inputType` (IME, voice typing, Win+V). The text stays in the box and is only logged.
>
> **Paste routes on Windows:**
>
> | Route | Can it be intercepted? | Why |
> |---|---|---|
> | Ctrl+V, Shift+Insert, Ctrl+Shift+V | **Yes** | `paste` and `beforeinput` are both cancellable |
> | Right-click → Paste | **Yes** | The menu never opens (`contextmenu` is cancelled) |
> | Shift+F10 / Menu key → Paste | **Yes** | Also fires `contextmenu` |
> | Middle-click | **Not a route on Windows** | Windows has no middle-click paste; Chrome and Edge use it for autoscroll. Mouse software that maps it to Ctrl+V becomes the Ctrl+V row. |
> | Drag and drop (within the page) | **Yes** | `dragstart` is blocked at the source |
> | Drag and drop (from another app) | **Yes, probably** | `drop` and `beforeinput insertFromDrop`. The other app has to be clicked first, which starts the blur grace. |
> | Browser ⋮ menu → Paste | **Likely yes** | The menu can't be reached in fullscreen, and leaving fullscreen locks. If it is reached, it should fire `paste`. |
> | Win+V clipboard history | **Likely yes** | The panel takes focus (a blip), then Windows sends a paste that should fire `paste` |
> | Win+. emoji panel, Win+H voice typing, on-screen keyboard, IME | **No** | Arrives as ordinary text input. Only the `bulk_insert` size check sees it. |
> | AutoHotkey / SendInput "typing out" the clipboard | **No** | One real-looking keystroke at a time; invisible |
> | DevTools setting `textarea.value` | **No** | No input event at all; the server can't tell either |
>
> Every row goes into the browser test list (§8).
>
> ---
>
> ## 6. Schema: migration 007
>
> This targets MariaDB 10.4.32. It goes into [schema.sql](database/schema.sql), [schema_import.sql](database/schema_import.sql) and `database/migrations/007_attempt_locks.sql`. It is **additive only**:
> - no drops;
> - `is_flagged` and `activity_logs` stay readable but nothing writes them;
> - `FLAG_THRESHOLD` stays in the config examples, marked unused, so no existing `config.php` breaks.
>
> **`exam_attempts`, new columns:**
> - `locked_at DATETIME NULL`: NULL means not locked. `findOwned` returns it through `a.*` ([Attempt.php:93](app/models/Attempt.php#L93)).
> - `seat_hash CHAR(64) NULL`: SHA-256 of the seat cookie (or of the session id, depending on question 1). The raw value is never stored.
> - `last_seen_at DATETIME NULL`
>
> **New table `attempt_locks`:**
> - `id BIGINT AUTO_INCREMENT PK`
> - `attempt_id INT NOT NULL`, FK → `exam_attempts`, `ON DELETE CASCADE`
> - `trigger_type ENUM('window_blur','tab_hidden','fullscreen_exit','new_session') NOT NULL`. Not `trigger`, which is a reserved word.
> - `detail JSON NULL`: blur ms, user agent, later triggers seen while locked
> - `locked_at DATETIME NOT NULL`
> - `claimed_by INT NULL`, FK users; `claimed_at DATETIME NULL`; `code_hash VARCHAR(255) NULL`; `failed_tries TINYINT NOT NULL DEFAULT 0`
> - `unlocked_at DATETIME NULL`, `unlocked_by INT NULL` (FK users), `unlock_method ENUM('code','bulk') NULL`, `bulk_unlock_id BIGINT NULL` (FK)
> - Indexes: `(attempt_id, unlocked_at)`, `(unlocked_at)`
>
> **New table `bulk_unlocks`:**
> - `id BIGINT PK`, `unlocked_by INT NOT NULL` (FK users), `reason VARCHAR(500) NOT NULL`, `lock_count INT NOT NULL`, `created_at DATETIME NOT NULL`
>
> **New table `attempt_events`** (everything that isn't a lock):
> - `id BIGINT PK`, `attempt_id INT NOT NULL` (FK cascade), `lock_id BIGINT NULL`
> - `event_type ENUM('blur_blip','heartbeat_gap','paste_landed','bulk_insert','claim','reclaim','code_failed','code_exhausted','superseded') NOT NULL`
> - `actor_id INT NULL` (FK users; staff actions only), `detail JSON NULL`, `created_at DATETIME NOT NULL`
> - Index `(attempt_id, created_at)`
>
> **Decisions in this schema:**
> - **Staff foreign keys have no `ON DELETE` action (RESTRICT).** A teacher who unlocked someone can't be deleted out from under the log. Staff are suspended, not deleted ([Auth.php:44](app/core/Auth.php#L44)).
> - **No foreign key from `exam_attempts` to the current lock.** It would create a cycle. The one-open-lock rule is enforced by the conditional `UPDATE … WHERE locked_at IS NULL` running in the same transaction as the INSERT. Tests check: `locked_at IS NOT NULL` ⇔ exactly one lock row with `unlocked_at IS NULL`.
> - **`DATETIME` written by `NOW()`,** matching `deadline_at`. `activity_logs` uses `TIMESTAMP`; don't copy that.
> - **Blocked copy and paste attempts aren't logged.** You asked for landed pastes; I haven't added more. Question 4.
>
> **Before it runs:** a dump of the working database, and your confirmation. The test database gets it through `test_reset_database()` ([tests/bootstrap.php](tests/bootstrap.php)).
>
> ---
>
> ## 7. Sweep, `closed_by_system_at` and labels
>
> - **The sweep stays unchanged** ([Attempt.php:191-211](app/models/Attempt.php#L191-L211)). Don't add `locked_at IS NULL`. A locked attempt past deadline plus 5 minutes gets closed (G).
> - **`autoSubmit` stays unchanged** ([Attempt.php:166-176](app/models/Attempt.php#L166-L176)). It leaves `locked_at` set and the lock row open. Unlock uses `WHERE status = 'in_progress' AND locked_at IS NOT NULL`, so InnoDB puts unlock and close in some order and the result is always consistent.
> - **The label rule:** `closed_by_system_at IS NOT NULL AND locked_at IS NOT NULL` → **"Closed while paused"**; with `locked_at` NULL → "Not submitted" as today. It goes in three places:
>   - [grading.php:66-70](app/views/lecturer/grading.php#L66-L70): tag **"Closed while paused"**.
>   - [grade_attempt.php:45-55](app/views/lecturer/grade_attempt.php#L45-L55): alert variant. "The candidate's exam was paused at {locked_at} ({trigger}) and was not unlocked before the deadline. Only answers saved before the pause, plus up to 5 seconds after it, are shown." Link to the lock history.
>   - [result.php:74-76](app/views/student/result.php#L74-L76): "Closed while paused. Time ran out while your exam was paused. Answers saved before the pause were counted."
> - **Teacher history:** [activity.php](app/views/lecturer/activity.php) gets a "Pauses" section (trigger, when, claimed by, unlocked by and how, bulk reason) and an events timeline. The old flag sections stay for past attempts. [grading.php:78-80](app/views/lecturer/grading.php#L78-L80) links to it whenever an attempt has any lock or event, not only when flagged. The Flagged column shows only if some row is flagged.
> - **Admin analytics:** [Analytics.php:27-50](app/models/Analytics.php#L27-L50) gets lock counts by trigger, bulk unlocks, and papers closed while paused. The old counts stay, labelled as the previous system. [Exam.php:86](app/models/Exam.php#L86) is handled the same way.
> - **Marketing copy becomes false:**
>   - [index.php:176-181](public/marketing/index.php#L176-L181) and [292-331](public/marketing/index.php#L292-L331);
>   - [how-it-works.php:182](public/marketing/how-it-works.php#L182) and [270-277](public/marketing/how-it-works.php#L270-L277) ("Nothing happens to the student automatically");
>   - [about.php:55-56](public/marketing/about.php#L55-L56) and [95](public/marketing/about.php#L95);
>   - [README.md:24](README.md#L24).
> - **Change B, install checklist:** a new client-machine subsection under [OFFLINE-DEPLOYMENT.md §9](OFFLINE-DEPLOYMENT.md#L293):
>   - sleep set to Never while plugged in;
>   - screensaver off;
>   - screen lock timeout off;
>   - how to confirm each.
>   - It also notes, without requiring anything, that notifications cause blips and pauses.
>   - Update the verification pass in [§10.3](OFFLINE-DEPLOYMENT.md#L354) to include the fullscreen gate and one test pause and unlock.
>
> ---
>
> ## 8. Test plan
>
> **PHP over HTTP.** A new `tests/lock_test.php` on port **8097** (8098 and 8099 are taken; update the port note in [router.php](tests/router.php)). It uses the lecturer, admin and student cookie jars, like sweep_test.php, and sets deadlines and `locked_at` with MySQL's clock.
>
> 1. **No-JavaScript shell (change A):**
>    - A plain `GET exam/{id}` contains the noscript message and **none** of the paper's question or option text, checked item by item.
>    - **Positive control first:** `POST paper/{id}` with CSRF returns every one of those strings. Without that, the "absent" check proves nothing.
>    - `GET paper/{id}` gets `405` with no question text in the body.
> 2. **Paper endpoint:** 404 for someone else's attempt, 403 on bad CSRF, `409 closed` past deadline (and the attempt is closed), `423` while locked, `409 superseded` from another seat.
> 3. **Lock:**
>    - One POST gives one lock row and `locked_at` set.
>    - A second trigger still gives one row, with `detail` updated.
>    - A bad trigger gives 422; a closed attempt gives 409.
>    - After every step, check the invariant from §6.
> 4. **Saves:** at `locked_at` + 2 s, 200 and the answer is written. At + 6 s, `423`, `error === 'locked'`, and `attempt_answers` unchanged row for row. The error must never be `closed`.
> 5. **Submit while locked:** refused, still `in_progress`, no grading ran.
> 6. **Timer:** a lock and an unlock leave `deadline_at` unchanged; the remaining time from the heartbeat is the deadline minus now.
> 7. **Seat:**
>    - Jar B opening the shell locks with `new_session` and rebinds to B.
>    - Jar A's save or heartbeat afterwards gets `409 superseded` and does **not** rebind or add a lock.
>    - Re-login in the same browser follows your answer to question 1.
> 8. **Invigilator screen:**
>    - A lecturer and an admin get 200 and see attempts from exams they don't teach; a student gets 403.
>    - Claiming stores a hash only.
>    - The code is absent from **every** student response and from a **second lecturer's** list JSON.
>    - A reclaim kills the old code.
> 9. **Unlock:**
>    - A wrong code is logged and stays locked.
>    - The 5th wrong code clears the claim.
>    - The right code unlocks with `unlocked_by` = the claimer and `method = 'code'`.
>    - Reusing the code is refused.
>    - Unlocking a closed attempt is refused.
> 10. **Bulk:**
>     - A blank reason is refused.
>     - Selected locks are unlocked with `bulk` and one `bulk_unlocks` row.
>     - Unselected and closed attempts are untouched.
>     - A student calling the endpoint gets 403.
> 11. **Sweep and labels:**
>     - Locked and past deadline plus grace: `auto_submitted`, `closed_by_system_at` set, `locked_at` kept.
>     - Grading, grade-attempt and result pages say "Closed while paused".
>     - Unlocked and expired still says "Not submitted".
>     - A locked attempt inside the grace period is untouched.
> 12. **Race:** a lock and saves fired together with curl_multi, many rounds. No save lands later than 5 s after `locked_at`. Unlock racing the sweep leaves a consistent row.
> 13. **Heartbeat:** a 60 s gap (set `last_seen_at` back) logs `heartbeat_gap {s}` and does **not** lock.
> 14. **Old path:** `logActivity` is 404, and `activity_logs` has no new rows after a full run.
> 15. No diagnostics raised. The existing autosave, sweep, enrollments and node suites still pass.
>
> **Node (`node --test`):**
> - **lock-monitor:**
>   - A 2.9 s blur logs a blip and doesn't lock; a 3.0 s blur locks once.
>   - Blur then hidden gives one lock, `tab_hidden`.
>   - Nothing locks before the gate.
>   - `submitting` suppresses the lock.
>   - A failed lock request keeps the overlay and retries.
>   - A heartbeat reporting `locked:true` puts the overlay up.
> - **save-queue:**
>   - `423 locked` → no `onClosed`, no retry timer, flush after resume.
>   - `409 superseded` → `onSuperseded`, no retry.
>   - `valueOf` returns the latest unsaved payload.
> - **clipboard guard:** each blocked `inputType` is cancelled, and a landed paste reports the right `chars`.
> - **render:** a question text of `<img src=x onerror=…>` comes out as literal text.
>
> **Browser only.** A manual checklist on real Windows with Chrome and Edge, over the LAN IP (not localhost), with notifications on.
> - **Every row of the paste table in §5.**
> - Esc, F11, Alt-Tab, Ctrl+Tab, Ctrl+T/N/U, Windows key, Win+D/V/L/H/.
> - F12 docked and undocked, a toast shown and a toast clicked, a UAC prompt, a second-monitor click.
> - F5, Ctrl+W and closing the window on an armed page; a cold reopen.
> - Back/Forward after a lock: no questions.
> - Pull the network cable while locked, then plug it back in.
> - Drawing the paper again after unlock keeps an essay that hadn't saved.
> - Ctrl+P and print preview show no paper.
> - With JavaScript turned off in browser settings: only the message.
> - Selecting question text; selecting inside the essay box.
> - Opening the attempt on a second PC.
> - The whole claim → walk → type → gate → fullscreen flow.
> - For each row, record blur / hidden / fullscreen exit / result, and confirm the §1 double-fire is gone (one action, one lock).
>
> **Vacuity breaks** (each must make a named assertion fail):
>
> | Break | Assertion that must fail |
> |---|---|
> | Echo one question text into the shell | Test 1 absent check |
> | Make `paper` accept GET | 405 check |
> | Remove the lock check in `paper` | 423 |
> | Change the save grace to 60 s, then to 0 | +6 s → 423, and +2 s → 200 |
> | Return `closed` instead of `locked` | PHP error check, and node "no onClosed" |
> | Drop `AND locked_at IS NULL` from the lock UPDATE | One-row invariant |
> | Allow submit while locked | Test 5 |
> | Rebind on save | Test 7, A doesn't rebind |
> | Put the code in the list JSON | Test 8 leak check |
> | Don't clear `code_hash` on unlock | Reuse refused |
> | Write `unlocked_by = NULL` | Identity check |
> | Add `locked_at IS NULL` to the sweep | Test 11 |
> | Clear `locked_at` in `autoSubmit` | "Closed while paused" label |
> | Lock on heartbeat gap | Test 13 |
> | Set the grace to 0 ms | Node 2.9 s blip |
> | Use `innerHTML` in render | Node XSS test |
> | Don't set `submitting` | Node suppression test |
>
> ---
>
> ## 9. Build order
>
> Each stage ends in a commit after it's verified. Nothing gets pushed. The old monitor keeps running until stage 5 replaces it; there is no school yet, so that gap isn't live.
>
> | # | Stage | Schema | Verify with | Size |
> |---|---|---|---|---|
> | **1** | **Clipboard and selection guard.** Copy/cut/paste/drop/contextmenu/`beforeinput` blocked, `user-select`, print CSS, Ctrl+P. Blocking only; logging comes in 10. | none | Node guard tests; browser paste table | S |
> | **2** | **Paper over JSON and the no-JavaScript shell.** `POST paper`, empty shell, noscript, fullscreen gate before the fetch, `textContent` render, `valueOf` on the queue, Back link gone, generic title. | none | lock_test §8 tests 1–2 (no-lock parts); node render/XSS; browser gate and JS-off | M |
> | **3** | **Migration 007.** Dump, show you the file, your go, apply. No behaviour change. | **007** | Schema loads in the test database; every existing suite passes; `information_schema` checks | S |
> | **4** | **Server lock core.** `lock`, `heartbeat` (with gap logging), `event`; enforcement in exam/paper/save (5 s)/submit; `logActivity` removed; "Closed while paused" labels. Tests clear `locked_at` directly in the database to stand in for unlock. | uses 007 | Tests 3–6, 11–15 | M |
> | **5** | **Client lock monitor and overlay.** 3 s grace, blips, immediate triggers, DOM removed, `423`/`superseded` in the queue, heartbeat, old monitor deleted. The overlay has no code box yet. | — | Node monitor and queue tests; browser trigger matrix | M |
> | **6** | **Invigilator screen, read only.** Controller, nav, live list, sweep in the constructor. | — | Test 8 (access and list) | S |
> | **7** | **Claim, code, unlock at the seat.** Claim, session-held code, `unlock`, 5 tries, back to the gate. | — | Tests 8–9; browser full flow | M |
> | **8** | **Seat binding and `new_session`.** Your answer to question 1 decides what the seat is. | — | Test 7; browser second PC; Retry flow | S |
> | **9** | **Bulk unlock.** Selection, required reason, `bulk_unlocks`, student learns via heartbeat. | — | Test 10 | S |
> | **10** | **Paste logging and teacher history.** `paste_landed`, `bulk_insert`; pauses and events on the activity page; grading link; admin analytics. | — | Node guard `chars`; HTTP events test; page checks | S |
> | **11** | **Words and docs.** Marketing, README, OFFLINE-DEPLOYMENT client checklist (change B) and §10.3. | — | Read-through; grep for "threshold" and "flagged" in copy | S |

---

# Part 3: amendments, in the order they were sent

## Amendment 1 (`#99`): eleven answers, plus changes A and B

Already folded into revision 2 above. Recorded here as sent:

> 1. Invigilator: no new role. Any Teacher or Admin. The invigilator screen
>    lists in-progress attempts across ALL exams, not only the ones they
>    teach — the subject teacher often isn't the invigilator. Nobody is
>    assigned in advance; whoever is in the hall unlocks, and the log records
>    who.
> 2. Sittings: don't build.
> 3. Unlock code: yes, your per-lock one-time code, claimed by a named
>    invigilator, shown only on their screen, typed at the seat. No one-click
>    unlock from the desk — the walk to the seat is the deterrent. The
>    invigilator screen shows locks appearing live with student, subject and
>    trigger. Bulk unlock is the only exception: invigilator screen only,
>    reason required, logged as a bulk action.
> 4. Blur: 3 s grace, shorter blurs logged as a blip with duration.
>    Visibility-hidden and fullscreen-exit lock immediately.
> 5. Reopening: a page load alone doesn't lock, but questions don't render
>    until fullscreen is entered. A different session opening the attempt does
>    lock (new_session).
> 6. Saves after a lock: accept for 5 s, then 423.
> 7. Locked students can't submit. Time running out while locked gets its own
>    label, "Closed while paused", not "Not submitted".
> 8. Old flag data: keep is_flagged and activity_logs readable. Stop writing
>    them. Don't drop them in 007.
> 9. Essays: block copy, cut and paste there too — no exception anywhere on the
>    page. Tell me which paste routes you can actually intercept and which you
>    can't (Ctrl+V, right-click, middle-click, drag-and-drop), and put each in
>    the browser test list. Log any paste that still lands, with its character
>    count.
> 10. Lab PCs: no school yet, so assume nothing. No browser policies, no HTTPS
>     on the LAN, notifications on. Build nothing that needs a secure context:
>     no Keyboard Lock, no Wake Lock.
> 11. Missed heartbeat: log only, never lock.
>
> Two changes:
>
> A. Close the no-JavaScript hole in this build. Question and option text must
>    not be in the initial HTML. The page fetches questions only after the
>    fullscreen gate is passed and the lock monitor is running. With
>    JavaScript off, the student gets a page saying the exam requires
>    JavaScript and nothing else. Test: with JavaScript off, no question text
>    anywhere in the response.
> B. Sleep and screensaver are a Windows setting on the install checklist, not
>    code.

## Amendment 2 (`#127` and `#142`): six answers and the typing-rate addition

Sent (`#127`):

> 1. Seat identity: per-browser seat cookie, your recommendation. A crash or
>    re-login on the same PC must not pause the exam.
> 2. Reload: yes, F5, closing the tab and closing the window all lock. One rule:
>    leaving an armed page locks, arriving doesn't. A cold reopen doesn't lock.
> 3. Bulk unlock: explicit selection with the exam filter. No hall or room
>    labels.
> 4. Blocked copy/paste attempts: log them, with route and a count. Aggregate
>    per attempt and route rather than one row per keystroke, so hammering
>    Ctrl+V twenty times is one row with count 20.
> 5. Yes. The old PC shows "This exam is open on another computer" with no
>    unlock box.
> 6. 45 s is fine.
>
> One addition: AutoHotkey and DevTools setting textarea.value are both
> undetectable as pastes, and both defeat the block. Since we're logging
> anyway, add a typing-rate signal on essay boxes: log an event when an essay
> gains a large number of characters in a short span, even one character at a
> time. Propose the threshold yourself and say what would false-positive.

Filed into the plan (`#142`):

> ## Plan changes
>
> **1. Seat is a per-browser cookie.**
> - `startExam` sets an httponly `exam_seat` cookie that lasts a day. It holds a random 32-byte value, and `exam_attempts.seat_hash` stores its SHA-256.
> - A crash or signing in again in the same browser keeps the cookie, so the exam doesn't pause. The existing Retry flow keeps working.
> - A different PC or browser has no matching cookie, so opening the attempt there is an opening: rebind and lock with `new_session`.
> - The column name `seat_hash` doesn't change.
>
> **2. Reload.** One rule: leaving an armed page locks, arriving doesn't. F5, Ctrl+W and closing the window all fire `visibilitychange` → hidden while armed, so they lock. A cold reopen starts at the gate with no lock. Browser checklist rows: F5, Ctrl+W, closing the window, cold reopen after killing the browser process.
>
> **3. Bulk unlock.** Rows are picked explicitly, with an exam filter to narrow them. There is no hall or room label.
>
> **4. Blocked-action log, one row per attempt, action and route.** Migration 007 gains a table:
> - `attempt_blocked_actions`: `attempt_id` (FK cascade), `action ENUM('copy','cut','paste','drop','drag','context_menu','print','save','replace')`, `route ENUM('keyboard','mouse','other')`, `count INT`, `first_at`, `last_at`, `PRIMARY KEY (attempt_id, action, route)`.
> - The server write is `INSERT … ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_at = NOW()`.
> - The client adds up counts and sends them every 5 s and before a lock, so pressing Ctrl+V twenty times is one row with `count = 20`, sent at most once per 5 s.
> - Routes: **keyboard** means the matching shortcut was pressed within 1 s; **mouse** covers right-click, drag and drop; **other** covers the browser menu, Win+V without a detectable key, and the `beforeinput` backstop.
> - Tests: 20 blocked pastes give one row with count 20. Vacuity break: write a plain INSERT instead of the upsert, and the test fails on the duplicate key.
>
> **5. Old PC after `new_session`.** It shows "This exam is open on another computer" with **no unlock box**. The server also refuses `unlock` from a seat that isn't bound (`409 superseded`), so removing the page's own restriction doesn't help. Test: jar A posts the correct code after jar B took the seat → 409, still locked.
>
> **6. Heartbeat gap is 45 s.**
>
> **Addition: typing-rate signal on essays.** Two signals, both log-only, both added to `attempt_events.event_type`:
>
> - **`typing_burst`.** Characters are counted from `input` events with `inputType` `insertText`, `insertCompositionText` or `insertLineBreak`, using `e.data` length. Undo, redo and deletions don't count.
>   - It fires when **200 or more characters are inserted into one essay within a rolling 10 s window**, which is 20 characters a second sustained.
>   - For scale: a fast typist at 100 wpm is about 8 characters a second, and competition typists sustain about 12–15. School students on lab keyboards are mostly well under 8.
>   - After firing, that essay's window resets, so a continuous burst logs one event every 10 s, not one per keystroke.
>   - `detail` holds `{qid, chars, ms, distinct_chars}`.
>   - AutoHotkey's `SendInput` pastes hundreds of characters a second, and `SendEvent` defaults to about 100, so either trips this.
> - **`value_jump`.** Every second, each essay's `value.length` is compared with the length the input events account for.
>   - It fires when the length **grows by 20 or more characters with no input event explaining it**.
>   - That is DevTools setting `textarea.value`, which fires no input event at all, so a rate check could never see it.
>   - The baseline resets whenever our own code sets the value (drawing the paper, restoring from the queue).
>
> **What would false-positive:**
> - **Holding a key down.** Windows auto-repeat runs at about 30 characters a second, so holding a letter or the space bar for 7 s trips `typing_burst`. `distinct_chars` (1–2 for a held key) lets the teacher see this at a glance.
> - **Voice typing (Win+H) and some IMEs** insert whole words or phrases per event. Long dictation can pass 20 characters a second.
> - **Extensions or password managers** that fill fields without an input event can trip `value_jump`. They're rare on essay boxes with `autocomplete="off"`.
> - **A paste that got past the block** would also trip these, but it's already logged as `paste_landed`. Teachers see both.
> - **Not a false positive but a miss:** AutoHotkey with a human-speed delay (say 150 ms per key) is invisible to any rate signal.
>
> Tests (node, fake clock):
> - 199 characters in 10 s → nothing; 200 → one event.
> - 200 over 11 s → nothing.
> - `historyUndo` of 500 characters → nothing.
> - A 25-character length jump with no input → `value_jump`; one caused by our own draw → nothing.
>
> Vacuity breaks: set the threshold to 10,000, and don't reset the baseline on draw.
>
> **Build order:** blocked-action logging and both typing signals go in **stage 10**. Stage 1 blocks only. It already works out each block's action and route and exposes that through an `onBlocked(action, route)` hook, so stage 10 only has to wire the hook to the server.

## Amendment 3 (`#253`): three notes, filed at `#264`

Sent:

> 1. The paper is injected after the fullscreen gate. It must land inside the
>    .quiz-page container, or the stage 1 selection CSS won't cover it. Test:
>    question text arriving by fetch can't be selected.
> 2. The lock endpoint must be idempotent. Blur and visibilitychange often
>    fire together. If the attempt is already locked, record the event but
>    don't create a second attempt_locks row or a second code. Test: two lock
>    requests in a row give one lock row and one code. Vacuity break: remove
>    the already-locked check.
> 3. Our own submit fires visibilitychange, so a real submit must not lock
>    the attempt. Test: submitting ends as submitted, not locked.

Filed (`#264`):

> **1. Paper injected inside `.quiz-page` → stage 2.** Tests P11, R5 and the headless Chrome check, with the breaks listed above.
>
> **2. Idempotent lock → split between stage 4 and stage 7.** In this plan, a lock creates no code: the code is made when an invigilator **claims** the lock.
> - **Stage 4 (lock rows):** a second lock request while locked appends its trigger to the open lock's `detail`, adds no row, and doesn't move `locked_at`. Test: two lock requests in a row (`window_blur` then `tab_hidden`) → one `attempt_locks` row with both triggers recorded, and `locked_at` unchanged. Break: remove the already-locked check (`AND locked_at IS NULL`) → two rows.
> - **Stage 7 (code):** a lock request on an attempt that is already locked and claimed leaves `claimed_by`, `code_hash` and `failed_tries` untouched. Test: lock → claim → lock again → still one row, the same `code_hash`, and the invigilator's code still unlocks. Break: let the repeat lock reset the claim → the code check fails.
>
> **3. Our own submit doesn't lock → split between stage 4 and stage 5.**
> - **Stage 4 (server):** a real submit ends with `status = 'submitted'`, `locked_at` NULL and no lock row. A lock request that arrives *after* the submit, as the page's keepalive request racing the navigation would, gets `409 closed` and creates no row. Break: drop the `in_progress` check from the lock UPDATE → a row appears on a submitted attempt.
> - **Stage 5 (client):** pressing "Submit all and finish" and the timer's own submit both set `submitting` before `form.submit()`. A `visibilitychange` → hidden after that sends no lock. Test (node monitor): `submitting` set, then hidden → zero lock calls. Break: don't set `submitting` → one lock call.
> - **Browser checklist:** submit normally → the result page, and the teacher's grading list shows no pause.

## Amendment 4 (`#267`): stage 2 gate failures, and a stage 4 race note

> 1. Gate failure handling. Retry only makes sense for a network failure.
>    - 403 csrf, 401, or a response that isn't JSON (for example, an expired
>      session redirecting to the login page): reload the page so the server
>      sends a fresh token or the login screen. Never loop on Retry.
>    - Add a render test: a non-JSON response and a 403 each trigger a
>      reload, not Retry. Vacuity break: treat them as network failures.
>    - Add to my hand checks: open the exam at the gate, log out in another
>      tab, then press Enter fullscreen. I should land on login, not a Retry
>      button.
>
> 2. Plan note for stage 4, not stage 2. Blur and tab-hidden arrive at the
>    same moment in practice, so the sequential two-request test can't catch
>    a check-then-insert race. Decide from the affected row count of a single
>    conditional UPDATE (WHERE id = ? AND status = 'in_progress' AND
>    locked_at IS NULL), and insert the lock row only when it returns 1. Add
>    a test that fires two lock requests concurrently and asserts one row.
>    Vacuity break: a SELECT-then-INSERT version must fail it. The append of
>    the second trigger to detail must also not lose either trigger.

## Amendment 5 (`#567`): two notes, filed at `#583`

Sent:

> 1. The lock row is created with detail already set to {"triggers":[first]}.
>    JSON_ARRAY_APPEND on a NULL detail returns NULL and would silently wipe
>    the triggers. Test: lock, then a second trigger, and detail holds both.
>    Vacuity break: create the row with detail NULL.
> 2. The student instructions page button still says "Attempt quiz". Fix the
>    wording in whichever stage next touches that page, and grep for any
>    other leftover "quiz", "lecturer" or "course" labels a student or
>    Teacher can see.

Filed (`#583`):

> **1. Lock row created with `detail = {"triggers":[first]}` → stage 4, with support in stage 3.**
> - **Stage 3:** `detail JSON NOT NULL` plus `JSON_VALID` (test M6), so a NULL can't even be written.
> - **Stage 4:** a column that can't be NULL doesn't close the whole gap. `JSON_ARRAY_APPEND` on `{}`, which is valid JSON with no `triggers` key, **silently changes nothing**.
>   - Test: lock, then a second trigger, then read back `detail`; it holds both triggers, in order.
>   - Breaks: create the row with detail `NULL` (now fails on insert), and create it with `{}` (the append does nothing and the read-back fails). Both must fail the test.
>   - This joins the concurrent-lock test already filed under stage 4.
>
> **2. "Attempt quiz" and other leftover labels → stage 5.** No planned stage touches the instructions page [attempt.php](app/views/student/attempt.php) before stage 11. Stage 5 rewrites [exam.php](app/views/student/exam.php), which has the same problem, so both get fixed there. A first single-line grep found:
> - [attempt.php:56](app/views/student/attempt.php#L56): the "Attempt quiz" button. I suggest **"Start exam"**, which the student dashboard already uses.
> - [exam.php:107-108](app/views/student/exam.php#L107-L108) and [result.php:223-224](app/views/student/result.php#L223-L224): "Quiz navigation", as both the `aria-label` and the heading. I suggest **"Questions"**.
> - Every "lecturer" and "course" match is a URL, form field or option value, none of it on screen.
>
> That grep can't see text split across lines, JavaScript strings or marketing copy. Stage 5 will do a full pass over views, JS and marketing pages for "quiz", "lecturer", "course" and "semester", and list every on-screen hit before changing it.

## Amendment 6 (`#589`): stage 3 additions

> 1. At most one open lock per attempt, enforced by the database. Add a
>    generated column on attempt_locks that holds attempt_id while
>    unlocked_at IS NULL and NULL once unlocked, with a UNIQUE index on it.
>    Include it in schema.sql, schema_import.sql and 007, so M2 covers it.
>    Test M11: a second open lock for the same attempt errors; a new lock
>    after the first is unlocked is accepted. Vacuity break: drop the unique
>    index, and a second open lock is stored.
>
> 2. Backup and apply on Windows:
>    - Dump with mysqldump --default-character-set=utf8mb4
>      --single-transaction --routines --result-file=<path>, never shell
>      redirection. PowerShell's > writes UTF-16.
>    - Prove the dump works: restore it into a throwaway database whose
>      name contains "test", compare row fingerprints of every table with
>      exam_system, confirm a row with ẹ ọ ṣ ń survived intact, then drop the
>      throwaway.
>    - Apply 007 without PowerShell's <, for example
>      mysql --default-character-set=utf8mb4 -e "source <path>".
>    - Still show me the final 007 file and wait for my go before touching
>      exam_system.
>
> 3. Rewrite my hand checks so the analytics comparison is exact:
>    run the hand-check script first, note the Admin integrity numbers, let
>    you apply 007, compare the numbers straight away, and only then sit the
>    exam. Don't compare numbers after I've sat it.
>
> 4. Report, don't fix: list every code path that can delete a user. For each
>    one, say whether a staff member referenced by attempt_locks or
>    bulk_unlocks would hit the RESTRICT foreign key as an unhandled error.
>    File the answer under stage 7 in the plan.
>
> Also add to stage 11's deployment checklist: confirm the server's MariaDB
> version supports enforced CHECK constraints and JSON functions (10.4 or
> newer), and record its sql_mode.

## Amendment 7 (`#746`): explicit table options

> 1. Every new table must declare its engine and character set explicitly,
>    matching whatever the existing tables in schema.sql declare (for
>    example ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 with the same collation).
>    Right now the four CREATE TABLEs inherit server defaults, and a school
>    server defaulting to latin1 would garble a bulk-unlock reason typed
>    with ẹ or ọ. Apply this to 007 and both schema files.
>    - Extend M2 to compare ENGINE, TABLE_COLLATION and each column's
>      CHARACTER_SET_NAME and COLLATION_NAME.
>    - Add M12: build the fresh and migrated test databases with a latin1
>      database default. The new tables must still come out utf8mb4, and a
>      bulk_unlocks.reason containing ẹ ọ ṣ ń must read back byte-identical.
>    - Vacuity break: remove the table options from one table, and M12
>      must fail.
>
> 2. Drop the "See migration 007 for why each column is here" sentence from
>    all three files.

## Amendment 8 (`#901`): the database-clock note for stage 4

> Add one note to stage 4 of the plan:
> All lock timing uses the database clock only. locked_at, unlocked_at and
> event timestamps come from NOW() in SQL, and the "accept saves for 5 s
> after a lock, then 423" rule compares against locked_at inside the same
> SQL statement, never PHP's time(). PHP and MySQL clocks already disagree
> in this codebase. Test: with PHP's clock deliberately offset from the
> database's, a save 3 s after locking is accepted and one 7 s after is
> refused. Vacuity break: compute the window in PHP.
>
> Stage 4 is the first stage that writes lock rows. Its hand checks must end
> with a lock on the HANDCHECK attempt, then a rerun of the hand-check
> script, confirming it removes the lock and event rows as well.

## Amendment 9 (`#917`): stage 4 test additions, and plan changes A, B, C

> 1. R4 in lock_race_test.php: 50 rounds, one worker calls submitAndGrade()
>    while the other calls lock() at the same instant. Every round must end
>    in exactly one of two states: submitted with locked_at NULL and no lock
>    row, or in_progress with locked_at set and one open lock row. Never
>    submitted with an open lock. Vacuity break: remove AND locked_at IS NULL
>    from the submit claim, with a pause so the race happens every round.
>
> 2. Extend L5: a submit POST carrying changed answer fields while locked
>    leaves every stored answer unchanged. Vacuity break: save posted
>    answers before the locked check. If submitExam never saves posted
>    answers, say so and prove it with the same assertion.
>
> 3. L15: heartbeat and event on a submitted attempt return 409 closed, and
>    write no last_seen_at change and no event row. Vacuity break: remove
>    the status check from heartbeat.
>
> 4. blur_ms and the blur_blip ms must be integers from 0 to 600000;
>    anything else is stored as null, never rejected, and never cast from a
>    string like "3200abc". Add it to L1 and L10. Vacuity break: store the
>    raw value.
>
> Plan changes:
>
> A. New step 4b, after stage 4 and before stage 5: move every deadline
>    decision onto the database clock. exam(), paper(), saveAnswer(),
>    submitExam(), the sweep and the exam page's countdown compare
>    deadline_at against NOW() in SQL, never PHP's time() or strtotime().
>    Under the clock-offset server, the exam page's remaining and the
>    heartbeat's remaining agree within 2 seconds, and a save 3 s before
>    the deadline is accepted while one 3 s after is refused. L8's control
>    will fail once this lands, so 4b replaces it with a control that
>    compares strtotime(deadline_at) against the database directly. Say in
>    4b whether the hardcoded time_zone in Database.php:21 still affects any
>    comparison once this is done.
>
> B. Stage 5: a 423 on a save keeps the answer in the retry queue rather than
>    dropping it, and the queue flushes after unlock. Test: a choice changed
>    during the 5 s window and an essay edit refused with 423 are both
>    stored after unlock.
>
> C. Stage 6: the invigilator screen lists only in_progress attempts. A paper
>    closed while paused keeps its open lock row for the record but must
>    never appear as waiting to be unlocked.

---

# Part 4: notes filed by stage

## Stage 1 — Clipboard and selection guard · **Done**, `4a5f425`

Build-order row, revision 2 §9:

> **Clipboard and selection guard.** Copy/cut/paste/drop/contextmenu/`beforeinput` blocked, `user-select`, print CSS, Ctrl+P. Blocking only; logging comes in 10.

Design detail: revision 2 §5 above.

From `#142`:

> Stage 1 blocks only. It already works out each block's action and route and exposes that through an `onBlocked(action, route)` hook, so stage 10 only has to wire the hook to the server.

Verified by hand (`#253`):

> Stage 1 verified on a real Windows PC: every block and every allowed action behaved as intended, and review, results and admin pages still copy normally.

## Stage 2 — Paper over JSON and the no-JavaScript shell · **Done**, `bb34ea6`

Brief: `#264`, approved at `#267`. Covered in full in Part 3, Amendments 3 and 4.

Notes filed here:

- **Note: the paper must land inside `.quiz-page`** (`#264`) — "Tests P11, R5 and the headless Chrome check, with the breaks listed above."
- **Gate failure handling** (`#267`, Amendment 4 item 1).

Verified by hand (`#567`):

> Stage 2 verified by hand in Edge: JavaScript off shows only the message and no question text in the source; the gate, reload and Esc-during-transition behave; stopping Apache gives Retry and restarting loads the paper; special characters and line breaks render literally; the stage 1 guard covers fetched content; saved answers, essays and flags survive a reload; the summary and Submit work; Back after submitting doesn't restore the paper; logging out in another tab sends the gate to the login page.

## Stage 3 — Migration 007 · **Done**, `21f2d03`, applied to local `exam_system`

Brief: `#583`, approved at `#589`. Additions at `#589` and `#746` (Part 3, Amendments 6 and 7).

Finding that shaped the design (`#583`):

> **One finding changes the design.** This MariaDB 10.4.32 runs with `sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`, which is **not strict**. An invalid ENUM value is silently stored as `''` with only a warning. Every ENUM in the new tables therefore gets a `CHECK` constraint listing its allowed values. MariaDB enforces CHECK constraints in any mode, so a typo errors instead of writing a blank trigger.

Notes filed here:

- **Note: `detail` can never be NULL** (`#583`) — "**Stage 3:** `detail JSON NOT NULL` plus `JSON_VALID` (test M6), so a NULL can't even be written."

Verified by hand (`#901`):

> Stage 3 verified: after 007 was applied to exam_system, Admin Analytics matched the numbers from before, the four new tables exist and are empty, the three new exam_attempts columns are NULL on every row, a fresh HANDCHECK attempt was sat, reloaded, submitted and graded, old CSC301 flags and activity timelines still show, and the hand-check script removed the graded attempt cleanly.

## Stage 4 — Server lock core · **Done**, `cbdb96e`

Brief: `#914`, approved at `#917`. Additions at `#917` (Part 3, Amendment 9 items 1–4).

Notes filed here:

- **Idempotent lock, lock-row half** (`#264`) — "a second lock request while locked appends its trigger to the open lock's `detail`, adds no row, and doesn't move `locked_at`."
- **Our own submit doesn't lock, server half** (`#264`) — "a real submit ends with `status = 'submitted'`, `locked_at` NULL and no lock row."
- **The check-then-insert race** (`#267`) — "Decide from the affected row count of a single conditional UPDATE … and insert the lock row only when it returns 1."
- **`JSON_ARRAY_APPEND` on `{}` silently does nothing** (`#583`) — "Breaks: create the row with detail `NULL` (now fails on insert), and create it with `{}` (the append does nothing and the read-back fails)."
- **All lock timing uses the database clock only** (`#901`, restated at `#914`):

  > **All lock timing uses the database clock only.** `locked_at`, `unlocked_at`, `claimed_at`, every event's `created_at` and each trigger's `at` come from `NOW()` in SQL. The "saves for 5 s after a lock, then 423" rule compares against `locked_at` inside the same SQL statement that reads the attempt, never PHP's `time()`, because PHP and MySQL clocks already disagree in this codebase.
  > - Test **L8**: with PHP's clock deliberately offset from the database's, and a control that proves the offset is real, a save 3 s after locking is accepted and one 7 s after is refused.
  > - Vacuity break: compute the window in PHP.

Trigger detail shape, approved at `#917` (`#914`):

> Each entry in `triggers` is an object built entirely in SQL, not a bare name: `{"type":"window_blur","at":"2026-09-15 16:40:02","blur_ms":3100}`, so the log shows *when* each trigger arrived. The client may send only `window_blur`, `tab_hidden` or `fullscreen_exit`. `new_session` comes from the server, in stage 8. In stage 4 the event endpoint accepts only `blur_blip`; the other event types arrive in stage 10.

Finding that shaped the race test (`#914`):

> On Windows, PHP's built-in server serves **one request at a time**. `PHP_CLI_SERVER_WORKERS` depends on `fork`, which Windows doesn't have. Two "simultaneous" HTTP requests to `php -S` can never race, so an HTTP concurrency test would pass even against broken code. The race test therefore runs **two separate PHP processes** calling `Attempt::lock()` on the test database at the same moment, which is real concurrency at the database.

Known interim quirk until stage 5 (`#1178`):

> a save refused with 423 is retried by the save queue with backoff, so the status line reads NOT SAVED and requests keep going. Plan note B fixes that.

### Added 2026-09-21: the 1467 error, and the restart experiment dropped

- **Error 1467 on attempt 8 followed an unclean restart.** On 16 Sep a lock
  request for attempt 8 failed with `SQLSTATE[HY000] 1467 "Failed to read
  auto-increment value from storage engine"` on the `INSERT INTO
  attempt_locks`. The transaction rolled back (attempt 8 kept `locked_at` NULL,
  and no lock row was written). `SHOW TABLE STATUS` gave `Auto_increment: 0`
  for `attempt_locks`. MySQL had started twice that morning, at 10:40:44 and
  10:40:54, both times through crash recovery and with no clean shutdown
  before. A server-wide `FLUSH TABLES` brought the counter back to 2.
- **The restart experiment was dropped.** It was meant to find out whether the
  table's shape (the VIRTUAL generated column with its UNIQUE index) or the
  restart caused the fault, using four probe tables in
  `exam_system_test_autoinc`. The cause turned out to be the Aria and
  crash-recovery damage, which came from mysqld started by the XAMPP Control
  Panel (see stage 11, "running MySQL on a school server"). The fix is the
  clean start and stop scripts in `scripts/windows/`, so the experiment is no
  longer needed. `exam_system_test_autoinc` held only the four empty probe
  tables and was dropped on 21 Sep 2026.
- The 16 Sep brief's other asks were open until 22 Sep: JSON endpoints must
  return HTTP 500 with a small JSON error when they throw, with no stack trace
  and no file paths (the error handler), and the proposal must say what
  production shows today with `display_errors`. The error handler is now done
  (`c92cf9c`, see "Error handling" below). It sets `display_errors` from config,
  off unless `DISPLAY_ERRORS` is true, so what a server shows no longer depends
  on its php.ini.

**Hand checks: all passed.** The first six passed before 21 Sep: lock, second
trigger, one lock row with both triggers, saves refused with 423, paper
refused with the paused message, and "Closed while paused" labels. The full
list is in the stage 4 brief at `#914`, steps 1–10.

### Added 2026-09-21: the last four hand checks

Run in Edge on HANDCHECK attempt 9 (exam 13), all in one attempt. MySQL was
started and stopped with the clean scripts. The page does not send heartbeats,
blips or locks until stage 5, so each request was sent from the DevTools
console with the page's own CSRF token. Each result was confirmed in the
database.

- **Heartbeat.** The first heartbeat gave `200 {"ok":true,"locked":false}` and
  set `last_seen_at`. It logged no gap, because there was no earlier heartbeat.
  Each `remaining` the heartbeat returned equalled `deadline_at` minus that
  heartbeat's `last_seen_at`, to the second (1181 and 883).
- **`heartbeat_gap`.** `last_seen_at` was moved back 60 s by SQL. The next
  heartbeat came 261 s after the moved value, and gave `200` and
  `locked:false`. It logged `heartbeat_gap {"seconds": 261}` and left
  `locked_at` NULL: a gap is logged and never pauses.
- **`blur_blip`.** `ms=1200` was stored as `{"ms": 1200}`. `ms=3200abc` was
  stored as `{"ms": null}`, not rejected and not cast to 3200. Both returned
  200. `type=focus_lost` returned `422 bad_event` and wrote nothing.
- **Submit refused while locked.** A lock with `tab_hidden` gave
  `{"ok":true,"locked":true,"new":true}`, and the heartbeat after it reported
  `locked:true`. Submitting the real form sent the browser back to
  `student/exam/9`, not the result page. The timer kept running, and the gate
  showed the paused message with no questions. In the database the attempt was
  still `in_progress`, with `submitted_at` and `total_score` NULL,
  `grading_status` `pending`, one open `tab_hidden` lock row, and both saved
  answers present. No answer snapshot was taken before the submit, so this
  shows the answers were still there, not that they were untouched. L5 proves
  the unchanged part.
- **The lock insert works on a long-lived table.** On 16 Sep, attempt 8's lock
  failed with 1467 on the `INSERT INTO attempt_locks` (above). The same insert
  on attempt 9 succeeded and wrote lock row 2 without error.
- **Cleanup.** The rerun of the hand-check script removed attempt 9 with
  **1 lock and 4 events**, not the 3 events predicted in the brief. The fourth
  is correct: E's heartbeat came 298 s after the one before it, so it logged a
  second `heartbeat_gap` and still did not lock. Afterwards `attempt_locks`,
  `attempt_events` and `attempt_blocked_actions` were empty. The `exam_system`
  fingerprints differed from the 21 Sep baseline only in HANDCHECK rows. MySQL
  shut down cleanly, with no Aria errors in the Application event log.

**L5 was checked for vacuity** in the stage 4 vacuity run on 15 Sep 2026, just
before `cbdb96e`. Two breaks to the submit path were each applied on their own,
and both were caught:
- `AND locked_at IS NULL` removed from the submit claim in `submitAndGrade()`,
  so a paused attempt could be submitted: 6 L5 assertions failed.
- Posted answers saved before the locked check: 4 L5 assertions failed.

R4 also caught the first break under a real race. No commit since `cbdb96e`
has touched `app/` or `tests/`.

## Error handling (before 4b) · **Done**, `c92cf9c`

Added 2026-09-22. Brief sent and approved on 21–22 Sep, with the changes
folded in below. It closes the error-handler item left open by the 16 Sep 1467
brief.

**Agreed:**
- One exception handler in the front controller, plus
  `register_shutdown_function` for fatal errors.
- JSON routes return HTTP 500 with `{"ok":false,"error":"server","id":"<id>"}`
  and no details.
- Page routes show a plain error page with the id: "Something went wrong on
  our side. It wasn't anything you did. If it keeps happening, report this
  code: XXXX-XXXX." Students, Teachers and Admins all see it.
- `display_errors` comes from config: on for the developer's laptop, off
  everywhere else.
- Warnings are logged only, never turned into exceptions.

**Built:**
- **[ErrorHandler.php](app/core/ErrorHandler.php)** is installed on the first
  lines of [index.php](public/index.php), before the session and the config.
  It uses `set_exception_handler`, `register_shutdown_function` for the fatal
  types, and `set_error_handler` for warnings, notices and deprecations. The
  warning handler logs and returns `false`, so PHP behaves as before.
- **JSON or page is decided by route, from the URL, before any controller
  runs.** `Router::routeKey()` is shared with the Router, and is
  case-insensitive the way PHP method lookup is. `ErrorHandler::JSON_ROUTES`
  lists the six actions that call `$this->json()`.
- **The id** is `XXXX-XXXX`, drawn with `random_int` from Password.php's
  alphabet. The same id goes on the page or in the JSON, and on the log line.
- **Output:** the handler opens its own output buffer (no callback). On an
  error it clears every buffer, including views' nested ones, and removes
  `Location`, `Content-Type`, `Content-Disposition`, `Content-Length` and
  `Refresh`. It sends 500 and `Cache-Control: no-store`. If headers were
  already sent, it only appends one line with the id.
- **The page** is [error_500.php](app/views/error_500.php). It touches neither
  the database nor the session, and checks each constant before use. If
  rendering it throws, a fallback page that depends on nothing is sent instead,
  and the failure is logged under the same id.
- **Details** (class, message, file:line, trace) appear on the page only when
  config sets `DISPLAY_ERRORS` to `true`. Missing counts as false. JSON never
  carries details. `display_errors` is 0 from the first line, whatever php.ini
  says, until config decides.
- **Log:** `ERROR_LOG_FILE`, defaulting to `logs/app-errors.log`, which is
  outside `public/`. One line per error: time, id, level, class, message
  (flattened, capped at 1,000 characters), relative file:line, method and URL
  path, user id, and `tx=`. Request bodies and query strings are never logged.
  Writes use `FILE_APPEND|LOCK_EX`, falling back to PHP's `error_log()`. The
  log rotates at 5 MB, keeping one old file.
- **`logs/.htaccess`** denies web access on Apache, because on XAMPP the repo
  sits inside htdocs. `.gitignore` tracks only that file in `logs/`.
- **Open transaction:** `Database::rollBackIfOpen()` never opens a connection.
  The log records `tx=none`, `rolled back` or `rollback failed`. MySQL would
  roll back on disconnect anyway, so this is a backstop: it frees locks sooner
  and flags code that forgot its own rollback.
- **Two exits became exceptions:** `Database::getInstance()` no longer calls
  `exit('Database connection failed.')`, which used to answer JSON routes with
  a plain-text 200. `Controller::view()` no longer echoes a missing view's path.
- **PHP versions:** the code is written for 8.0 and tested on 8.0.30, 8.4.25
  and 8.5.10. WhoGoHost runs 8.5.9, but no checksum is published for that
  archived build, so 8.5.10 was used; its SHA-256 matched windows.php.net. On
  8.5, a fatal error's message can carry a backtrace; the log keeps only the
  first line. `curl_close()` is deprecated in 8.5 and has done nothing since
  8.0, so it was removed from the five test HTTP helpers.

**Not covered, on purpose:** the marketing pages, which are served directly
and touch no database, and CLI scripts.

**Tests:** [error_test.php](tests/error_test.php) drives the real app on four
`php -S` servers (8091–8094).
- **No throw-on-purpose route exists in the app.** Real routes fail because
  `exam_attempts` is renamed in the test database (restored in a `finally`,
  and repaired at the start of the next run if a crashed run left it renamed).
  E14 drops the test database, which is rebuilt before exit. Faults that need
  code (a fatal, half a page, an open transaction) come from
  [FaultController.php](tests/faults/FaultController.php), which
  [router.php](tests/router.php) loads only when the suite starts a server
  with `EXAM_FAULTS=1`.
- `/fault/memory` sets its own 16M `memory_limit` before exhausting memory.

| # | Test | Break that must fail it |
|---|---|---|
| E1 | JSON route (`student/heartbeat`, table gone): 500, JSON, no-store, exactly `ok,error,id`, id from Password.php's exact alphabet | Heartbeat left off the JSON list; status 200; alphabet `IO01` |
| E2 | Page route (`student/dashboard`): 500 HTML page with the sentence and id | Every route treated as JSON |
| E3 | `Student//HeartBeat/1/` is a JSON route | Case-sensitive route key |
| E4 | `JSON_ROUTES` is exactly the actions that call `$this->json()` | `student/event` dropped from the list |
| E5 | Fatal (memory) caught by the shutdown function | `register_shutdown_function` removed |
| E6 | Display undefined or false hides every detail probe; control: display on shows them on the page; JSON never shows them | Display always on; undefined treated as on; JSON given a `detail` key |
| E7 | The id shown to the user is on exactly one log line, with class, message, file:line and request | The id generated twice |
| E8 | Half a page, in nested buffers, is thrown away | Buffer clearing removed |
| E9 | After a flush: only the sentence and id are added, no second page | Headers-sent branch removed |
| E10 | Open transaction: `tx=rolled back`, row gone | Rollback removed |
| E11 | No connection opened by the handler: `tx=none` | — (see E14) |
| E12 | A warning: 200, full page, one `warning` line | Warnings converted to `ErrorException` |
| E13 | `/fault/...` is a 404 without `EXAM_FAULTS` | FaultController copied into `app/controllers` |
| E14 | Database gone: JSON 500 and page 500, `tx=none`, one line each | `exit()` restored; handler connects |
| E15 | Error page itself fails: fallback page with the id, two log lines | try/catch around rendering removed |
| E16 | No diagnostics in the runner | — |
| E17 | Log rotates at 5 MB to `.1` | Rotation removed |

**Results, 22 Sep 2026:**
- **error_test.php:** 134 passed, 0 failed on each of PHP 8.0.30 (XAMPP),
  8.4.25 (Herd) and 8.5.10.
- **Vacuity:** 20 breaks, each applied alone. All 20 were caught by the
  assertion they target, and every file was restored and its hash re-checked.
- **Existing suites, on all three versions:** autosave 47, sweep 80, paper 53,
  lock 173, lock race 17, schema 007 260, enrolments list 120, all passing; node
  55 passing.
- **Server-side diagnostics:** the test servers logged no warning, notice or
  deprecation in any of those runs. Before this work that could not be seen.
- **Found along the way:** on 8.4 and 8.5 the enrolments suite failed.
  `fputcsv()` and `fgetcsv()` without an explicit `$escape` are deprecated from
  PHP 8.4, and the fixture generator's deprecations landed inside its CSV.
  `StudentImport::parse()` makes the same `fgetcsv()` call in production code.
  Both now pass `'\\'`, the old default, so files parse exactly as before and
  the live 8.5 host logs nothing per row.
- **Apache smoke check on the laptop:** `/fault/memory` is 404;
  `logs/app-errors.log`, `logs/.htaccess` and `config/config.php` are 403.

### Added 2026-09-22: hand checks, all passed

Done in Edge on the laptop's XAMPP (PHP 8.0.30), with the HANDCHECK accounts.
MySQL was stopped and started with the clean scripts to cause real database
failures. Every code on screen was matched to exactly one line in the log.

1. **Page route error, details on.** As the Teacher, with MySQL stopped,
   Dashboard returned 500 with code `GZ49-Y3V4` and the details below it. The
   log line: `RuntimeException "Database connection failed. (caused by
   PDOException: SQLSTATE[HY000] [2002] …)" app/core/Database.php:26 | GET
   /lecturer/dashboard | user 37 | tx=none`. It was the only dashboard request
   in Apache's access log (1,948 bytes).
2. **Details off.** With `DISPLAY_ERRORS` false, F5 gave `SYFA-FSCH` and no
   details (752 bytes). Ctrl+U re-requested the page and gave `KD6F-B3NU`;
   `.php`, `SQLSTATE` and "Database connection" were each found 0 times in the
   source. Both codes are logged, one line each. The setting was put back to
   `true`.
3. **JSON route error and recovery.** As the Student on attempt 10 (exam 15),
   with MySQL stopped, `post('heartbeat')` returned 500
   `{"ok":false,"error":"server","id":"XFB6-2BBK"}`, logged as `POST
   /student/heartbeat/10 | user 38`. Changing the capital-of-Nigeria answer
   from Abuja to Ibadan showed NOT SAVED, never closed, and the page did not
   submit. The save queue retried with growing gaps: **8** saves got a 46-byte
   JSON 500 between 11:53:52 and 11:55:06, each with its own logged id. With
   MySQL back, the retry at 11:55:25 got 200, and question 40 held option 88,
   Ibadan. The attempt stayed in progress and unlocked.
4. **Fatal error.** On a `php -S` test server (8199, test database, faults on,
   details undefined), `/fault/memory` returned 500 with code `T7W5-RVXG` and
   no details. The test log: `fatal E_ERROR "Allowed memory size of 16777216
   bytes exhausted (tried to allocate 1052672 bytes)"
   tests/faults/FaultController.php:50 | GET /fault/memory | tx=none`. On
   Apache, `/exam-system/public/fault/memory` gave the app's own 404 page. The
   server was stopped and port 8199 checked free.
5. **Log not served.** `http://localhost/exam-system/logs/app-errors.log` gave
   Apache's 403 Forbidden. That page shows the Apache, OpenSSL and PHP
   versions; hiding them is now on stage 11's checklist.

**Afterwards:** the hand-check script removed attempt 10. The `exam_system`
fingerprints differed from the 21 Sep baseline only in HANDCHECK rows (subject
4, exam 16, and users 37 and 38's password hashes). `login_attempts` and the
lock tables were unchanged. MySQL shut down cleanly, with no MySQL warnings or
errors in the Application event log.

## Step 4b — Every deadline decision on the database clock · **Built and tested**, hand checks pending

The original filing, from 16 Sep: no brief was written then; 4b was created at `#917` and filed at `#1178`, and work was stopped before it began. The brief was written and approved on 22 Sep 2026; see "Added 2026-09-22" below.

Filed (`#1178`):

> - Every deadline decision moves onto the database clock: `exam()`, `paper()`, `saveAnswer()`, `submitExam()`, the sweep and the exam page's countdown.
> - Under the clock-offset server, the page's and the heartbeat's `remaining` agree within 2 s, and a save 3 s before the deadline is accepted while one 3 s after is refused.
> - L8's control is replaced by one comparing `strtotime(deadline_at)` with the database directly.
> - 4b will say whether the hardcoded `time_zone` at [Database.php:21](app/core/Database.php#L21) still affects any comparison.

The question that produced it (`#914`):

> The **existing deadline checks** still compare `deadline_at` with PHP's `time()`. That covers `exam()`, `paper()`, `saveAnswer()` and `submitExam()`, plus the countdown the exam page is given. It's the same clock disagreement your note describes, but it isn't lock timing, so stage 4 leaves it alone. Moving those checks onto the database clock would be its own small change with its own tests.

### Added 2026-09-22: the brief, as approved

**Scope:** every decision about a student's attempt that depended on PHP's
clock now compares a stored time with `NOW()` in SQL. The approved changes
added the window decisions and a new rule for when correct answers are shown.

| Decision | Before | Now |
|---|---|---|
| `exam()`: past the deadline → close; the countdown's start | `strtotime(deadline_at)` vs `time()` | `findOwned()` returns `seconds_left` and `expired` from SQL; closing is `Attempt::closeIfExpired()`, one `UPDATE … AND deadline_at <= NOW()` decided by its row count |
| `paper()`: close or serve, and its `remaining` | PHP | The same |
| `saveAnswer()`: accept or `409 closed` | PHP, in the controller | `deadline_at > NOW()` inside the `FOR UPDATE` read in `saveAnswerIfWritable()`, so the check and the write share one transaction. `saved_at` also comes from `NOW()` |
| `lock()`: close instead of pausing | PHP check before the model | The model's UPDATE already had `deadline_at > NOW()`; on `closed`, `closeIfExpired()` |
| `submitExam()`: `submitted` or `auto_submitted` | PHP, passed into `submitAndGrade()` | `IF(deadline_at <= NOW(), 'auto_submitted', 'submitted')` in the claim; `submitAndGrade()` no longer takes a status |
| `startExam()`, `attempt()`, the dashboard: is the window open | PHP | `Exam::find()` and `availableForStudent()` return `not_yet_open` and `window_closed` from SQL |
| `result()`: when correct answers are shown | `time() > window_end` | See below |

The sweep, the pause, the heartbeat and `start()` were already on the
database clock.

**Correct answers: a new rule.** The old rule revealed answers at
`window_end`, but `deadline_at` is start time plus duration with no cap at
`window_end`. So a candidate who started just before the window closed could
still be sitting while someone who had finished read the answer key.
`Exam::answerReveal()` now reveals only once `NOW()` is past **both**
`window_end` **and** the latest `deadline_at` among all of that exam's attempts
plus the sweep's 5-minute grace. It is one SQL statement:
`GREATEST(window_end, COALESCE(MAX(deadline_at) + INTERVAL 5 MINUTE,
window_end))`. So an invigilator extending a deadline in future can never
reveal answers while that candidate is still sitting.
- **Every attempt counts, submitted or not.** An early finisher's own
  deadline can hold the answers back, by at most one exam's duration after the
  window closes.
- **The result page's wording** now reads: hidden "until the exam window has
  closed and every candidate has finished, not before" that time.

**Left out on purpose:**
- `LecturerController`'s "window end must be in the future" check (create
  exam, and publishing from the pool page). It still uses PHP's clock. It
  decides nothing about a student's result, and was left out at the owner's
  decision.
- The sign-in lockout in `Auth.php`. It is PHP on both sides, so it's
  consistent with itself.

**Database.php:21, `SET time_zone = '+01:00'`: kept, and it matters more
now.**
- Every decision compares a DATETIME with `NOW()`. A DATETIME has no time
  zone and means whatever `NOW()` meant when it was written; teachers type
  windows in Lagos time. So `NOW()` must be Lagos time on every server,
  whatever that server's own zone says.
- Added on 20 Jul 2026 (`b861c45`, "fix PHP/MySQL timezone alignment for
  server deadline"). No record names WhoGoHost's clock; the owner is checking
  that separately with a read-only phpMyAdmin query.
- A fixed offset, not `'Africa/Lagos'`, because named zones need MySQL's
  time-zone tables. Nigeria has no daylight saving.
- The comment on the line now says all this, and test C11 fails if the line
  goes.

**Column types:**
- Every column used in a decision is DATETIME: `deadline_at`, `started_at`,
  `submitted_at`, `closed_by_system_at`, `locked_at`, `last_seen_at`,
  `window_start`, `window_end`, the lock and event times, and `graded_at`.
- Only some `created_at` columns and `attempt_answers.updated_at` are
  TIMESTAMP. They are converted through the session zone, and decide nothing.
- **Laptop:** its system zone is Lagos, so the line changes nothing there.
- **A school server:** a wrong Windows time zone becomes harmless; only the
  actual time must be right.
- **WhoGoHost:** to be confirmed by the owner's query.

**L8's control, replaced rather than deleted:**
- **Before:** it proved the offset by comparing the page's countdown (then
  PHP's arithmetic) with the heartbeat's (the database's).
- **Why it had to change:** after 4b both come from the database, so they
  agree.
- **Now:** `tests/router.php` (test-only) sends the server's own PHP wall
  clock as `X-Test-Php-Now`, and the control requires it to be more than 11 h
  from the database's `NOW()`.
- **The old comparison** became a positive test, C2: the page and the
  heartbeat must agree within 2 s.

**Tests:** [clock_test.php](tests/clock_test.php) runs three servers on the
test database.
- **Servers:** 8090 has PHP and the database agreeing, 8089 has PHP 12 h
  behind (`config.clock_offset.php`), and 8088 has PHP 13 h ahead (the new
  `config.clock_offset_east.php`).
- **Why both offsets:** a PHP-clock decision fails on one or the other. With
  PHP behind, every stored time reads 12 h later: deadlines accept too late,
  and a window that opened an hour ago looks 11 h away, so starting is refused.
  With PHP ahead, everything reads 13 h earlier: papers close too early, and a
  window that closes in 3 s already looks closed. So between them, both
  window edges and the deadline are each caught.
- **How times are set:** by moving `deadline_at` or a window edge with
  `NOW() ± INTERVAL`, never by sleeping.

| # | Test | Break that must fail it |
|---|---|---|
| C1 | Control: each server's PHP clock is 0, −12 h or +13 h from the database's | Offset removed from the config (also L8's control) |
| C2 | Page countdown vs heartbeat within 2 s, and about an hour | `exam()` back to `strtotime() - time()` |
| C3 | The paper's `remaining` vs heartbeat within 2 s | `paper()` back to PHP |
| C4 | A save 3 s before the deadline: 200 and stored. 3 s after: `409 closed`, answer unchanged. `saved_at` is the database's time | The PHP check restored; the SQL deadline condition removed |
| C5 | `GET exam`: 3 s before, served; 3 s after, closed by the system | `exam()` in PHP; `closeIfExpired()` without its deadline condition |
| C6 | `POST paper`: the same | `paper()` in PHP |
| C7 | Submit 3 s before: `submitted`; 3 s after: `auto_submitted` | The status chosen with PHP's clock |
| C8 | Pause 3 s before: paused; 3 s after: `409`, no lock row, closed | The PHP check restored in `lock()` |
| C9 | Sweep: 5 min 3 s past, swept; 4 min 57 s past, left | The sweep's cutoff computed in PHP |
| C10 | Window, 3 s either side of each edge: the dashboard, the instructions page and `startExam` | Each of the three put back on PHP's clock |
| C11 | Session zone `+01:00`; `NOW()` is UTC plus one hour | `Database.php:21` removed |
| C13 | `StudentController` calls no `time()`, `strtotime()` or `date()`; the dashboard and attempt views use no `$now` | A `time()` call added |
| C14 | Answers: window closed but someone still sitting, hidden; 4 min 57 s after the last deadline, hidden; 5 min 3 s after, shown; window still open with every deadline long past, hidden | The old `window_end`-only rule; grace dropped; window ignored; decided in PHP |

**Results, 22 Sep 2026:**
- **clock_test.php:** 143 passed, 0 failed on each of PHP 8.0.30, 8.4.25 and
  8.5.10.
- **lock_test.php:** 173 passed with L8's new control, on all three versions.
- **Every other suite, on all three:** autosave 47, sweep 80, paper 53, lock
  race 17, schema 007 260, error 134, enrolments 120, all passing; node 55
  passing.
- **Vacuity:** 21 breaks, each applied alone and restored with its hash
  re-checked. All 21 were caught by the assertions they target. Most restore
  the exact pre-4b PHP-clock code, so the suite is shown to fail against what
  4b replaced.
- **K09 and K10 (window checks in PHP):** my first prediction of which
  assertion would fail was wrong, not the test. With PHP behind, the start
  edge dominates, so every start is refused, including the one that should be
  refused anyway. The failing assertions are the start edge on the behind
  server and the end edge on the ahead server.
- **Found while writing C14:** every attempt's deadline counts toward the
  reveal, submitted or not, as specified. A test that forgot this failed
  first, and was corrected.

**Hand checks (pending), in Edge:**
1. **The real app, normal clock:** the timer starts at about 30:00, the
   heartbeat's `remaining` matches it within 2 s, and F5 doesn't restart it.
2. **The deadline, for real:** the HANDCHECK attempt's deadline is moved 90 s
   out. The page counts down, a save before zero is **Saved**, and at 0:00 the
   page submits itself, closing as `auto_submitted`. **This also covers the
   open item "auto-submit at zero with the tab left open"** (not previously
   written into this file).
3. **A wrong PHP clock:** on test servers with PHP 12 h behind, then 13 h
   ahead. The timer starts right, a save before the moved deadline is
   **Saved**, and one after it is refused.

## Stage 5 — Client lock monitor and overlay · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Client lock monitor and overlay.** 3 s grace, blips, immediate triggers, DOM removed, `423`/`superseded` in the queue, heartbeat, old monitor deleted. The overlay has no code box yet.

Design detail: revision 2 §3 above.

Notes filed here:

- **Our own submit doesn't lock, client half** (`#264`) — "pressing 'Submit all and finish' and the timer's own submit both set `submitting` before `form.submit()`. A `visibilitychange` → hidden after that sends no lock. Test (node monitor): `submitting` set, then hidden → zero lock calls. Break: don't set `submitting` → one lock call."
- **Leftover labels** (`#583`) — "Attempt quiz" → **"Start exam"**; "Quiz navigation" (`aria-label` and heading, in [exam.php:107-108](app/views/student/exam.php#L107-L108) and [result.php:223-224](app/views/student/result.php#L223-L224)) → **"Questions"**; plus "Stage 5 will do a full pass over views, JS and marketing pages for 'quiz', 'lecturer', 'course' and 'semester', and list every on-screen hit before changing it."
- **A 423 keeps the answer in the retry queue** (`#917` B, filed `#1178`) — "a 423 on a save keeps the answer in the retry queue, and the queue flushes after unlock. Test: a choice changed during the 5 s window and an essay edit refused with 423 are both stored after unlock."
- **The paused gate** — see Part 5, "Added from review".

## Stage 6 — Invigilator screen, read only · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Invigilator screen, read only.** Controller, nav, live list, sweep in the constructor.

Design detail: revision 2 §4 above (the list, and clash 3).

Notes filed here:

- **Only `in_progress` attempts** (`#917` C, filed `#1178`) — "the invigilator screen lists only `in_progress` attempts. A paper closed while paused keeps its open lock row for the record but never appears as waiting to be unlocked."

## Stage 7 — Claim, code, unlock at the seat · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Claim, code, unlock at the seat.** Claim, session-held code, `unlock`, 5 tries, back to the gate.

Design detail: revision 2 §4 above (claim, code, at the seat).

Notes filed here:

- **A repeat lock never resets a claimed code** (`#264`) — "a lock request on an attempt that is already locked and claimed leaves `claimed_by`, `code_hash` and `failed_tries` untouched. Test: lock → claim → lock again → still one row, the same `code_hash`, and the invigilator's code still unlocks. Break: let the repeat lock reset the claim → the code check fails."
- **Every code path that can delete a user** (`#589` item 4, reported at the stage 3 go-ahead message):

  > | Path | Can it delete a staff member referenced by `attempt_locks` or `bulk_unlocks`? | If a RESTRICT foreign key fired |
  > |---|---|---|
  > | **Admin → Import batches → Delete batch**: [AdminController::deleteImportBatch](app/controllers/AdminController.php#L365) → [ImportBatch::deleteMembers](app/models/ImportBatch.php#L92-L149) | **No.** The DELETE requires `role = 'student'`, the batch id and no exam attempts ([ImportBatch.php:118-124](app/models/ImportBatch.php#L118-L124)). Staff are never selected. | **Handled.** The `PDOException` is caught, the whole batch rolls back, and the admin sees "could not be deleted and nothing was removed" ([ImportBatch.php:136-146](app/models/ImportBatch.php#L136-L146)). |
  > | **[tests/teardown_users_fixture.php](tests/teardown_users_fixture.php#L26)** → the same `deleteMembers` | No, same query. Test database only. | Handled the same way; the script prints the error and exits 1. |
  > | **[tests/enrollments_list_test.php:634](tests/enrollments_list_test.php#L634)**: raw `DELETE FROM users WHERE id = ? AND role = 'lecturer'` in its `finally` cleanup | **Yes, in principle.** It deletes the Teacher that test created. Today that Teacher never claims or unlocks a lock, so nothing references them. | **Unhandled.** Nothing catches inside the `finally`. If a future test gave that Teacher a lock or bulk-unlock row, the RESTRICT error would be fatal, and cleanup would stop after the subject was already dropped, leaving the Teacher behind. |
  > | Anything else | **None found.** There's no admin action that deletes a user (staff are suspended, [AdminController.php:540](app/controllers/AdminController.php#L540)), no `User::delete`, no foreign key that cascades into `users`, no migration that deletes users, and the hand-check script deletes attempts, exams and questions but never users. | — |
  >
  > Outside the app, deleting such a Teacher by hand in phpMyAdmin would be refused with MySQL error 1451. That's the intended protection.

## Stage 8 — Seat binding and `new_session` · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Seat binding and `new_session`.** Your answer to question 1 decides what the seat is.

Answered at `#127` item 1 and filed at `#142` item 1 (per-browser `exam_seat` cookie). Design detail: revision 2 §2, clash 1. The old PC's screen is `#142` item 5.

## Stage 9 — Bulk unlock · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Bulk unlock.** Selection, required reason, `bulk_unlocks`, student learns via heartbeat.

Design detail: revision 2 §4 (bulk unlock, clash 3), and `#142` item 3.

## Stage 10 — Paste logging and teacher history · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Paste logging and teacher history.** `paste_landed`, `bulk_insert`; pauses and events on the activity page; grading link; admin analytics.

Notes filed here:

- **Blocked-action logging, `typing_burst` and `value_jump`** (`#142`) — "blocked-action logging and both typing signals go in **stage 10**." The full design of `attempt_blocked_actions`, `typing_burst` and `value_jump`, with thresholds, false positives, tests and vacuity breaks, is in Part 3, Amendment 2.
- Teacher history and admin analytics detail: revision 2 §7 above.

## Stage 11 — Words and docs · **Not started**

Brief: NOT FOUND IN TRANSCRIPT.

Build-order row, revision 2 §9:

> **Words and docs.** Marketing, README, OFFLINE-DEPLOYMENT client checklist (change B) and §10.3.

Marketing and README targets, and the client-machine install subsection: revision 2 §7 above.

Notes filed here:

- **Never copy `database/dev/` to a school server** (from the hand-check script report):

  > **The localhost check can't tell your laptop from a school server.** A school server running XAMPP also talks to MySQL on localhost. The script also refuses to run from the web or against a test database, and its header says never to copy `database/dev/` onto a school server. We should also say that in the deployment doc in stage 11.

- **The missing Backups section and the dump command** (`#583`, restated in the stage 3 report):

  > Side finding for stage 11: migrations 001, 005 and 006 point to a "Backups" section in OFFLINE-DEPLOYMENT.md that doesn't exist. The dump command lives at [line 422](OFFLINE-DEPLOYMENT.md#L422), under "Running it day to day".

  > Also for stage 11: migrations 001, 005 and 006 point to an OFFLINE-DEPLOYMENT.md "Backups" section that doesn't exist, and the documented dump command there uses `>`, which on PowerShell writes UTF-16. Both should move to the `--result-file` form.

- **MariaDB version and `sql_mode`** (`#589`, filed as "Added to stage 11's deployment checklist"):

  > - Confirm the school server runs **MariaDB 10.4 or newer** (`SELECT VERSION();`). That's what enforces CHECK constraints and provides the JSON functions and generated columns the lock tables need. If the server has MySQL instead, record that too, because CHECK enforcement arrived at a different version there.
  > - Record the server's `sql_mode` (`SELECT @@GLOBAL.sql_mode;`) in the install notes.

- **Table collations and the table count** (from the stage 3 work, "Two things for stage 11, not fixed now"):

  > - The existing 14 tables still inherit their character set. An install that loads `schema_import.sql` into a database created with a latin1 default gets latin1 tables. `schema.sql` is safe because it creates its own utf8mb4 database.
  > - [OFFLINE-DEPLOYMENT.md §3](OFFLINE-DEPLOYMENT.md#L88-L108) says "Expect 14 tables". Once 007 lands there will be 18. Its command also uses `<`, which works in cmd but is a syntax error in PowerShell.

### Added 2026-09-21: running MySQL on a school server

From the dev-laptop work on starting and stopping MySQL safely. These are
deployment-checklist items, not application changes.

- **Install MySQL as a Windows service** on school servers. It gives a clean
  shutdown when the machine is powered off and starts automatically on boot,
  neither of which the XAMPP Control Panel provides.
- **Before relying on the Windows service on a school server, verify its
  shutdown logs no Aria errors, since mysqld started without console handles
  failed Aria checkpoints on the dev laptop.** On 21 Sep 2026 both
  Control-Panel-started instances failed their Aria checkpoint on a normal
  `mysqladmin shutdown` — `Error writing file 'aria_log_control' (Errcode: 9
  "Bad file descriptor")`, `Aria engine: checkpoint failed` — while instances
  started with real stdout/stderr file handles shut down clean every time. A
  service starts mysqld without a console, so it must be checked, not assumed.
- **Stop MySQL with the service, or with `mysqladmin -u root shutdown`. Never
  the Control Panel's Stop button**, which runs `killprocess.bat` on
  `mysqld.exe` — a force-kill.
- `bind-address=127.0.0.1` in `my.ini` unless the database must be reached from
  another machine.
- Set a **root password**, and update every application config that connects.
- `display_errors = Off` in `php.ini`.
- Confirm **MariaDB 10.4 or newer** (`SELECT VERSION();`) and **record the
  server's `sql_mode`** (`SELECT @@GLOBAL.sql_mode;`) in the install notes.
- **Check the existing tables' collations** before deploying: loading
  `schema_import.sql` into a database created with a latin1 default gets latin1
  tables.

### Added 2026-09-22: error logging on a server

- **`DISPLAY_ERRORS`** must be `false` or absent in the server's
  `config/config.php`. Only the developer's laptop sets it `true`.
- **The error log must be outside the web root.** The default,
  `logs/app-errors.log`, is outside it when the docroot is `public/`. WhoGoHost's
  subdomain docroot is `public/`, served through nginx, which ignores
  `.htaccess`. Anywhere the repository sits inside the web root, set
  `ERROR_LOG_FILE` in config to a path outside it.
- **Check it:** request the log's URL in a browser (for example
  `/logs/app-errors.log`, and the same path under the app's base URL). The
  answer must be 403 or 404, never the file.
- **The log directory must be writable** by the PHP user. If it isn't, lines go
  to PHP's own `error_log` instead, which is easy to miss. After the deploy,
  confirm that `logs/` (or the `ERROR_LOG_FILE` directory) exists and is
  writable, for example in cPanel's File Manager.
- **After step 4b, rewrite OFFLINE-DEPLOYMENT.md step 5 ("Make the two clocks
  agree").** Every decision now uses the database's clock, pinned to +01:00 by
  `Database.php`, so PHP's time zone only affects how dates are displayed. What
  matters is that the machine's actual time is right, and that config's
  display zone is `Africa/Lagos`. The step should say that instead of asking
  for the two clocks to be matched.
- **Hide server versions on school servers:** set `ServerTokens Prod` and
  `ServerSignature Off` in Apache's `httpd.conf`, and restart Apache. On the
  dev laptop, Apache's own 403 page (from the hand check on
  `logs/app-errors.log`) showed the Apache, OpenSSL and PHP versions. Afterwards
  a 403 or 404 from Apache should name no versions, and the response's `Server`
  header should read only `Apache`.

---

# Part 5: added from review

These are not in the transcript. They were supplied on 2026-09-16, when this
file was written, and are kept separate so the recovered plan stays exactly as
it was written.

## Stage 5

- **The paused gate hides the Retry button, and the paused message replaces the
  fullscreen text instead of sitting under it.**

  The transcript has the gate's paused wording (stage 4 brief, `#914`: "Your
  exam is paused. Raise your hand and wait for the invigilator. Your time is
  still running.") and the Retry button as a network-failure affordance (`#264`
  test G2, `#267`), but never says what happens to the Retry button or to the
  "Enter fullscreen" text when the gate is showing the paused message.

## Stage 11

- **The import command switched to `-e "source"`.**

  The transcript prescribes `--result-file` for the dump in the deployment doc,
  and separately identifies OFFLINE-DEPLOYMENT.md §3's use of `<` as "a syntax
  error in PowerShell", but never prescribes the `-e "source"` replacement for
  that documented import command. The `mysql --default-character-set=utf8mb4 -e
  "source <path>"` form appears in the transcript only as the way to apply
  migration 007 during stage 3 (`#589`), not as an edit to the deployment doc.

- **Check production's table collations before deploying.**

  The transcript records the cause ("An install that loads `schema_import.sql`
  into a database created with a latin1 default gets latin1 tables") but files
  no action for it. The check against the production server's existing table
  collations before deploying is added here.
