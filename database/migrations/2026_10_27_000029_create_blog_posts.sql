-- Update 34 (ADR 0034): the school's blog (news, events, stories and guides).
--
-- blog_posts: written by the school's administrators and teachers, published by
--   administrators. A post is public only when published; drafts and the
--   author's name stay inside the school until then. The slug is the address
--   (/blog/<slug>) and stays the same after publishing so shared links keep working.

CREATE TABLE blog_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(160) NOT NULL,
    summary VARCHAR(300) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    category VARCHAR(20) NOT NULL DEFAULT 'news',
    status VARCHAR(12) NOT NULL DEFAULT 'draft',
    author_id BIGINT UNSIGNED NOT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_blog_posts_tenant_slug (tenant_id, slug),
    UNIQUE KEY uq_blog_posts_tenant_id (tenant_id, id),
    KEY ix_blog_posts_public (tenant_id, status, published_at),
    CONSTRAINT fk_blog_posts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_blog_posts_author FOREIGN KEY (author_id) REFERENCES users (id),
    CONSTRAINT ck_blog_posts_status CHECK (status IN ('draft', 'published')),
    CONSTRAINT ck_blog_posts_category CHECK (category IN ('news', 'events', 'stories', 'guides'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
