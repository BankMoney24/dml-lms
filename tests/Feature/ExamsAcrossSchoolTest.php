<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Console\ConsoleApplication;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/**
 * ADR 0044: exams reach the rest of the school — notices when an exam is set and when
 * held-back results are ready, "Exams to sit" on the home page, results for parents,
 * and pictures in questions that only the right people can open.
 */
final class ExamsAcrossSchoolTest extends AppTestCase
{
    private const HOST = 'school.test';
    /** The smallest valid PNG: one transparent pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private SampleClock $clock;
    private Tenant $tenant;
    private int $physics;
    private int $tobi;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->timeIs('2026-11-02 08:00:00');
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->tobi = $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner)->id;
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->as('teacher@school.test');
        $made = $this->post(self::HOST, '/teach/banks', ['name' => 'SS 2 Physics', 'description' => '', 'practice' => '']);
        $this->assertSame(1, preg_match('#/teach/banks/(\d+)$#', (string) $made->header('Location'), $m));
        $this->physics = (int) ($m[1] ?? 0);
        $this->post(self::HOST, '/teach/banks/' . $this->physics . '/import', [
            'questions' => "Speed is distance divided by\nA. mass\nB. time\nANSWER: B\n\n"
                . "The unit of force is the\nA. newton\nB. joule\nANSWER: A\n\n"
                . "Current is measured in\nA. volts\nB. amperes\nANSWER: B",
        ]);
    }

    private function timeIs(string $utc): void
    {
        $this->clock->at(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    private function as(string $email, string $password = 'correct-horse-battery'): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, $password));
    }

    /** @param array<string, mixed> $over */
    private function exam(array $over = []): int
    {
        $made = $this->post(self::HOST, '/teach/exams', $over + [
            'title' => 'Physics test', 'section_bank' => [(string) $this->physics], 'section_count' => ['3'], 'section_label' => ['Physics'], 'section_compulsory' => ['0'],
            'choose_count' => '0', 'duration_minutes' => '20', 'opens_date' => '', 'opens_time' => '', 'closes_date' => '', 'closes_time' => '',
            'audience' => 'everyone', 'max_attempts' => '1', 'results' => 'immediately', 'review' => '1', 'shuffle_questions' => '1', 'shuffle_options' => '1', 'access_code' => '',
        ]);
        $this->assertSame(1, preg_match('#^/teach/exams/(\d+)$#', (string) $made->header('Location'), $m), $made->body);

        return (int) ($m[1] ?? 0);
    }

    private function sit(int $exam, bool $right): int
    {
        $started = $this->post(self::HOST, '/exams/' . $exam . '/start');
        $this->assertSame(1, preg_match('#^/exams/attempts/(\d+)$#', (string) $started->header('Location'), $m), $started->body);
        $attempt = (int) ($m[1] ?? 0);
        $paper = json_decode((string) $this->db()->fetchValue('SELECT paper FROM cbt_attempts WHERE id = ?', [$attempt]), true);
        $answers = [];
        foreach ($paper['sections'][0]['questions'] as $q) {
            $key = (int) $this->db()->fetchValue('SELECT correct FROM bank_questions WHERE id = ?', [$q['id']]);
            $answers[$q['id']] = array_search($right ? $key : 1 - $key, $q['perm'], true);
        }
        $service = $this->app->get(\App\Modules\Exams\Cbt\CbtExams::class);
        $userId = (int) $this->db()->fetchValue('SELECT user_id FROM cbt_attempts WHERE id = ?', [$attempt]);
        $service->saveAnswers($this->tenant, $attempt, $userId, 1, $answers, []);
        $service->submit($this->tenant, $attempt, 'candidate');

        return $attempt;
    }

    /** @return list<array{title: string, link: ?string}> */
    private function notices(string $email): array
    {
        return array_map(
            static fn (array $r): array => ['title' => (string) $r['title'], 'link' => $r['link'] === null ? null : (string) $r['link']],
            $this->db()->fetchAll('SELECT n.title, n.link FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = ? ORDER BY n.id', [$email]),
        );
    }

