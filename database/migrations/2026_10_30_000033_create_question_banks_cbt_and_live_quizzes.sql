-- Update 42 (ADR 0042): question banks, CBT exams and live quizzes.
--
-- question_banks: a school's own questions for one subject ("Use of English",
--   "Physics"). practice = 1 lets learners practise from it on their own.
-- bank_passages: a passage, table or set of instructions that several questions
--   share (a comprehension passage in English, a data table in Biology).
-- bank_questions: one question with two to five options (A to E) and the index
--   of the correct one (0 = A). Retired questions stay for old attempts but are
--   never drawn again. times_seen / times_correct count submitted attempts, for
--   how hard each question turns out to be.
-- cbt_exams: a computer-based test the school sets: sections drawn from banks
--   (some compulsory, with choose_count others the candidate picks, as in the
--   UTME), a duration, an optional window and access code, who may sit it, how
--   many attempts, and when results and corrections are shown.
-- cbt_attempts: one sitting. paper holds the questions drawn for this candidate
--   and the order of their options; answers maps question id -> option index
--   (original order). deadline_at is the server's: answers after it are refused
--   and the attempt is submitted with what was saved. exam_id is NULL for a
--   practice test the learner set up.
-- live_quizzes / live_questions: quick quizzes a teacher runs live in class.
-- live_sessions: one run of a quiz, joined with a 6-digit PIN. questions is a
--   copy taken when the run starts, so editing the quiz never changes a game in
--   progress. version changes whenever what players see changes, so their
--   devices can ask "anything new?" cheaply.
-- live_players / live_answers: who joined (a member, or a guest with a
--   nickname in that browser only) and every answer with its time and points.

