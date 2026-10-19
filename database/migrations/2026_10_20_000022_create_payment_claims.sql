-- Update 26 (ADR 0026): subscribing to a course, and paying the school by bank
-- transfer when it does not take payments online.
--
-- payment_instructions: what the school tells learners about paying it directly
--   (bank, account name and number, what to put as the narration). Written by
--   the school; shown on the subscribe page. No secrets belong here.
-- payment_claims: a learner says "I have paid by transfer". Nothing opens on the
--   learner's word: the school checks its bank and confirms (which records a
--   manual payment and enrols the learner) or rejects with a reason.

CREATE TABLE payment_instructions (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    instructions TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_payment_instructions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_instructions_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    method VARCHAR(12) NOT NULL,
    paid_on DATE NOT NULL,
    payer_reference VARCHAR(80) NOT NULL DEFAULT '',
    note VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) NULL,
    payment_id BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_claims_tenant_id (tenant_id, id),
    KEY ix_payment_claims_queue (tenant_id, status, created_at),
    KEY ix_payment_claims_user (tenant_id, user_id, course_id, status),
    CONSTRAINT fk_payment_claims_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_claims_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_claims_payment FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id),
    CONSTRAINT fk_payment_claims_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_payment_claims_status CHECK (status IN ('pending', 'confirmed', 'rejected', 'withdrawn')),
    CONSTRAINT ck_payment_claims_method CHECK (method IN ('transfer', 'deposit', 'cash', 'pos'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
