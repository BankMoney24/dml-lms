# ADR 0024: Joining from a course to pay, receipts, a fuller front page, and the TridaxLabs panel

Date: 2026-10-16 · Status: accepted

## Context

Adults and students who find a course want to sign up and pay (or start) in one go. Until now a visitor who chose "Create an account" landed on the getting-started questions and had to find the course again; signing in always went to the home page. Learners had no receipt for what they paid. The public front page was a single hero and a course list, and the TridaxLabs credit was a plain link although the school wants visitors to see what TridaxLabs builds.

## Decision

* **Join for a course.** Course and lesson pages send visitors to `/join?course={slug}`. The join page says which course and its price; after the account is made, a free course is joined at once and the learner lands on it, and a paid course opens at its payment box (Paystack). The getting-started questions wait on the home page. Signing in from a course uses `/login?next=…`, accepted only for paths on this site (no scheme, no `//`, no backslashes, at most 300 characters); anything else goes to the home page.
* **What the price includes**, shown on the payment box from the course itself: its readings, labs, quizzes and assignments, its hours, a certificate when the school issues them, and questions under lessons. No promises the platform cannot check.
* **Receipts.** Every confirmed payment has a printable receipt at `/account/receipts/{reference}` (linked from the account page): school, reference, date, payer, method, Paystack transaction, course and amount. Only the payer and people who manage the school can open it; pending or failed payments have none. Printed on A4 portrait without the app's navigation.
* **Front page.** A search box into the course list; real figures (published courses, lessons, hands-on labs, learning paths, counted from the database, shown only when there are at least two kinds); learning paths; popular courses (by learners); open courses to start reading free; who learns here (programmers, students, adults changing career) with a sign-up button when sign-up is open. Nothing invented: no ratings, testimonials or learner counts.
* **TridaxLabs panel.** The credit's name opens a panel of what TridaxLabs builds: software categories grouped by sector, with a link to tridax.org. It opens on hover (after a short pause, closing when the pointer leaves), on keyboard focus with Enter or arrow keys, or on a tap (as a bottom sheet on phones); Escape, the close button or a click outside closes it and focus returns to the link. Without JavaScript the name is a plain link. The list lives in `resources/data/tridax.php` so TridaxLabs can keep it current without code changes; the headline count is worked out from it.

## Consequences

* A visitor can go from a course page to paying for it with one form in between.
* The TridaxLabs list is content the school and TridaxLabs own: it must be kept true to what TridaxLabs actually offers.
