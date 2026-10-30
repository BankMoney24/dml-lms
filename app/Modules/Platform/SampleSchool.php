<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Bootstrap\Application;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Modules\Achievements\Achievements;
use App\Modules\Applications\Admissions;
use App\Modules\Applications\ApplicationRepository;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assessments\AttemptService;
use App\Modules\Assignments\AssignmentService;
use App\Modules\Courses\Course;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Discussions\Discussions;
use App\Modules\Discussions\QuestionRepository;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\Enrolment;
use App\Modules\Learning\LearningService;
use App\Modules\Paths\PathRepository;
use App\Modules\Paths\PathService;
use App\Modules\Payments\PaymentService;
use App\Modules\Payments\TransferPayments;
use App\Modules\Support\SupportService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use App\Modules\VirtualLabs\LabGateway;
use App\Modules\VirtualLabs\LabSessionRepository;
use DateTimeImmutable;
use DateTimeZone;

// Mostly sample text and SQL with long value lists: line length is not checked in this file.
// phpcs:disable Generic.Files.LineLength

/**
 * Fills a school with realistic sample data for testing every part of the
 * platform (ADR 0027). It drives the real services (enrolling, quizzes,
 * labs, assignments and reviews, payments and payment notes, applications,
 * questions, groups, paths, the help desk) with a clock that moves through
 * the last 90 days, so dates, reports, certificates, notifications and the
 * audit log all look as if people really used the school.
 *
 * Every sample account has an @sample.test address (a reserved domain that
 * never receives email) and shares one generated password, printed once.
 * In production it only fills a school it created itself (--create), so
 * a real school's data is never mixed with sample people.
 */
final class SampleSchool
{
    public const DOMAIN = 'sample.test';
    public const SIZES = ['small' => [24, 3], 'medium' => [60, 4], 'large' => [150, 6]];
    private const DAYS = 90;

    private const FIRST = ['Adaeze', 'Chinedu', 'Ngozi', 'Emeka', 'Tobi', 'Funmilayo', 'Ibrahim', 'Aisha', 'Kelechi', 'Tunde', 'Zainab', 'Oluwaseun', 'Chiamaka', 'Bola', 'Yusuf', 'Amarachi', 'Femi', 'Halima', 'Uche', 'Damilola', 'Ifeoma', 'Musa', 'Temitope', 'Obinna', 'Kemi', 'Sani', 'Nneka', 'Segun', 'Hadiza', 'Chukwuemeka', 'Folake', 'Abubakar', 'Ebuka', 'Ronke', 'Jide', 'Amina', 'Ikenna', 'Yetunde', 'Bashir', 'Chidinma', 'Kunle', 'Fatima', 'Nnamdi', 'Bukola', 'Garba', 'Ogechi', 'Wale', 'Rukayat', 'Ifeanyi', 'Mariam'];
    private const LAST = ['Okafor', 'Adeyemi', 'Bello', 'Eze', 'Ogunleye', 'Musa', 'Nwosu', 'Abdullahi', 'Okonkwo', 'Balogun', 'Ibrahim', 'Okoro', 'Afolabi', 'Danjuma', 'Chukwu', 'Adebayo', 'Uzor', 'Lawal', 'Obi', 'Oyelaran', 'Yakubu', 'Nwachukwu', 'Akinola', 'Mohammed', 'Ezeh', 'Ogbonna', 'Salami', 'Onyeka', 'Aliyu', 'Ojo'];
    private const QUESTIONS = [
        'I followed the steps but got a different answer for the broadcast address. Is the mask applied to every octet?',
        'Why does the second device count as a different network here? They look almost the same.',
        'Can someone explain the difference between return and print again? My function prints the right thing but the test fails.',
        'In the lab my trace stops at R2. Does that always mean R2 has no route, or could it be the way back?',
        'What is the easiest way to remember which DNS packet is the answer?',
        'My titration readings are close but not the same each time. How close is close enough?',
        'Is there a quick way to check my work before submitting the assignment?',
        'Do we need to memorise the commands for the exam or will we have a reference?',
        'Where can I practise more problems like this one?',
        'I get an IndentationError on line 3. What does it mean?',
    ];
    private const ANSWERS = [
        'Good question. Work it out in binary for the octet where the prefix splits; the other octets are either all network or all host. Try the converter in the lab.',
        'Look at the network address of each: if they differ, the devices are on different networks even if the first numbers match.',
        'print only shows a value on the screen; return hands it back to the code that called the function, which is what the tests check. Change print to return.',
        'Not always. If later hops show only * * *, check the routes back to your own network on the routers further along.',
        'Match the transaction ID: the response carries the same ID as its query, and its source is the DNS server.',
        'Within about 0.1 cm³ of each other is a good target. Repeat until two titres agree that closely.',
        'Read the task again and tick off each part. If you are unsure, post your question here before you submit.',
        'You will have the reference sheet from the course resources. Focus on knowing when to use each command.',
        'The coding challenges in the Code studio have more of these, with tests you can run.',
        'Python uses indentation to group lines. Make sure every line inside the if or def is indented by the same four spaces.',
    ];
    private const SNIPPETS = [
        ['Times table', 'python', "for i in range(1, 13):\n    print(f\"9 x {i} = {9 * i}\")\n"],
        ['Average score', 'python', "scores = [72, 58, 91, 64]\nprint(sum(scores) / len(scores))\n"],
        ['Subnet hosts', 'javascript', "for (let p = 24; p <= 30; p++) {\n  console.log('/' + p, 2 ** (32 - p) - 2);\n}\n"],
        ['Grade function', 'javascript', "function grade(s) {\n  if (s >= 70) return 'A';\n  if (s >= 60) return 'B';\n  return 'C';\n}\nconsole.log(grade(75));\n"],
        ['My first page', 'web', "<!doctype html>\n<html><body>\n<h1>Hello from the sample school</h1>\n<p>This page was made in the Code studio.</p>\n</body></html>\n"],
        ['Leap years', 'python', "def is_leap(y):\n    return (y % 4 == 0 and y % 100 != 0) or y % 400 == 0\n\nprint([y for y in range(1896, 1912) if is_leap(y)])\n"],
    ];
    private const SUPPORT = [
        ['account', 'I cannot change my password', 'When I try to change my password it says the current one is wrong, but I can sign in with it.'],
        ['payment', 'Paid by transfer yesterday', 'I paid for the Linux course by bank transfer yesterday. How long does it take to open?'],
        ['course', 'Lab timer ran out', 'The timer on the subnetting lab ran out before I finished. Can I try again?'],
        ['technical', 'Video does not play on my phone', 'The video lesson shows a blank box on my phone. It works on the school computer.'],
        ['other', 'Certificate name spelling', 'My name on the certificate is missing the second letter. Can it be corrected?'],
    ];

