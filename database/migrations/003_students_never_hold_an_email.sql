-- ============================================================================
-- 003. Students never hold an email address
--
-- 002 cleared the addresses; this stops them coming back. Without it the admin
-- create-user form could repopulate the column one student at a time, and
-- users.email would drift back to meaning two different things.
--
--   mysql -u root exam_system < database/migrations/003_students_never_hold_an_email.sql
--
-- Requires 001 and 002. Run 002 first or this will fail on the existing rows,
-- which is the correct outcome: the constraint should refuse to be added while
-- data violates it, rather than being added and quietly unenforced.
-- ============================================================================

-- Safety check. If this returns anything other than 0, STOP and run 002:
-- adding the constraint below would fail against those rows.
SELECT COUNT(*) AS students_still_holding_an_email
  FROM users
 WHERE role = 'student' AND email IS NOT NULL;

-- MariaDB 10.2+ and MySQL 8.0.19+ spell this DROP CONSTRAINT. On MySQL
-- 8.0.16-8.0.18 use: ALTER TABLE users DROP CHECK chk_users_login_identifier;
ALTER TABLE users
    DROP CONSTRAINT chk_users_login_identifier;

-- The tightened rule. The only change from 001 is the student arm, which now
-- also requires email IS NULL, so each role has exactly one identifier column
-- populated and the other empty.
ALTER TABLE users
    ADD CONSTRAINT chk_users_login_identifier CHECK (
        (role =  'student' AND admission_no IS NOT NULL AND email IS NULL)
     OR (role <> 'student' AND admission_no IS NULL AND email IS NOT NULL)
    );
