# Status against the PRD (v1.0, 12 Oct 2026)

Legend: **Built** = implemented and covered by tests · **Partial** = foundation in place, gaps listed · **Next** = not started.

## Phase 0 — engineering foundation

| Item | Status | Notes |
|---|---|---|
| Repository, layout, coding standards | Built | PSR-12 (phpcs), PHPStan level 8, strict types |
| Architecture overview, ADRs | Built | `docs/architecture.md`, `docs/adr/0001–0009` |
| ERD / data dictionary | Partial | Schema diagram in architecture.md; full data dictionary next |
| Threat model | Next | Controls are listed below; a written threat model is still required |
| CI (lint, analysis, tests, secret scan, asset budget) | Built | `.github/workflows/ci.yml`, PHP 8.3 / 8.4 / 8.5 matrix on MySQL 8.4 |
| Staging + approved production pipeline | Built | `deploy.yml` + `deploy-target.yml`; needs GitHub environments and secrets |
| Restricted deploy, atomic switch, auto-rollback | Built | Rehearsed locally, including a broken release and manual rollback |
| Server inspection | Ready to run | `deploy/server/inspect.sh` — needs to be run on 66.29.156.116 |
| Authentication, tenant context, memberships, audit, migrations | Built | |
| Backup/restore rehearsal | Built (server step pending) | Encrypted nightly backups of database, uploads and `.env`; off-server copy; weekly automatic restore test into a scratch database; `dml-restore apply` for real restores. Rehearsal script runs in CI (MySQL 8.4) and passed on MySQL 8.0.46 and MariaDB 10.11. Still to do on the server: install the timers, store the key off the server, set `REMOTE`, run one rehearsal on a spare VM. Guide: `docs/backup-restore.md` |

**Phase 0 exit gate (PRD §18):** tenant-isolation tests ✔ · CI ✔ · staging deploy ☐ (needs server + secrets) · backup/restore rehearsal ✔ in CI, ☐ on the server.

## Functional requirements

| ID | Status | Notes |
|---|---|---|
| TEN-001 create/suspend tenants | Partial | `bin/console tenant:create`; suspend via repository. No platform admin UI yet |
| TEN-002 tenant from verified host | Built | |
| TEN-003 server-side membership checks | Built | Route gate + use-case checks + repository scoping |
| TEN-004 branding, locale, terminology | Partial | Name, locale, timezone stored; branding/terminology next |
| TEN-005 tenant in jobs/cache/storage/audit | Partial | Audit ✔; no jobs/cache/uploads exist yet |
| IAM-001 sign-in/out, reset, invitation | Built | Sign-in/out; self-service password change; emailed password reset (60 min, single use, rate-limited, no account enumeration); emailed invitations (7 days) and resend from **People**. Without an email provider, admins hand out a one-time password the person must replace |
| IAM-002 secure server sessions + CSRF | Built | ADR 0002 |
| IAM-003 password hashing | Built | |
| IAM-004 roles as tenant memberships | Built | One role per membership (ADR 0001) |
| IAM-005 MFA for privileged admins | Built | Authenticator-app codes (TOTP, RFC 6238) with replay protection, 10 hashed recovery codes, secret encrypted at rest; required for owners/admins (`TWO_FACTOR_REQUIRED_ROLES`); owner can reset a member who lost their phone. ADR 0006 |
| IAM-006 invalidate sessions on credential/membership change | Built | session_version |
| IAM-007 generic auth errors | Built | Constant-time path for unknown accounts |
| CRS-001/002 courses, modules, ordered lessons | Built | Reading (Markdown: headings, lists, code blocks with copy, callouts, tables), video (YouTube/Vimeo, privacy-enhanced, loads only on request), quiz and assignment lessons; edit, reorder, preview, unpublish; level, hours, outcomes and prerequisites. Files on any lesson (PDFs, handouts, configs, scripts), shown with their size. Course packages import with `course:import`, including files. Lab lessons and price next |
| CRS-003 preview/gated content | Partial | Enrolment gate ✔; preview lessons next |
| CRS-004 progress and resume | Built | "Continue" goes to the first incomplete lesson |
| ASM-001 MCQ, server scoring, thresholds | Built | Floor rounding; answers never sent to the browser; per-question explanations shown once the learner passes (or runs out of attempts) |
| ASM-002 auditable attempts, limits | Built | Idempotency keys, attempt limits under row lock |
| ASM-003 file/text submissions | Built | Assignment lessons: text answer (Markdown), a link and up to 3 files; teacher review queue with approve / ask for changes, feedback, full history, email to the learner |
| ASM-004 configurable completion rule | Partial | All mandatory lessons, quizzes need their pass mark; per-course rule config next |
| ASM-005 certificates | Built | Issued automatically on completion, printable (A4 landscape), 12-character code checked on the public `/verify` page, revocable (`certificate:revoke`). ADR 0007 |
| LAB-001…007 virtual labs | Next | Separate service per PRD §10.1; not in this slice |
| PAY-001…005 payments (Paystack) | Next | |
| PAR-* guardians | Next | Guardian role exists with no permissions |
| ADM-001 tenant reports | Partial | Per-course learner progress (lessons done, best quiz score per quiz, last activity). Tenant-wide reports and export next |
| Operations overview | Built | **System** page for platform administrators: backups, restore test, migrations, email, disk and per-school storage, virus scanner; red/amber/green with the fix written out |
| ADM-002 audit events | Built | Recorded for sign-in, people changes, course and lesson changes, enrolment, attempts, completion; readable **Activity** page for owners/admins |
| NTF-002 transactional email | Partial | SMTP (STARTTLS/SSL) through a durable outbox with retry/backoff, `mail:work` cron, bodies erased after send. Account emails only; course/enrolment notifications and preferences next |
| NTF-001/003+, SUP-001 | Next | In-app notifications, SMS/WhatsApp, support desk |
| §8 offline | Partial | Offline banner, static-asset service worker, quiz drafts on device until server confirms, idempotent submit. IndexedDB outbox and conflict handling next |
| §9 public API v1 | Next | |
| §12 accessibility | Partial | Semantic HTML, labels, error association, focus styles, reduced motion, 44px targets. Not yet audited with assistive technology |