    private Container $c;
    private SampleClock $clock;
    private Connection $db;
    private Tenant $tenant;
    private string $hash = '';
    /** @var list<string> */
    private array $log = [];
    private int $ip = 0;
    /** @var array<string, true> */
    private array $usedEmails = [];

    public function __construct(private readonly Config $config)
    {
        $this->clock = new SampleClock();
        $this->c = Application::create($config, [Clock::class => $this->clock]);
        $this->db = $this->c->get(Connection::class);
    }

    /**
     * @param array{create?: string, host?: string, size?: string, seed?: string, public?: bool} $options
     * @return array{tenant: Tenant, password: string, counts: array<string, int>, log: list<string>}
     */
    public function run(string $slug, array $options): array
    {
        // One transaction: if anything fails, the school is left exactly as it was.
        return $this->db->transaction(fn (): array => $this->fill($slug, $options));
    }

    /**
     * @param array{create?: string, host?: string, size?: string, seed?: string, public?: bool} $options
     * @return array{tenant: Tenant, password: string, counts: array<string, int>, log: list<string>}
     */
    private function fill(string $slug, array $options): array
    {
        $size = $options['size'] ?? 'medium';
        if (!isset(self::SIZES[$size])) {
            throw new \RuntimeException('Choose --size=small, medium or large.');
        }
        mt_srand((int) ($options['seed'] ?? 2026));
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . self::DAYS . ' days')->setTime(8, 0);
        $this->clock->at($start);

        $tenants = $this->c->get(TenantRepository::class);
        $tenant = $tenants->findBySlug($slug);
        $created = false;
        if ($tenant === null) {
            if (!isset($options['create'], $options['host'])) {
                throw new \RuntimeException('There is no school "' . $slug . '". Add --create="School name" --host=<address> to make a sample school.');
            }
            if ($tenants->hostExists(strtolower($options['host']))) {
                throw new \RuntimeException('That address already belongs to a school.');
            }
            $tenant = $this->db->transaction(static function () use ($tenants, $slug, $options): Tenant {
                $t = $tenants->create($slug, $options['create']);
                $tenants->addDomain($t->id, strtolower($options['host']), true, true);

                return $t;
            });
            $created = true;
            $this->db->insert(
                "INSERT INTO audit_events (tenant_id, actor_user_id, action, subject_type, subject_id, metadata, ip_hash, created_at) VALUES (?, NULL, 'sample.school_created', NULL, NULL, ?, NULL, ?)",
                [$tenant->id, json_encode(['public' => (bool) ($options['public'] ?? false)]), $this->clock->sqlNow()],
            );
        }
        $marked = $this->db->fetchValue("SELECT 1 FROM audit_events WHERE tenant_id = ? AND action = 'sample.school_created' LIMIT 1", [$tenant->id]) !== null;
        if ($this->config->isProduction() && !$marked) {
            throw new \RuntimeException('In production, sample data only goes into a school this command created. Make one: sample:seed <new-slug> --create="DML Sample School" --host=<address>.');
        }
        if ($this->db->fetchValue("SELECT 1 FROM audit_events WHERE tenant_id = ? AND action = 'sample.seeded' LIMIT 1", [$tenant->id]) !== null) {
            throw new \RuntimeException('This school already has sample data.');
        }
        $this->tenant = $tenant;
        $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
        $this->hash = AuthService::hashPassword($password);

        [$learnerCount, $teacherCount] = self::SIZES[$size];
        if ($created) {
            $this->setUpSchool($tenant, $options['create'] ?? $tenant->name, (bool) ($options['public'] ?? false));
        }
        $tenant = $tenants->findById($tenant->id) ?? $tenant;
        $this->tenant = $tenant;

        // Staff first, on day 0.
        $owner = $this->person('owner', 'Folake', 'Adeyemi', Role::Owner);
        $admin = $this->person('admin', 'Ibrahim', 'Musa', Role::Admin);
        $teachers = [];
        for ($i = 0; $i < $teacherCount; $i++) {
            [$f, $l] = $this->name();
            $teachers[] = $this->person('teacher.' . strtolower($f), $f, $l, Role::Educator);
        }
        $courses = $this->courses($teachers, $created);
        $this->say(sprintf('%d published courses ready.', count($courses)));
        $this->paths($teachers[0], $courses);
        $groups = $this->groups($admin, $teachers, $courses);

        // Learners join over the first 75 days and learn until today.
        $learners = [];
        for ($i = 0; $i < $learnerCount; $i++) {
            // More people join as the term goes on; a few joined this week.
            $day = (int) round(88 - 87 * (mt_rand(0, 1000) / 1000) ** 1.4);
            $joined = $start->modify('+' . $day . ' days')->setTime(mt_rand(7, 21), mt_rand(0, 59));
            // Someone who "joined today" never joins later than now.
            $this->clock->at($joined < $this->today() ? $joined : $this->today()->modify('-' . mt_rand(20, 240) . ' minutes'));
            [$f, $l] = $this->name();
            $learner = $this->person(strtolower($f . '.' . $l), $f, $l, Role::Learner);
            $this->profile($learner);
            $learners[] = $learner;
            $this->learn($learner, $courses, $groups, $admin, $teachers, mt_rand(20, 100) / 100);
        }
        $this->recentNotes($learners, $courses);
        $this->attendance($groups);
        $this->guardians($admin, $learners);
        $this->requests();
        $this->support($learners, $admin);
        foreach ((new SampleSchoolTests($this->c, $this->clock, $tenant))->run($teachers[0], $learners, $start) as $line) {
            $this->say($line);
        }
        $this->clock->now = null;
        foreach ($learners as $l) {
            $this->c->get(Achievements::class)->refresh($tenant->id, $l->user->id);
        }
        $this->db->insert(
            "INSERT INTO audit_events (tenant_id, actor_user_id, action, subject_type, subject_id, metadata, ip_hash, created_at) VALUES (?, NULL, 'sample.seeded', NULL, NULL, ?, NULL, UTC_TIMESTAMP())",
            [$tenant->id, json_encode(['size' => $size])],
        );

