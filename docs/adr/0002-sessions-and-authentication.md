# ADR 0002 — Server-side sessions for the first-party web app

**Status:** accepted · 2026-10-12

## Decision
* Native PHP sessions stored in the app's private `shared/storage/sessions` (not the server-wide tmp, because other sites share the host).
* Cookie `__Host-dml_session`: Secure, HttpOnly, SameSite=Lax, host-only. Strict mode, 48-char ids, 2-hour idle timeout.
* Session id regenerated and CSRF token rotated at sign-in and sign-out.
* The session stores user id, tenant id and the user's `session_version`. Password change, suspension or removed membership invalidate every session on the next request (IAM-006).
* `password_hash(PASSWORD_DEFAULT)` with transparent rehash; 12-character minimum.
* Generic failure message; unknown accounts are verified against a dummy hash so timing does not reveal existence (IAM-007).
* Throttling: 5 failures per account per tenant and 30 per IP in 15 minutes → 15-minute lock.

## Not yet
MFA for privileged roles (IAM-005, P0) and password reset by email (IAM-001) are the next identity items; until then accounts are created and reset by an administrator via `bin/console`.

Public API authentication (OAuth client credentials) is separate and will not reuse cookie sessions (PRD §9.2).
