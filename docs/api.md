# API v1

A read-only JSON API a school gives to its **own** systems (for example PrimeERP, a finance spreadsheet or a reporting tool). ADR 0036 records the design.

## Keys

* A school administrator makes a key under **School → Settings → API keys**, naming the system that will use it. The key is shown once; only a SHA-256 hash of it is stored.
* Keys look like `dmlk_ab12cd34_…` (5 + 8 + 1 + 40 characters). The 8 characters after `dmlk_` identify the key in the list.
* A key reads only its own school's data, cannot change anything, and stops working as soon as it is revoked. A school can have up to 10 active keys. Making and revoking keys is recorded in Activity.
* Anyone with a key can read learners' names, emails, progress and payments. Keep keys in the other system's settings, never in a chat, email or public code.

## Requests

```
GET https://<school address>/api/v1/<list>
Authorization: Bearer dmlk_…
```

* Use the school's own address (the key must belong to that school) and HTTPS.
* Only `GET`. No cookies or sessions.
* Up to **120 requests a minute** per key; more gets `429` with `Retry-After: 60`.
* Lists are paged: `?page=1&per_page=50` (at most 100). Every list answer has `data`, `page`, `per_page`, `total` and `next` (the address of the next page, or `null`).
* Times are UTC in ISO 8601 (`2026-10-28T08:30:00Z`). Money is in minor units (kobo for NGN: `2500000` is ₦25,000).
* Errors: `{"error": {"code": "unauthorised" | "not_found" | "rate_limited" | "read_only", "message": "…"}}` with status 401, 404, 429 or 405.

## Lists

| Address | Fields | Filters |
|---|---|---|
| `/api/v1` | `school` (name, slug, timezone, url), `version`, `endpoints` | |
| `/api/v1/courses` | `id`, `slug`, `title`, `summary`, `status` (published, draft), `track` (school, skills, everyday or null), `level`, `open_to_everyone`, `price` (`amount_minor`, `currency`, or null when free), `duration_hours`, `lessons`, `url`, `updated_at` | `status` |
| `/api/v1/learners` | `id`, `name`, `email`, `status` (active, suspended), `joined_at` | |
| `/api/v1/enrolments` | `id`, `learner_id`, `course_id`, `status` (active, completed, withdrawn), `enrolled_at`, `completed_at`, `lessons_completed`, `lessons_total`, `progress_percent` | `course`, `learner`, `status` |
| `/api/v1/payments` | `id`, `reference`, `learner_id`, `course_id`, `amount_minor`, `currency`, `status` (pending, paid, failed, refunded), `method` (paystack, manual, coupon), `test`, `paid_at`, `created_at` | `status`, `since` (YYYY-MM-DD) |
| `/api/v1/certificates` | `code`, `learner_id`, `course_id`, `learner_name`, `course_title`, `issued_at`, `revoked_at`, `verify_url` | |

Lists are newest first (highest `id` first).

## Example

```
curl -H "Authorization: Bearer dmlk_…" "https://dmlschooloftechnology.org/api/v1/payments?status=paid&since=2026-10-01"
```

```json
{"data": [{"id": 5, "reference": "MAN-1-C5A593E96997", "learner_id": 16, "course_id": 2, "amount_minor": 2500000,
  "currency": "NGN", "status": "paid", "method": "manual", "test": false, "plan": null,
  "paid_at": "2026-10-19T08:29:29Z", "created_at": "2026-10-19T08:29:29Z"}],
 "page": 1, "per_page": 50, "total": 1, "next": null}
```
