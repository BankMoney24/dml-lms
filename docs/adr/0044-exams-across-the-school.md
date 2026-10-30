# ADR 0044: Exams across the school, and pictures in questions

Date: 2026-10-31 · Status: accepted

## Context

After ADR 0042 and 0043, CBT exams worked on their own pages but nowhere else:

* nobody was told when an exam was set;
* results that were held back until the exam closed appeared without a word;
* a learner's home page did not mention exams;
* parents with permission to see results saw quiz scores but not exam results.

ADR 0042 also left out pictures in questions. Many science, geography and agriculture questions cannot be asked without a diagram, graph, map or circuit.

## Decision

### Notices

Two new notification kinds tell learners about exams.

* **`exam.set`.** When an exam is published, every learner who may sit it gets a notice: all active learners, or the members of the exam's classes. The notice links to the exam and gives its length, its number of questions, and, in the school's time zone, when it opens and closes.
* **`exam.results`.** For exams whose results show "when the exam closes", each candidate is told once when the results are ready. The notice links to their latest attempt's result page.
  * This happens when a teacher closes the exam. When the closing time passes on its own, the `exams:close` cron does it (every 5 minutes).
  * `cbt_exams.results_announced_at` records that the notice went out. Setting it is a conditional update, so two cron runs cannot both announce.

### Home page

Learners get an "Exams to sit" panel at the top of the side column.

* It lists up to four exams they can start now, with the length and closing time.
* It also lists any exam they have started, with "In progress" and the minutes left. These link straight back into the exam room.
* An exam they have already sat, or cannot sit, does not appear.

### Parents

A guardian whose link includes "results" now sees exam results. They appear on the child page and on the printable report, and the permission is relabelled "Quiz and exam scores, and assignment results".

* Each row shows the exam, the score in each subject out of 100, the total, and the share of questions answered right.
* Only results the learner can see themselves are shown. Results held until closing appear only after the exam closes, and hidden results never appear.
* Practice tests and teachers trying a paper are never shown.

### Pictures in questions

* **Adding a picture.** A bank question can have one picture: PNG, JPEG, WebP or GIF, under 2 MB. Teachers add, replace or remove it in the question editor, once the question is saved.
* **Checks and storage.** The picture is checked and virus-scanned like every upload, counted in the school's storage, and stored outside the web root as a file owned by the question (`files.bank_question_id`, cascading). Replacing or removing a picture deletes the old file.
* **Description.** Every picture needs a written description of up to 300 characters, for screen readers and for phones that cannot load the image. The hint asks teachers to describe what the question needs, not the answer.
* **Who can open it.** Pictures are served through `/files/{id}` to:
  * teachers;
  * learners who have the question in one of their papers (exam, practice or trial);
  * learners who may practise from the question's bank.

  Anyone else gets "not found", as with every file, so file ids reveal nothing.
* **Where it appears.** The picture is shown in the exam room under the question, sized for a phone, and on the review pages. The bank list marks questions that have one.
* **Live quizzes.** Copying from a bank into a live quiz skips questions with a picture or a passage, because live quizzes show neither.

## Consequences

* Migration 000034: `cbt_exams.results_announced_at`, `files.bank_question_id`, and `bank_questions.image_file_id` / `image_alt`. The data dictionary is updated.
* Publishing an exam for a large school writes one notification per learner, inside the request. This is acceptable at our sizes (thousands of learners).
* Not done yet:
  * pictures in passages and in live quiz questions;
  * importing pictures with Aiken or CSV;
  * pictures in the CSV export.
