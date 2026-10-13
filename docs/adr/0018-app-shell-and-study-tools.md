# ADR 0018: App shell and study tools

Date: 2026-10-14 · Status: accepted

## Context

Schools asked for a more modern interface and for tools learners use every day: a way to come back to a lesson, somewhere to write things down, a sense of progress over time, due dates on their phone, and a way to try a course before paying. The platform still has to work on slow connections, without a build step, under a strict content security policy (no inline script or style).

## Decision

* **App shell for signed-in pages.** A navy sidebar like the dark logo (Home, Courses, Saved and notes; Teaching; School), with only the links a person's role uses and counts of work waiting (reviews, open help requests). The current page is marked with one lit pixel. A slim app bar holds search, notifications and an account menu (built on `<details>`, so it works without scripts). On phones the sidebar slides in; without scripts it opens with `:target`. Icons come from one SVG sprite. Public pages keep the simple top bar.
* **Appearance per person.** `users.appearance` is `system`, `light` or `dark`. A chosen value is written on `<html data-appearance>`; every dark-mode rule applies under the media query unless the person chose light, and again when they chose dark.
* **Search.** `/search` matches published courses, their lessons, page shortcuts, and, by permission, draft courses, classes and people. Plain escaped `LIKE` on titles, inside the school only. The same results come back as JSON for the search box, which answers as you type (`/` or Ctrl/Cmd+K). Lessons link to the lesson when enrolled, to a free preview when there is one, otherwise to the course.
* **Saved lessons and private notes** (`lesson_notes`). One row per person per lesson, kept only while it holds a bookmark or text, up to 5,000 characters. Never shown to teachers or guardians.
* **Learning days and streak.** Counted from lesson opens and completions, quiz attempts, lab runs, submissions and notes, in the school's time zone, for 16 weeks. Only counts are shown.
* **Coming up.** Due dates set through classes for courses not finished, overdue first.
* **Calendar link.** An iCalendar feed at `/calendar/{secret}.ics` with course titles and due dates only. The secret is 160 random bits; only its SHA-256 is stored on the membership, so the link is shown once and replaced or turned off at will. No session; ends when the person leaves the school.
* **Free preview lessons** (CRS-003). Authors mark readings and videos as previews. Anyone who may see the course page can read them at `/courses/{slug}/preview/{id}`, with a call to join or pay. Quizzes, labs and assignments are never previews.

## Consequences

* A full-text index can replace `LIKE` if catalogues grow past a few thousand lessons.
* Calendar links need the site on HTTPS for Google Calendar to fetch them.
