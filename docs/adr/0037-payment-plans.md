# ADR 0037: Paying for a course in parts; help desk and applications lists

Date: 2026-10-28 · Status: accepted

## Context

"Instalments later" was the last open item on the payments row. Many learners, particularly for longer skills courses, cannot pay the whole price at once, and schools already take part payments informally at the office. The help desk and applications lists had no find or filter, and the school setup wizard lost typed answers silently when someone followed a link away from a step.

## Decision

### Payment plans

* **Set by a school administrator** next to the price: in full only, or in full **or** 2, 3 or 4 monthly parts. Each part must be at least the minimum price (₦100), so a cheap course cannot be split into tiny parts.
* **Equal parts, the odd amount on part 1** (₦25,000 in three: ₦8,333.34, then ₦8,333.33 twice), so the total is always exactly the price.
* **The learner chooses** on the subscribe page (online) or in "I have paid" (paying the school directly). A plan (`payment_plans`) records the price and the number of parts agreed; a later change of the course price never changes a plan already started. An unpaid plan at an older price is replaced.
* **Part 1 opens the course.** Part N is due (N − 1) × 30 days after part 1 was paid. Each payment row carries `plan_id` and `plan_part`; the plan's status (pending, active, completed) is recomputed from the paid parts whenever a part is paid, by Paystack confirmation (same row-locked path as full payments, PAY-002/003) or by the school (recorded at the office, or a learner's note confirmed).
* **Late parts:** reminders three days before a part is due, on the day after it is late, and when the course pauses. A part more than **7 days** late pauses the course for that learner: lessons, quizzes, labs and assignments refuse with a plain message, and the lesson link takes them to the plan, where they can pay. Progress is kept and the course opens again the moment the part is paid. Course builders are never paused.
* **The school's view:** Payments shows a "Paying in parts" figure (learners, total still to come, how many paused) and a Payment plans table (paid parts, next part and due date, still to pay, standing). Each plan has a page with every part, the payments behind them, "Record part N" (for money taken at the office) and "Stop asking for the rest" (for a scholarship or agreed discount: the course stays open, no more reminders; audited, with a reason only staff see). The ordinary "Record a payment" form refuses a learner already on a plan and points to the plan.
* **Reminders** run from a daily cron line (`payments:plan-reminders`, 07:13 UTC = 08:13 Lagos). Each reminder is sent once per part (the last one sent is kept on the plan); a job that runs late skips ahead rather than sending three at once. Learners get a notification and, when email is set up, an email with a link to pay; administrators get a notification when a course pauses.
* **Not combined with discount codes:** codes are for paying in full; the page says so.
* The API's payments list gains `plan: {id, part}`.

### Lists

* Help desk: find by subject, name or email; filter by what it is about; the status tabs count what the words match.
* Applications: find by name, email or phone; filter by course; the tabs keep the words.

### Setup wizard

* Each step's form warns before leaving with changed answers (the same unsaved-changes guard as other forms); moving between steps with the buttons saves as before.

## Consequences

* One more cron line: the cron file must be reinstalled once after this release.
* Plans are monthly only (30-day steps); weekly or custom schedules, automatic card charging (Paystack subscriptions) and late fees are not offered. Refunding a part does not reopen earlier parts automatically; a school that refunds should close the plan.