    public function testLearnersAreToldWhenAnExamIsSetAndFindItOnTheirHomePage(): void
    {
        $exam = $this->exam(['closes_date' => '2026-11-06', 'closes_time' => '17:00']);
        $this->assertSame([], $this->notices('tobi@school.test'), 'drafts tell nobody');
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->assertSame([['title' => 'Exam set: Physics test', 'link' => '/exams/' . $exam]], $this->notices('tobi@school.test'));
        $this->assertCount(1, $this->notices('ada@school.test'));
        $this->assertSame([], $this->notices('teacher@school.test'), 'only learners');
        $body = (string) $this->db()->fetchValue("SELECT body FROM notifications WHERE title = 'Exam set: Physics test' LIMIT 1");
        $this->assertStringContainsString('Closes 6 Nov, 17:00', $body, 'in the school\'s time');

        $this->as('tobi@school.test');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Exams to sit', $home);
        $this->assertStringContainsString('href="/exams/' . $exam . '"', $home);
        $this->assertStringContainsString('20 minutes, closes 6 Nov, 17:00', $home);
        $attempt = (int) substr((string) $this->post(self::HOST, '/exams/' . $exam . '/start')->header('Location'), strlen('/exams/attempts/'));
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('href="/exams/attempts/' . $attempt . '"', $home);
        $this->assertStringContainsString('In progress: 20 minutes left', $home);
        $this->post(self::HOST, '/exams/attempts/' . $attempt . '/submit');
        $this->assertStringNotContainsString('Exams to sit', $this->get(self::HOST, '/')->body, 'sat: nothing left to sit');

        // A class exam is announced to that class only.
        $this->as('admin@school.test');
        $made = $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold', 'level' => 'SS 2', 'period' => '2026']);
        $group = (int) substr((string) $made->header('Location'), strlen('/groups/'));
        $this->post(self::HOST, '/groups/' . $group . '/learners', ['emails' => 'ada@school.test']);
        $this->as('teacher@school.test');
        $classExam = $this->exam(['title' => 'Gold class test', 'audience' => 'groups', 'groups' => [(string) $group]]);
        $this->post(self::HOST, '/teach/exams/' . $classExam . '/publish');
        $this->assertContains('Exam set: Gold class test', array_column($this->notices('ada@school.test'), 'title'));
        $this->assertNotContains('Exam set: Gold class test', array_column($this->notices('tobi@school.test'), 'title'));
    }

