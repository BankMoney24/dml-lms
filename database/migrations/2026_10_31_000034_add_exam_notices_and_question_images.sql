-- Update 44 (ADR 0044): exam results are announced to candidates once, and a
-- bank question can carry one picture (a diagram, graph, map or circuit).

ALTER TABLE cbt_exams
    ADD COLUMN results_announced_at DATETIME NULL AFTER status;

-- The picture is a stored file owned by its question (deleted with it) and read
-- only by teachers and by candidates whose paper has the question.
ALTER TABLE files
    ADD COLUMN bank_question_id BIGINT UNSIGNED NULL AFTER class_session_id,
    ADD KEY ix_files_bank_question (tenant_id, bank_question_id),
    ADD CONSTRAINT fk_files_bank_question FOREIGN KEY (tenant_id, bank_question_id) REFERENCES bank_questions (tenant_id, id) ON DELETE CASCADE;

-- image_file_id has no foreign key, like lessons.media_file_id: the file belongs
-- to the question through files.bank_question_id, and the code clears the pointer.
ALTER TABLE bank_questions
    ADD COLUMN image_file_id BIGINT UNSIGNED NULL AFTER passage_id,
    ADD COLUMN image_alt VARCHAR(300) NULL AFTER image_file_id;
