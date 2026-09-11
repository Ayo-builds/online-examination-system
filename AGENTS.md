# Project Guidelines

## Stack
- Vanilla PHP, MySQL and plain JavaScript. No frameworks, no build step,
  no npm packages in the app.
- The product runs on a school LAN with no internet. Every font, icon,
  script, stylesheet and image the app uses must be served from this
  repo. No CDNs, no Google Fonts, no hotlinked images.

## Git Workflow
- Commit at each verified checkpoint. Never push until I say so.
- Use clear, descriptive commit messages

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
