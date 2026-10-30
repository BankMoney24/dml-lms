-- Update 46 (ADR 0046): term report cards for a class, built from CBT exam results.
--
-- report_cards: one per class per term. components lists the exams that count and
-- their weights, e.g. [{"exam": 12, "label": "Mid-term test", "weight": 30}, ...].
-- Scores are not copied: the card is worked out from the exam attempts each time,
-- so a corrected answer key or a reset attempt shows straight away.
CREATE TABLE report_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    components JSON NOT NULL,
    grading VARCHAR(20) NOT NULL DEFAULT 'waec',
    next_term VARCHAR(160) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_report_cards_tenant_id (tenant_id, id),
    KEY ix_report_cards_group (tenant_id, group_id, status),
    CONSTRAINT fk_report_cards_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_report_cards_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_report_cards_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_report_cards_grading CHECK (grading IN ('waec', 'simple')),
    CONSTRAINT ck_report_cards_status CHECK (status IN ('draft', 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the class teacher and the head write on each learner's card.
CREATE TABLE report_card_remarks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    report_card_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    teacher_remark VARCHAR(600) NULL,
    head_remark VARCHAR(600) NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_report_card_remarks (report_card_id, user_id),
    CONSTRAINT fk_report_card_remarks_card FOREIGN KEY (tenant_id, report_card_id) REFERENCES report_cards (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_report_card_remarks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_report_card_remarks_editor FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