## Security controls in place

Parameterised SQL everywhere · uploads checked by content, stored outside the web root, served only after an access check (text as text/plain under a sandbox CSP), per-school quota, optional ClamAV with fail-closed scanning · two-step sign-in for owners/admins · hashed single-use account links · DB-backed rate limits on reset requests · contextual output escaping · CSRF on all state changes · `__Host-` secure session cookie, rotation on login/logout · login throttling · strict CSP with no inline code · `X-Frame-Options: DENY`, `nosniff`, referrer and permissions policies, HSTS on HTTPS · `Cache-Control: no-store` on pages · user-facing errors carry a request id, never internals · IPs in audit stored as keyed hashes · composite foreign keys against cross-tenant writes · secrets only in `shared/.env` · forced-command deploy key · actions pinned to SHAs.

## Content

`courses/linux-server-administration`: an advanced, complete course (8 modules, 21 readings, 7 module quizzes and a 20-question final exam with explanations, 3 teacher-reviewed assignments including a capstone, about 40 hours) covering AlmaLinux and Ubuntu. Import with `bin/console course:import <tenant> linux-server-administration --publish`.

## Interface

Shared components rendered by `ViewHelpers` (labelled fields with inline errors, error summary linking to fields, badges, empty states, breadcrumbs), role-aware navigation with the current section marked, the same page-header pattern on every screen, two-step confirmation for destructive actions, and course tabs (Outline / Learner progress) in the teaching workspace.

## Recommended next slice

1. On the server: install the backup timers, save the key off the server, set `REMOTE` (deployment.md → Backups). Then staging (closes Phase 0).
2. Choose the email provider for the server and add SPF/DKIM for the domain (code is ready; see deployment.md → Email).
3. Turn on ClamAV scanning on the server and add uploads to the backup routine (deployment.md → Uploaded files).
4. Institution onboarding wizard on a reusable wizard engine (PRD §6.3–6.4).
5. Paystack adapter with verified, idempotent webhooks.
6. First lab provider behind the lab-session contract.
