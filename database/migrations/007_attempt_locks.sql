-- ============================================================================
-- 007. Pause an attempt instead of flagging it
--
-- The old monitor counted tab switches, blurs and fullscreen exits and set
-- exam_attempts.is_flagged once they passed a threshold, for a teacher to read
-- afterwards. Nothing happened to the student at the time. The replacement
-- pauses the attempt the moment the candidate leaves the paper, keeps the timer
-- running, and only an invigilator at the seat can unlock it with a one-time
-- code. This migration adds only the storage for that; no code reads or writes
-- it yet.
--
--   mysql --default-character-set=utf8mb4 -u root exam_system
--         -e "source database/migrations/007_attempt_locks.sql"
--
-- On Windows, run it with -e "source ..." as above and not with PowerShell's
-- "<", and take the dump with mysqldump --result-file, not ">": PowerShell
-- rewrites redirected text as UTF-16. MySQL does not roll DDL back, so take
-- the dump first (OFFLINE-DEPLOYMENT.md, "Running it day to day"). Fresh
-- installs get this from database/schema.sql instead and must NOT run this
-- file.
--
-- Additive only. Nothing is dropped, renamed or backfilled:
--   - is_flagged and activity_logs stay readable, so past flags and timelines
--     still show on the Teacher's pages. Nothing writes them after this.
--   - Every new column on exam_attempts is NULL on every existing row, which
--     is the truth for them: no existing attempt was ever paused.
--
-- Two decisions that are easy to undo by accident:
--
--   Every ENUM is repeated as a CHECK. This server runs without strict mode,
--   where an ENUM silently stores '' for a value it does not list. MariaDB
--   enforces CHECK in any mode, so a mistyped trigger is an error, not a blank.
--
--   At most one open lock per attempt is enforced here, not only in PHP.
--   attempt_locks.open_attempt_id holds attempt_id while the lock is open and
--   NULL once it is unlocked, and it is UNIQUE. Two lock requests arriving
--   together can then never leave two open locks, whatever the code does.
-- ============================================================================

-- locked_at: when the current pause began; NULL while not paused. Kept on the
-- attempt so every request that reads the attempt sees it without a join.
-- seat_hash: SHA-256 of the seat cookie set when the attempt started. A
-- different browser opening the paper is what locks with new_session.
-- last_seen_at: the last heartbeat. A long gap is logged, never locked on.
ALTER TABLE exam_attempts
    ADD COLUMN locked_at DATETIME NULL AFTER closed_by_system_at,
    ADD COLUMN seat_hash CHAR(64) NULL AFTER locked_at,
    ADD COLUMN last_seen_at DATETIME NULL AFTER seat_hash;

-- ============ LOCKS ============
-- The pause-and-unlock record that replaced the flag threshold.
--
-- This server may not run in strict mode, and outside strict mode an ENUM
-- quietly stores '' for a value it does not list. Every ENUM below is repeated
-- as a CHECK, which MariaDB enforces in any mode, so a bad value is an error.
--
-- References to staff carry no ON DELETE action, which is RESTRICT: the record
-- of who unlocked a paper must not vanish with the account. Staff are
-- suspended, not deleted.
--
-- Each table names its engine and character set instead of inheriting them.
-- The tables above take theirs from CREATE DATABASE, but a migration runs
-- against whatever database already exists, and on a server that defaults to
-- latin1 a bulk-unlock reason typed with ẹ or ọ would be stored as question
-- marks.

