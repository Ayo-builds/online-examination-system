-- ============================================================================
-- 004. Import batches, so a bulk import can be undone
--
-- A CSV import creates hundreds of accounts in one click. Until now, undoing a
-- wrong file meant working out by hand which rows were new and deleting them
-- one at a time. This records each import as a batch and stamps every account
-- it created, so the set is knowable afterwards.
--
--   mysql -u root exam_system < database/migrations/004_import_batches.sql
--
-- Requires 001. Safe to run on a database with existing students: they simply
-- have no batch, which is the correct answer for an account made by hand.
-- ============================================================================

-- The batch is its own row rather than just an id on users, because it has to
-- outlive its accounts. After a batch is deleted the record stays, showing
-- what was imported, from which file and by whom - which is exactly what
-- someone asks for after a mistaken import.
CREATE TABLE import_batches (
    id CHAR(32) NOT NULL PRIMARY KEY,          -- bin2hex(random_bytes(16))
    filename VARCHAR(255) NOT NULL,
    row_count INT NOT NULL,
    imported_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_batches_user FOREIGN KEY (imported_by)
        REFERENCES users(id) ON DELETE SET NULL
);

-- Nullable: an account created through the create-user form belongs to no
-- batch, and that is not a defect to be backfilled.
--
-- ON DELETE SET NULL, not CASCADE. Deleting the batch record must never delete
-- the people it created - that decision belongs to the delete-batch action,
-- which refuses accounts that have already sat an exam. A CASCADE here would
-- quietly route around that refusal.
ALTER TABLE users
    ADD COLUMN import_batch_id CHAR(32) NULL AFTER class_id,
    ADD INDEX idx_users_import_batch (import_batch_id),
    ADD CONSTRAINT fk_users_import_batch FOREIGN KEY (import_batch_id)
        REFERENCES import_batches(id) ON DELETE SET NULL;
