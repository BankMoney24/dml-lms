# ADR 0015: In-app notifications and class announcements

Date: 2026-10-13 · Status: accepted

## Context

NTF-001 asks for an in-app notification centre for enrolment, submission, grade and account events. Teachers also need to tell a class something ("Lab day is on Friday") without a separate channel. PAR-004 warns against sensitive details in notices.

## Decision

* **One table, one line per person.** `notifications` holds a short general title, an optional one-line body, an optional link and read time, for one member of one school. Rows are inserted only for current members (the insert joins active memberships) and inside the same transaction as the event, so there is no notice without the event.
* **Events:** new assignment work (to the course author and the learner's class teachers, once per submission, not per correction); review decisions (to the learner, alongside the existing email); joining a class; a course set for the class or its due date changed; becoming a class teacher; payment confirmed; certificate issued; class announcements.
* **Safe links only.** A notification's link is kept only if it is a path on the same site; opening one marks it read and redirects there. Someone else's notification is "not found".
* **Plain words, no marks.** Titles name the lesson or course and the decision, never scores or private matters; teachers are reminded of this when posting.
* **Bell in the top bar** with the unread count (capped at 99+, counted with an indexed query on each signed-in page) and a Notifications page with "Mark all as read". Read notifications older than 90 days are removed when the person opens the list; no extra cron job.
* **Announcements** belong to a class or cohort: its teachers and administrators post and delete them; learners see the latest two on their home page and get a notification. Learners never see other classes' announcements or who else is in their class.

## Consequences

* Email stays for account and review messages; SMS and WhatsApp (NTF-003) and push notifications can be added later as further channels from the same events.
* Notifications are not a message thread: learners cannot reply. A support or discussion feature would be separate.