-- One bulk unlock from the invigilator screen. Each lock it released points
-- here.
CREATE TABLE bulk_unlocks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    unlocked_by INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    lock_count INT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_bulk_unlocks_user FOREIGN KEY (unlocked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One pause of one attempt, from the trigger that caused it to the unlock.
-- detail is never NULL: it is created holding {"triggers":[first]}, and each
-- later trigger is appended to that array.
CREATE TABLE attempt_locks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    trigger_type ENUM('window_blur','tab_hidden','fullscreen_exit','new_session') NOT NULL,
    detail JSON NOT NULL,
    locked_at DATETIME NOT NULL,
    claimed_by INT NULL,
    claimed_at DATETIME NULL,
    code_hash VARCHAR(255) NULL,
    failed_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
    unlocked_at DATETIME NULL,
    unlocked_by INT NULL,
    unlock_method ENUM('code','bulk') NULL,
    bulk_unlock_id BIGINT NULL,
    -- The attempt while this lock is open, NULL once it is unlocked. Unique, so
    -- the database itself refuses a second open lock on one attempt, while any
    -- number of closed ones can sit alongside. VIRTUAL rather than stored:
    -- MySQL forbids a cascading foreign key on the base column of a stored
    -- generated column, and attempt_id cascades.
    open_attempt_id INT AS (IF(unlocked_at IS NULL, attempt_id, NULL)) VIRTUAL,
    UNIQUE KEY uq_attempt_locks_one_open (open_attempt_id),
    INDEX idx_attempt_locks_attempt (attempt_id, unlocked_at),
    INDEX idx_attempt_locks_open (unlocked_at),
    CONSTRAINT fk_attempt_locks_attempt FOREIGN KEY (attempt_id)
        REFERENCES exam_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_locks_claimed_by FOREIGN KEY (claimed_by) REFERENCES users(id),
    CONSTRAINT fk_attempt_locks_unlocked_by FOREIGN KEY (unlocked_by) REFERENCES users(id),
    CONSTRAINT fk_attempt_locks_bulk FOREIGN KEY (bulk_unlock_id) REFERENCES bulk_unlocks(id),
    CONSTRAINT chk_attempt_locks_trigger
        CHECK (trigger_type IN ('window_blur','tab_hidden','fullscreen_exit','new_session')),
    CONSTRAINT chk_attempt_locks_method
        CHECK (unlock_method IS NULL OR unlock_method IN ('code','bulk'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything worth knowing that is not a lock: short blurs, heartbeat gaps,
-- pastes that got through, typing bursts, and each staff action on a lock.
CREATE TABLE attempt_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    lock_id BIGINT NULL,
    event_type ENUM('blur_blip','heartbeat_gap','paste_landed','bulk_insert','typing_burst',
                    'value_jump','claim','reclaim','code_failed','code_exhausted','superseded') NOT NULL,
    actor_id INT NULL,
    detail JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_attempt_events_attempt (attempt_id, created_at),
    CONSTRAINT fk_attempt_events_attempt FOREIGN KEY (attempt_id)
        REFERENCES exam_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_events_lock FOREIGN KEY (lock_id)
        REFERENCES attempt_locks(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_events_actor FOREIGN KEY (actor_id) REFERENCES users(id),
    CONSTRAINT chk_attempt_events_type
        CHECK (event_type IN ('blur_blip','heartbeat_gap','paste_landed','bulk_insert','typing_burst',
                              'value_jump','claim','reclaim','code_failed','code_exhausted','superseded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocked copy, paste and the rest, counted rather than listed: twenty presses
-- of Ctrl+V are one row with count 20.
CREATE TABLE attempt_blocked_actions (
    attempt_id INT NOT NULL,
    action ENUM('copy','cut','paste','drop','drag','context_menu','print','save','replace') NOT NULL,
    route ENUM('keyboard','mouse','other') NOT NULL,
    count INT UNSIGNED NOT NULL,
    first_at DATETIME NOT NULL,
    last_at DATETIME NOT NULL,
    PRIMARY KEY (attempt_id, action, route),
    CONSTRAINT fk_blocked_actions_attempt FOREIGN KEY (attempt_id)
        REFERENCES exam_attempts(id) ON DELETE CASCADE,
    CONSTRAINT chk_blocked_actions_action
        CHECK (action IN ('copy','cut','paste','drop','drag','context_menu','print','save','replace')),
    CONSTRAINT chk_blocked_actions_route
        CHECK (route IN ('keyboard','mouse','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
