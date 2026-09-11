# Project Guidelines

## Images
- Use Unsplash MCP for all photos — never use placeholder/gradient blocks
- Prefer landscape orientation for hero sections

## Components
- Use shadcn components for UI elements (buttons, forms, cards) before writing custom ones
- Use 21st.dev for more complex/pre-styled sections when relevant

## Style
- Follow frontend-design skill guidance for spacing, typography, and visual hierarchy

## Git Workflow
- After completing any major feature or fix, commit and push to GitHub automatically
- Use clear, descriptive commit messages
- Confirm with me before pushing if changes touch .env, config, or database files


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