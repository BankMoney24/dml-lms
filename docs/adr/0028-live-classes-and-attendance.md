# ADR 0028: Live classes and attendance

Date: 2026-10-21 · Status: accepted

## Context

Schools teach live as well as online: evening cohorts on Google Meet or Zoom, practicals in a lab, revision sessions in a classroom. Teachers kept timetables and registers on paper or in group chats. Parents asked whether their children turned up, and learners missed classes because the time or link was buried in a message.

## Decision

* **Classes belong to a class, cohort or group.** Its teachers (and administrators) plan a class from the group page:
  * a title, day, start time and length;
  * where it happens: **online** (a meeting link), **in a room**, or **both**;
  * an optional course and notes for learners;
  * **once or weekly** (up to 15 weeks, linked as one series).
* **Times** are typed in the school's time zone and stored in UTC. Pages and the calendar show them in the school's time.
* **Learners** see their classes:
  * on the home page (a "Live now / Starting soon" card with a Join button, and a panel of upcoming classes);
  * on `/classes`, with their attendance;
  * in their calendar feed (the existing private `.ics` link, now named "classes and due dates", with cancelled classes marked as such).
  * They are told when classes are planned and when one is cancelled (with the reason).
* **Joining.** The Join button is a plain link to the meeting, shown from 15 minutes before the start until the end.
  * While it opens, the page records a check-in: present, or late after 10 minutes. It never overwrites a mark that already exists.
  * The meeting link is not a redirect through this site. A form post may not redirect to another site under the page's security policy, and a plain link keeps the meeting provider out of our logs.
  * Links must be plain http(s) addresses with a host name.
* **The register.** From the start of the class, teachers mark each learner present, late, absent or excused, with an optional note.
  * "Mark everyone not marked as present" speeds this up. Self check-ins appear already marked as "joined online".
  * Only learners of that group can be marked.
  * Parents who follow their child's notices are told **once** when the child is marked absent.
* **Attendance.** Rate = (present + late) ÷ (classes marked − excused).
  * Classes with no register yet do not count against the learner, and only classes since the learner joined the group count.
  * The group page shows a bar per learner (below 75% in red) and offers a CSV download.
  * Parents with progress permission see the rate, recent classes and what is coming up.
* **For teachers:**
  * Today's classes and "registers to take" appear on the staff home page, and in the Live classes sidebar link with a count.
  * Changing a class's time, place, link or recording is a single edit; cancelling needs a reason.
* **Sample data:** `sample:seed` now gives every sample group a weekly class with attendance, one cancelled class and some registers left to take.

## Consequences

* A school can run its timetable, registers and parent attendance notices in one place, for online, in-person and blended classes.
* The platform does not host video. It links to the school's own meeting service and does not know who actually stayed in the meeting; the teacher's register is the record.
* Check-in depends on JavaScript sending the background request. Without it the link still opens the meeting and the teacher marks the register.
