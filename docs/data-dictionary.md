# Data dictionary

Generated from the live schema by `bin/console schema:dictionary` (do not edit by hand; edit `app/Core/Database/DataDictionary.php` and run it again). School data carries `tenant_id`, and every query on it is limited to one school (ADR 0001). The exceptions are shared by the whole platform: `tenants`, `users` (one account can belong to several schools), `recovery_codes`, `login_throttles`, `rate_limits` and `schema_migrations`.

Personal data kinds: **Account** (who someone is), **Learning record** (what they did and scored), **Family link**, **Payment**, **Written by a person** (free text and files), **Security** (secrets and counters, never shown).

| Area | Table | What it holds | Personal data |
|---|---|---|---|
| Admissions | [`course_applications`](#course-applications) | Applications for courses that take them: answers, place in the queue, offer or decline. | Account |
| Classes | [`group_announcements`](#group-announcements) | Messages a teacher posts to a class. | Written by a person |
| Classes | [`learner_groups`](#learner-groups) | Classes or cohorts: name, level, year, archived. | None |
| Classes | [`learner_group_courses`](#learner-group-courses) | Courses set for a class, with due dates. | None |
| Classes | [`learner_group_members`](#learner-group-members) | Who is in each class, as learner or teacher. | Learning record |
| Code studio | [`code_challenge_solves`](#code-challenge-solves) | Which coding challenges a person solved, and when. | Learning record |
| Code studio | [`code_snippets`](#code-snippets) | Code a person saved in the Code studio. | Written by a person |
| Courses | [`assessments`](#assessments) | A quiz lesson's settings: pass mark and attempts allowed. | None |
| Courses | [`courses`](#courses) | Courses: title, summary, level, price and payment in parts, who can read and join, completion rule. | None |
| Courses | [`course_modules`](#course-modules) | Sections of a course, in order. | None |
| Courses | [`learning_paths`](#learning-paths) | Learning paths: an ordered set of courses. | None |
| Courses | [`learning_path_courses`](#learning-path-courses) | The courses in each path, in order. | None |
| Courses | [`lessons`](#lessons) | Lessons of every type (reading, video, audio, quiz, assignment, lab), whether required, free preview. | None |
| Courses | [`questions`](#questions) | Quiz questions and their explanation. | None |
| Courses | [`question_options`](#question-options) | Answer options for each question, and which is correct. | None |
| Discussions | [`lesson_answers`](#lesson-answers) | Answers to those questions; one may be accepted. | Written by a person |
| Discussions | [`lesson_questions`](#lesson-questions) | Questions learners ask under a lesson. | Written by a person |
| Families | [`guardian_links`](#guardian-links) | Which parent or guardian follows which learner, what they may see, and who checked their identity. | Family link |
| Files | [`files`](#files) | Uploaded files (lesson files, media, class materials, help-desk attachments, pictures in bank questions): name, type, size, storage key, scan result. | Written by a person |
| Help desk | [`support_messages`](#support-messages) | Messages in each help request. | Written by a person |
| Help desk | [`support_requests`](#support-requests) | Help requests a person sends the school, with status. | Written by a person |
| Learning | [`achievements`](#achievements) | Badges a learner has earned. | Learning record |
| Learning | [`assignment_submissions`](#assignment-submissions) | Work sent for an assignment and the teacher's decision and feedback. | Learning record |
| Learning | [`certificates`](#certificates) | Certificates issued, with their public number; revocations and reasons. | Learning record |
| Learning | [`enrolments`](#enrolments) | A learner's place on a course: active, completed or withdrawn, with dates. | Learning record |
| Learning | [`lab_sessions`](#lab-sessions) | Virtual lab runs: which lab, when, the result. | Learning record |
| Learning | [`learning_path_followers`](#learning-path-followers) | Who follows which path. | Learning record |
| Learning | [`lesson_notes`](#lesson-notes) | A learner's private notes and saved lessons. | Written by a person |
| Learning | [`lesson_progress`](#lesson-progress) | Which lessons a learner opened and finished, and when. | Learning record |
| Learning | [`quiz_attempts`](#quiz-attempts) | Every quiz attempt: answers, score, pass or not, time. | Learning record |
| Live classes | [`classroom_settings`](#classroom-settings) | Each school's online classroom server and its secret (encrypted). | Security |
| Live classes | [`class_attendance`](#class-attendance) | The register: present, late, absent or excused, per learner and class. | Learning record |
| Live classes | [`class_events`](#class-events) | Join, still-here and leave signals from the online classroom, for minutes attended. | Learning record |
| Live classes | [`class_sessions`](#class-sessions) | Planned and held live classes: time, place or online room, style, recording. | None |
| Meetings | [`meetings`](#meetings) | Video meetings that are not classes: host, time, room. | None |
| Meetings | [`meeting_events`](#meeting-events) | Join, still-here and leave signals for meetings. | Account |
| Meetings | [`meeting_invites`](#meeting-invites) | Exactly who may join each meeting. | Account |
| Messages | [`mail_outbox`](#mail-outbox) | Emails waiting to be sent and those sent or failed, kept for a short time. | Account |
| Messages | [`notifications`](#notifications) | In-app notices to a person (live class, payment, refund, feedback); read or not. | Account |
| Payments | [`coupons`](#coupons) | Discount codes: percentage or amount, course, uses allowed, last day. | None |
| Payments | [`payments`](#payments) | Every payment: who, course, amount, discount code, method, status (pending, paid, failed, refunded). | Payment |
| Payments | [`payment_accounts`](#payment-accounts) | Each school's Paystack connection: mode, currency, secret key (encrypted, never shown). | Security |
| Payments | [`payment_claims`](#payment-claims) | Learners saying they paid the school directly, until the school confirms or rejects. | Payment |
| Payments | [`payment_events`](#payment-events) | Everything Paystack or staff reported about a payment, each event once. | Payment |
| Payments | [`payment_instructions`](#payment-instructions) | The school's bank details text shown to learners who pay directly. | None |
| Payments | [`payment_plans`](#payment-plans) | Learners paying a course in monthly parts: the price and number of parts agreed, when part 1 was paid, status, last reminder sent. | Payment |
| Payments | [`payment_refunds`](#payment-refunds) | Money paid back, in full or part, with reason and status. | Payment |
| People | [`account_tokens`](#account-tokens) | Single-use links: password reset, invitation, email confirmation. Stored as hashes; they expire. | Security |
| People | [`join_requests`](#join-requests) | Requests to join from parents and would-be teachers, until the school decides. | Account |
| People | [`learner_profiles`](#learner-profiles) | What a person told the school when joining online: goals, experience, optional GitHub name. | Account |
| People | [`memberships`](#memberships) | Who belongs to which school and with which role (owner, admin, educator, learner, guardian). | Account |
| People | [`recovery_codes`](#recovery-codes) | Two-step sign-in recovery codes, stored as hashes. | Security |
| People | [`users`](#users) | Accounts: name, email, password hash, two-step secret (encrypted), appearance. One account can belong to several schools. | Account |
| Quizzes and exams | [`bank_passages`](#bank-passages) | Passages, tables or instructions that several bank questions share. | None |
| Quizzes and exams | [`bank_questions`](#bank-questions) | Bank questions: options A to E, the right one, explanation, topic, difficulty, a picture and its description, times seen and right. | None |
| Quizzes and exams | [`cbt_attempts`](#cbt-attempts) | One sitting of a CBT exam or practice test: the questions drawn, answers, flags, deadline, scores by subject. | Learning record |
| Quizzes and exams | [`cbt_exams`](#cbt-exams) | CBT exams: sections from banks, subjects to choose, time, window, who sits it, attempts, results policy and announcement, access code. | None |
| Quizzes and exams | [`cbt_exam_groups`](#cbt-exam-groups) | Which classes sit a CBT exam set for classes only. | None |
| Quizzes and exams | [`live_answers`](#live-answers) | Every answer in a live quiz with its time and points. | Learning record |
| Quizzes and exams | [`live_players`](#live-players) | Who joined a live quiz (a member, or a guest nickname), score, streak. | Learning record |
| Quizzes and exams | [`live_questions`](#live-questions) | Questions in a live quiz: answers, the right one, time limit, points. | None |
| Quizzes and exams | [`live_quizzes`](#live-quizzes) | Live quizzes teachers run in class. | None |
| Quizzes and exams | [`live_sessions`](#live-sessions) | Each time a live quiz is played: PIN, host, a copy of the questions, where the game is. | None |
| Quizzes and exams | [`question_banks`](#question-banks) | A school's question banks, one per subject, and whether learners may practise from them. | None |
| School | [`api_keys`](#api-keys) | Keys the school made for its own systems to read data through API v1: name, prefix, a hash of the key (never the key), who made it, last use. | Security |
| Schools | [`tenants`](#tenants) | One row per school: name, branding, time zone, settings chosen in setup. | None |
| Schools | [`tenant_domains`](#tenant-domains) | Web addresses that open a school; one is the primary address. | None |
| Security | [`audit_events`](#audit-events) | Who did what and when (sign-ins, role changes, payments, refunds); field values are not stored. | Account |
| Security | [`login_throttles`](#login-throttles) | Failed sign-in counters per account and address, to slow down guessing. | Security |
| Security | [`rate_limits`](#rate-limits) | Counters for other limited actions (discount codes, emails, uploads). | Security |
| Setup | [`wizard_runs`](#wizard-runs) | Answers saved part-way through a guided setup, until they are applied or discarded. | None |
| System | [`schema_migrations`](#schema-migrations) | Which database migrations have been applied, with checksums. | None |
| Website | [`blog_posts`](#blog-posts) | Blog posts: title, summary, text, kind (news, event, story, guide), draft or published, the staff member who wrote it. | None |
| Website | [`site_pages`](#site-pages) | The school's own public pages (about, admissions, contact, questions and answers, careers, privacy, terms, refunds, safeguarding). | None |

## account_tokens

Single-use links: password reset, invitation, email confirmation. Stored as hashes; they expire.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `purpose` | varchar(20) |  |  |  |
| `token_hash` | char(64) |  |  | unique |
| `expires_at` | datetime |  |  |  |
| `used_at` | datetime | yes | `NULL` |  |
| `created_by` | bigint(20) unsigned | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## achievements

Badges a learner has earned.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `code` | varchar(40) |  |  | primary |
| `earned_at` | datetime |  |  |  |
| `recorded_at` | datetime |  |  |  |

## api_keys

Keys the school made for its own systems to read data through API v1: name, prefix, a hash of the key (never the key), who made it, last use.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `name` | varchar(80) |  |  |  |
| `prefix` | char(8) |  |  |  |
| `key_hash` | char(64) |  |  | unique |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `last_used_at` | datetime | yes | `NULL` |  |
| `revoked_at` | datetime | yes | `NULL` |  |

## assessments

A quiz lesson's settings: pass mark and attempts allowed.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `pass_threshold` | tinyint(3) unsigned |  | `70` |  |
| `max_attempts` | tinyint(3) unsigned | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## assignment_submissions

Work sent for an assignment and the teacher's decision and feedback.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `enrolment_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `body` | mediumtext |  |  |  |
| `link_url` | varchar(500) | yes | `NULL` |  |
| `status` | varchar(20) |  | `'submitted'` |  |
| `feedback` | text | yes | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | yes | `NULL` | index |
| `reviewed_at` | datetime | yes | `NULL` |  |
| `submitted_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## audit_events

Who did what and when (sign-ins, role changes, payments, refunds); field values are not stored.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned | yes | `NULL` | index |
| `actor_user_id` | bigint(20) unsigned | yes | `NULL` | index |
| `action` | varchar(80) |  |  |  |
| `subject_type` | varchar(60) | yes | `NULL` |  |
| `subject_id` | bigint(20) unsigned | yes | `NULL` |  |
| `metadata` | longtext | yes | `NULL` |  |
| `ip_hash` | char(64) | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## bank_passages

Passages, tables or instructions that several bank questions share.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `bank_id` | bigint(20) unsigned |  |  |  |
| `title` | varchar(120) |  |  |  |
| `body` | text |  |  |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## bank_questions

Bank questions: options A to E, the right one, explanation, topic, difficulty, a picture and its description, times seen and right.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `bank_id` | bigint(20) unsigned |  |  |  |
| `passage_id` | bigint(20) unsigned | yes | `NULL` | index |
| `image_file_id` | bigint(20) unsigned | yes | `NULL` |  |
| `image_alt` | varchar(300) | yes | `NULL` |  |
| `prompt` | text |  |  |  |
| `options` | longtext |  |  |  |
| `correct` | tinyint(3) unsigned |  |  |  |
| `explanation` | text | yes | `NULL` |  |
| `topic` | varchar(80) |  | `''` |  |
| `difficulty` | tinyint(3) unsigned | yes | `NULL` |  |
| `times_seen` | int(10) unsigned |  | `0` |  |
| `times_correct` | int(10) unsigned |  | `0` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `retired_at` | datetime | yes | `NULL` |  |

## blog_posts

Blog posts: title, summary, text, kind (news, event, story, guide), draft or published, the staff member who wrote it.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `slug` | varchar(120) |  |  |  |
| `title` | varchar(160) |  |  |  |
| `summary` | varchar(300) |  | `''` |  |
| `body` | mediumtext |  |  |  |
| `category` | varchar(20) |  | `'news'` |  |
| `status` | varchar(12) |  | `'draft'` |  |
| `author_id` | bigint(20) unsigned |  |  | index |
| `published_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## cbt_attempts

One sitting of a CBT exam or practice test: the questions drawn, answers, flags, deadline, scores by subject.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `exam_id` | bigint(20) unsigned | yes | `NULL` |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `title` | varchar(160) |  |  |  |
| `paper` | longtext |  |  |  |
| `answers` | longtext |  |  |  |
| `flags` | longtext |  |  |  |
| `question_count` | int(10) unsigned |  |  |  |
| `started_at` | datetime |  |  |  |
| `deadline_at` | datetime |  |  |  |
| `extra_minutes` | smallint(5) unsigned |  | `0` |  |
| `save_seq` | int(10) unsigned |  | `0` |  |
| `last_saved_at` | datetime | yes | `NULL` |  |
| `submitted_at` | datetime | yes | `NULL` | index |
| `submit_reason` | varchar(10) | yes | `NULL` |  |
| `result` | longtext | yes | `NULL` |  |
| `correct_count` | int(10) unsigned | yes | `NULL` |  |
| `percent` | tinyint(3) unsigned | yes | `NULL` |  |

## cbt_exams

CBT exams: sections from banks, subjects to choose, time, window, who sits it, attempts, results policy and announcement, access code.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `title` | varchar(120) |  |  |  |
| `instructions` | text | yes | `NULL` |  |
| `sections` | longtext |  |  |  |
| `choose_count` | tinyint(3) unsigned |  | `0` |  |
| `duration_minutes` | smallint(5) unsigned |  |  |  |
| `opens_at` | datetime | yes | `NULL` |  |
| `closes_at` | datetime | yes | `NULL` |  |
| `audience` | varchar(10) |  | `'everyone'` |  |
| `max_attempts` | tinyint(3) unsigned | yes | `NULL` |  |
| `results` | varchar(12) |  | `'immediately'` |  |
| `review` | tinyint(1) |  | `1` |  |
| `shuffle_questions` | tinyint(1) |  | `1` |  |
| `shuffle_options` | tinyint(1) |  | `1` |  |
| `access_code` | varchar(12) | yes | `NULL` |  |
| `status` | varchar(10) |  | `'draft'` |  |
| `results_announced_at` | datetime | yes | `NULL` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## cbt_exam_groups

Which classes sit a CBT exam set for classes only.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `exam_id` | bigint(20) unsigned |  |  | primary |
| `group_id` | bigint(20) unsigned |  |  | primary |

## certificates

Certificates issued, with their public number; revocations and reasons.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `enrolment_id` | bigint(20) unsigned |  |  |  |
| `course_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  | index |
| `code` | char(12) |  |  | unique |
| `learner_name` | varchar(190) |  |  |  |
| `course_title` | varchar(190) |  |  |  |
| `issued_at` | datetime |  |  |  |
| `revoked_at` | datetime | yes | `NULL` |  |
| `revoked_reason` | varchar(500) | yes | `NULL` |  |

## classroom_settings

Each school's online classroom server and its secret (encrypted).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `domain` | varchar(190) |  |  |  |
| `app_id` | varchar(100) |  |  |  |
| `secret_sealed` | text |  |  |  |
| `low_data` | tinyint(1) |  | `1` |  |
| `updated_by` | bigint(20) unsigned | yes | `NULL` | index |
| `updated_at` | datetime |  |  |  |

## class_attendance

The register: present, late, absent or excused, per learner and class.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `session_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `status` | varchar(10) |  |  |  |
| `note` | varchar(200) |  | `''` |  |
| `self_checked_in` | tinyint(1) |  | `0` |  |
| `marked_by` | bigint(20) unsigned | yes | `NULL` | index |
| `marked_at` | datetime |  |  |  |

## class_events

Join, still-here and leave signals from the online classroom, for minutes attended.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `session_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  | index |
| `type` | varchar(10) |  |  |  |
| `created_at` | datetime |  |  |  |

## class_sessions

Planned and held live classes: time, place or online room, style, recording.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `group_id` | bigint(20) unsigned |  |  |  |
| `course_id` | bigint(20) unsigned | yes | `NULL` |  |
| `series_id` | char(16) | yes | `NULL` |  |
| `title` | varchar(160) |  |  |  |
| `description` | text | yes | `NULL` |  |
| `starts_at` | datetime |  |  |  |
| `ends_at` | datetime |  |  |  |
| `mode` | varchar(10) |  |  |  |
| `style` | varchar(12) |  | `'discussion'` |  |
| `location` | varchar(200) |  | `''` |  |
| `join_url` | varchar(500) | yes | `NULL` |  |
| `room_name` | varchar(80) | yes | `NULL` |  |
| `recording_url` | varchar(500) | yes | `NULL` |  |
| `recording_file_id` | bigint(20) unsigned | yes | `NULL` |  |
| `status` | varchar(10) |  | `'scheduled'` |  |
| `cancelled_reason` | varchar(255) | yes | `NULL` |  |
| `created_by` | bigint(20) unsigned | yes | `NULL` | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## code_challenge_solves

Which coding challenges a person solved, and when.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  |  |
| `challenge` | varchar(40) |  |  |  |
| `language` | varchar(12) |  |  |  |
| `code` | mediumtext |  |  |  |
| `solved_at` | datetime |  |  |  |

## code_snippets

Code a person saved in the Code studio.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  |  |
| `title` | varchar(120) |  |  |  |
| `language` | varchar(12) |  | `'javascript'` |  |
| `code` | mediumtext |  |  |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## coupons

Discount codes: percentage or amount, course, uses allowed, last day.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `code` | varchar(32) |  |  |  |
| `course_id` | bigint(20) unsigned | yes | `NULL` |  |
| `percent_off` | tinyint(3) unsigned | yes | `NULL` |  |
| `amount_off_minor` | int(10) unsigned | yes | `NULL` |  |
| `currency` | char(3) | yes | `NULL` |  |
| `max_uses` | int(10) unsigned | yes | `NULL` |  |
| `expires_on` | date | yes | `NULL` |  |
| `status` | varchar(10) |  | `'active'` |  |
| `note` | varchar(160) | yes | `NULL` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## courses

Courses: title, summary, level, price and payment in parts, who can read and join, completion rule.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `slug` | varchar(120) |  |  |  |
| `title` | varchar(190) |  |  |  |
| `summary` | text |  |  |  |
| `level` | varchar(20) | yes | `NULL` |  |
| `duration_hours` | smallint(5) unsigned | yes | `NULL` |  |
| `outcomes` | text | yes | `NULL` |  |
| `prerequisites` | text | yes | `NULL` |  |
| `status` | varchar(20) |  | `'draft'` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `published_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `track` | varchar(20) | yes | `NULL` |  |
| `price_minor` | int(10) unsigned | yes | `NULL` |  |
| `currency` | char(3) | yes | `NULL` |  |
| `instalments` | tinyint(3) unsigned | yes | `NULL` |  |
| `access` | varchar(10) |  | `'members'` |  |
| `enrolment` | varchar(10) |  | `'open'` |  |
| `capacity` | int(10) unsigned | yes | `NULL` |  |
| `completion_min_average` | tinyint(3) unsigned | yes | `NULL` |  |

## course_applications

Applications for courses that take them: answers, place in the queue, offer or decline.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned | yes | `NULL` | index |
| `name` | varchar(120) |  |  |  |
| `email` | varchar(254) |  |  |  |
| `phone` | varchar(40) |  | `''` |  |
| `message` | text |  |  |  |
| `status` | varchar(12) |  | `'waiting'` |  |
| `decided_by` | bigint(20) unsigned | yes | `NULL` | index |
| `decided_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## course_modules

Sections of a course, in order.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `title` | varchar(190) |  |  |  |
| `position` | int(10) unsigned |  |  |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## enrolments

A learner's place on a course: active, completed or withdrawn, with dates.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `status` | varchar(20) |  | `'active'` |  |
| `enrolled_at` | datetime |  |  |  |
| `completed_at` | datetime | yes | `NULL` |  |

## files

Uploaded files (lesson files, media, class materials, help-desk attachments, pictures in bank questions): name, type, size, storage key, scan result.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `lesson_id` | bigint(20) unsigned | yes | `NULL` |  |
| `submission_id` | bigint(20) unsigned | yes | `NULL` |  |
| `class_session_id` | bigint(20) unsigned | yes | `NULL` |  |
| `bank_question_id` | bigint(20) unsigned | yes | `NULL` |  |
| `support_message_id` | bigint(20) unsigned | yes | `NULL` |  |
| `uploaded_by` | bigint(20) unsigned |  |  | index |
| `original_name` | varchar(190) |  |  |  |
| `extension` | varchar(10) |  |  |  |
| `mime_type` | varchar(120) |  |  |  |
| `size_bytes` | bigint(20) unsigned |  |  |  |
| `sha256` | char(64) |  |  |  |
| `storage_key` | varchar(120) |  |  | unique |
| `scan_status` | varchar(20) |  |  |  |
| `position` | int(10) unsigned |  | `0` |  |
| `created_at` | datetime |  |  |  |

## group_announcements

Messages a teacher posts to a class.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `group_id` | bigint(20) unsigned |  |  |  |
| `author_id` | bigint(20) unsigned | yes | `NULL` | index |
| `body` | text |  |  |  |
| `created_at` | datetime |  |  |  |

## guardian_links

Which parent or guardian follows which learner, what they may see, and who checked their identity.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `guardian_id` | bigint(20) unsigned |  |  |  |
| `learner_id` | bigint(20) unsigned |  |  |  |
| `relationship` | varchar(20) |  |  |  |
| `can_progress` | tinyint(1) |  | `1` |  |
| `can_results` | tinyint(1) |  | `1` |  |
| `can_notices` | tinyint(1) |  | `1` |  |
| `status` | varchar(20) |  | `'active'` |  |
| `verified_by` | bigint(20) unsigned | yes | `NULL` |  |
| `verified_at` | datetime |  |  |  |
| `revoked_by` | bigint(20) unsigned | yes | `NULL` |  |
| `revoked_at` | datetime | yes | `NULL` |  |

## join_requests

Requests to join from parents and would-be teachers, until the school decides.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `kind` | varchar(20) |  |  |  |
| `user_id` | bigint(20) unsigned | yes | `NULL` | index |
| `name` | varchar(120) |  |  |  |
| `email` | varchar(254) |  |  |  |
| `phone` | varchar(40) |  | `''` |  |
| `child_name` | varchar(120) |  | `''` |  |
| `message` | text | yes | `NULL` |  |
| `status` | varchar(20) |  | `'pending'` |  |
| `decided_by` | bigint(20) unsigned | yes | `NULL` |  |
| `decided_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## lab_sessions

Virtual lab runs: which lab, when, the result.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `enrolment_id` | bigint(20) unsigned |  |  |  |
| `lab_key` | varchar(60) |  |  |  |
| `lab_version` | int(10) unsigned |  |  |  |
| `seed` | char(32) |  |  |  |
| `status` | varchar(20) |  |  | index |
| `started_at` | datetime |  |  |  |
| `expires_at` | datetime |  |  |  |
| `ended_at` | datetime | yes | `NULL` |  |
| `score` | tinyint(3) unsigned | yes | `NULL` |  |
| `passed` | tinyint(1) | yes | `NULL` |  |
| `evidence` | mediumtext | yes | `NULL` |  |
| `feedback` | text | yes | `NULL` |  |

## learner_groups

Classes or cohorts: name, level, year, archived.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `name` | varchar(80) |  |  |  |
| `level` | varchar(60) | yes | `NULL` |  |
| `period_label` | varchar(60) | yes | `NULL` |  |
| `archived_at` | datetime | yes | `NULL` |  |
| `created_by` | bigint(20) unsigned | yes | `NULL` | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## learner_group_courses

Courses set for a class, with due dates.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `group_id` | bigint(20) unsigned |  |  | primary |
| `course_id` | bigint(20) unsigned |  |  | primary |
| `due_on` | date | yes | `NULL` |  |
| `assigned_by` | bigint(20) unsigned | yes | `NULL` |  |
| `assigned_at` | datetime |  |  |  |

## learner_group_members

Who is in each class, as learner or teacher.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `group_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `role` | varchar(20) |  |  |  |
| `added_by` | bigint(20) unsigned | yes | `NULL` |  |
| `added_at` | datetime |  |  |  |

## learner_profiles

What a person told the school when joining online: goals, experience, optional GitHub name.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `goals` | varchar(200) |  | `''` |  |
| `experience` | varchar(20) | yes | `NULL` |  |
| `weekly_goal` | tinyint(3) unsigned | yes | `NULL` |  |
| `github` | varchar(60) | yes | `NULL` |  |
| `portfolio` | varchar(255) | yes | `NULL` |  |
| `joined_via` | varchar(20) |  | `'school'` |  |
| `consent_at` | datetime | yes | `NULL` |  |
| `onboarded_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## learning_paths

Learning paths: an ordered set of courses.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `slug` | varchar(190) |  |  |  |
| `title` | varchar(190) |  |  |  |
| `summary` | text |  |  |  |
| `level` | varchar(20) | yes | `NULL` |  |
| `status` | varchar(20) |  | `'draft'` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## learning_path_courses

The courses in each path, in order.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `path_id` | bigint(20) unsigned |  |  | primary |
| `course_id` | bigint(20) unsigned |  |  | primary |
| `position` | int(10) unsigned |  |  |  |

## learning_path_followers

Who follows which path.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `path_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `started_at` | datetime |  |  |  |

## lessons

Lessons of every type (reading, video, audio, quiz, assignment, lab), whether required, free preview.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `module_id` | bigint(20) unsigned |  |  |  |
| `type` | varchar(20) |  |  |  |
| `title` | varchar(190) |  |  |  |
| `body` | mediumtext |  |  |  |
| `media_url` | varchar(500) | yes | `NULL` |  |
| `media_file_id` | bigint(20) unsigned | yes | `NULL` |  |
| `lab_key` | varchar(60) | yes | `NULL` |  |
| `position` | int(10) unsigned |  |  |  |
| `is_mandatory` | tinyint(1) |  | `1` |  |
| `is_preview` | tinyint(1) |  | `0` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## lesson_answers

Answers to those questions; one may be accepted.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `question_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `body` | text |  |  |  |
| `by_staff` | tinyint(1) |  | `0` |  |
| `status` | varchar(10) |  | `'visible'` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## lesson_notes

A learner's private notes and saved lessons.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  |  |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `is_saved` | tinyint(1) |  | `0` |  |
| `body` | text | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## lesson_progress

Which lessons a learner opened and finished, and when.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `enrolment_id` | bigint(20) unsigned |  |  |  |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `status` | varchar(20) |  |  |  |
| `completed_at` | datetime | yes | `NULL` |  |
| `updated_at` | datetime |  |  |  |

## lesson_questions

Questions learners ask under a lesson.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `lesson_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `body` | text |  |  |  |
| `status` | varchar(10) |  | `'open'` |  |
| `accepted_answer_id` | bigint(20) unsigned | yes | `NULL` |  |
| `answer_count` | int(10) unsigned |  | `0` |  |
| `staff_answered` | tinyint(1) |  | `0` |  |
| `last_activity_at` | datetime |  |  |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## live_answers

Every answer in a live quiz with its time and points.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `session_id` | bigint(20) unsigned |  |  |  |
| `player_id` | bigint(20) unsigned |  |  | index |
| `position` | int(10) unsigned |  |  |  |
| `choice` | tinyint(3) unsigned |  |  |  |
| `correct` | tinyint(1) |  |  |  |
| `points` | int(10) unsigned |  |  |  |
| `ms` | int(10) unsigned |  |  |  |
| `answered_at` | datetime(3) |  |  |  |

## live_players

Who joined a live quiz (a member, or a guest nickname), score, streak.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `session_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned | yes | `NULL` | index |
| `nickname` | varchar(24) |  |  |  |
| `score` | int(10) unsigned |  | `0` |  |
| `streak` | smallint(5) unsigned |  | `0` |  |
| `correct_count` | smallint(5) unsigned |  | `0` |  |
| `seen_position` | int(10) unsigned | yes | `NULL` |  |
| `seen_at` | datetime(3) | yes | `NULL` |  |
| `joined_at` | datetime |  |  |  |
| `last_seen_at` | datetime |  |  |  |
| `removed_at` | datetime | yes | `NULL` |  |

## live_questions

Questions in a live quiz: answers, the right one, time limit, points.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `quiz_id` | bigint(20) unsigned |  |  |  |
| `position` | int(10) unsigned |  |  |  |
| `prompt` | varchar(500) |  |  |  |
| `options` | longtext |  |  |  |
| `correct` | tinyint(3) unsigned |  |  |  |
| `seconds` | smallint(5) unsigned |  | `20` |  |
| `points` | varchar(10) |  | `'standard'` |  |

## live_quizzes

Live quizzes teachers run in class.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `title` | varchar(120) |  |  |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `archived_at` | datetime | yes | `NULL` |  |

## live_sessions

Each time a live quiz is played: PIN, host, a copy of the questions, where the game is.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `quiz_id` | bigint(20) unsigned |  |  |  |
| `host_id` | bigint(20) unsigned |  |  | index |
| `pin` | char(6) |  |  |  |
| `title` | varchar(120) |  |  |  |
| `questions` | longtext |  |  |  |
| `status` | varchar(10) |  | `'lobby'` |  |
| `position` | int(10) unsigned | yes | `NULL` |  |
| `question_started_at` | datetime(3) | yes | `NULL` |  |
| `question_ends_at` | datetime(3) | yes | `NULL` |  |
| `version` | int(10) unsigned |  | `1` |  |
| `allow_guests` | tinyint(1) |  | `1` |  |
| `fun_names` | tinyint(1) |  | `0` |  |
| `created_at` | datetime |  |  |  |
| `ended_at` | datetime | yes | `NULL` |  |

## login_throttles

Failed sign-in counters per account and address, to slow down guessing.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `throttle_key` | char(64) |  |  | primary |
| `failures` | int(10) unsigned |  | `0` |  |
| `window_started_at` | datetime |  |  |  |
| `locked_until` | datetime | yes | `NULL` |  |

## mail_outbox

Emails waiting to be sent and those sent or failed, kept for a short time.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned | yes | `NULL` | index |
| `to_email` | varchar(254) |  |  |  |
| `to_name` | varchar(120) | yes | `NULL` |  |
| `subject` | varchar(255) |  |  |  |
| `text_body` | mediumtext | yes | `NULL` |  |
| `html_body` | mediumtext | yes | `NULL` |  |
| `status` | varchar(20) |  | `'queued'` | index |
| `attempts` | tinyint(3) unsigned |  | `0` |  |
| `next_attempt_at` | datetime |  |  |  |
| `last_error` | varchar(500) | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `sent_at` | datetime | yes | `NULL` |  |

## meetings

Video meetings that are not classes: host, time, room.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `host_id` | bigint(20) unsigned |  |  | index |
| `title` | varchar(160) |  |  |  |
| `agenda` | text | yes | `NULL` |  |
| `starts_at` | datetime |  |  |  |
| `ends_at` | datetime |  |  |  |
| `room_name` | varchar(80) |  |  | unique |
| `staff_moderate` | tinyint(1) |  | `1` |  |
| `status` | varchar(12) |  | `'scheduled'` |  |
| `cancelled_reason` | varchar(255) | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## meeting_events

Join, still-here and leave signals for meetings.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `meeting_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  | index |
| `type` | varchar(10) |  |  |  |
| `created_at` | datetime |  |  |  |

## meeting_invites

Exactly who may join each meeting.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `meeting_id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | primary |
| `invited_at` | datetime |  |  |  |

## memberships

Who belongs to which school and with which role (owner, admin, educator, learner, guardian).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `role` | varchar(20) |  |  |  |
| `status` | varchar(20) |  | `'active'` |  |
| `calendar_token_hash` | char(64) | yes | `NULL` | unique |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## notifications

In-app notices to a person (live class, payment, refund, feedback); read or not.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  |  |
| `kind` | varchar(40) |  |  |  |
| `title` | varchar(200) |  |  |  |
| `body` | varchar(500) | yes | `NULL` |  |
| `link` | varchar(300) | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `read_at` | datetime | yes | `NULL` |  |

## payments

Every payment: who, course, amount, discount code, method, status (pending, paid, failed, refunded).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `reference` | varchar(64) |  |  | unique |
| `provider` | varchar(20) |  |  |  |
| `mode` | varchar(10) |  |  |  |
| `amount_minor` | int(10) unsigned |  |  |  |
| `currency` | char(3) |  |  |  |
| `status` | varchar(20) |  |  |  |
| `provider_transaction_id` | varchar(40) | yes | `NULL` |  |
| `channel` | varchar(30) | yes | `NULL` |  |
| `note` | varchar(255) | yes | `NULL` |  |
| `coupon_id` | bigint(20) unsigned | yes | `NULL` |  |
| `list_price_minor` | int(10) unsigned | yes | `NULL` |  |
| `plan_id` | bigint(20) unsigned | yes | `NULL` |  |
| `plan_part` | tinyint(3) unsigned | yes | `NULL` |  |
| `recorded_by` | bigint(20) unsigned | yes | `NULL` | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `paid_at` | datetime | yes | `NULL` |  |

## payment_accounts

Each school's Paystack connection: mode, currency, secret key (encrypted, never shown).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `provider` | varchar(20) |  |  |  |
| `secret_sealed` | text |  |  |  |
| `mode` | varchar(10) |  |  |  |
| `currency` | char(3) |  |  |  |
| `connected_by` | bigint(20) unsigned |  |  | index |
| `connected_at` | datetime |  |  |  |

## payment_claims

Learners saying they paid the school directly, until the school confirms or rejects.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `amount_minor` | int(10) unsigned |  |  |  |
| `currency` | char(3) |  |  |  |
| `method` | varchar(12) |  |  |  |
| `paid_on` | date |  |  |  |
| `payer_reference` | varchar(80) |  | `''` |  |
| `note` | varchar(500) |  | `''` |  |
| `status` | varchar(12) |  | `'pending'` |  |
| `reason` | varchar(255) | yes | `NULL` |  |
| `payment_id` | bigint(20) unsigned | yes | `NULL` |  |
| `decided_by` | bigint(20) unsigned | yes | `NULL` | index |
| `decided_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `plan_id` | bigint(20) unsigned | yes | `NULL` |  |
| `plan_part` | tinyint(3) unsigned | yes | `NULL` |  |

## payment_events

Everything Paystack or staff reported about a payment, each event once.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `payment_id` | bigint(20) unsigned | yes | `NULL` |  |
| `event_key` | varchar(160) |  |  |  |
| `source` | varchar(20) |  |  |  |
| `type` | varchar(60) |  |  |  |
| `outcome` | varchar(60) |  |  |  |
| `details` | text | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## payment_instructions

The school's bank details text shown to learners who pay directly.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `tenant_id` | bigint(20) unsigned |  |  | primary |
| `instructions` | text |  |  |  |
| `updated_by` | bigint(20) unsigned | yes | `NULL` | index |
| `updated_at` | datetime |  |  |  |

## payment_plans

Learners paying a course in monthly parts: the price and number of parts agreed, when part 1 was paid, status, last reminder sent.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  | index |
| `course_id` | bigint(20) unsigned |  |  |  |
| `parts` | tinyint(3) unsigned |  |  |  |
| `total_minor` | int(10) unsigned |  |  |  |
| `currency` | char(3) |  |  |  |
| `status` | varchar(12) |  | `'pending'` | index |
| `started_at` | datetime | yes | `NULL` |  |
| `reminded` | varchar(16) | yes | `NULL` |  |
| `ended_by` | bigint(20) unsigned | yes | `NULL` | index |
| `ended_note` | varchar(255) | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## payment_refunds

Money paid back, in full or part, with reason and status.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `payment_id` | bigint(20) unsigned |  |  |  |
| `amount_minor` | int(10) unsigned |  |  |  |
| `currency` | char(3) |  |  |  |
| `reason` | varchar(255) |  |  |  |
| `status` | varchar(12) |  |  |  |
| `provider_refund_id` | varchar(40) | yes | `NULL` | index |
| `failure` | varchar(255) | yes | `NULL` |  |
| `withdrew_learner` | tinyint(1) |  | `0` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `processed_at` | datetime | yes | `NULL` |  |

## questions

Quiz questions and their explanation.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `assessment_id` | bigint(20) unsigned |  |  |  |
| `prompt` | text |  |  |  |
| `explanation` | text | yes | `NULL` |  |
| `position` | int(10) unsigned |  |  |  |
| `created_at` | datetime |  |  |  |

## question_banks

A school's question banks, one per subject, and whether learners may practise from them.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `name` | varchar(80) |  |  |  |
| `description` | varchar(500) |  | `''` |  |
| `practice` | tinyint(1) |  | `0` |  |
| `created_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `archived_at` | datetime | yes | `NULL` |  |

## question_options

Answer options for each question, and which is correct.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `question_id` | bigint(20) unsigned |  |  |  |
| `label` | varchar(500) |  |  |  |
| `is_correct` | tinyint(1) |  | `0` |  |
| `position` | int(10) unsigned |  |  |  |

## quiz_attempts

Every quiz attempt: answers, score, pass or not, time.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `assessment_id` | bigint(20) unsigned |  |  |  |
| `enrolment_id` | bigint(20) unsigned |  |  |  |
| `user_id` | bigint(20) unsigned |  |  |  |
| `idempotency_key` | char(32) |  |  |  |
| `score_percent` | tinyint(3) unsigned |  |  |  |
| `correct_count` | int(10) unsigned |  |  |  |
| `question_count` | int(10) unsigned |  |  |  |
| `passed` | tinyint(1) |  |  |  |
| `answers` | longtext |  |  |  |
| `submitted_at` | datetime |  |  |  |

## rate_limits

Counters for other limited actions (discount codes, emails, uploads).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `bucket` | char(64) |  |  | primary |
| `hits` | int(10) unsigned |  |  |  |
| `window_started_at` | datetime |  |  |  |

## recovery_codes

Two-step sign-in recovery codes, stored as hashes.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `user_id` | bigint(20) unsigned |  |  | index |
| `code_hash` | char(64) |  |  | unique |
| `used_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## schema_migrations

Which database migrations have been applied, with checksums.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `name` | varchar(190) |  |  | primary |
| `checksum` | char(64) |  |  |  |
| `applied_at` | datetime |  |  |  |

## site_pages

The school's own public pages (about, admissions, contact, questions and answers, careers, privacy, terms, refunds, safeguarding).

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `slug` | varchar(40) |  |  |  |
| `title` | varchar(120) |  |  |  |
| `summary` | varchar(300) |  | `''` |  |
| `body` | mediumtext |  |  |  |
| `status` | varchar(20) |  | `'draft'` |  |
| `published_at` | datetime | yes | `NULL` |  |
| `updated_by` | bigint(20) unsigned | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## support_messages

Messages in each help request.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `request_id` | bigint(20) unsigned |  |  |  |
| `author_id` | bigint(20) unsigned | yes | `NULL` | index |
| `from_staff` | tinyint(1) |  | `0` |  |
| `body` | text |  |  |  |
| `created_at` | datetime |  |  |  |

## support_requests

Help requests a person sends the school, with status.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `user_id` | bigint(20) unsigned |  |  |  |
| `category` | varchar(20) |  |  |  |
| `subject` | varchar(160) |  |  |  |
| `status` | varchar(20) |  | `'open'` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `resolved_at` | datetime | yes | `NULL` |  |

## tenants

One row per school: name, branding, time zone, settings chosen in setup.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `slug` | varchar(63) |  |  | unique |
| `name` | varchar(190) |  |  |  |
| `status` | varchar(20) |  | `'active'` |  |
| `timezone` | varchar(64) |  | `'Africa/Lagos'` |  |
| `locale` | varchar(16) |  | `'en-NG'` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
| `kind` | varchar(30) | yes | `NULL` |  |
| `tagline` | varchar(160) |  | `''` |  |
| `theme` | varchar(20) |  | `'dml'` |  |
| `logo_key` | varchar(120) | yes | `NULL` |  |
| `logo_type` | varchar(40) | yes | `NULL` |  |
| `contact_email` | varchar(254) |  | `''` |  |
| `contact_phone` | varchar(40) |  | `''` |  |
| `website` | varchar(255) |  | `''` |  |
| `calendar` | varchar(20) | yes | `NULL` |  |
| `levels` | text | yes | `NULL` |  |
| `group_term` | varchar(20) | yes | `NULL` |  |
| `learner_term` | varchar(20) | yes | `NULL` |  |
| `certificates_enabled` | tinyint(1) |  | `1` |  |
| `public_catalogue` | tinyint(1) |  | `1` |  |
| `setup_completed_at` | datetime | yes | `NULL` |  |
| `social_links` | text | yes | `NULL` |  |
| `search_verification` | text | yes | `NULL` |  |
| `registration` | varchar(10) |  | `'closed'` |  |
| `join_code` | varchar(20) | yes | `NULL` |  |
| `parent_requests` | tinyint(1) |  | `1` |  |

## tenant_domains

Web addresses that open a school; one is the primary address.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `host` | varchar(253) |  |  | unique |
| `is_primary` | tinyint(1) |  | `0` |  |
| `verified_at` | datetime | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |

## users

Accounts: name, email, password hash, two-step secret (encrypted), appearance. One account can belong to several schools.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `email` | varchar(254) |  |  | unique |
| `display_name` | varchar(120) |  |  |  |
| `password_hash` | varchar(255) |  |  |  |
| `status` | varchar(20) |  | `'active'` |  |
| `is_platform_admin` | tinyint(1) |  | `0` |  |
| `session_version` | int(10) unsigned |  | `1` |  |
| `password_changed_at` | datetime |  |  |  |
| `must_change_password` | tinyint(1) |  | `0` |  |
| `appearance` | varchar(10) |  | `'system'` |  |
| `email_verified_at` | datetime | yes | `NULL` |  |
| `totp_secret` | varchar(255) | yes | `NULL` |  |
| `totp_enabled_at` | datetime | yes | `NULL` |  |
| `totp_last_step` | bigint(20) unsigned | yes | `NULL` |  |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |

## wizard_runs

Answers saved part-way through a guided setup, until they are applied or discarded.

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned |  |  | primary |
| `tenant_id` | bigint(20) unsigned |  |  | index |
| `wizard` | varchar(40) |  |  |  |
| `state` | mediumtext |  |  |  |
| `current_step` | varchar(40) |  |  |  |
| `started_by` | bigint(20) unsigned |  |  | index |
| `updated_by` | bigint(20) unsigned |  |  | index |
| `created_at` | datetime |  |  |  |
| `updated_at` | datetime |  |  |  |