    public function testHeldBackResultsAreAnnouncedOnceAndReachParents(): void
    {
        $exam = $this->exam(['results' => 'after_close', 'closes_date' => '2026-11-02', 'closes_time' => '12:00']);
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->as('tobi@school.test');
        $attempt = $this->sit($exam, true);

        // A parent with "results" sees nothing until the results are out.
        $this->as('admin@school.test');
        $this->post(self::HOST, '/people/' . $this->tobi . '/guardians', ['name' => 'Funke Bello', 'email' => 'mum@school.test', 'relationship' => 'mother', 'progress' => '1', 'results' => '1']);
        $page = $this->get(self::HOST, '/people/' . $this->tobi . '/guardians')->body;
        $this->assertSame(1, preg_match('#<span class="secret">([^<]+)</span>#', $page, $m));
        $password = html_entity_decode($m[1] ?? '');
        $this->as('mum@school.test', $password);
        $this->post(self::HOST, '/account/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $this->assertStringNotContainsString('Physics test', $this->get(self::HOST, '/children/' . $this->tobi)->body);

        // The exam closes by the clock; the cron tells candidates once.
        $this->timeIs('2026-11-02 11:05:00');
        $console = new ConsoleApplication($this->app);
        ob_start();
        $console->run(['console', 'exams:close']);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('told 1 candidates', $out);
        $this->assertContains(['title' => 'Results ready: Physics test', 'link' => '/exams/attempts/' . $attempt . '/result'], $this->notices('tobi@school.test'));
        ob_start();
        $console->run(['console', 'exams:close']);
        ob_end_clean();
        $this->assertCount(1, array_filter($this->notices('tobi@school.test'), static fn (array $n): bool => $n['title'] === 'Results ready: Physics test'), 'once only');

        $child = $this->get(self::HOST, '/children/' . $this->tobi)->body;
        $this->assertStringContainsString('Physics test', $child);
        $this->assertStringContainsString('Physics 100', $child);
        $this->assertStringContainsString('(100% right)', $child);
        $this->assertStringContainsString('Physics test', $this->get(self::HOST, '/children/' . $this->tobi . '/report')->body);

        // Hidden results and practice never reach parents.
        $this->as('teacher@school.test');
        $hidden = $this->exam(['title' => 'Secret paper', 'results' => 'hidden']);
        $this->post(self::HOST, '/teach/exams/' . $hidden . '/publish');
        $this->as('tobi@school.test');
        $this->sit($hidden, true);
        $this->as('mum@school.test', 'a brand new passphrase');
        $this->assertStringNotContainsString('Secret paper', $this->get(self::HOST, '/children/' . $this->tobi)->body);
    }

    public function testClosingAnExamByHandAnnouncesHeldBackResults(): void
    {
        $exam = $this->exam(['results' => 'after_close']);
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->as('tobi@school.test');
        $this->sit($exam, false);
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/exams/' . $exam . '/close');
        $this->assertContains('Results ready: Physics test', array_column($this->notices('tobi@school.test'), 'title'));
        $this->assertNotContains('Results ready: Physics test', array_column($this->notices('ada@school.test'), 'title'), 'only candidates');
        $this->assertNotNull($this->db()->fetchValue('SELECT results_announced_at FROM cbt_exams WHERE id = ?', [$exam]));
    }

    public function testAQuestionPictureIsCheckedDescribedAndShownOnlyToTheRightPeople(): void
    {
        $q = (int) $this->db()->fetchValue("SELECT id FROM bank_questions WHERE bank_id = ? AND prompt LIKE 'Current%'", [$this->physics]);
        $base = '/teach/banks/' . $this->physics . '/questions/' . $q;
        $png = (string) base64_decode(self::PNG, true);

        $noAlt = $this->upload(self::HOST, $base . '/image', ['image_alt' => ''], ['image' => [$this->fakeUpload('circuit.png', $png)]]);
        $this->assertSame(422, $noAlt->status);
        $this->assertStringContainsString('Describe the picture in a sentence', $noAlt->body);
        $wrong = $this->upload(self::HOST, $base . '/image', ['image_alt' => 'A circuit'], ['image' => [$this->fakeUpload('circuit.pdf', self::pdfBytes())]]);
        $this->assertStringContainsString('Choose a PNG, JPEG, WebP or GIF picture.', $wrong->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files'));

        $added = $this->upload(self::HOST, $base . '/image', ['image_alt' => 'An ammeter in series with a lamp'], ['image' => [$this->fakeUpload('circuit.png', $png)]]);
        $this->assertRedirectTo($base . '#picture', $added);
        $file = (int) $this->db()->fetchValue('SELECT image_file_id FROM bank_questions WHERE id = ?', [$q]);
        $this->assertSame($q, (int) $this->db()->fetchValue('SELECT bank_question_id FROM files WHERE id = ?', [$file]));
        $editor = $this->get(self::HOST, $base)->body;
        $this->assertStringContainsString('src="/files/' . $file . '"', $editor);
        $this->assertStringContainsString('alt="An ammeter in series with a lamp"', $editor);
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $file)->status, 'teachers see it');

        $this->assertRedirectTo($base . '#picture', $this->post(self::HOST, $base . '/image', ['image_alt' => 'An ammeter and a lamp in series with a cell']));
        $this->assertSame($file, (int) $this->db()->fetchValue('SELECT image_file_id FROM bank_questions WHERE id = ?', [$q]), 'a new description keeps the picture');

        $exam = $this->exam();
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->as('tobi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $file)->status, 'not before it is in their paper');
        $attempt = (int) substr((string) $this->post(self::HOST, '/exams/' . $exam . '/start')->header('Location'), strlen('/exams/attempts/'));
        $images = array_column(json_decode($this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->body, true)['sections'][0]['questions'], 'image', 'id');
        $this->assertSame(['src' => '/files/' . $file, 'alt' => 'An ammeter and a lamp in series with a cell'], $images[$q]);
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $file)->status);
        $this->as('ada@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $file)->status, 'another learner without the paper');

        $this->as('teacher@school.test');
        $this->upload(self::HOST, $base . '/image', ['image_alt' => 'A newer drawing'], ['image' => [$this->fakeUpload('circuit2.png', $png)]]);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files'), 'the old picture is removed when replaced');
        $this->assertRedirectTo($base . '#picture', $this->post(self::HOST, $base . '/image/remove'));
        $this->assertNull($this->db()->fetchValue('SELECT image_file_id FROM bank_questions WHERE id = ?', [$q]));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files'));
    }
}