        $count = fn (string $sql): int => (int) $this->db->fetchValue($sql, [$tenant->id]);

        return [
            'tenant' => $tenant,
            'password' => $password,
            'counts' => [
                'people' => $count("SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND u.email LIKE '%@" . self::DOMAIN . "'"),
                'enrolments' => $count('SELECT COUNT(*) FROM enrolments WHERE tenant_id = ?'),
                'lessons completed' => $count("SELECT COUNT(*) FROM lesson_progress WHERE tenant_id = ? AND status = 'completed'"),
                'quiz attempts' => $count('SELECT COUNT(*) FROM quiz_attempts WHERE tenant_id = ?'),
                'lab sessions' => $count('SELECT COUNT(*) FROM lab_sessions WHERE tenant_id = ?'),
                'assignments' => $count('SELECT COUNT(*) FROM assignment_submissions WHERE tenant_id = ?'),
                'certificates' => $count('SELECT COUNT(*) FROM certificates WHERE tenant_id = ?'),
                'payments' => $count('SELECT COUNT(*) FROM payments WHERE tenant_id = ?'),
                'payment notes' => $count('SELECT COUNT(*) FROM payment_claims WHERE tenant_id = ?'),
                'applications' => $count('SELECT COUNT(*) FROM course_applications WHERE tenant_id = ?'),
                'questions' => $count('SELECT COUNT(*) FROM lesson_questions WHERE tenant_id = ?'),
                'groups' => $count('SELECT COUNT(*) FROM learner_groups WHERE tenant_id = ?'),
                'live classes' => $count('SELECT COUNT(*) FROM class_sessions WHERE tenant_id = ?'),
                'attendance marks' => $count('SELECT COUNT(*) FROM class_attendance WHERE tenant_id = ?'),
                'help requests' => $count('SELECT COUNT(*) FROM support_requests WHERE tenant_id = ?'),
                'exam attempts' => $count('SELECT COUNT(*) FROM cbt_attempts WHERE tenant_id = ?'),
                'live quiz players' => $count('SELECT COUNT(*) FROM live_players WHERE tenant_id = ?'),
                'notifications' => $count('SELECT COUNT(*) FROM notifications WHERE tenant_id = ?'),
            ],
            'log' => $this->log,
        ];
    }

    private function setUpSchool(Tenant $tenant, string $name, bool $public): void
    {
        $tenants = $this->c->get(TenantRepository::class);
        $tenants->updateProfile($tenant->id, [
            'name' => $name, 'kind' => 'training_centre', 'tagline' => 'Sample data for trying the platform', 'theme' => $tenant->theme,
            'contact_email' => 'office@' . self::DOMAIN, 'contact_phone' => '', 'website' => '', 'calendar' => 'terms', 'levels' => [],
            'certificates_enabled' => true, 'public_catalogue' => $public, 'group_term' => 'cohort',
        ]);
        $tenants->updateJoining($tenant->id, 'open', null, true);
        $tenants->markSetUp($tenant->id);
    }

    private function person(string $local, string $first, string $last, Role $role): CurrentUser
    {
        $email = $local . '@' . self::DOMAIN;
        for ($n = 2; isset($this->usedEmails[$email]) || $this->c->get(UserRepository::class)->findByEmail($email) !== null; $n++) {
            $email = $local . $n . '@' . self::DOMAIN;
        }
        $this->usedEmails[$email] = true;
        $user = $this->c->get(UserRepository::class)->create($email, $first . ' ' . $last, $this->hash);
        $this->c->get(UserRepository::class)->markEmailVerified($user->id);
        $membership = $this->c->get(MembershipRepository::class)->add($this->tenant->id, $user->id, $role);

        return new CurrentUser($user, $membership);
    }

    /** @return array{0: string, 1: string} */
    private function name(): array
    {
        return [self::FIRST[mt_rand(0, count(self::FIRST) - 1)], self::LAST[mt_rand(0, count(self::LAST) - 1)]];
    }

    private function profile(CurrentUser $learner): void
    {
        $now = $this->clock->sqlNow();
        $goals = array_slice(self::shuffled(array_keys(\App\Modules\Joining\LearnerProfile::GOALS)), 0, mt_rand(1, 3));
        $this->db->execute(
            'INSERT INTO learner_profiles (tenant_id, user_id, goals, experience, weekly_goal, github, portfolio, joined_via, consent_at, onboarded_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)',
            [$this->tenant->id, $learner->user->id, implode(',', $goals), ['new', 'some', 'pro'][mt_rand(0, 2)], [2, 3, 5][mt_rand(0, 2)], ['self', 'school'][mt_rand(0, 1)], $now, $now, $now, $now],
        );
    }

    /**
     * The school's published courses; imports the shipped packages a new
     * sample school does not have yet, with prices and rules to exercise.
     *
     * @param list<CurrentUser> $teachers
     * @return list<Course>
     */
    private function courses(array $teachers, bool $created): array
    {
        $repo = $this->c->get(CourseRepository::class);
        $existing = array_map('strval', array_column($this->db->fetchAll('SELECT title FROM courses WHERE tenant_id = ?', [$this->tenant->id]), 'title'));
        $i = 0;
        foreach (glob(Application::basePath('courses/*/course.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || in_array($data['title'] ?? '', $existing, true)) {
                continue;
            }
            $author = $teachers[$i++ % count($teachers)];
            $course = $this->c->get(CoursePackageImporter::class)->import($this->tenant->id, $author, dirname($file), true)['course'];
            if (!$created) {
                continue;
            }
            // Only courses this command made are given prices and rules.
            match (basename(dirname($file))) {
                'linux-server-administration' => $repo->updatePrice($this->tenant->id, $course->id, 2500000, 'NGN'),
                'python-first-programs' => $repo->updatePrice($this->tenant->id, $course->id, 1500000, 'NGN'),
                'network-troubleshooting' => $repo->updateEnrolment($this->tenant->id, $course->id, 'apply', 30),
                'ss2-science-practicals' => $repo->updateAccess($this->tenant->id, $course->id, 'open'),
                default => null,
            };
            $this->say('Imported ' . $course->title . '.');
        }
        if ($created) {
            $this->c->get(TransferPayments::class)->saveInstructions($this->tenant, $this->staff(), "SAMPLE DETAILS, NOT A REAL ACCOUNT\nBank: Sample Bank\nAccount name: DML Sample School\nAccount number: 0000000000\nNarration: your full name and the course name", '127.0.0.1');
        }
        $ids = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM courses WHERE tenant_id = ? AND status = 'published' ORDER BY id", [$this->tenant->id]), 'id'));

        return array_values(array_filter(array_map(fn (int $id): ?Course => $repo->findById($this->tenant->id, $id), $ids)));
    }

    /** @param list<Course> $courses */
    private function paths(CurrentUser $author, array $courses): void
    {
        if ($this->c->get(PathRepository::class)->all($this->tenant->id) !== []) {
            return;
        }
        $free = array_values(array_filter($courses, static fn (Course $c): bool => !$c->isPaid() && !$c->takesApplications()));
        $paid = array_values(array_filter($courses, static fn (Course $c): bool => $c->isPaid()));
        $service = $this->c->get(PathService::class);
        $plans = [['Junior IT support technician', 'Networks, troubleshooting and the command line, in order.', [...$free, ...$paid]], ['Programming from zero', 'Start with small programs and grow to real projects.', [...$paid, ...$free]]];
        foreach ($plans as [$title, $summary, $list]) {
            $list = array_slice($list, 0, 3);
            if (count($list) < 2) {
                continue;
            }
            $path = $service->create($this->tenant->id, $author, $title, $summary);
            foreach ($list as $c) {
                $service->addCourse($path, $author, $c->id);
            }
            $service->publish($path, $author, true);
        }
    }

    /**
     * @param list<CurrentUser> $teachers
     * @param list<Course>      $courses
     * @return list<array{id: int, courses: list<int>, teacher: CurrentUser}>
     */
    private function groups(CurrentUser $admin, array $teachers, array $courses): array
    {
        $service = $this->c->get(GroupService::class);
        $repo = $this->c->get(GroupRepository::class);
        $out = [];
        $free = array_values(array_filter($courses, static fn (Course $c): bool => !$c->isPaid() && !$c->takesApplications()));
        foreach ([['Evening cohort, October', 'Adults'], ['SS2 Science A', 'SS2'], ['Weekend coders', 'Mixed'], ['Network technicians', 'Adults']] as $i => [$name, $level]) {
            $this->clock->shift(mt_rand(2, 8) * 3600);
            $id = $service->create($this->tenant, $admin, ['name' => $name, 'level' => $level, 'period' => 'Term 1'], $this->ip());
            $group = $repo->find($this->tenant->id, $id) ?? throw new \LogicException('Group not saved');
            $service->setTeachers($this->tenant, $group, $admin, [$teachers[$i % count($teachers)]->user->id], $this->ip());
            $assigned = [];
            foreach (array_slice(self::shuffled($free), 0, mt_rand(1, 2)) as $course) {
                $service->assignCourse($this->tenant, $group, $admin, $course->id, $this->clock->now()->modify('+' . mt_rand(30, 80) . ' days')->format('Y-m-d'), $this->ip());
                $assigned[] = $course->id;
            }
            $out[] = ['id' => $id, 'courses' => $assigned, 'teacher' => $teachers[$i % count($teachers)]];
            $this->timetable($group, $teachers[$i % count($teachers)], $i, $assigned[0] ?? null);
        }

        return $out;
    }

    /** A weekly live class for the group, from the first week of term to two weeks from now (ADR 0028). */
    private function timetable(\App\Modules\Groups\LearnerGroup $group, CurrentUser $teacher, int $i, ?int $courseId): void
    {
        $zone = new DateTimeZone($this->tenant->timezone);
        $first = $this->clock->now()->setTimezone($zone)->modify('+' . (3 + $i) . ' days');
        $mode = ['online', 'room', 'both', 'online'][$i % 4];
        $this->c->get(\App\Modules\LiveClasses\LiveClasses::class)->schedule($this->tenant, $group, $teacher, [
            'title' => ['Weekly class: questions and practice', 'Practical session', 'Code-along', 'Troubleshooting clinic'][$i % 4],
            'date' => $first->format('Y-m-d'),
            'time' => ['18:00', '14:00', '10:00', '17:30'][$i % 4],
            'minutes' => ['60', '90', '120', '60'][$i % 4],
            'mode' => $mode,
            'location' => $mode === 'online' ? '' : ['', 'Science lab, block B', 'Computer room 2', ''][$i % 4],
            'join_url' => $mode === 'room' ? '' : 'https://meet.example.org/sample-' . strtolower(bin2hex(random_bytes(3))),
            'course_id' => $courseId === null ? '' : (string) $courseId,
            'weeks' => (string) \App\Modules\LiveClasses\LiveClasses::MAX_WEEKS,
            'description' => "Bring your questions from this week's lessons.\n\nSample class: the meeting link goes nowhere.",
        ], $this->ip());
    }

    /**
     * Teachers take the register after each class held so far; the latest
     * class of every other group is left for the teacher, so registers to take
     * can be tried.
     *
     * @param list<array{id: int, courses: list<int>, teacher: CurrentUser}> $groups
     */
    private function attendance(array $groups): void
    {
        $repo = $this->c->get(\App\Modules\LiveClasses\ClassSessionRepository::class);
        $live = $this->c->get(\App\Modules\LiveClasses\LiveClasses::class);
        $now = $this->today();
        foreach ($groups as $gi => $g) {
            $held = array_reverse($repo->pastForGroup($this->tenant->id, $g['id'], $now->format('Y-m-d H:i:s'), 50));
            if ($gi % 2 === 0) {
                array_pop($held);
            }
            $members = $this->db->fetchAll("SELECT user_id, added_at FROM learner_group_members WHERE tenant_id = ? AND group_id = ? AND role = 'learner'", [$this->tenant->id, $g['id']]);
            foreach ($held as $k => $session) {
                if ($k === 2 && $gi === 1) {
                    $this->clock->at(new DateTimeImmutable($session->startsAt . ' UTC'));
                    $live->cancel($this->tenant, $session, $g['teacher'], 'The teacher is at a training day. We will cover this next week.', $this->ip());
                    continue;
                }
                $statuses = [];
                foreach ($members as $m) {
                    if ((string) $m['added_at'] > $session->startsAt) {
                        continue;
                    }
                    $roll = mt_rand(1, 100);
                    $statuses[(string) $m['user_id']] = $roll <= 76 ? 'present' : ($roll <= 86 ? 'late' : ($roll <= 96 ? 'absent' : 'excused'));
                }
                if ($statuses === []) {
                    continue;
                }
                $this->clock->at((new DateTimeImmutable($session->endsAt . ' UTC'))->modify('+' . mt_rand(5, 90) . ' minutes'));
                $live->register($this->tenant, $session, $g['teacher'], $statuses, [], $this->ip());
            }
        }
    }

    /**
     * One learner's whole story: joining a group, picking courses, paying or
     * applying, and working through lessons at their own pace.
     *
     * @param list<Course>                                            $courses
     * @param list<array{id: int, courses: list<int>, teacher: CurrentUser}> $groups
     * @param list<CurrentUser>                                      $teachers
     */
    private function learn(CurrentUser $learner, array $courses, array $groups, CurrentUser $admin, array $teachers, float $diligence): void
    {
        $joined = $this->clock->now();
        if ($groups !== [] && mt_rand(1, 100) <= 55) {
            $g = $groups[mt_rand(0, count($groups) - 1)];
            $group = $this->c->get(GroupRepository::class)->find($this->tenant->id, $g['id']);
            if ($group !== null) {
                $this->c->get(GroupService::class)->addLearners($this->tenant, $group, $admin, '', [$learner->user->id], $this->ip());
                if (mt_rand(1, 100) <= 30) {
                    $this->clock->shift(mt_rand(1, 5) * 86400);
                    if ($this->clock->now() < $this->today()) {
                        $this->c->get(GroupService::class)->announce($this->tenant, $group, $g['teacher'], self::pick([
                            'Reminder: finish this week\'s lessons before Friday.',
                            'Great work so far, everyone. The quiz results are looking good.',
                            'The lab will be open all weekend. Ask questions under the lesson if you are stuck.',
                            'Class is moved to 5pm on Thursday this week.',
                        ]), $this->ip());
                    }
                    $this->clock->at($joined);
                }
            }
        }
        $paths = $this->c->get(PathRepository::class)->published($this->tenant->id);
        if ($paths !== [] && mt_rand(1, 100) <= 30) {
            try {
                $this->c->get(PathService::class)->start($paths[mt_rand(0, count($paths) - 1)], $learner);
            } catch (\Throwable) {
                // A path whose first course needs payment or a place just records the follow.
            }
        }
        $picked = array_slice(self::shuffled($courses), 0, mt_rand(1, min(3, count($courses))));
        foreach ($picked as $course) {
            $this->clock->at($joined->modify('+' . mt_rand(0, 6) . ' hours'));
            $enrolment = $this->join($learner, $course, $admin, $teachers);
            if ($enrolment !== null) {
                $this->study($learner, $course, $enrolment, $diligence, $teachers);
            }
        }
        // Group courses were enrolled automatically: study those too.
        foreach ($this->db->fetchAll("SELECT course_id FROM enrolments WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [$this->tenant->id, $learner->user->id]) as $row) {
            $course = $this->c->get(CourseRepository::class)->findById($this->tenant->id, (int) $row['course_id']);
            $done = (int) $this->db->fetchValue("SELECT COUNT(*) FROM lesson_progress p JOIN enrolments e ON e.id = p.enrolment_id WHERE e.tenant_id = ? AND e.user_id = ? AND e.course_id = ?", [$this->tenant->id, $learner->user->id, (int) $row['course_id']]);
            if ($course !== null && $done === 0 && !in_array($course->id, array_map(static fn (Course $p): int => $p->id, $picked), true)) {
                $this->clock->at($joined->modify('+1 day'));
                $e = $this->c->get(LearningService::class)->findEnrolment($course, $learner);
                if ($e !== null) {
                    $this->study($learner, $course, $e, $diligence, $teachers);
                }
            }
        }
        $this->clock->at($joined->modify('+' . mt_rand(1, 20) . ' days'));
        if ($this->clock->now() < $this->today()) {
            $this->extras($learner);
        }
    }

    /**
     * A few people told the school they paid in the last day or so: these wait
     * in Payments to confirm, so the queue can be tried.
     *
     * @param list<CurrentUser> $learners
     * @param list<Course>      $courses
     */
    private function recentNotes(array $learners, array $courses): void
    {
        $paid = array_values(array_filter($courses, static fn (Course $c): bool => $c->isPaid() && !$c->takesApplications()));
        if ($paid === []) {
            return;
        }
        $made = 0;
        foreach (self::shuffled($learners) as $learner) {
            $course = $paid[$made % count($paid)];
            if ($made >= 3 || $this->c->get(LearningService::class)->findEnrolment($course, $learner) !== null) {
                continue;
            }
            $this->clock->at($this->today()->modify('-' . mt_rand(1, 30) . ' hours'));
            if ($this->claim($learner, $course, (int) $course->priceMinor) !== null) {
                $made++;
            }
        }
    }

    /** @param list<CurrentUser> $teachers */
    private function join(CurrentUser $learner, Course $course, CurrentUser $admin, array $teachers): ?Enrolment
    {
        $learning = $this->c->get(LearningService::class);
        if ($learning->findEnrolment($course, $learner) !== null) {
            return null;
        }
        if ($learning->mustApply($course, $learner)) {
            $application = $this->c->get(Admissions::class)->apply($this->tenant, $course, $learner, ['name' => '', 'email' => '', 'phone' => '080' . mt_rand(10000000, 99999999), 'message' => self::pick(['I would like to join the next intake.', 'My manager asked me to take this course.', 'I have done the free course and want to go further.', '']), 'agree' => true], $this->ip());
            $this->clock->shift(mt_rand(1, 6) * 86400);
            if ($this->clock->now() > $this->today()) {
                return null; // still waiting today
            }
            $roll = mt_rand(1, 100);
            if ($roll <= 15) {
                $this->c->get(Admissions::class)->decline($this->tenant, $teachers[0], $application->id, $this->ip());

                return null;
            }
            if ($roll <= 35) {
                return null; // still on the waiting list
            }
            $this->c->get(Admissions::class)->offer($this->tenant, $admin, $application->id, $this->ip(), 'https://' . self::DOMAIN);
            // A free course enrols the person when the place is offered.
            $enrolment = $this->c->get(\App\Modules\Learning\EnrolmentRepository::class)->find($this->tenant->id, $course->id, $learner->user->id);
            if ($enrolment !== null || !$course->isPaid()) {
                return $enrolment;
            }
        }
        if ($learning->needsPayment($course, $learner)) {
            return $this->pay($learner, $course, $admin);
        }

        return $learning->enrol($course, $learner);
    }

    private function pay(CurrentUser $learner, Course $course, CurrentUser $admin): ?Enrolment
    {
        $learning = $this->c->get(LearningService::class);
        $roll = mt_rand(1, 100);
        $amount = (int) $course->priceMinor;
        if ($roll <= 45) {
            // Paid online (recorded as a Paystack test-mode payment: no real money moved).
            $ref = sprintf('DML-%d-%s', $this->tenant->id, strtoupper(bin2hex(random_bytes(8))));
            $now = $this->clock->sqlNow();
            $this->db->insert(
                "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, provider_transaction_id, channel, created_at, updated_at, paid_at)
                 VALUES (?, ?, ?, ?, 'paystack', 'test', ?, ?, 'paid', ?, ?, ?, ?, ?)",
                [$this->tenant->id, $learner->user->id, $course->id, $ref, $amount, (string) $course->currency, (string) mt_rand(1000000000, 4999999999), self::pick(['card', 'bank_transfer', 'ussd']), $now, $now, $now],
            );

            return $learning->enrol($course, $learner);
        }
        if ($roll <= 75) {
            // Paid the school directly and told it; the school confirms a day or two later.
            $claim = $this->claim($learner, $course, $roll <= 70 ? $amount : intdiv($amount, 10));
            $this->clock->shift(mt_rand(4, 60) * 3600);
            if ($claim === null || $this->clock->now() > $this->today()) {
                return null;
            }
            if ($roll <= 70) {
                $this->c->get(TransferPayments::class)->confirm($this->tenant, $admin, $claim, $this->ip());

                return $learning->findEnrolment($course, $learner);
            }
            $this->c->get(TransferPayments::class)->reject($this->tenant, $admin, $claim, 'Only part of the fee arrived. Please pay the balance and send a new note.', $this->ip());

            return null;
        }
        if ($roll <= 85) {
            // Recorded by hand at the office.
            $user = $learner->user;
            $this->c->get(PaymentService::class)->storeManual($this->tenant, $admin, $user, $course, $amount, 'Cash at the office, receipt ' . mt_rand(1000, 9999), $this->ip());

            return $learning->findEnrolment($course, $learner);
        }
        // Started paying online and did not finish, or the card was declined.
        $now = $this->clock->sqlNow();
        $failed = mt_rand(0, 1) === 1;
        $this->db->insert(
            "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'paystack', 'test', ?, ?, ?, ?, ?, ?)",
            [$this->tenant->id, $learner->user->id, $course->id, sprintf('DML-%d-%s', $this->tenant->id, strtoupper(bin2hex(random_bytes(8)))), $amount, (string) $course->currency, $failed ? 'failed' : 'pending', $failed ? 'Card declined by the bank' : null, $now, $now],
        );

        return null;
    }

    private function claim(CurrentUser $learner, Course $course, int $amount): ?int
    {
        try {
            return $this->c->get(TransferPayments::class)->claim($this->tenant, $course, $learner, [
                'method' => self::pick(['transfer', 'transfer', 'deposit', 'pos']),
                'amount' => (string) intdiv($amount, 100),
                'paid_on' => $this->clock->now()->format('Y-m-d'),
                'reference' => '0000' . mt_rand(10000000, 99999999) . mt_rand(1000, 9999),
                'note' => '',
            ], $this->ip())->id;
        } catch (ValidationException) {
            return null;
        }
    }

    /** @param list<CurrentUser> $teachers */
    private function study(CurrentUser $learner, Course $course, Enrolment $enrolment, float $d, array $teachers): void
    {
        $lessons = $this->c->get(LessonRepository::class)->orderedLessons($this->tenant->id, $course->id);
        $learning = $this->c->get(LearningService::class);
        $stopAt = mt_rand(1, 100) <= (int) (25 + 55 * $d) ? count($lessons) : mt_rand(0, max(0, count($lessons) - 1));
        $asked = false;
        foreach ($lessons as $i => $lesson) {
            if ($i >= $stopAt) {
                break;
            }
            $this->clock->shift((int) (mt_rand(2, 60) * 3600 * (1.25 - $d)));
            if ($this->clock->now() > $this->today()) {
                break;
            }
            $learning->markOpened($enrolment, $lesson);
            $ok = match ($lesson->type) {
                Lesson::TYPE_QUIZ => $this->quiz($enrolment, $lesson, $d),
                'lab' => $this->lab($learner, $course, $enrolment, $lesson, $d),
                'assignment' => $this->assignment($enrolment, $lesson, $teachers),
                default => $learning->completeTextLesson($enrolment, $lesson) || true,
            };
            if ($this->clock->now() > $this->today()) {
                break; // the quiz, lab or assignment ran past today
            }
            if (!$asked && mt_rand(1, 100) <= 12) {
                $asked = true;
                $this->ask($learner, $course, $lesson, $teachers);
            }
            if (mt_rand(1, 100) <= 6) {
                $now = $this->clock->sqlNow();
                $this->db->execute(
                    'INSERT INTO lesson_notes (tenant_id, user_id, lesson_id, is_saved, body, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
                    [$this->tenant->id, $learner->user->id, $lesson->id, self::pick(['Revise this before the quiz.', 'Ask about this in class.', 'Good example to copy.', null]), $now, $now],
                );
            }
            if (!$ok) {
                break;
            }
        }
    }

    private function quiz(Enrolment $enrolment, Lesson $lesson, float $d): bool
    {
        $assessment = $this->c->get(AssessmentRepository::class)->findForLesson($this->tenant->id, $lesson->id);
        if ($assessment === null) {
            return true;
        }
        $questions = $this->c->get(AssessmentRepository::class)->questions($this->tenant->id, $assessment['id']);
        for ($try = 0; $try < 3; $try++) {
            $accuracy = min(0.98, 0.4 + 0.5 * $d + 0.15 * $try);
            $answers = [];
            foreach ($questions as $q) {
                $right = array_values(array_filter($q->options, static fn ($o): bool => $o->correct));
                $wrong = array_values(array_filter($q->options, static fn ($o): bool => !$o->correct));
                $choice = mt_rand(1, 100) <= $accuracy * 100 || $wrong === [] ? ($right[0] ?? $q->options[0]) : $wrong[mt_rand(0, count($wrong) - 1)];
                $answers[(string) $q->id] = (string) $choice->id;
            }
            try {
                $this->c->get(AttemptService::class)->submit($enrolment, $lesson, $answers, bin2hex(random_bytes(16)));
            } catch (\Throwable) {
                return false;
            }
            if ($this->c->get(\App\Modules\Assessments\AttemptRepository::class)->hasPassed($this->tenant->id, $assessment['id'], $enrolment->userId)) {
                return true;
            }
            if (mt_rand(1, 100) > 75) {
                return false;
            }
            $this->clock->shift(mt_rand(1, 20) * 3600);
            if ($this->clock->now() > $this->today()) {
                return false;
            }
        }

        return false;
    }

    private function lab(CurrentUser $learner, Course $course, Enrolment $enrolment, Lesson $lesson, float $d): bool
    {
        $gateway = $this->c->get(LabGateway::class);
        for ($try = 0; $try < 2; $try++) {
            try {
                $session = $gateway->launch($course, $lesson, $enrolment, $learner, $this->ip());
            } catch (\Throwable) {
                return false;
            }
            $this->clock->shift(mt_rand(8, 30) * 60);
            $score = min(100, max(0, (int) round((0.45 + 0.55 * $d) * 100 + mt_rand(-25, 15) + 15 * $try)));
            $passed = $score >= $gateway->labFor($lesson)->definition()->passMark;
            $this->c->get(LabSessionRepository::class)->markSubmitted($this->tenant->id, $session->id, $score, $passed, ['sample' => true], [
                ['label' => 'Your answers', 'ok' => $passed, 'detail' => $passed ? 'Most parts correct.' : 'Some parts need another look. Try again with new numbers.'],
            ]);
            if ($passed) {
                $this->c->get(LearningService::class)->recordLessonCompleted($enrolment, $lesson);

                return true;
            }
            $this->clock->shift(mt_rand(1, 24) * 3600);
            if ($this->clock->now() > $this->today()) {
                return false;
            }
        }

        return false;
    }

    /** @param list<CurrentUser> $teachers */
    private function assignment(Enrolment $enrolment, Lesson $lesson, array $teachers): bool
    {
        $service = $this->c->get(AssignmentService::class);
        $submission = $service->submit($enrolment, $lesson, self::pick([
            "I set up the server as described.\n\n1. Created the user and added it to sudo.\n2. Disabled password login for SSH and tested with my key.\n3. Enabled the firewall with only ports 22, 80 and 443 open.\n\nThe output of `ufw status` is pasted below.",
            "Here is my write-up. I followed each step and noted what I saw. The part about permissions took me longest: I had to use chmod 640 for the config file.",
            "Answers:\n- systemctl status shows the service active (running)\n- journalctl -u nginx shows no errors since the restart\n- The site answers on https with a valid certificate.",
        ]), mt_rand(0, 2) === 0 ? 'https://github.com/sample-learner/linux-notes' : '', $this->ip());
        $this->clock->shift(mt_rand(6, 72) * 3600);
        if ($this->clock->now() > $this->today()) {
            return false; // waiting for review today
        }
        $teacher = $teachers[mt_rand(0, count($teachers) - 1)];
        if (mt_rand(1, 100) <= 25) {
            $service->review($this->tenant, $teacher, $submission->id, 'changes', 'Nearly there. Please add the output of the firewall status command and say which ports are open and why.', 'https://' . self::DOMAIN, $this->ip());
            $this->clock->shift(mt_rand(12, 48) * 3600);
            if ($this->clock->now() > $this->today()) {
                return false;
            }
            $submission = $service->submit($enrolment, $lesson, "Updated with the firewall output:\n\nStatus: active\n22/tcp ALLOW\n80/tcp ALLOW\n443/tcp ALLOW\n\nOnly SSH and web traffic are needed on this server.", '', $this->ip());
            $this->clock->shift(mt_rand(6, 48) * 3600);
            if ($this->clock->now() > $this->today()) {
                return false;
            }
        }
        $service->review($this->tenant, $teacher, $submission->id, 'approve', self::pick(['Well done: clear and complete.', 'Good work. Keep these notes; you will use them again.', '']), 'https://' . self::DOMAIN, $this->ip());

        return true;
    }

    /** @param list<CurrentUser> $teachers */
    private function ask(CurrentUser $learner, Course $course, Lesson $lesson, array $teachers): void
    {
        $discussions = $this->c->get(Discussions::class);
        $k = mt_rand(0, count(self::QUESTIONS) - 1);
        try {
            $qid = $discussions->ask($course, $lesson, $learner, self::QUESTIONS[$k], $this->ip());
        } catch (\Throwable) {
            return;
        }
        $back = $this->clock->now();
        $this->clock->shift(mt_rand(1, 40) * 3600);
        if ($this->clock->now() < $this->today() && mt_rand(1, 100) <= 75) {
            $question = $this->c->get(QuestionRepository::class)->find($this->tenant->id, $qid);
            if ($question !== null) {
                $answerId = $discussions->answer($course, $question, $teachers[mt_rand(0, count($teachers) - 1)], self::ANSWERS[$k], $this->ip());
                if (mt_rand(1, 100) <= 50) {
                    $question = $this->c->get(QuestionRepository::class)->find($this->tenant->id, $qid);
                    if ($question !== null) {
                        $discussions->accept($course, $question, $learner, $answerId);
                    }
                }
            }
        }
        $this->clock->at($back);
    }

    /** Studio snippets and practice challenges for some learners. */
    private function extras(CurrentUser $learner): void
    {
        $now = $this->clock->sqlNow();
        if (mt_rand(1, 100) <= 35) {
            foreach (array_slice(self::shuffled(self::SNIPPETS), 0, mt_rand(1, 3)) as [$title, $lang, $code]) {
                $this->db->insert(
                    'INSERT INTO code_snippets (tenant_id, user_id, title, language, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$this->tenant->id, $learner->user->id, $title, $lang, $code, $now, $now],
                );
            }
        }
        if (mt_rand(1, 100) <= 30) {
            foreach (array_slice(self::shuffled(array_keys(\App\Modules\Playground\Challenges::all())), 0, mt_rand(1, 6)) as $key) {
                $lang = mt_rand(0, 1) === 1 ? 'python' : 'javascript';
                $this->db->execute(
                    'INSERT IGNORE INTO code_challenge_solves (tenant_id, user_id, challenge, language, code, solved_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$this->tenant->id, $learner->user->id, $key, $lang, \App\Modules\Playground\Challenges::starter($key, $lang), $now],
                );
            }
        }
    }

    /** @param list<CurrentUser> $learners */
    private function guardians(CurrentUser $admin, array $learners): void
    {
        $repo = $this->c->get(GuardianRepository::class);
        foreach (array_slice($learners, 0, max(2, intdiv(count($learners), 6))) as $child) {
            $last = explode(' ', $child->user->displayName)[1] ?? 'Parent';
            [$first] = $this->name();
            $this->clock->at($this->today()->modify('-' . mt_rand(5, 60) . ' days'));
            $parent = $this->person('parent.' . strtolower($last), $first, $last, Role::Guardian);
            $repo->link($this->tenant->id, $parent->user->id, $child->user->id, self::pick(['mother', 'father', 'guardian', 'sponsor']), true, true, true, $admin->user->id);
        }
    }

    /** People asking to teach or to follow a child, waiting for the school. */
    private function requests(): void
    {
        foreach ([['teach', 'Mr Kayode Ojo', null, 'I teach networking at a polytechnic and would like to run an evening class here.'], ['guardian', 'Mrs Halima Bello', 'Aisha Bello', 'I would like to follow my daughter\'s progress.'], ['teach', 'Ms Ifeoma Uzor', null, 'I can help mark Python assignments.']] as $i => [$kind, $name, $child, $msg]) {
            $this->db->insert(
                'INSERT INTO join_requests (tenant_id, kind, user_id, name, email, phone, child_name, message, status, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                [$this->tenant->id, $kind, $name, 'request' . ($i + 1) . '@' . self::DOMAIN, '0803' . mt_rand(1000000, 9999999), $child ?? '', $msg, 'pending', $this->today()->modify('-' . mt_rand(0, 6) . ' days')->format('Y-m-d H:i:s')],
            );
        }
    }

    /** @param list<CurrentUser> $learners */
    private function support(array $learners, CurrentUser $admin): void
    {
        $service = $this->c->get(SupportService::class);
        foreach (self::SUPPORT as $i => [$category, $subject, $body]) {
            $learner = $learners[($i * 7) % count($learners)];
            $this->clock->at($this->today()->modify('-' . mt_rand(1, 30) . ' days'));
            try {
                $id = $service->open($this->tenant, $learner, $category, $subject, $body, [], $this->ip());
                if ($i % 3 !== 2) {
                    $this->clock->shift(mt_rand(2, 30) * 3600);
                    $service->reply($this->tenant, $admin, $id, 'Thanks for telling us. We have looked into it and it is sorted now. Reply here if it happens again.', [], $this->ip());
                    if ($i % 2 === 0) {
                        $service->setResolved($this->tenant, $learner, $id, true, $this->ip());
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function staff(): CurrentUser
    {
        $id = (int) $this->db->fetchValue("SELECT user_id FROM memberships WHERE tenant_id = ? AND role IN ('owner', 'admin') AND status = 'active' ORDER BY id LIMIT 1", [$this->tenant->id]);
        $user = $this->c->get(UserRepository::class)->findById($id) ?? throw new \RuntimeException('No owner or admin to act for the school.');

        return new CurrentUser($user, $this->c->get(MembershipRepository::class)->find($this->tenant->id, $id));
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function ip(): string
    {
        $this->ip++;

        return '198.51.100.' . ($this->ip % 250 + 1);
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }
}
