# ADR 0022: Questions under lessons, and school reports with downloads

Date: 2026-10-14 · Status: accepted

## Context

Learners who got stuck had nowhere to ask except the help desk, which is for account and school problems, not course content. Teachers had a progress table per course but no school-wide view, and administrators had no way to take figures into a spreadsheet (ADM-001 listed "tenant-wide reports and export next").

## Decision

### Questions and answers (`lesson_questions`, `lesson_answers`)

* Under every lesson, people **enrolled in the course** and **course builders** can read, ask and answer. Nobody else sees them: not visitors, not guardians, not readers of an open course (ADR 0021), not members of other schools. The service checks this on every action, not only the page.
* Text is the platform's safe Markdown (everything escaped, code blocks for programmers, links only to http(s) with `nofollow`). Questions are 10–3,000 characters, answers up to 5,000. The same question twice is refused; 10 questions and 30 answers per person per hour.
* The person who asked, or a teacher, marks one answer as **the answer**; its author is told. Teachers' answers carry a "Teacher" badge and count as "Teacher replied".
* Authors delete their own posts (a deleted question cannot be brought back). Teachers hide any question or answer and can show it again; a hidden answer stops being the accepted one. Every action is audited.
* Notifications: the course's author and the learner's class teachers hear about a new question; the person who asked hears about answers.
* **Teachers' inbox** (`/teach/questions`, sidebar "Questions" with a count): questions waiting for a teacher (no teacher reply and no accepted answer), oldest first, answered in place; "All recent" and "Hidden" tabs.

### School reports (`/school/reports`)

* Live figures from aggregate queries: learners (and new in 30 days), people learning this week and month, course places and completions, certificates, questions asked.
* Every course: enrolled, a bar of completed / in progress / not started, completion rate, average progress, last activity, linking to the course's own progress table.
* People who manage members also see every learner (find by name or email, filter by class, 50 a page) with courses, completions, lessons done, certificates and last activity. People who manage the school also see payments received per currency.
* **Downloads** (`courses.csv`, `learners.csv`): UTF-8 with a byte-order mark for Excel, times in UTC, and every cell that a spreadsheet would run as a formula (`= + - @`, tab, carriage return) prefixed with an apostrophe. Teachers can download course figures; the learner list (with email addresses) needs members permission. Each download is recorded in Activity with its row count.

## Consequences

* Course content questions move out of the help desk and stay attached to the lesson they are about, for the next learner.
* Learner data leaves the platform only by a deliberate, recorded download by someone who can already see it.
