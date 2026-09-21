# Project Guidelines

## Stack
- Vanilla PHP, MySQL and plain JavaScript. No frameworks, no build step,
  no npm packages in the app.
- The product runs on a school LAN with no internet. Every font, icon,
  script, stylesheet and image the app uses must be served from this
  repo. No CDNs, no Google Fonts, no hotlinked images.

## Words users see
- The code and the database keep their original names. Only the words on
  screen changed. Use the label in everything a user reads: page titles,
  headings, nav, buttons, flash and error messages, help text, marketing
  pages.

  | In code and data | Users see |
  |---|---|
  | role `lecturer` (`users.role`, `LecturerController`, `/lecturer/...`) | Teacher, Teachers |
  | `course`, `courses`, `course_code` | Subject, Subjects, Subject code |
  | semester | Term |
  | `window_start`, `window_end` | Not renamed yet. Teacher form: "Window opens" / "Window closes". Students: "Opens" / "Closes". `window_end` also decides when students see correct answers (StudentController::result), so it is more than a start deadline; decide that before relabelling it. |

- Render a stored role with `role_label()` in app/core/helpers.php, never the
  raw value.
- Never rename a table, column, role value, class, variable, route, URL or
  form field to match a label.

## Git Workflow
- Commit and push to main at every verified checkpoint.
- Before each push: list the commits being pushed, confirm no secrets, dumps,
  .sql files, .env or credentials are in them, and confirm nothing
  auto-deploys.
- Pushing is not deploying. Never deploy to production (no git pull on the
  server) without my explicit approval. The current hold stands: no deploy
  until stage 5 is done and the deployment checklist is followed.
- Use clear, descriptive commit messages

## The anti-cheat plan
- The plan lives in docs/anti-cheat-plan.md and must be updated in the same
  commit as any change to the plan.

## Tests and data safety
- Never point config/config.php at a test database; use config/config.test.php.
- Never run DELETE FROM users.
- Before any schema change, take a dump first and confirm with me before running
  the migration.
- Tests must be checked for vacuity: break the thing under test and confirm the
  assertion fails.

## Background processes
- Stop any `php -S` server or other background process you start before the
  task ends, and check afterwards that its port is free. Never leave one
  running: a forgotten `php -S` on 8099 answered tests/autosave_test.php in
  place of its own server from 9 to 11 Sep 2026.
