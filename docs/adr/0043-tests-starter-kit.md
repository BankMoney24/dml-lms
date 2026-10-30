# ADR 0043: The tests starter kit — sample banks, live quizzes and exams

Date: 2026-10-30 · Status: accepted

## Context

Update 42 (ADR 0042) added question banks, CBT exams and live quizzes, with five small sample banks of 10–13 questions each. The school asked for these to be seeded: live quizzes, CBT exams "and others". With so few questions, a mock UTME was very short. Every live quiz and exam also had to be built by hand before anyone could see the features working, and the sample school (`sample:seed`, ADR 0027) had no tests activity at all.

## Decision

### What the kit holds

The starter kit has three parts. Every question and quiz in it was written for this platform and avoids facts that change, such as office holders or prices. The kit says plainly, wherever it is offered, that **they are not past JAMB, WAEC or NECO questions**.

* **16 sample banks with 212 questions.** Every question has an explanation, a topic and a difficulty.
  * The five core banks (Use of English, Mathematics, Physics, Chemistry, Biology) each gain 9–10 questions, giving 19–23 each.
  * Seven more school subjects have 10 questions each: Economics, Government, Geography, Agricultural Science, Commerce, Civic Education and Computer Studies.
  * Four banks follow the school's own courses: Networking Fundamentals (11 questions, with OSPF answered as the network builder models it), Cybersecurity Basics, Python Programming and Linux Basics (10 each).
* **6 live quizzes, ready to host.** They are Friday general knowledge, Science quick check, Mental maths sprint, Networking basics, Staying safe online and Python warm-up.
  * Each quiz has 8 questions with up to four short answers.
  * Time limits come from the allowed set, and the last question of each quiz scores double.
* **5 CBT exams, added as drafts with no dates.** A teacher checks each one, sets times and classes, and publishes it.

  | Exam | Sections | Choose | Time |
  |---|---|---|---|
  | Mock UTME: sciences | Use of English (20, compulsory), Mathematics, Physics, Chemistry, Biology (15 each) | 3 | 50 min |
  | Mock UTME: arts and social sciences | Use of English (20, compulsory), Government, Economics, Geography, Commerce, Civic Education (10 each) | 3 | 40 min |
  | Mathematics class test | Mathematics (20) | – | 30 min |
  | Networking Fundamentals test | Networking (11), Security (5) | – | 20 min |
  | Digital skills check | Computer Studies (10, compulsory), Python, Linux, Cybersecurity (10 each) | 2 | 30 min |

  Section counts are capped at what each bank holds when the kit is added.

### Adding it

* **Where it is offered.** A "Starter kit" panel appears on the Live quizzes, CBT exams and Question banks lists. It shows the kit's contents and, while something is missing, a button: "Add the starter kit" for a school with none of it, or "Add what is missing from the kit". The same thing runs on the server with `bin/console exams:starter-kit <school> [--author=<email>]`. Without `--author`, the first owner, admin or teacher is recorded as the author.
* **Safe to repeat.** Adding the kit again never duplicates anything:
  * Sample banks are found by name. A bank the school already has is **topped up** with any sample questions it lacks, matched by their exact wording. A school that added the five banks in update 42 therefore gets the new questions.
  * Archived sample banks are left alone.
  * Quizzes and exams are added only when no quiz or exam has the same title. An archived quiz counts as present, so one a teacher put away does not come back.
* **What counts as missing.** The panel counts sample questions missing from banks the school keeps, as well as missing quizzes and exams. Sample questions added in a later update therefore bring the button back.

### The sample school

`sample:seed` now also fills in tests activity:

* the kit, added by a teacher on day 20;
* the sciences mock UTME, published on day 57, sat by about 70% of the learners who had joined by then, and closed on day 72. A few candidates run out of time, so their attempts are submitted at the deadline;
* the Mathematics class test, published on day 82 and left open, with some attempts;
* practice tests;
* two finished live quiz games, on days 80 and 86, with 8–16 players each.

All of this goes through the real services with the sample clock. Each candidate and player has their own skill level, so the results tables, item analysis and podiums look real. Only learners who had joined by the date take part, and nothing is dated in the future.

## Consequences

* No new tables. The kit is data in code: `SampleBanks`, `SampleSubjects`, `SampleTech`, `SampleQuizzes` and `SampleExams`. Unit tests check every sample against the rules the forms enforce: 2–5 options in banks, 2–4 short answers in live quizzes, allowed time limits, exam layouts that leave a real choice, and no question asked twice in a bank (topping up depends on that).
* Exams in the kit are never published automatically, and nothing in it is presented as an examination body's material.
