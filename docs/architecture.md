# Architecture

A **modular monolith** (PRD §10.2): one deployable PHP application with explicit module boundaries. Lab execution, when it arrives, is a separate service; the PHP app stays the control plane.

## Request lifecycle

```
public/index.php
  └─ Kernel::handle
       ├─ ResolveTenant        Host → verified tenant_domains row → Tenant (404 unknown, 503 suspended)
       ├─ StartSession         server-side session in shared/storage/sessions, CSRF token
       ├─ AuthenticateSession  session user + tenant + session_version + active membership → CurrentUser
       ├─ VerifyCsrf           every non-GET request
       ├─ route middleware     tenant · auth · can:<permission>
       └─ Controller → use case (service) → repository (SQL with explicit tenant_id)
  SecurityHeaders and X-Request-Id are added to every response, including errors.
```

Health endpoints (`/health`, `/health/ready`) skip session, tenant and auth.

## Module ownership

| Module | Owns | Depends on |
|---|---|---|
| Tenancy | tenants (profile, theme, logo), domains, memberships, tenant resolution, the school setup wizard | Core, Wizards, Courses (starter courses) |
| Identity | users, roles → permissions, sign-in, throttling | Tenancy, Audit |
| Courses | courses, modules, lessons, authoring, who can read a course and the filtered course list (ADR 0021) | Assessments (to create quizzes), Audit |
| Learning | enrolments, lesson progress, completion | Courses |
| Assessments | assessments, questions, attempts, scoring | Learning (to record a passed quiz) |
| Audit | audit_events | Core |
| Wizards | wizard_runs and the generic wizard screens (ADR 0010) | Identity, Audit |
| VirtualLabs | lab catalogue, lab_sessions, the lab gateway and code runner (ADR 0011) | Courses, Learning, Audit |
| Notifications | email outbox; in-app notifications and the Notifier other modules call (ADR 0015) | Identity, Tenancy |
| Guardians | guardian_links, the guardian's read-only child view (ADR 0016) | Identity, Tenancy, Learning, Groups |
| Support | support_requests, support_messages, the help desk (ADR 0017) | Identity, Tenancy, Files, Notifications |
| Study | lesson_notes, learning days, due dates, calendar link (ADR 0018) | Identity, Tenancy, Learning, Groups |
| Search | school-wide search across courses, lessons, classes, people and pages (ADR 0018) | Courses, Groups, Identity |
| Site | site_pages, the school's public pages and website settings (ADR 0019) | Tenancy, Audit |
| Joining | sign-up modes and join codes, email confirmation, learner_profiles and the getting-started wizard, suggestions, join_requests (teach / guardian) (ADR 0020) | Identity, Tenancy, Wizards, Notifications, Audit |
| Playground | code_snippets and the JavaScript playground on the sandboxed runner (ADR 0020) | Identity, VirtualLabs (runner) |
| Platform/Seo | page metadata, share images, robots and sitemap on the canonical address (ADR 0019) | Tenancy, Courses, Site |
| Groups | learner_groups (classes, cohorts), members, courses set for a group, progress grid (ADR 0013) | Courses, Learning, Identity, Audit |
| Payments | course prices, payment_accounts (sealed Paystack keys), payments, payment_events, checkout, verify and webhook handling (ADR 0012) | Courses, Learning, Identity, Audit |
| Platform | home page, health, System page, Schools (platform administrators) | Tenancy, Identity; otherwise read-only |

Controllers stay thin: parse input, call a use case, render. Business rules live in services (`CourseAuthoring`, `LearningService`, `AttemptService`) and pure classes (`QuizScorer`, `CompletionRule`) that are unit-tested without a database.

## Tenant isolation — three layers

1. **Resolution**: the tenant comes only from a verified Host mapping; nothing the client sends selects a tenant.
2. **Application**: every repository method takes `$tenantId` explicitly and filters on it; object lookups use `(tenant_id, id)`. Sessions are bound to the tenant they signed into.
3. **Database**: tenant-owned child tables carry `tenant_id` and reference parents through composite foreign keys `(tenant_id, parent_id)`, so a row pointing at another tenant's record is rejected by MySQL itself.

Each layer has negative tests in `tests/Security/TenantIsolationTest.php`.

## Data model (current)

```
tenants ─┬─ tenant_domains
         ├─ memberships ── users (global; one person can belong to several tenants)
         └─ courses ─ course_modules ─ lessons ─ assessments ─ questions ─ question_options
                    └─ enrolments ─┬─ lesson_progress
                                   └─ quiz_attempts (idempotency_key unique per user)
audit_events · login_throttles · schema_migrations
```

All timestamps are stored in UTC (`time_zone = '+00:00'` on every connection).

## Front end

Server-rendered HTML, one hand-written CSS file and one small progressive-enhancement script (~5 KB compressed together). Pages work without JavaScript. No inline script or style, so the CSP is strict (`script-src 'self'; style-src 'self'`). See ADR 0004.

## Offline (current scope)

* Service worker caches only public static files and an offline page — never personal pages.
* Quiz answers are kept in the browser while answering and cleared only after the server's result page confirms the attempt. The UI says "Saved on this device. Not submitted yet." until then.
* Attempts carry an idempotency key, so a resend after a dropped connection records one attempt.

The full outbox / IndexedDB sync contract (PRD §8.3–8.4) is Phase 1 work.
