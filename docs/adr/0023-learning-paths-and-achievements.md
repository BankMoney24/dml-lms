# ADR 0023: Learning paths and achievements

Date: 2026-10-15 · Status: accepted

## Context

Learners, especially people changing career into tech, asked "which course do I do first, and what next?". Courses stood alone. There was also nothing to mark progress beyond a single course: a learning streak and certificates, but no recognition of steady effort, helping classmates or practising code.

## Decision

### Learning paths (`learning_paths`, `learning_path_courses`, `learning_path_followers`)

* Course builders create a path (name, who it is for, level) at Teach → Learning paths, add courses, put them in order (move up / down) and publish. Publishing needs at least two courses and every course published; a published path keeps at least two. Deleting a path (typed confirmation) never touches its courses.
* Courses keep all their own rules: price, enrolment, certificates, open access (ADR 0021). The path adds order and progress across courses; it does not bypass payment.
* Path pages (`/paths/{slug}`) show the courses as a route with each one's state for this learner (done, lessons done so far, price, up next). Visible like the course list: to everyone when it is public, to members otherwise; drafts are never shown. Published paths lead the course list's first page and are in the sitemap when the list is public.
* "Start this path" follows it and joins the first unfinished course when that course is free; a paid course is left for the learner to pay on its page. Followed paths appear on the home page with progress and the next course. Stopping following keeps every course and its progress.

### Achievements (`achievements`)

* Ten achievements, each earned by something recorded in the learning data: first lesson, first course, three courses, 100% on a quiz, a passed lab, seven learning days in a row, a question asked, an answer marked as the answer to someone else's question, five saved code snippets, and every course of a followed path.
* They are worked out from the records when the learner opens their home page or Achievements page, so a missed hook can never lose one and nothing can be claimed by hand. Each is stored once with the time the thing actually happened.
* A notification goes out only for achievements earned in the last two days, so learners with history are not flooded the first time.
* Achievements belong to one school. Only people who learn (course.learn) have them.

## Consequences

* Schools can offer career-shaped programmes ("Junior IT support technician") from courses they already have.
* Computing achievements on page view costs about ten small indexed queries for the learner; fine at this scale, and easy to move to a background job if it ever is not.
