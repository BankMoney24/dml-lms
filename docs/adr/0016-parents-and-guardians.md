# ADR 0016: Parents and guardians

Date: 2026-10-13 · Status: accepted

## Context

PRD 7.7 (PAR-001…004) is required before children use the platform: guardians linked through a verified, auditable process; explicit record-level permissions; clear consent and data routes; and notices that do not expose sensitive information.

## Decision

* **The school verifies, then links.** Only people who manage members (`members.manage`) link a guardian to a learner, from People → the learner → Parents and guardians, after checking who the guardian is (for example at the school office). The link stores the relationship, who verified it and when; linking, changing and unlinking are audited. If the email is new, the guardian is added to the school with the guardian role and invited (email, or a one-time password shown once). An existing member, such as a teacher who is also a parent, is linked to their own account and keeps their role. A learner cannot be their own guardian.
* **Each link says what may be seen** (PAR-002): courses and progress, quiz scores and assignment results, and class announcements, each on or off. The guardian's page fetches only the allowed parts, and lists them under "What you can see" with how to ask the school about the child's information (PAR-003).
* **Read-only and narrow.** Guardians see `/children/{learner}` only for active links to active learners in the same school; anything else is "not found". They cannot open lessons, change anything, or see other learners or classmates.
* **Notices** (PAR-004): guardians allowed to see announcements hear when the child's class posts one ("News from Tobi's class"); guardians allowed to see progress hear when the child finishes a course. In-app only, by first name, no scores.

## Consequences

* Account recovery for guardians is the school's usual route (password reset email, or the school resets it).
* Attendance and termly report sheets (ADM-005) can be added to the same page later, behind their own permission.
