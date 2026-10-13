# Status against the PRD (v1.0, 13 Oct 2026)

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
| TEN-001 create/suspend tenants | Built | **System → Schools** for platform administrators: create a school with its address and first owner (emailed invitation or one-time password), add addresses, suspend and reopen (not the school you are signed in through); all audited. `bin/console tenant:create` still works |
| TEN-002 tenant from verified host | Built | |
| TEN-003 server-side membership checks | Built | Route gate + use-case checks + repository scoping |
| TEN-004 branding, locale, terminology | Partial | Name, tagline, logo (PNG/JPEG/WebP, checked and scanned), one of 7 colour themes checked for contrast in light and dark mode, public contact details in the footer. Terminology (e.g. "student" vs "learner") next |
| TEN-005 tenant in jobs/cache/storage/audit | Partial | Audit ✔; uploads and logos stored per school; no jobs or cache yet |
| §6.3 wizard engine | Built | One engine for every guided journey: progress trace, Back / Continue / Skip / Save and exit, answers saved on the server after each step and shared by the institution's admins, steps open in order, review before anything is applied, discard, audit without field values. ADR 0010 |
| §6.4 institution onboarding | Built (billing step later) | Welcome, type (suggests defaults), academic year, name and look, learning features (certificates on/off, public course list on/off, starter courses as drafts), invite the team (pasted list, every line validated, invitations only on launch), check and launch. Owners are prompted on their home page; later changes reuse the wizard from **School → Settings**. Billing and policy come with payments |
| IAM-001 sign-in/out, reset, invitation | Built | Sign-in/out; self-service password change; emailed password reset (60 min, single use, rate-limited, no account enumeration); emailed invitations (7 days) and resend from **People**. Without an email provider, admins hand out a one-time password the person must replace |
| IAM-002 secure server sessions + CSRF | Built | ADR 0002 |
| IAM-003 password hashing | Built | |
| IAM-004 roles as tenant memberships | Built | One role per membership (ADR 0001) |
| IAM-005 MFA for privileged admins | Built | Authenticator-app codes (TOTP, RFC 6238) with replay protection, 10 hashed recovery codes, secret encrypted at rest; required for owners/admins (`TWO_FACTOR_REQUIRED_ROLES`); owner can reset a member who lost their phone. ADR 0006 |
| IAM-006 invalidate sessions on credential/membership change | Built | session_version |
| IAM-007 generic auth errors | Built | Constant-time path for unknown accounts |
| CRS-001/002 courses, modules, ordered lessons | Built | Lab lessons from the lab catalogue. School subject / Skills track on courses, filterable in the course list. Reading (Markdown: headings, lists, code blocks with copy, callouts, tables), video (YouTube/Vimeo, privacy-enhanced, loads only on request), quiz and assignment lessons; edit, reorder, preview, unpublish; level, hours, outcomes and prerequisites. Files on any lesson (PDFs, handouts, configs, scripts), shown with their size. Course packages import with `course:import`, including files. Prices on courses (ADR 0012) |
| CRS-003 preview/gated content | Partial | Enrolment gate ✔; preview lessons next |
| CRS-004 progress and resume | Built | "Continue" goes to the first incomplete lesson |
| ASM-001 MCQ, server scoring, thresholds | Built | Floor rounding; answers never sent to the browser; per-question explanations shown once the learner passes (or runs out of attempts) |
| ASM-002 auditable attempts, limits | Built | Idempotency keys, attempt limits under row lock |
| ASM-003 file/text submissions | Built | Assignment lessons: text answer (Markdown), a link and up to 3 files; teacher review queue with approve / ask for changes, feedback, full history, email to the learner |
| ASM-004 configurable completion rule | Partial | All mandatory lessons, quizzes need their pass mark; per-course rule config next |
| ASM-005 certificates | Built | Issued automatically on completion, printable (A4 landscape), 12-character code checked on the public `/verify` page, revocable (`certificate:revoke`). ADR 0007 |
| LAB-001…008 virtual labs | Built (browser labs) | Versioned catalogue of 6 labs: Ohm's law, titration, straight-line graphs (school subjects); subnetting, a JavaScript function, phishing (skills). Lab lessons; gateway with enrolment check, per-learner bindings re-checked on every request, one open session per lesson, 30 launches/hour, time limits with expiry (`lab:expire` cron), row-locked marking on the server from a per-session seed, capped evidence, audit events. Learner code runs only in a sandboxed, network-less iframe + Web Worker with a 2 s limit. Teacher catalogue with unsaved practice runs; lab columns in progress reports; System page usage. ADR 0011. Server-side isolated execution (shells, network emulation) for later |
| Classes and cohorts (§6.4 structure, ADM-001 class reports, TEN-004 terminology) | Built | The school's own word (class, cohort, group, stream, set) from setup. Administrators create groups with a level and year, choose teachers, add learners (tick or paste emails) and set courses with due dates; learners are enrolled automatically, including those who join later; paid courses set by an administrator are covered by the school. Teachers see only their groups. Progress grid (lessons done, completed, past due, last active) with CSV. Learners see their group's courses and due dates on their home page, never classmates. Archive at year end. ADR 0013 |
| PAY-001…005 payments (Paystack) | Built | Course prices (admins only), each school's own Paystack key (checked, sealed, never shown). Checkout with a random reference; access only after the server verifies with Paystack on return or a signed (HMAC-SHA512) `charge.success` webhook; amount, currency and reference checked under a row lock; repeated events are no-ops, so one enrolment. Manual bank-transfer or cash payments with a note. Payments page with filters, 30-day totals, webhook URL and CSV export (formula-safe). Learners see their payments on their account. ADR 0012. Refunds, coupons and subscriptions later |
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

Shared components rendered by `ViewHelpers` (labelled fields with inline errors, error summary linking to fields, badges, empty states, breadcrumbs), role-aware navigation with the current section marked, the same page-header pattern on every screen, two-step confirmation for destructive actions, and course tabs (Outline / Learner progress) in the teaching workspace. Course cards share one layout (module trace, kind of course, title, summary, a footer with facts and progress or price); one start box on the course page for every state; lesson pager cards; sign-in page with the school beside the form; staff home split into a to-do list, their classes and their courses; find-as-you-type on People and learner lists; add-a-lesson shows only the fields the chosen type uses; tables that stack into records on phones.

## Recommended next slice

1. On the server: finish the school setup for DML School of Technology (home page prompt) and rotate the exposed backup key. Install the backup timers, save the key off the server, set `REMOTE` (deployment.md → Backups). Then staging (closes Phase 0).
2. Choose the email provider for the server and add SPF/DKIM for the domain (code is ready; see deployment.md → Email).
3. Turn on ClamAV scanning on the server (deployment.md → Uploaded files).
4. On the server: enable outgoing HTTPS for PHP, connect a Paystack test key, try a payment, then HTTPS for the domain and the live key (deployment.md → Payments).
5. Isolated execution service for real-machine labs (Linux shell, network emulation) behind the same lab-session contract.
6. Guardian links (PAR-001) and reports across classes and levels, building on classes.
