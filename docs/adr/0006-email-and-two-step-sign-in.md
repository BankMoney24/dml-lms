# ADR 0006: Email delivery and two-step sign-in

Date: 2026-10-12 · Status: accepted

## Context

Password reset, invitations and MFA for privileged roles (PRD IAM-001, IAM-005) were blocked on email. The server is shared, runs PHP-FPM without a queue system, and we keep runtime dependencies at zero (ADR 0005). Connectivity for users can be poor, so email must not slow page responses.

## Decision

* **Provider-neutral SMTP.** A small SMTP client (STARTTLS/implicit TLS, AUTH PLAIN/LOGIN, peer verification on by default) works with any transactional provider or a local relay. Choosing the provider is an operations decision, not a code change.
* **Durable outbox.** Every email is a row in `mail_outbox`. It is sent after the response is flushed (`fastcgi_finish_request`) and retried by `mail:work` from cron with backoff (1 m, 5 m, 30 m, 2 h, 6 h; failed after 6 tries). Rows are claimed atomically so the request and the worker never double-send. Bodies are erased once sent or abandoned because they contain sign-in links.
* **Account links.** 256-bit random tokens; only the SHA-256 is stored. Single use, scoped to tenant and membership, revoked when any is used. Reset: 60 minutes; invitation: 7 days. The reset request page says the same thing whether or not the address exists, and requests are rate-limited per IP and per address.
* **Two-step sign-in via TOTP** (RFC 6238, 30 s, 6 digits, ±1 step), not SMS: free, works offline on the phone, no provider. Secrets are AES-256-GCM encrypted with a key derived from `APP_KEY`. The last accepted step is stored to refuse replays. Ten recovery codes, stored as keyed hashes, each usable once. Wrong codes count toward the existing login lock. Roles in `TWO_FACTOR_REQUIRED_ROLES` (default owner, admin) cannot use the app until it is set up and cannot turn it off. Only an owner can reset another member's two-step; it ends that person's sessions.
* A password reset does not bypass two-step.

## Consequences

* Without SMTP configured in production, nothing is emailed and the earlier one-time-password flow is used; the UI says so.
* Rotating `APP_KEY` forces everyone to re-enrol two-step sign-in.
* Deliverability depends on SPF/DKIM/DMARC for the sending domain, which is set at the DNS host.
* WebAuthn/passkeys can be added later as another second factor without changing the session model.
