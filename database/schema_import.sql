

-- ============ PEOPLE & ACCESS ============
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- ORDER OF THIS ENUM IS LOAD-BEARING. There is no rank column on this
    -- table; MySQL sorts an ENUM by the ordinal of its declaration, and the
    -- admin users list relies on that for its class ordering - it is what
    -- makes JSS3 sort before SS1 instead of alphabetically.
    --
    -- Adding a value at the END is safe. Reordering these, or inserting one
    -- in the middle, silently changes how every class-sorted list reads and
    -- rewrites the stored ordinals of existing rows. To add a year group in
    -- the middle, add it in the right position deliberately and re-check
    -- UserListQuery::SORTS['class'] and its DEFAULT_ORDER.
    year_group ENUM('JSS1','JSS2','JSS3','SS1','SS2','SS3','UNASSIGNED') NOT NULL,
    arm VARCHAR(5) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_year_group_arm (year_group, arm)
);

INSERT INTO classes (year_group, arm) VALUES
    ('JSS1','A'), ('JSS1','B'), ('JSS1','C'),
    ('JSS2','A'), ('JSS2','B'), ('JSS2','C'),
    ('JSS3','A'), ('JSS3','B'), ('JSS3','C'),
    ('SS1','A'),  ('SS1','B'),  ('SS1','C'),
    ('SS2','A'),  ('SS2','B'),  ('SS2','C'),
    ('SS3','A'),  ('SS3','B'),  ('SS3','C'),
    ('UNASSIGNED','');

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL UNIQUE,
    admission_no VARCHAR(30) NULL UNIQUE,
    class_id INT NULL,
    import_batch_id CHAR(32) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','lecturer','student') NOT NULL,
    status ENUM('active','suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    CONSTRAINT chk_users_login_identifier CHECK (
        (role =  'student' AND admission_no IS NOT NULL AND email IS NULL)
     OR (role <> 'student' AND admission_no IS NULL AND email IS NOT NULL)
    )
);

-- Each bulk student import, recorded so it can be undone. The row outlives the
-- accounts it created: after a batch is deleted the record stays, showing what
-- was imported, from which file and by whom.
CREATE TABLE import_batches (
    id CHAR(32) NOT NULL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    row_count INT NOT NULL,
    imported_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_batches_user FOREIGN KEY (imported_by)
        REFERENCES users(id) ON DELETE SET NULL
);

-- ON DELETE SET NULL, not CASCADE: deleting the batch record must never delete
-- the people it created. That decision belongs to the delete-batch action,
-- which refuses accounts that have already sat an exam.
ALTER TABLE users
    ADD INDEX idx_users_import_batch (import_batch_id),
    ADD CONSTRAINT fk_users_import_batch FOREIGN KEY (import_batch_id)
        REFERENCES import_batches(id) ON DELETE SET NULL;

CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    lecturer_id INT NOT NULL,
    FOREIGN KEY (lecturer_id) REFERENCES users(id)
);

CREATE TABLE enrollments (
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    PRIMARY KEY (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

-- ============ QUESTION BANK ============
CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    question_type ENUM('mcq','essay') NOT NULL,
    question_text TEXT NOT NULL,
    marks DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    is_correct TINYINT(1) DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- ============ EXAMS ============
CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    instructions TEXT,
    duration_minutes INT NOT NULL,
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    questions_per_attempt INT NOT NULL,
    shuffle_options TINYINT(1) DEFAULT 1,
    pass_mark DECIMAL(5,2) DEFAULT 50.00,
    status ENUM('draft','published','closed') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE exam_question_pool (
    exam_id INT NOT NULL,
    question_id INT NOT NULL,
    PRIMARY KEY (exam_id, question_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- ============ ATTEMPTS ============
CREATE TABLE exam_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    status ENUM('in_progress','submitted','auto_submitted','invalidated') DEFAULT 'in_progress',
    total_score DECIMAL(6,2) NULL,
    grading_status ENUM('pending','partial','complete') DEFAULT 'pending',
    is_flagged TINYINT(1) DEFAULT 0,
    -- Answers the browser still had unsaved at submit time. Client-reported,
    -- inert, and only ever written on a real submission. See migration 005.
    unsaved_at_submit INT NOT NULL DEFAULT 0,
    UNIQUE KEY one_attempt (exam_id, student_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id),
    FOREIGN KEY (student_id) REFERENCES users(id)
);

CREATE TABLE attempt_questions (
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    display_order INT NOT NULL,
    option_order JSON NULL,
    PRIMARY KEY (attempt_id, question_id),
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id)
);

CREATE TABLE attempt_answers (
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_option_id INT NULL,
    essay_text MEDIUMTEXT NULL,
    awarded_marks DECIMAL(5,2) NULL,
    graded_by INT NULL,
    graded_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id, question_id),
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE
);

-- ============ ANTI-CHEAT ============
CREATE TABLE activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    event_type ENUM('tab_switch','fullscreen_exit','window_blur','copy','paste',
                    'right_click','late_submit','multiple_session','heartbeat_gap') NOT NULL,
    event_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt (attempt_id),
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE
);



CREATE TABLE login_attempts (
    identifier VARCHAR(150) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (identifier)
);