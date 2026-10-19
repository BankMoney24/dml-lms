-- Update 27 (ADR 0027): the Code studio.
--
-- code_snippets.language: what a saved snippet is written in, so it opens in
--   the right mode: javascript, python, or web (one HTML page with its own
--   <style> and <script>).
-- code_challenge_solves: practice challenges a learner has passed. The tests
--   run in the learner's browser (code never runs on the server), so this is
--   the learner's own record of practice, not an assessed result.

ALTER TABLE code_snippets
    ADD COLUMN language VARCHAR(12) NOT NULL DEFAULT 'javascript' AFTER title,
    ADD CONSTRAINT ck_code_snippets_language CHECK (language IN ('javascript', 'python', 'web'));

CREATE TABLE code_challenge_solves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    challenge VARCHAR(40) NOT NULL,
    language VARCHAR(12) NOT NULL,
    code MEDIUMTEXT NOT NULL,
    solved_at DATETIME NOT NULL,
    UNIQUE KEY uq_code_challenge_solves (tenant_id, user_id, challenge, language),
    KEY ix_code_challenge_solves_user (tenant_id, user_id, solved_at),
    CONSTRAINT fk_code_challenge_solves_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_code_challenge_solves_language CHECK (language IN ('javascript', 'python'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
