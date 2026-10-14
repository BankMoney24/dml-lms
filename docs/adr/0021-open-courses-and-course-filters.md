# ADR 0021: Open courses, course list filters, and seeing every course

Date: 2026-10-14 · Status: accepted

## Context

A free course was still closed to anyone without an account: visitors saw the outline and "Sign in to enrol", and could read only lessons marked as free previews. A school whose course list is for members only showed visitors nothing at all. Teachers opening a course they did not write found no way into its lessons from the course page, and learners who followed a lesson link before joining got a bare "forbidden" page.

## Decision

* **Who can read a course** (`courses.access`, set in the course editor by anyone who builds courses): *People who join* (the default, unchanged behaviour) or *Everyone*. An open course must be free; a price always wins (`Course::isOpen()` is false for a paid course, the editor refuses to open a paid course, and a price cannot be set on an open course until it is switched back).
* **What "open" opens.** Every reading and video can be read by anyone, with or without an account, at `/courses/{slug}/preview/{lesson}`, with previous/next links between open lessons. Quizzes, labs and assignments stay for people who join, so attempts, progress, reviews and certificates work exactly as before; the outline marks them "Join to do this". Reading never enrols anyone and records nothing.
* **Open courses are visible even when the school's course list is for members only.** Visitors then see only the open courses on `/courses`, the public home page ("See the free courses") and the header link; every other course still asks them to sign in, and unknown addresses get the same sign-in redirect so nothing hints at which courses exist. Open courses and their readable lessons go into the sitemap and get share images and `index, follow`.
* **Course list filters** on `/courses`: kind (school subject / skills, as before), price and access (any, free, open to read, paid), level, and words in the title or summary (wildcards searched as text). Counts sit on each filter; combinations are links, so they can be shared. Only the unfiltered list and single "show" filters are indexable, with `?show=` as their canonical form.
* **Cards and badges.** Free courses say "Free" on their card, open ones also carry "Open"; the course page shows "Open to everyone" or "Free"; the teaching list marks open courses.
* **Getting to a lesson.** Course builders see "You teach here" on any course page, and every lesson in the outline links to the staff preview (quizzes and labs included, nothing recorded). A signed-in learner who opens a lesson they have not joined is sent to the readable version if there is one, otherwise to the course page with "Join the course to open …". When sign-up is open (ADR 0020), visitors are offered "Create a free account" instead of only "Sign in".

## Consequences

* A school can publish free learning to the whole web one course at a time, without opening its whole course list.
* Readings in open courses are public: authors should not put anything there that is meant only for enrolled learners.
