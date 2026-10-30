# ADR 0042: Question banks, CBT exams and live quizzes

Date: 2026-10-30 · Status: accepted

## Context

The school asked for two new ways to test learners:

* **A quick, game-style quiz for class time.** The teacher puts questions on a projector, learners answer on their phones, and points reward speed. The well-known commercial products do this, but we must not copy their name, colours or shapes. Ours is called "Live quiz", and the answer tiles use letters A to D and our own colours.
* **Computer-based tests like the exams learners sit to leave school and enter university.** The UTME is the model: Use of English is compulsory, the candidate picks three more subjects, there are 180 questions in 2 hours, and the server keeps the clock. The same room should work for mock exams, class tests and practice at home.

Course quizzes (ASM-001) belong to one lesson, and their questions belong to that quiz. Exams need questions that can be reused across papers, drawn at random, and given statistics. So questions now live in **banks**, which both new features use.

## Decision

### Question banks

* A bank belongs to the school. It holds multiple-choice questions, each with:
  * a prompt in Markdown;
  * 2 to 5 options;
  * one correct option;
  * an explanation, a topic and a difficulty (all optional);
  * optionally a **passage**, a comprehension text shared by several questions.
* **Import.** Teachers paste or upload questions in Aiken text (the format Moodle uses, with optional EXPLANATION, TOPIC and DIFFICULTY lines) or as CSV with a header row. An import is all or nothing: one mistake names its line or row, and nothing is saved until every question reads cleanly. When options run straight into a new question with no ANSWER line, the importer reports the question that is missing its answer and carries on reading from the new question, so later errors keep their line numbers.
* **Export.** Banks export as CSV. Cells that look like formulas are made safe.
* **Retiring.** A question can be retired instead of deleted, so past attempts still mark and review correctly. Retired questions are never drawn into new papers.
* **Statistics.** Each question counts how often it was seen and answered right in real exams. Practice and teachers' trials are not counted.
* **Sample banks.** Five sample banks are available: Use of English (with a passage), Mathematics, Physics, Chemistry and Biology, 55 questions in all. **They were written for this platform. They are not past JAMB, WAEC or NECO questions**, and the bank description and the confirmation message both say so. They are open for practice from the start.

### CBT exams

* **Building an exam.** An exam has 1 to 8 sections. Each section draws a set number of questions from one bank, has a label, and can be compulsory. "Choose N" lets candidates pick N of the optional sections, as in the UTME. A new exam starts from a UTME layout: Use of English compulsory, three of the rest, 120 minutes. The question counts are capped at what the banks hold.
* **Exam settings.** These are:
  * opening and closing times, in the school's time zone;
  * who sits it: everyone, or chosen classes;
  * the number of attempts allowed: 1 to 10, or any number;
  * when results show: immediately, after the exam closes, or never (teacher only);
  * whether candidates can review their answers;
  * whether to shuffle questions and options;
  * an optional access code for the invigilator to read out.
* **Publishing.** An exam cannot be published while a section asks for more questions than its bank holds. Once published, only the time and access settings can change; the sections are fixed.
* **The paper.** Each candidate gets their own paper, drawn by `PaperBuilder` with PHP's `Random\Randomizer`:
  * questions on one passage stay together, in order;
  * options are shuffled for each candidate, and the order is stored;
  * the browser receives prompts and options in the candidate's order, never the key.
* **The clock.** The server stores a deadline. It is the start time plus the duration, but never later than the closing time. The rules around it:
  * Saves are accepted up to 30 seconds after the deadline, to allow for slow networks.
  * After that, the attempt is submitted **at the deadline**, not at the moment the server noticed. This happens on the next request, or by the `exams:close` cron every 5 minutes.
  * A teacher can add 5 to 30 minutes for one candidate, for example after a power cut, or reset the attempt so they can sit it again. Both actions are recorded in Activity.
  * Closing an exam submits everyone still sitting it.
* **Saving.** The room saves changed answers as JSON, each save with a sequence number. A save that arrives late never overwrites a newer one. Unsent answers are also kept in the browser until the server confirms them. The final answers travel with the submission.
* **Heartbeat.** Every 45 seconds the room checks in, and the timer is corrected to the server's time.
* **Marking.** The paper is marked against the bank's current answers, so a corrected key fixes every paper. Each section is scored out of 100 (right answers ÷ questions × 100), and the total is out of 100 × the number of sections sat. This is the UTME's 400 for four subjects.
* **The room.** The exam room uses a full-screen focus layout with:
  * a subject tab for each section and a numbered question palette;
  * flags, a calculator, and a submit dialog that lists unanswered and flagged questions;
  * warnings at 10, 5 and 1 minutes left;
  * keyboard keys: A–E to answer, N next, P previous, F flag, S submit.
* **For teachers.** Teachers get:
  * a results page with scores by subject;
  * a list of who is sitting now, with the time each has left;
  * a CSV of results;
  * item analysis, hardest questions first, showing how many candidates chose each option, so a wrong key or a misleading option stands out;
  * "Try it", which sits the exam as a candidate would, without counting.
* **Practice.** Learners can practise from any bank marked for practice. They choose up to four subjects, 5 to 60 questions each, and timed or untimed.

### Live quizzes

* **Writing a quiz.** A quiz has up to 100 questions (up to 50 copied from a bank at a time). Each question has:
  * 2 to 4 short answers (one per tile);
  * a time limit from 5 to 240 seconds;
  * standard, double or no points.
  Questions can be written in the quiz or copied at random from a bank, using only bank questions with four or fewer short options.
* **Hosting.** Hosting a quiz opens a game with a 6-digit PIN and a snapshot of the questions, so later edits do not change a game already running. The host chooses:
  * whether guests without accounts may join, by choosing a nickname;
  * whether players get made-up "fun names" instead of their real names.
* **The host's stage.** It moves through these screens: lobby, then question, then reveal (the right answer, and how many chose each tile), then scores (the top five), then the next question, and finally a podium. The host can remove a player, and ending the game needs two presses.
* **Points.** A right answer earns the full points (1,000, or 2,000 for double) less up to half for the time taken. Time is counted from when the question reached **that player's** device, so a slow phone is not punished. Three or more right answers in a row add a streak bonus: 200 for three, 300 for four, and so on up to 500.
* **Reveals and answers.** The reveal happens on its own when time runs out or when every player still in the game has answered. Each player answers each question once.
* **Polling.** Phones and the stage poll every second with the game's version number. When nothing has changed, the reply is a few bytes, which suits school Wi-Fi and mobile data.
* **Joins.** Joining is limited to 30 tries a minute from one network. A finished game frees its PIN.
* **After a game.** A results page and a CSV list every player's answers and points.

### Look

* The exam room and the play screen use `layouts/focus.php`, which has no sidebar and one exit link.
* Live quiz tiles are A, B, C and D in four colours chosen for the platform: `--tile-0` to `--tile-3`. Each colour is checked for contrast with white text in light and dark mode. Every tile also carries its letter, so colour is never the only cue.

## Consequences

* Eleven new tables (migration 000033), all described in the data dictionary.
* The cron file gains `exams:close` every 5 minutes, so the server's `/etc/cron.d/dml-lms` must be reinstalled.
* The sample questions are a starting point. A school that wants real exam practice must write or licence its own questions. Past examination questions belong to the examining bodies.
* Not done yet:
  * question types other than single-answer multiple choice;
  * images in questions (beyond Markdown links);
  * live quizzes over WebSockets (polling is enough for a class of up to 200).
