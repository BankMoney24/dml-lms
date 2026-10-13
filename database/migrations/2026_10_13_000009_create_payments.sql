-- Payments a school collects from its learners for paid courses (PRD 7.6).
-- This is separate from any subscription the school pays to the platform
-- (PAY-001), which will have its own tables.
--
-- payment_accounts: the school's own Paystack account. The secret key is
--   encrypted with APP_KEY and never shown again after it is saved (PAY-005).
-- payments: one row per checkout or manually recorded payment, with the
--   provider's reference and transaction id, amount in minor units (kobo),
--   currency and status (PAY-004).
-- payment_events: every status change and every provider notification, with
--   a unique key so a webhook delivered twice is processed once (PAY-002).

ALTER TABLE courses
    ADD COLUMN price_minor INT UNSIGNED NULL,
    ADD COLUMN currency CHAR(3) NULL;

CREATE TABLE payment_accounts (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    provider VARCHAR(20) NOT NULL,
    secret_sealed TEXT NOT NULL,
    mode VARCHAR(10) NOT NULL,
    currency CHAR(3) NOT NULL,
    connected_by BIGINT UNSIGNED NOT NULL,
    connected_at DATETIME NOT NULL,
    CONSTRAINT fk_payment_accounts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_accounts_user FOREIGN KEY (connected_by) REFERENCES users (id),
    CONSTRAINT ck_payment_accounts_provider CHECK (provider IN ('paystack')),
    CONSTRAINT ck_payment_accounts_mode CHECK (mode IN ('test', 'live'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(64) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    mode VARCHAR(10) NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(20) NOT NULL,
    provider_transaction_id VARCHAR(40) NULL,
    channel VARCHAR(30) NULL,
    note VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_payments_reference (reference),
    UNIQUE KEY uq_payments_tenant_id (tenant_id, id),
    KEY ix_payments_tenant_created (tenant_id, created_at),
    KEY ix_payments_user_course (tenant_id, user_id, course_id, status),
    CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_payments_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT ck_payments_status CHECK (status IN ('pending', 'paid', 'failed')),
    CONSTRAINT ck_payments_provider CHECK (provider IN ('paystack', 'manual'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(160) NOT NULL,
    source VARCHAR(20) NOT NULL,
    type VARCHAR(60) NOT NULL,
    outcome VARCHAR(60) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_events_key (tenant_id, event_key),
    KEY ix_payment_events_payment (tenant_id, payment_id),
    CONSTRAINT fk_payment_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_events_payment FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
