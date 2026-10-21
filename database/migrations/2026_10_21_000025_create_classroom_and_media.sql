-- Update 29 (ADR 0029): the DML virtual classroom (self-hosted Jitsi) and
-- video/audio uploads.
--
-- classroom_settings: one per school. The Jitsi server's address, the app id
--   and the JWT secret (sealed with APP_KEY, never shown again) used to sign
--   short-lived passes into a class's room, and whether rooms start in low-data mode.
-- class_sessions: "classroom" is a new place a class can happen; room_name is
--   unpredictable and fixed per class; recording_file_id points at an uploaded
--   recording.
-- class_events: joins, leaves and once-a-minute "still here" signals from the
--   classroom page, to work out time in class even when a phone just drops off.
-- files.class_session_id: materials and recordings belong to one class.
-- lessons.media_file_id: an uploaded video or audio file that is the lesson's
--   main media; "audio" is a new lesson type.

CREATE TABLE classroom_settings (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    domain VARCHAR(190) NOT NULL,
    app_id VARCHAR(100) NOT NULL,
    secret_sealed TEXT NOT NULL,
    low_data TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_classroom_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_classroom_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE class_sessions DROP CONSTRAINT ck_class_sessions_mode;
ALTER TABLE class_sessions
    ADD COLUMN room_name VARCHAR(80) NULL AFTER join_url,
    ADD COLUMN recording_file_id BIGINT UNSIGNED NULL AFTER recording_url,
    ADD CONSTRAINT ck_class_sessions_mode CHECK (mode IN ('online', 'room', 'both', 'classroom'));

CREATE TABLE class_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_class_events_session (tenant_id, session_id, user_id, created_at),
    CONSTRAINT fk_class_events_session FOREIGN KEY (tenant_id, session_id) REFERENCES class_sessions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_class_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_class_events_type CHECK (type IN ('join', 'here', 'leave'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE files
    ADD COLUMN class_session_id BIGINT UNSIGNED NULL AFTER submission_id,
    ADD KEY ix_files_class_session (tenant_id, class_session_id),
    ADD CONSTRAINT fk_files_class_session FOREIGN KEY (tenant_id, class_session_id) REFERENCES class_sessions (tenant_id, id) ON DELETE CASCADE;

-- media_file_id and recording_file_id have no foreign key: a file belongs to its
-- lesson or class (files.lesson_id / class_session_id, cascading), and the code
-- clears these pointers when the file is removed.
ALTER TABLE lessons DROP CONSTRAINT ck_lessons_type;
ALTER TABLE lessons
    ADD COLUMN media_file_id BIGINT UNSIGNED NULL AFTER media_url,
    ADD CONSTRAINT ck_lessons_type CHECK (type IN ('text', 'quiz', 'video', 'audio', 'assignment', 'lab'));
