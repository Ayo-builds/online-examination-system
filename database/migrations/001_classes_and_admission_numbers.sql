-- ============================================================================
-- 001. Classes, admission numbers, and a student-shaped login identity
--
-- Students belong to a class (JSS1-SS3, each with an arm) and sign in with an
-- admission number. Staff keep signing in with an email. Run once, in order,
-- against an existing exam_system database:
--
--   mysql -u root exam_system < database/migrations/001_classes_and_admission_numbers.sql
--
-- MySQL does not roll DDL back, so take a dump first (OFFLINE-DEPLOYMENT.md,
-- "Backups"). Fresh installs get all of this from database/schema.sql instead
-- and must NOT run this file.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Classes. year_group + arm is the natural key, so it carries the UNIQUE.
--
-- 'UNASSIGNED' is a placeholder year group, not a real one. Students who
-- predate this migration land there so that every existing row has a class
-- without anyone inventing a year group on their behalf; an admin reassigns
-- them and the row can then be deleted. It sorts last because ENUM orders by
-- declaration, so real classes stay at the top of every list.
--
-- arm is NOT NULL with a '' default rather than nullable: MySQL lets a UNIQUE
-- key hold unlimited NULLs, so a nullable arm would allow SS3 to be created
-- over and over. A single-stream school uses '' and gets the constraint.
-- ---------------------------------------------------------------------------
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_group ENUM('JSS1','JSS2','JSS3','SS1','SS2','SS3','UNASSIGNED') NOT NULL,
    arm VARCHAR(5) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_year_group_arm (year_group, arm)
);

-- Arms A-C for each year group: enough for the admin form to be usable on day
-- one. There is no class-management screen yet, so further arms are added here.
INSERT INTO classes (year_group, arm) VALUES
    ('JSS1','A'), ('JSS1','B'), ('JSS1','C'),
    ('JSS2','A'), ('JSS2','B'), ('JSS2','C'),
    ('JSS3','A'), ('JSS3','B'), ('JSS3','C'),
    ('SS1','A'),  ('SS1','B'),  ('SS1','C'),
    ('SS2','A'),  ('SS2','B'),  ('SS2','C'),
    ('SS3','A'),  ('SS3','B'),  ('SS3','C'),
    ('UNASSIGNED','');

-- ---------------------------------------------------------------------------
-- 2. The two new columns on users.
--
-- admission_no is UNIQUE and nullable. Staff rows keep it NULL, and MySQL
-- allows any number of NULLs in a UNIQUE index, so uniqueness binds exactly
-- where it should: across students.
--
-- class_id is nullable and ON DELETE SET NULL. Retiring a class must not
-- delete the students who sat in it.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN admission_no VARCHAR(30) NULL AFTER email,
    ADD COLUMN class_id INT NULL AFTER admission_no,
    ADD UNIQUE KEY uniq_admission_no (admission_no),
    ADD CONSTRAINT fk_users_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 3. Email becomes optional at the column level. The existing UNIQUE index
--    survives and, as above, tolerates many NULLs. Which roles actually
--    require an email is enforced in step 5.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    MODIFY COLUMN email VARCHAR(150) NULL;

-- ---------------------------------------------------------------------------
-- 4. Backfill. Every existing student gets a generated admission number in the
--    ADM/<intake year>/<id> form and the placeholder class. Derived from id, so
--    it is unique by construction and the UNIQUE index cannot trip here.
-- ---------------------------------------------------------------------------
UPDATE users
   SET admission_no = CONCAT('ADM/', YEAR(created_at), '/', LPAD(id, 4, '0')),
       class_id     = (SELECT id FROM classes WHERE year_group = 'UNASSIGNED' AND arm = '')
 WHERE role = 'student'
   AND admission_no IS NULL;

-- ---------------------------------------------------------------------------
-- 5. One credential per person, enforced at the schema layer as well as the
--    application layer: students carry an admission number, staff carry an
--    email and never an admission number. Added after the backfill, or it
--    would reject the very rows it exists to protect.
--
--    MySQL 8.0.16+ and MariaDB 10.2+ enforce this. MySQL 5.7 parses and ignores
--    CHECK constraints, so on 5.7 the application-layer validation in
--    AdminController is the only guard. That is a reason to be on 8.0, not a
--    reason to omit the constraint.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD CONSTRAINT chk_users_login_identifier CHECK (
        (role =  'student' AND admission_no IS NOT NULL)
     OR (role <> 'student' AND admission_no IS NULL AND email IS NOT NULL)
    );

-- ---------------------------------------------------------------------------
-- 6. The lockout table no longer keys on an email. Renaming the column rather
--    than adding a second one keeps it a single primary key, so a given
--    identifier has exactly one throttle row and cannot be locked out under
--    one spelling while free under another. Existing rows carry over.
--
--    The column collation stays utf8mb4_unicode_ci (case-insensitive), which
--    matches how Auth resolves both identifier kinds.
-- ---------------------------------------------------------------------------
ALTER TABLE login_attempts
    CHANGE COLUMN email identifier VARCHAR(150) NOT NULL;