CREATE TABLE question_banks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    practice TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY uq_question_banks_tenant_id (tenant_id, id),
    UNIQUE KEY uq_question_banks_name (tenant_id, name),
    CONSTRAINT fk_question_banks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_question_banks_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bank_passages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bank_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_bank_passages_tenant_id (tenant_id, id),
    KEY ix_bank_passages_bank (tenant_id, bank_id),
    CONSTRAINT fk_bank_passages_bank FOREIGN KEY (tenant_id, bank_id) REFERENCES question_banks (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bank_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bank_id BIGINT UNSIGNED NOT NULL,
    passage_id BIGINT UNSIGNED NULL,
    prompt TEXT NOT NULL,
    options JSON NOT NULL,
    correct TINYINT UNSIGNED NOT NULL,
    explanation TEXT NULL,
    topic VARCHAR(80) NOT NULL DEFAULT '',
    difficulty TINYINT UNSIGNED NULL,
    times_seen INT UNSIGNED NOT NULL DEFAULT 0,
    times_correct INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    retired_at DATETIME NULL,
    UNIQUE KEY uq_bank_questions_tenant_id (tenant_id, id),
    KEY ix_bank_questions_bank (tenant_id, bank_id, retired_at),
    CONSTRAINT fk_bank_questions_bank FOREIGN KEY (tenant_id, bank_id) REFERENCES question_banks (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_bank_questions_passage FOREIGN KEY (passage_id) REFERENCES bank_passages (id) ON DELETE SET NULL,
    CONSTRAINT fk_bank_questions_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_bank_questions_correct CHECK (correct <= 4),
    CONSTRAINT ck_bank_questions_difficulty CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cbt_exams (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    instructions TEXT NULL,
    sections JSON NOT NULL,
    choose_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    audience VARCHAR(10) NOT NULL DEFAULT 'everyone',
    max_attempts TINYINT UNSIGNED NULL,
    results VARCHAR(12) NOT NULL DEFAULT 'immediately',
    review TINYINT(1) NOT NULL DEFAULT 1,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
    shuffle_options TINYINT(1) NOT NULL DEFAULT 1,
    access_code VARCHAR(12) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cbt_exams_tenant_id (tenant_id, id),
    KEY ix_cbt_exams_status (tenant_id, status),
    CONSTRAINT fk_cbt_exams_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_exams_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_cbt_exams_audience CHECK (audience IN ('everyone', 'groups')),
    CONSTRAINT ck_cbt_exams_results CHECK (results IN ('immediately', 'after_close', 'hidden')),
    CONSTRAINT ck_cbt_exams_status CHECK (status IN ('draft', 'published', 'closed')),
    CONSTRAINT ck_cbt_exams_duration CHECK (duration_minutes BETWEEN 1 AND 600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cbt_exam_groups (
    tenant_id BIGINT UNSIGNED NOT NULL,
    exam_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, exam_id, group_id),
    CONSTRAINT fk_cbt_exam_groups_exam FOREIGN KEY (tenant_id, exam_id) REFERENCES cbt_exams (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_exam_groups_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cbt_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    exam_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    paper JSON NOT NULL,
    answers JSON NOT NULL,
    flags JSON NOT NULL,
    question_count INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,
    extra_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    save_seq INT UNSIGNED NOT NULL DEFAULT 0,
    last_saved_at DATETIME NULL,
    submitted_at DATETIME NULL,
    submit_reason VARCHAR(10) NULL,
    result JSON NULL,
    correct_count INT UNSIGNED NULL,
    percent TINYINT UNSIGNED NULL,
    UNIQUE KEY uq_cbt_attempts_tenant_id (tenant_id, id),
    KEY ix_cbt_attempts_exam (tenant_id, exam_id, user_id),
    KEY ix_cbt_attempts_user (tenant_id, user_id, submitted_at),
    KEY ix_cbt_attempts_open (submitted_at, deadline_at),
    CONSTRAINT fk_cbt_attempts_exam FOREIGN KEY (tenant_id, exam_id) REFERENCES cbt_exams (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_cbt_attempts_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_cbt_attempts_reason CHECK (submit_reason IS NULL OR submit_reason IN ('candidate', 'time', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_quizzes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY uq_live_quizzes_tenant_id (tenant_id, id),
    CONSTRAINT fk_live_quizzes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_live_quizzes_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    quiz_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    prompt VARCHAR(500) NOT NULL,
    options JSON NOT NULL,
    correct TINYINT UNSIGNED NOT NULL,
    seconds SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    points VARCHAR(10) NOT NULL DEFAULT 'standard',
    UNIQUE KEY uq_live_questions_tenant_id (tenant_id, id),
    KEY ix_live_questions_quiz (tenant_id, quiz_id, position),
    CONSTRAINT fk_live_questions_quiz FOREIGN KEY (tenant_id, quiz_id) REFERENCES live_quizzes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_live_questions_correct CHECK (correct <= 3),
    CONSTRAINT ck_live_questions_seconds CHECK (seconds BETWEEN 5 AND 240),
    CONSTRAINT ck_live_questions_points CHECK (points IN ('standard', 'double', 'none'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    quiz_id BIGINT UNSIGNED NOT NULL,
    host_id BIGINT UNSIGNED NOT NULL,
    pin CHAR(6) NOT NULL,
    title VARCHAR(120) NOT NULL,
    questions JSON NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'lobby',
    position INT UNSIGNED NULL,
    question_started_at DATETIME(3) NULL,
    question_ends_at DATETIME(3) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    allow_guests TINYINT(1) NOT NULL DEFAULT 1,
    fun_names TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    UNIQUE KEY uq_live_sessions_tenant_id (tenant_id, id),
    KEY ix_live_sessions_pin (tenant_id, pin, status),
    KEY ix_live_sessions_quiz (tenant_id, quiz_id),
    CONSTRAINT fk_live_sessions_quiz FOREIGN KEY (tenant_id, quiz_id) REFERENCES live_quizzes (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_live_sessions_host FOREIGN KEY (host_id) REFERENCES users (id),
    CONSTRAINT ck_live_sessions_status CHECK (status IN ('lobby', 'question', 'reveal', 'scores', 'finished'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_players (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    nickname VARCHAR(24) NOT NULL,
    score INT UNSIGNED NOT NULL DEFAULT 0,
    streak SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    correct_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    seen_position INT UNSIGNED NULL,
    seen_at DATETIME(3) NULL,
    joined_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    removed_at DATETIME NULL,
    UNIQUE KEY uq_live_players_tenant_id (tenant_id, id),
    UNIQUE KEY uq_live_players_nickname (session_id, nickname),
    UNIQUE KEY uq_live_players_user (session_id, user_id),
    CONSTRAINT fk_live_players_session FOREIGN KEY (tenant_id, session_id) REFERENCES live_sessions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_live_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE live_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    choice TINYINT UNSIGNED NOT NULL,
    correct TINYINT(1) NOT NULL,
    points INT UNSIGNED NOT NULL,
    ms INT UNSIGNED NOT NULL,
    answered_at DATETIME(3) NOT NULL,
    UNIQUE KEY uq_live_answers_player_position (player_id, position),
    KEY ix_live_answers_session (tenant_id, session_id, position),
    CONSTRAINT fk_live_answers_session FOREIGN KEY (tenant_id, session_id) REFERENCES live_sessions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_live_answers_player FOREIGN KEY (player_id) REFERENCES live_players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
