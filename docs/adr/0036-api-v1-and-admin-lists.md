# ADR 0036: API v1 with school keys; payments and activity lists; empty states

Date: 2026-10-28 · Status: accepted

## Context

Schools asked for their other systems (PrimeERP first) to read from the platform, which was on the pending list as "Public API v1 with per-school keys". The interface checklist also had find, filter and pages still to do on payments and activity, and a review of empty states for a brand-new school.

## Decision

### API v1

* Read-only JSON under `/api/v1` on the school's own address: school, courses, learners, enrolments (with progress), payments and certificates. Paged (50 by default, at most 100), newest first, UTC times, money in minor units. `docs/api.md` describes every field.
* **Keys per school** (`api_keys`): made and revoked by school administrators (School → Settings → API keys). The key (`dmlk_` + 8 + `_` + 40 random characters) is shown once; only its SHA-256 hash is stored, plus the 8-character prefix to tell keys apart. At most 10 active keys. Making and revoking are audited.
* **Isolation:** the school comes from the verified host (as for webhooks), and the key must belong to that school; every query is limited to it. No sessions or cookies on API routes. 120 requests a minute per key. Errors are JSON. Last use is recorded at most every five minutes.
* Not in v1: writing, webhooks out, OAuth, per-key scopes. A key reads everything listed above, so the key page says plainly what a key exposes.

### Payments page

* What happened comes first: an at-a-glance strip (last 30 days, payments to confirm, paid courses, Paystack state), payments to confirm, then the history; settings (record a payment, how to pay directly, Paystack) move into panels at the end once Paystack is connected. A school without Paystack still sees the connect form first.
* History: find by name, email or reference; filter by course; status tabs with counts that follow the filters; 25 a page; the spreadsheet download exports exactly what the list shows. Long status notes are shortened in the table (the full note is on the payment's page).

### Activity

* Filter by kind (sign-ins and accounts, warnings, people and joining, courses and teaching, learning, payments, school and website) and by the person who did it; grouped by day (Today, Yesterday, then dates) with the time on each line; warnings are marked. Every action the platform records now reads as a plain sentence, and more subjects are named (classes, groups, paths, meetings, blog posts, website pages, the lesson a question was asked under).

### Empty states and small fixes

* A brand-new school was crawled as its owner. Staff now see actionable empty states (the course list points to Teaching, reports point to adding people and joining, notifications describe what staff receive); empty tools (payment filters, the activity pager) are hidden until there is something to filter or page.
* Optional lessons are marked "Optional" in course outlines.

## Consequences

* An integration can be cut off instantly by revoking its key; a leaked key exposes learner personal data until then, which the key page states.
* Payments and activity stay fast as schools grow: both are paged and filtered in SQL.
