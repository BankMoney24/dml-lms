# ADR 0025: Applying for a place, the waiting list, and the new course page

Date: 2026-10-19 · Status: accepted

## Context

Some courses have limited places or an intake the school selects (a cohort, a sponsored programme, a lab with a fixed number of seats). The school wants people to fill a form and wait to be added, instead of joining directly. The course page itself looked plain, and the whole interface used the device's default font.

## Decision

### How people join a course

* Each course has **How people join** in the editor: *They join directly* (as before) or *They apply and wait*, plus an optional **number of places**. A course with places left behaves as before; once full, new people join the **waiting list**.
* **Applying.** Visitors give name, email, optional phone and a note, and agree to be contacted (hidden bot field; 10 per hour per connection, 5 per day per email). Members apply with their account in one click. Nobody can apply twice while an application is open; the page then shows "Application received" or the member's **place in line**, with "Leave the waiting list".
* **Deciding** (Teach → Applications, sidebar count, oldest first): *Offer a place* or *Decline*. Offering a free course enrols the person at once; offering a paid course lets them pay on the course page (no one can pay before being offered a place). Declining tells members by notification. Owners and the course author hear about new applications.
* **People without an account** get one (as a learner) when offered a place: an emailed invitation when the school sends email, otherwise a one-time password shown once to the administrator to pass on. Creating accounts needs permission to manage members, so a teacher asks an administrator for those.
* **The rule lives in the enrolment service**: enrolling, paying, starting a learning path and joining from a sign-up all check it. Course builders, people already enrolled, people offered a place, and classes a teacher assigns are not held back.

### Course page and type

* The course page opens with a navy **hero**: track, title, summary and chips (level, hours, modules, lessons, labs, price or Free or Open, places left or "Apply for a place"), with the course's own pixel cover behind it. The **join card** (enrol, pay, apply, waiting list, progress) sits beside the content and stays in view on wide screens; on phones it comes straight after the hero.
* The interface uses **Plus Jakarta Sans**, self-hosted (about 27 KB for Latin, SIL Open Font License included), with system fonts as fallback.

## Consequences

* Schools can run intakes: collect interest, then offer places in order.
* Offered-but-unpaid places do not count against the number of places until the person joins; schools watch the "Offered, not yet started" tab.
