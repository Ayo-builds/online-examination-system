-- ============================================================================
-- 006. Record when the system, not the candidate, closed an attempt
--
-- An attempt used to leave 'in_progress' only through its own student: by
-- submitting, or by reopening the paper after the deadline. A student who
-- never came back - a power cut, a walk-out - left it in_progress for good:
-- never graded, missing from the grading queue and from every analytics query,
-- and counted as a live attempt forever. There is no cron on a school LAN, so
-- staff page loads now close those (Attempt::sweepAbandoned).
--
--   mysql -u root exam_system < database/migrations/006_closed_by_system.sql
--
-- MySQL does not roll DDL back, so take a dump first (OFFLINE-DEPLOYMENT.md,
-- "Backups"). Fresh installs get this from database/schema.sql instead and
-- must NOT run this file.
-- ============================================================================

-- NULL: a submission arrived from the candidate's browser.
-- Set: none did. The deadline passed and the server closed the paper at this
-- time - either the sweep, or the candidate reopening the paper too late.
--
-- The status stays 'auto_submitted'. Every grading and analytics query filters
-- on status IN ('submitted','auto_submitted'); a new status value would have to
-- be added to each of them, and missing one would hide these papers again,
-- which is the failure this exists to fix.
--
-- The lecturer needs to see it. On a paper nobody submitted, unsaved_at_submit
-- = 0 means UNKNOWN, not none: no browser was there to report a count. Without
-- this column such a paper is indistinguishable from a clean submission by a
-- candidate who chose to leave questions blank.
--
-- No backfill. Past auto-submits cannot be told apart after the fact: the
-- browser's timer and a late return both wrote 'auto_submitted' and nothing
-- else. Existing rows stay NULL.
ALTER TABLE exam_attempts
    ADD COLUMN closed_by_system_at DATETIME NULL AFTER unsaved_at_submit,
    -- The sweep's own query, run on every staff page load.
    ADD INDEX idx_attempts_sweep (status, deadline_at);
