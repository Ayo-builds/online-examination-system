-- ============================================================================
-- 002. users.email means "staff login address", and nothing else
--
-- Migration 001 stopped resolving student emails at sign-in, but left the
-- addresses in place. That was the worst of both worlds: the column still
-- showed an address for every student, so the admin users list read as though
-- email were still a student credential, while the login path had already
-- stopped honouring it. Clearing them makes the column mean one thing.
--
--   mysql -u root exam_system < database/migrations/002_clear_student_emails.sql
--
-- This DELETES data. The addresses exist only in whatever dump was taken
-- beforehand; there is no way to recover them from the schema. Take one.
-- Requires migration 001 to have been applied first.
-- ============================================================================

UPDATE users
   SET email = NULL
 WHERE role = 'student'
   AND email IS NOT NULL;

-- The CHECK from 001 is unaffected and still holds: it requires a student to
-- carry an admission number, and says nothing about a student's email. Staff
-- rows are untouched, so their arm of the constraint (email IS NOT NULL) is
-- equally undisturbed.
--
-- Note what this does NOT do: the admin create-user form still accepts an
-- optional email for a student, so the column can be repopulated one student
-- at a time. Closing that needs a form change and a tighter CHECK, which is a
-- separate decision, not a data fix.
