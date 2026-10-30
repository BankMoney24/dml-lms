# ADR 0046: Term report cards from CBT exams

Date: 2026-10-31 · Status: accepted

## Context

At the end of each term, schools in Nigeria give every learner a report card. It shows:

* the score in each subject, made up of continuous assessment and the exam;
* a grade, usually on the nine-point A1 to F9 scale;
* the learner's position in each subject and in the class;
* attendance;
* the class teacher's and the head's remarks;
* when the next term begins.

The platform already had per-exam results (ADR 0042), class exam results (ADR 0045) and a printable parent report (ADR 0032). Teachers still copied scores into spreadsheets by hand to make report cards.

## Decision

### What a report card is

* A **report card** belongs to one class and one term (`report_cards`). It names up to six CBT exams that count, each with:
  * a name for the card (for example "Mid-term"), and
  * a weight from 1 to 100 (for example 30 and 70).

  The exams must be published or closed, and set for the class or for everyone.
* Each card also has a grading scale: A1–F9, or A–F.
* A last line can say, for example, when the next term begins.
* **Subjects** are the exams' section labels ("Use of English", "Physics"), in the order they first appear.

### How it is worked out

The card is worked out from each learner's latest submitted attempt at each exam, every time it is shown. Nothing is copied, so a corrected key, an extra-time submission or a "sit again" shows straight away.

* **Subject score.** It is a weighted average out of 100: Σ weight × section score ÷ Σ weight, over the exams that include the subject.
  * A learner who **missed** an exam scores 0 for its subjects, and the card says "Absent".
  * A subject the learner **did not choose** in an exam they sat (an optional UTME-style subject) is left out of that exam's part.
  * A subject with no part at all is left off their card.
* **Average and positions.**
  * The average is over the subjects on the learner's card.
  * Positions in each subject and in the class are ranked; ties share a place (1st, 2nd, 2nd, 4th).
  * A learner with no results has no average and no position.
* **Attendance** is taken from the class's live-class registers (ADR 0028).

### Remarks

* The class teacher writes the teacher's remark. Only someone who manages every class (the head or an administrator) writes the head's remark; a teacher's form keeps the head's remark as it is.
* Remarks are up to 600 characters (`report_card_remarks`).
* The per-learner page has "Save and go to the next learner", so a teacher can work through the class in one sitting.

### Pages

* **For teachers** (the class's teachers and staff who manage classes):
  * a list and a "new report card" form, reached from the class page;
  * a broadsheet with every learner's subject scores, grades, average, position, and whether their remark is written;
  * a page for each learner with their card and the remarks form;
  * "Print all cards", one card per printed page;
  * the broadsheet as CSV.
* **Sharing.** "Share with the class" publishes the card.
  * Each learner sees **only their own** card under Report cards (`/report-cards`, also linked from Exams).
  * Parents whose link allows results see their child's card on the child page.
  * Both are notified (`report_card.published`).
  * "Take it back" unpublishes the card. A card can be deleted only while unshared.
* **The printed card** reuses the parent-report styles (ADR 0032): school name and logo, learner, class, average, position, attendance, then a table of exam parts, score, grade and position for each subject, the remarks, and a key to the weights and grades.
* **Sample school.** It gains a shared report card for one class, with remarks.

## Consequences

* Two tables (migration 000036), described in the data dictionary.
* Continuous assessment that is not a CBT exam (assignments, projects, oral tests) cannot be entered yet. A teacher who uses the platform for every assessment gets complete cards. Adding hand-entered scores is the obvious next step.
* Positions and grades follow common Nigerian practice. Schools that do not rank learners should not share positions. A setting to hide positions is a small follow-up.
