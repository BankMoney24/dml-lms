# ADR 0045: Leaving the exam page, and exam results on the class page

Date: 2026-10-31 · Status: accepted

## Context

Schools running computer-based tests in a hall, or at home, asked two things:

* Can a teacher tell whether a candidate left the exam to look something up?
* Can a class teacher see the whole class's exam results in one place, beside progress and attendance, and take them away as a spreadsheet for term reports?

Locking a browser down is not possible on a website, and a heavy-handed "proctoring" tool would treat every phone call or low-battery warning as cheating. We record what the browser can honestly report. We say so to candidates, show it to teachers, and leave the judgement to people.

## Decision

### Leaving the exam page

* **What the room records.** In an exam (not practice), the room counts the times the page stops being visible or loses focus for **2 seconds or more**, and adds up the time spent away. Moving to another tab or app, minimising the browser, or a phone call all count. Shorter flickers of focus are ignored.
* **How it is sent.** The running totals (`away: {count, seconds}`) travel with every save, heartbeat and the final submission.
  * The server keeps the larger of what it has and what it is sent (`GREATEST`), so totals never go down.
  * Values are capped at 10,000 times and 24 hours, and anything malformed is ignored.
  * Nothing is recorded after submission, or for anyone but the candidate.
  * `cbt_attempts.away_count` and `away_seconds` hold the totals (migration 000035).
* **Candidates are told.** The exam room shows "Your teacher sees if you leave this page or switch apps". The first time they come back, a screen-reader announcement says the same. Practice tests are not watched and say nothing about it.
* **Teachers see it.** It appears as "3 times, 1 min 5 s":
  * on the results page, both for candidates sitting now and in the submitted list;
  * on the candidate's review;
  * in the CSV (times, seconds);
  * on the class page.

  Each place notes that leaving the page can have innocent reasons. Nothing is automatic: the exam is never stopped, marked down or flagged as cheating.
* **Sample school.** About 15% of sample exam attempts show some time away.

### Exam results on the class page

* **The table.** The class page gains an "Exams" section listing the published or closed exams set for the class or for everyone, up to the latest six.
  * Each learner's latest attempt shows as "250 of 300" and "% right", "Sitting now" or "Not sat", with "Left the page …" when it applies.
  * A class-average row shows the average percentage and how many have sat.
  * Column headings link to each exam's results.
* **What teachers see.** The class's teachers and staff who manage classes see every score, even when an exam holds results back from candidates. Learners cannot open the page or the download.
* **Download.** "Download exam results" (`/groups/{id}/exams.csv`) gives, for each exam, three columns: total, out of, and % right. Unfinished attempts show as "not sat" or "sitting", and cells are made safe for spreadsheets.

## Consequences

* Away counts are evidence for a conversation, not proof. A candidate who uses a second device, or a determined one who disables JavaScript events, is not caught. For high-stakes exams the school still needs invigilation and an access code.
* The class table shows six exams at a time; the CSV covers the same six. Older exams stay on their own results pages.
