-- Tenancy and identity foundation.
-- Users are global (one person may belong to several institutions);
-- everything a tenant owns carries tenant_id and tenant-scoped uniqueness.

CREATE TABLE tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(63) NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
    locale VARCHAR(16) NOT NULL DEFAULT 'en-NG',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_tenants_slug (slug),
    CONSTRAINT ck_tenants_status CHECK (status IN ('active', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_tenant_domains_host (host),
    KEY ix_tenant_domains_tenant (tenant_id),
    CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT ck_users_status CHECK (status IN ('active', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_memberships_tenant_user (tenant_id, user_id),
    KEY ix_memberships_user (user_id),
    CONSTRAINT fk_memberships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_memberships_role CHECK (role IN ('owner', 'admin', 'educator', 'learner', 'guardian')),
    CONSTRAINT ck_memberships_status CHECK (status IN ('active', 'removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_throttles (
    throttle_key CHAR(64) NOT NULL PRIMARY KEY,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    subject_type VARCHAR(60) NULL,
    subject_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    KEY ix_audit_tenant_time (tenant_id, created_at),
    KEY ix_audit_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
