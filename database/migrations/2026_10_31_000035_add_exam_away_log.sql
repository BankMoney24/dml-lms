-- Update 45 (ADR 0045): how often, and for how long, a candidate left the exam
-- page (switched tab or app, minimised the browser). Recorded by the exam room,
-- shown to teachers, and told to candidates in the room. It never stops an exam.

ALTER TABLE cbt_attempts
    ADD COLUMN away_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER save_seq,
    ADD COLUMN away_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER away_count;
