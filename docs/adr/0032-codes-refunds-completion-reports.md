# ADR 0032: Discount codes, refunds, course completion rules and learner reports

Date: 2026-10-27 · Status: accepted

## Context

From the pending list agreed with the school: refunds, coupons, per-course completion rules and parent report sheets, plus a threat model and a data dictionary. Instalments were also on the list; they are left for a later update (see Consequences).

## Decision

### Course completion rules

* Every lesson has **Needed to finish the course** (the existing `lessons.is_mandatory`, now editable in the lesson editor). At least one lesson stays required, or no one could finish.
* A course may also need an **average across its required quizzes** (`courses.completion_min_average`, 1–100). The average uses the best score on each required quiz; a quiz not tried counts as 0, rounded down.
* The rule is checked every time a lesson is completed (as before) and again for every unfinished learner when a teacher loosens it (makes a lesson optional, lowers or removes the average). Those who now meet it finish and get their certificate. Tightening a rule never takes a finished course away.
* The course page tells a learner what the rule needs and their average so far, including the new state "every lesson is done" when only the average is missing.

### Discount codes

* School administrators make codes on **Payments → Discount codes**: a percentage (1–100) or an amount (in the course currency) off, for one paid course or all of them, with an optional number of uses and last day. Codes are unique per school, compared without case. They can be switched off and on.
* Learners type a code on the subscribe page; the page shows the new price before they pay. The checkout asks Paystack for the discounted amount, and the payment records the code and the price before it (`payments.coupon_id`, `list_price_minor`). Webhooks and returns still check the stored amount.
* A code that covers the whole price enrols without Paystack (a payment with provider `coupon` and amount 0), with the code's row locked while its uses are counted.
* A use counts when a payment with the code is confirmed; a full refund gives it back. Checking codes is limited to 20 an hour per person. A discount that would leave less than the minimum online payment (₦100) is refused.

### Refunds

* From the payment history, an administrator can pay back all or part of a payment, with a reason the learner sees, and optionally take the learner off the course.
* Paystack payments are refunded through Paystack's refund API and start as "being paid back"; Paystack's `refund.processed` and `refund.failed` webhooks settle them (matched by the payment reference and amount; repeats ignored). A failed full refund makes the payment count as paid again.
* Payments recorded by hand are refunded by hand; the platform records that the money went back.
* The payment row is locked while refunds are added up, so the total can never exceed what was paid. A payment paid back in full becomes `refunded`: it no longer opens the course, so the learner would pay again to return.
* **Taking a learner off a course** sets the enrolment to `withdrawn` (a status the schema always had but nothing used). A withdrawn learner cannot open lessons and the course shows as not joined; their progress, results and certificates are kept, and joining again restores them.
* Receipts show the discount and any refunds. Learners get a notification.

### Learner reports

* A printable report for a period (default the last 90 days, at most 400): courses with progress, quiz average and last activity; lessons finished in the period; attendance at live classes in the period; quiz results; assignments; certificates; lines for the class teacher's and head's signatures.
* Parents open it from their child's page (`/children/{id}/report`) and see only what their link allows. Staff who manage people open it from People (`/people/{id}/report`).
* It contains only what the platform records. There are no typed comments or grades yet.

### Documents

* `docs/data-dictionary.md` is generated from the live schema by `bin/console schema:dictionary`; a test fails when a table has no description.
* `docs/threat-model.md` lists assets, trust boundaries, threats with their controls, and the top remaining risks.

## Consequences

* **Instalments are not built.** They need a payment plan per learner, part-access rules and reminders; that is a design of its own.
* Codes apply to online payment and to free places. A learner paying the school directly with a code shows it to the school, which records the reduced payment by hand.
* With many learners starting checkouts with the last use of a code at once, more than the limit can end up paying; the platform honours payments Paystack confirms rather than refusing money already taken.
* Paystack's refund webhook fields were taken from Paystack's documentation and are read defensively; the first live refund should be checked on the payments page.
