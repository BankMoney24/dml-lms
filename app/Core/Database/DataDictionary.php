<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Writes docs/data-dictionary.md from the live schema (`bin/console schema:dictionary`),
 * so the columns listed are always the real ones. What each table is for, and the
 * kind of personal data it holds, is kept here next to the generator.
 */
final class DataDictionary
{
    /** Kinds of personal data, for privacy requests and the school's privacy policy. */
    public const NONE = 'None';
    public const ACCOUNT = 'Account';
    public const LEARNING = 'Learning record';
    public const MONEY = 'Payment';
    public const CHILD = 'Family link';
    public const CONTENT = 'Written by a person';
    public const SECURITY = 'Security';

    /** @var array<string, array{0: string, 1: string, 2: string}> table => [area, what it holds, personal data] */
    public const TABLES = [
        'tenants' => ['Schools', 'One row per school: name, branding, time zone, settings chosen in setup.', self::NONE],
        'tenant_domains' => ['Schools', 'Web addresses that open a school; one is the primary address.', self::NONE],
        'memberships' => ['People', 'Who belongs to which school and with which role (owner, admin, educator, learner, guardian).', self::ACCOUNT],
        'users' => ['People', 'Accounts: name, email, password hash, two-step secret (encrypted), appearance. One account can belong to several schools.', self::ACCOUNT],
        'account_tokens' => ['People', 'Single-use links: password reset, invitation, email confirmation. Stored as hashes; they expire.', self::SECURITY],
        'recovery_codes' => ['People', 'Two-step sign-in recovery codes, stored as hashes.', self::SECURITY],
        'login_throttles' => ['Security', 'Failed sign-in counters per account and address, to slow down guessing.', self::SECURITY],
        'rate_limits' => ['Security', 'Counters for other limited actions (discount codes, emails, uploads).', self::SECURITY],
        'audit_events' => ['Security', 'Who did what and when (sign-ins, role changes, payments, refunds); field values are not stored.', self::ACCOUNT],
        'learner_profiles' => ['People', 'What a person told the school when joining online: goals, experience, optional GitHub name.', self::ACCOUNT],
        'join_requests' => ['People', 'Requests to join from parents and would-be teachers, until the school decides.', self::ACCOUNT],
        'guardian_links' => ['Families', 'Which parent or guardian follows which learner, what they may see, and who checked their identity.', self::CHILD],
        'wizard_runs' => ['Setup', 'Answers saved part-way through a guided setup, until they are applied or discarded.', self::NONE],
        'site_pages' => ['Website', 'The school\'s own public pages (about, admissions, contact, questions and answers, careers, privacy, terms, refunds, safeguarding).', self::NONE],
        'api_keys' => ['School', 'Keys the school made for its own systems to read data through API v1: name, prefix, a hash of the key (never the key), who made it, last use.', self::SECURITY],
        'blog_posts' => ['Website', 'Blog posts: title, summary, text, kind (news, event, story, guide), draft or published, the staff member who wrote it.', self::NONE],
        'courses' => ['Courses', 'Courses: title, summary, level, price and payment in parts, who can read and join, completion rule.', self::NONE],
        'course_modules' => ['Courses', 'Sections of a course, in order.', self::NONE],
        'lessons' => ['Courses', 'Lessons of every type (reading, video, audio, quiz, assignment, lab), whether required, free preview.', self::NONE],
        'assessments' => ['Courses', 'A quiz lesson\'s settings: pass mark and attempts allowed.', self::NONE],
        'questions' => ['Courses', 'Quiz questions and their explanation.', self::NONE],
        'question_options' => ['Courses', 'Answer options for each question, and which is correct.', self::NONE],
        'files' => ['Files', 'Uploaded files (lesson files, media, class materials, help-desk attachments, pictures in bank questions): name, type, size, storage key, scan result.', self::CONTENT],
        'enrolments' => ['Learning', 'A learner\'s place on a course: active, completed or withdrawn, with dates.', self::LEARNING],
        'lesson_progress' => ['Learning', 'Which lessons a learner opened and finished, and when.', self::LEARNING],
        'quiz_attempts' => ['Learning', 'Every quiz attempt: answers, score, pass or not, time.', self::LEARNING],
        'assignment_submissions' => ['Learning', 'Work sent for an assignment and the teacher\'s decision and feedback.', self::LEARNING],
        'lab_sessions' => ['Learning', 'Virtual lab runs: which lab, when, the result.', self::LEARNING],
        'certificates' => ['Learning', 'Certificates issued, with their public number; revocations and reasons.', self::LEARNING],
        'achievements' => ['Learning', 'Badges a learner has earned.', self::LEARNING],
        'learning_paths' => ['Courses', 'Learning paths: an ordered set of courses.', self::NONE],
        'learning_path_courses' => ['Courses', 'The courses in each path, in order.', self::NONE],
        'learning_path_followers' => ['Learning', 'Who follows which path.', self::LEARNING],
        'lesson_notes' => ['Learning', 'A learner\'s private notes and saved lessons.', self::CONTENT],
        'lesson_questions' => ['Discussions', 'Questions learners ask under a lesson.', self::CONTENT],
        'lesson_answers' => ['Discussions', 'Answers to those questions; one may be accepted.', self::CONTENT],
        'code_snippets' => ['Code studio', 'Code a person saved in the Code studio.', self::CONTENT],
        'code_challenge_solves' => ['Code studio', 'Which coding challenges a person solved, and when.', self::LEARNING],
        'course_applications' => ['Admissions', 'Applications for courses that take them: answers, place in the queue, offer or decline.', self::ACCOUNT],
        'learner_groups' => ['Classes', 'Classes or cohorts: name, level, year, archived.', self::NONE],
        'learner_group_members' => ['Classes', 'Who is in each class, as learner or teacher.', self::LEARNING],
        'learner_group_courses' => ['Classes', 'Courses set for a class, with due dates.', self::NONE],
        'group_announcements' => ['Classes', 'Messages a teacher posts to a class.', self::CONTENT],
        'class_sessions' => ['Live classes', 'Planned and held live classes: time, place or online room, style, recording.', self::NONE],
        'class_attendance' => ['Live classes', 'The register: present, late, absent or excused, per learner and class.', self::LEARNING],
        'class_events' => ['Live classes', 'Join, still-here and leave signals from the online classroom, for minutes attended.', self::LEARNING],
        'classroom_settings' => ['Live classes', 'Each school\'s online classroom server and its secret (encrypted).', self::SECURITY],
        'meetings' => ['Meetings', 'Video meetings that are not classes: host, time, room.', self::NONE],
        'meeting_invites' => ['Meetings', 'Exactly who may join each meeting.', self::ACCOUNT],
        'meeting_events' => ['Meetings', 'Join, still-here and leave signals for meetings.', self::ACCOUNT],
        'notifications' => ['Messages', 'In-app notices to a person (live class, payment, refund, feedback); read or not.', self::ACCOUNT],
        'mail_outbox' => ['Messages', 'Emails waiting to be sent and those sent or failed, kept for a short time.', self::ACCOUNT],
        'support_requests' => ['Help desk', 'Help requests a person sends the school, with status.', self::CONTENT],
        'support_messages' => ['Help desk', 'Messages in each help request.', self::CONTENT],
        'payment_accounts' => ['Payments', 'Each school\'s Paystack connection: mode, currency, secret key (encrypted, never shown).', self::SECURITY],
        'payment_instructions' => ['Payments', 'The school\'s bank details text shown to learners who pay directly.', self::NONE],
        'payments' => ['Payments', 'Every payment: who, course, amount, discount code, method, status (pending, paid, failed, refunded).', self::MONEY],
        'payment_events' => ['Payments', 'Everything Paystack or staff reported about a payment, each event once.', self::MONEY],
        'payment_claims' => ['Payments', 'Learners saying they paid the school directly, until the school confirms or rejects.', self::MONEY],
        'question_banks' => ['Quizzes and exams', 'A school\'s question banks, one per subject, and whether learners may practise from them.', self::NONE],
        'bank_passages' => ['Quizzes and exams', 'Passages, tables or instructions that several bank questions share.', self::NONE],
        'bank_questions' => ['Quizzes and exams', 'Bank questions: options A to E, the right one, explanation, topic, difficulty, a picture and its description, times seen and right.', self::NONE],
        'cbt_exams' => ['Quizzes and exams', 'CBT exams: sections from banks, subjects to choose, time, window, who sits it, attempts, results policy and announcement, access code.', self::NONE],
        'cbt_exam_groups' => ['Quizzes and exams', 'Which classes sit a CBT exam set for classes only.', self::NONE],
        'cbt_attempts' => ['Quizzes and exams', 'One sitting of a CBT exam or practice test: the questions drawn, answers, flags, deadline, scores by subject.', self::LEARNING],
        'live_quizzes' => ['Quizzes and exams', 'Live quizzes teachers run in class.', self::NONE],
        'live_questions' => ['Quizzes and exams', 'Questions in a live quiz: answers, the right one, time limit, points.', self::NONE],
        'live_sessions' => ['Quizzes and exams', 'Each time a live quiz is played: PIN, host, a copy of the questions, where the game is.', self::NONE],
        'live_players' => ['Quizzes and exams', 'Who joined a live quiz (a member, or a guest nickname), score, streak.', self::LEARNING],
        'live_answers' => ['Quizzes and exams', 'Every answer in a live quiz with its time and points.', self::LEARNING],
        'payment_plans' => ['Payments', 'Learners paying a course in monthly parts: the price and number of parts agreed, when part 1 was paid, status, last reminder sent.', self::MONEY],
        'payment_refunds' => ['Payments', 'Money paid back, in full or part, with reason and status.', self::MONEY],
        'coupons' => ['Payments', 'Discount codes: percentage or amount, course, uses allowed, last day.', self::NONE],
        'schema_migrations' => ['System', 'Which database migrations have been applied, with checksums.', self::NONE],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function markdown(): string
    {
        $columns = [];
        foreach (
            $this->db->fetchAll(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, COLUMN_KEY AS k
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ) as $r
        ) {
            $columns[(string) $r['t']][] = $r;
        }
        $out = "# Data dictionary\n\nGenerated from the live schema by `bin/console schema:dictionary` (do not edit by hand; edit "
            . "`app/Core/Database/DataDictionary.php` and run it again). School data carries `tenant_id`, and every query on it "
            . "is limited to one school (ADR 0001). The exceptions are shared by the whole platform: `tenants`, `users` (one account can "
            . "belong to several schools), `recovery_codes`, `login_throttles`, `rate_limits` and `schema_migrations`.\n\n"
            . "Personal data kinds: **Account** (who someone is), **Learning record** (what they did and scored), **Family link**, "
            . "**Payment**, **Written by a person** (free text and files), **Security** (secrets and counters, never shown).\n\n";

        $byArea = [];
        foreach (array_keys($columns) as $table) {
            $byArea[self::TABLES[$table][0] ?? 'Other'][] = $table;
        }
        ksort($byArea);
        $out .= "| Area | Table | What it holds | Personal data |\n|---|---|---|---|\n";
        foreach ($byArea as $area => $tables) {
            foreach ($tables as $table) {
                [, $what, $kind] = self::TABLES[$table] ?? ['Other', '(not described yet)', '?'];
                $out .= sprintf("| %s | [`%s`](#%s) | %s | %s |\n", $area, $table, str_replace('_', '-', $table), $what, $kind);
            }
        }
        foreach ($columns as $table => $cols) {
            $out .= "\n## " . $table . "\n\n" . (self::TABLES[$table][1] ?? '(not described yet)') . "\n\n| Column | Type | Null | Default | Key |\n|---|---|---|---|---|\n";
            foreach ($cols as $c) {
                $out .= sprintf(
                    "| `%s` | %s | %s | %s | %s |\n",
                    $c['c'],
                    $c['type'],
                    $c['nullable'] === 'YES' ? 'yes' : '',
                    $c['dflt'] === null ? '' : '`' . str_replace('|', '\\|', (string) $c['dflt']) . '`',
                    match ((string) $c['k']) {
                    'PRI' => 'primary', 'UNI' => 'unique', 'MUL' => 'index', default => ''
                    },
                );
            }
        }

        return $out;
    }

    /** @return list<string> tables in the database that have no description here */
    public function undescribed(): array
    {
        $tables = array_map(static fn (array $r): string => (string) $r['t'], $this->db->fetchAll(
            'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        ));

        return array_values(array_diff($tables, array_keys(self::TABLES)));
    }
}
