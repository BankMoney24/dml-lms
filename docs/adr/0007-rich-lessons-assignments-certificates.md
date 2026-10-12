# ADR 0007: Rich lessons, assignments and certificates

Date: 2026-10-12 · Status: accepted

## Context

Courses could only hold plain-text readings and multiple-choice quizzes. An advanced technical course needs formatted text with commands and code, video, work that a teacher judges, and a credential at the end (PRD CRS-001/002, ASM-003, ASM-005). Constraints stay the same: no runtime dependencies, strict CSP, low bandwidth, tenant isolation.

## Decision

* **Markdown subset, rendered on the server** (`App\Core\View\Markdown`). It escapes every piece of text and emits only tags it builds itself, so author and learner text can never inject HTML. Links are limited to `http(s)://`, site paths and fragments. No third-party parser: the subset is small and fully tested, and a general Markdown library would allow raw HTML by default.
* **Video lessons embed only YouTube (privacy-enhanced `youtube-nocookie.com`) and Vimeo** (`dnt=1`). The CSP gains a `frame-src` for exactly those two hosts. Nothing is loaded until the learner presses Play; without JavaScript the button is a plain link. URLs are parsed into provider + id and stored in one canonical form.
* **Assignments** are a lesson type. Submissions are rows in `assignment_submissions`; a pending one may be corrected, a reviewed one is never changed, so the history is the audit trail. Approval completes the lesson through the same `recordLessonCompleted` path as a passed quiz. Reviews lock the row (`FOR UPDATE`), refuse self-review, and queue the learner's email in the same transaction. File uploads are deliberately left for a later slice (storage quotas, scanning).
* **Quiz explanations** are revealed only after the learner passes or has no attempts left; before that they see which questions were wrong, not the answers.
* **Certificates** are issued inside the completion transaction, one per enrolment. The code is 12 characters from a 32-symbol alphabet without 0/O/1/I (60 random bits), unique across the platform, and verified only on the issuing school's host. Name and course title are copied at issue time. Courses completed before this release get their certificate the next time the learner opens the course or dashboard. The router gained a `{name:code}` segment type for mixed-case codes.
* **Course packages**: a folder with `course.json` and Markdown files, imported through `CourseAuthoring` in one transaction, so a package obeys the same validation as the teaching screens and a broken package leaves nothing behind. Lesson files must resolve inside the package folder.

## Consequences

* Lesson text written before this release renders as before (paragraphs and line breaks), apart from Markdown markers it may happen to contain.
* Migration 000005 uses `ALTER TABLE … DROP CONSTRAINT`, which needs MySQL 8.0.19+ or MariaDB 10.2+. Verified on MySQL 8.0.46 and MariaDB 10.11.
* Revocation is a console operation for now; a staff screen can follow.
