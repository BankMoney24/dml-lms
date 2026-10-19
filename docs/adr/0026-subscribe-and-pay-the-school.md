# ADR 0026: Subscribing to a course, and paying the school directly

Date: 2026-10-20 · Status: accepted

## Context

The school wants one clear place where a person subscribes to a course: if it is free they take it straight away; if it is paid they pay and then take it. Until now the steps were spread over the course page (enrol, pay, join), and a paid course could only be bought through Paystack or by an administrator recording a payment by hand. Many learners pay schools by bank transfer, and Paystack is not yet live on the production server.

## Decision

### One subscribe page per course

* `/courses/{slug}/subscribe` shows three steps, **Your account → Payment → Start learning** (no payment step for free courses), with a summary of what the course includes beside it.
  * **Visitors**: create an account (the join form remembers the course) or sign in; either way they come back to this page.
  * **Free**: *Subscribe and start* enrols and opens the first lesson.
  * **Paid**: *Pay online* (Paystack, when the school has connected it) and *Pay the school directly*; the course opens only after the payment is confirmed.
  * **Subscribed**: "You are subscribed" with a link to carry on.
  * Courses that take applications, or are full, send people to apply (ADR 0025).
* Course cards, the course page, free-preview lessons and the join flow all lead here. "Enrol" is now "Subscribe" in the interface; the old `/courses/{slug}/enrol` and `/pay` routes still work.
* **My subscriptions** (`/account/subscriptions`, in the sidebar): courses with progress, anything waiting (payments being checked, online payments not yet confirmed, applications), and paid payments with receipts.

### Paying the school directly

* Under School → Payments the school writes **how learners pay it** (bank, account name and number, narration). Learners see this text, so the page refuses anything that looks like a Paystack key.
* After paying, the learner tells the school on the subscribe page: method (transfer, deposit, POS, cash), amount, date (not in the future, at most 90 days ago), reference (needed except for cash) and an optional note. They can have one note waiting per course, can withdraw it, and can send at most 5 a day.
* **The learner's note opens nothing.** Owners and administrators are notified. The note appears under **Payments to confirm**, oldest first, with a warning when the amount is less than the price, and a count on the sidebar.
  * **Confirm and enrol** records a manual payment (with the method, date and reference in its note), enrols the learner and notifies them. Confirming twice records once.
  * **Not found** sends the learner the school's reason. They can then send a corrected note.
* Only people who manage the school can confirm or reject. Confirming uses the same code path as recording a payment by hand.

## Consequences

* Schools can sell courses before Paystack is live, without trusting a learner's word.
* Paystack remains the fastest path when connected: the course opens the moment Paystack confirms.
* Payment details are plain text the school writes. The platform does not check bank accounts; the school checks its own statement before confirming.
