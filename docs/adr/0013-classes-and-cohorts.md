# ADR 0013: Classes, cohorts and groups of learners

Date: 2026-10-13 · Status: accepted

## Context

PRD 6.4 and 7 ask for the academic structure a school works in (classes, cohorts, programmes), class reports for educators (ADM-001), and terminology each institution can set (TEN-004). DML serves formal schools (SS 2 Gold, Primary 4B) and informal training (a January intake), so one fixed word fits nobody.

## Decision

* **One concept, the school's word.** `learner_groups` hold a name, an optional level (from the school's own list of levels) and a year or term. Each school picks what to call them in setup (class, cohort, group, stream or set; `tenants.group_term`), defaulting by type: schools say class, training centres cohort, others group. URLs stay `/groups`.
* **Members** (`learner_group_members`) are learners or teachers, both bound to the school membership by a composite foreign key. Only people with the learner role can be learners; owners, admins and educators can teach.
* **Courses** (`learner_group_courses`) have an optional due date. Setting a course enrols every learner in the group; a learner who joins later is enrolled in the group's courses. Removing either keeps enrolments and progress.
* **Paid courses:** an administrator setting a paid course for a group means the school covers it, so those learners are enrolled without paying (`LearningService::enrolForGroup`, audited as `learning.enrolled_by_group`). Teachers can only set free courses.
* **Access:** `groups.manage` (owners and admins) create, rename, archive and choose teachers. A group's teachers see it, add learners and set free courses; other educators get "not found". Learners see their own group, its teachers and its courses with due dates on their home page, never their classmates.
* **Progress:** one grid per group, learners against courses (lessons done of the course's total, completed, past due), with the last activity and a formula-safe CSV download.
* **Archiving** at the end of the year hides the group from learners and freezes it; nothing is deleted.

## Consequences

* Reports by level or across groups, attendance, timetables and guardians' views can build on these tables.
* A learner can be in several groups; their home page shows each.
* Due dates are guidance: nothing is locked when one passes.
