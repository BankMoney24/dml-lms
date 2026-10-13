# ADR 0012: Paid courses through Paystack, confirmed on the server

Date: 2026-10-13 · Status: accepted

## Context

PRD 7.6 asks for paid courses with Paystack first: initialise a checkout, verify on return, accept signed webhooks, never grant access on the browser's word, never double-enrol, and never expose the secret key (PAY-001…005). Many schools also take money by bank transfer or cash at the bursary, and that has to open the course too.

## Decision

* **A course has an optional price** (`price_minor`, `currency`, in kobo/pesewas/cents). Blank means free. Only school administrators set prices (`school.manage`); teachers see the price. Learners already enrolled before a price was set keep access.
* **Each school connects its own Paystack account.** The secret key is checked against Paystack before saving, sealed with `APP_KEY` (`SecretBox`) in `payment_accounts`, and only decrypted on the server to call Paystack or check a webhook signature. It is never rendered, logged, audited or refilled into a form. Test and live mode follow the key prefix.
* **Checkout:** `POST /courses/{slug}/pay` creates a `pending` payment with a random reference (`DML-{school}-{16 hex}`) and the amount and currency at that moment, then sends the learner to the `https://checkout.paystack.com/…` address Paystack returned (`Response::redirectToCheckout` accepts nothing else). At most 10 checkouts an hour per learner. The CSP's `form-action` allows that one origin.
* **Confirmation, two routes, one outcome.** (1) The return URL asks Paystack's verify API itself; the query string is only a reference, and someone else's reference is "not found" with no lookup. (2) `POST /webhooks/paystack` checks `x-paystack-signature` (HMAC-SHA512 of the raw body with the school's key, constant-time compare) and acts only on `charge.success`. Both go through one routine that locks the payment row, checks status, reference, amount and currency (a mismatch marks it failed), marks it paid, records the event and enrols the learner, all in one transaction. A unique `(tenant_id, event_key)` in `payment_events` makes repeats no-ops, so the same payment confirmed twice, or by both routes at once, enrols once.
* **The webhook route is bare:** no session, cookie or CSRF. A `host-tenant` route middleware resolves the school from the verified host. JSON bodies up to 64 KB are read raw (`Request::rawBody`), and are no longer mistaken for an oversized form.
* **The enrolment rule lives in `LearningService`:** a paid course needs a paid payment unless the person can manage courses. The course page shows the price and a Pay button, a "confirming" note with a *Check now* link while a recent checkout is pending, or "ask the school how to pay" when online payments are not connected.
* **Manual payments:** admins record bank transfers or cash with a note; the learner is enrolled at once and the record is audited.
* **The school's Payments page** lists payments (filter by paid, waiting, failed), shows 30-day live totals, the webhook URL to paste into Paystack, and exports a CSV with formula-like cells neutralised.

## Consequences

* No platform-level money handling: funds settle directly in each school's Paystack account. Subscriptions, refunds, coupons and split payments are not built.
* Outgoing HTTPS from PHP is required (on SELinux: `setsebool -P httpd_can_network_connect on`). Without it checkout fails cleanly and nothing is charged.
* Paystack only delivers live webhooks to HTTPS. Until the domain has HTTPS, payments are confirmed when learners return from the checkout; a learner who closes the tab can use *Check now* on the course page.
