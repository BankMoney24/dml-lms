<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use App\Core\Container;
use App\Core\Database\Connection;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Tenancy\Tenant;
use RuntimeException;

/** Adds one small published course so a fresh staging tenant has something to click through. */
final class DemoSeeder
{
    public function __construct(private readonly Container $container)
    {
    }

    public function run(Tenant $tenant): void
    {
        $db = $this->container->get(Connection::class);
        $authorId = $db->fetchValue(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND role IN ('owner', 'admin', 'educator') AND status = 'active' ORDER BY id LIMIT 1",
            [$tenant->id],
        );
        if ($authorId === null) {
            throw new RuntimeException('Create an owner, admin or educator in this tenant first (user:create).');
        }

        $courses = $this->container->get(CourseRepository::class);
        $lessons = $this->container->get(LessonRepository::class);
        $assessments = $this->container->get(AssessmentRepository::class);

        $db->transaction(function () use ($tenant, $authorId, $courses, $lessons, $assessments): void {
            $slug = 'networking-basics';
            for ($n = 2; $courses->slugExists($tenant->id, $slug); $n++) {
                $slug = 'networking-basics-' . $n;
            }
            $course = $courses->create(
                $tenant->id,
                (int) $authorId,
                $slug,
                'Networking Basics: How Data Travels',
                'A short first course on how devices find each other and exchange data. Three lessons and a quiz; about 20 minutes.',
            );

            $m1 = $lessons->addModule($tenant->id, $course->id, 'Getting connected');
            $lessons->addLesson($tenant->id, $course->id, $m1, Lesson::TYPE_TEXT, 'What is a network?', <<<TXT
            A network is two or more devices that can send data to each other. Your phone and your school's Wi-Fi router form a small network. The internet is millions of networks joined together.

            Data does not travel as one big piece. It is cut into small packets. Each packet carries part of the message and a label saying where it is going, a bit like envelopes in the post.

            Try it: count the devices in your home or classroom that use Wi-Fi. Each one is a member of that local network.
            TXT);
            $lessons->addLesson($tenant->id, $course->id, $m1, Lesson::TYPE_TEXT, 'Addresses: how devices find each other', <<<TXT
            Every device on a network needs an address so packets reach the right place. On the internet this is an IP address, written like 192.168.1.20.

            Your router gives addresses to devices on your local network. When you visit a website, a service called DNS turns a name such as example.org into the IP address of the server.

            Remember: names are for people, IP addresses are for machines, and DNS translates between them.
            TXT);

            $m2 = $lessons->addModule($tenant->id, $course->id, 'Check your understanding');
            $quizLesson = $lessons->addLesson($tenant->id, $course->id, $m2, Lesson::TYPE_QUIZ, 'Networking basics quiz', 'Answer all four questions. You need 70% to pass, and you can try again.');
            $assessmentId = $assessments->create($tenant->id, $quizLesson, 70, null);
            $assessments->addQuestion($tenant->id, $assessmentId, 'Data sent across a network is split into small pieces called…', ['Packets', 'Pages', 'Pixels', 'Ports'], 0);
            $assessments->addQuestion($tenant->id, $assessmentId, 'Which of these is an IP address?', ['www.example.org', '192.168.1.20', 'admin@example.org', 'HTTP'], 1);
            $assessments->addQuestion($tenant->id, $assessmentId, 'What does DNS do?', ['Encrypts passwords', 'Turns website names into IP addresses', 'Charges your phone', 'Blocks viruses'], 1);
            $assessments->addQuestion($tenant->id, $assessmentId, 'What usually gives devices on a home network their addresses?', ['The router', 'The keyboard', 'The website', 'The printer'], 0);

            $courses->markPublished($tenant->id, $course->id);
        });
    }
}
