# DML Learning Platform

Multi-tenant LMS and virtual-laboratory SaaS for schools, universities and training centres — *Learn it. Practise it. Prove it.*

This repository is the **Phase 0 foundation plus the first learning slice** of the PRD (`docs/status.md` maps every requirement to built / next):

* tenant resolution by verified host, memberships and role permissions, audited sign-in with throttling;
* educator authoring: course → modules → reading lessons and server-scored multiple-choice quizzes → publish;
* learner journey: catalogue → enrol → lessons with progress → quiz attempts (idempotent, attempt limits) → course completion;
* low-bandwidth UI (≈5 KB compressed CSS+JS, system fonts, no framework), offline banner, quiz answers kept on the device until the server confirms;
* release pipeline: CI checks → one immutable artifact → staging → approved production, atomic switch, automatic rollback.

## Stack

PHP 8.3+ (8.5 targeted in production) · MySQL 8.4 LTS · server-rendered PHP templates · no runtime Composer dependencies · PHPUnit, PHPStan level 8, PHPCS PSR-12 in CI.

## Run it locally

Prerequisites: PHP 8.3+ with `pdo_mysql`, `mbstring`, `intl`; MySQL 8.4 or MariaDB 10.11; Composer 2.

```bash
composer install
cp .env.example .env            # set APP_ENV=local, APP_KEY, DB_*, SESSION_SECURE_COOKIE=false
php bin/console migrate
php bin/console tenant:create dml "DML School of Technology" dml.localhost
echo 'a-long-teacher-password' | php bin/console user:create dml teacher@example.test "Ada Teacher" educator
echo 'a-long-learner-password' | php bin/console user:create dml learner@example.test "Tobi Learner" learner
php bin/console seed:demo dml   # optional demo course (refused when APP_ENV=production)
php -S 127.0.0.1:8080 -t public
```

Open <http://dml.localhost:8080> (most browsers resolve `*.localhost` to 127.0.0.1).

## Checks

```bash
composer check      # phpcs + phpstan + phpunit
```

Tests need a disposable database whose name contains `test` (default `dml_lms_test`; override with `DB_TEST_HOST`, `DB_TEST_DATABASE`, `DB_TEST_USERNAME`, `DB_TEST_PASSWORD`). The suite drops and recreates its tables.

`tests/Security/TenantIsolationTest.php` is release-blocking: two tenants with identical slugs and users must never see or change each other's data.

## Layout

| Path | What lives there |
|---|---|
| `app/Core/` | HTTP kernel, router, request/response, sessions, CSRF, views, PDO wrapper, migrator, console |
| `app/Modules/Tenancy/` | tenants, verified domains, memberships, tenant resolution |
| `app/Modules/Identity/` | users, roles/permissions, sign-in, throttling, auth middleware |
| `app/Modules/Courses/` | catalogue, modules, lessons, authoring use cases |
| `app/Modules/Learning/` | enrolments, progress, completion rule |
| `app/Modules/Assessments/` | quizzes, scoring, attempts |
| `app/Modules/Audit/` | append-only audit trail |
| `app/Modules/Platform/` | home and health endpoints |
| `app/Bootstrap/Application.php` | the whole service graph, wired explicitly |
| `database/migrations/` | forward-only SQL migrations (checksummed) |
| `resources/views/` | templates; every dynamic value is escaped with `$v->e()` |
| `public/` | front controller, assets, service worker — the only web-visible directory |
| `deploy/` | server scripts, Nginx/PHP-FPM/Apache config |
| `docs/` | architecture, ADRs, deployment runbook, status against the PRD |

## Courses

Ready-made course packages live in `courses/` (see `courses/README.md`). `bin/console course:import <tenant> linux-server-administration --publish` adds the advanced Linux Server Administration course.

## Deploying

See **[docs/deployment.md](docs/deployment.md)**. In short: tag `vX.Y.Z` on `main`; GitHub Actions builds once, deploys to staging, waits for approval, deploys to production through a restricted SSH key, and rolls back automatically if the health check fails.

Email (password reset, invitations) needs `MAIL_*` settings and the `mail:work` cron job; owners and admins must use two-step sign-in. Both are covered in the runbook.

## Contributing

Short-lived branches, pull requests into protected `main`, CI must be green. Record consequential decisions as ADRs in `docs/adr/`. Never commit `.env`, credentials, learner data or database dumps.
