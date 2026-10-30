<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0042: CBT exams — UTME-style subject choice, the exam clock, saving, marking and results. */
final class CbtExamsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private SampleClock $clock;
    private Tenant $tenant;
    /** @var array<string, int> bank ids by subject */
    private array $banks = [];

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->timeIs('2026-11-02 08:00:00'); // 09:00 in Lagos
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'bayo@school.test', Role::Learner);
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/banks/samples');
        foreach ($this->db()->fetchAll('SELECT id, name FROM question_banks WHERE tenant_id = ?', [$this->tenant->id]) as $b) {
            $this->banks[(string) strtok((string) $b['name'], ' ')] = (int) $b['id'];
        }
    }

    private function timeIs(string $utc): void
    {
        $this->clock->at(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    /** @param array<string, mixed> $over */
    private function exam(array $over = []): int
    {
        $made = $this->post(self::HOST, '/teach/exams', $over + [
            'title' => 'SS 3 Mock UTME', 'instructions' => 'Answer every question.',
            'section_bank' => [(string) $this->banks['Use'], (string) $this->banks['Physics'], (string) $this->banks['Chemistry'], (string) $this->banks['Biology']],
            'section_count' => ['5', '4', '4', '4'], 'section_label' => ['Use of English', 'Physics', 'Chemistry', 'Biology'], 'section_compulsory' => ['0'],
            'choose_count' => '2', 'duration_minutes' => '30', 'opens_date' => '2026-11-02', 'opens_time' => '09:00', 'closes_date' => '2026-11-02', 'closes_time' => '12:00',
            'audience' => 'everyone', 'max_attempts' => '1', 'results' => 'immediately', 'review' => '1', 'shuffle_questions' => '1', 'shuffle_options' => '1', 'access_code' => '',
        ]);
        $this->assertSame(1, preg_match('#^/teach/exams/(\d+)$#', (string) $made->header('Location'), $m), $made->body);

        return (int) ($m[1] ?? 0);
    }

    private function startedId(Response $r): int
    {
        $this->assertSame(1, preg_match('#^/exams/attempts/(\d+)$#', (string) $r->header('Location'), $m), $r->body);

        return (int) ($m[1] ?? 0);
    }

    /** @param array<string, mixed> $data */
    private function json(string $path, array $data): Response
    {
        return $this->send(new Request('POST', $path, self::HOST, [], [], [
            'x-csrf-token' => $this->app->get(Csrf::class)->token(), 'content-type' => 'application/json', 'accept' => 'application/json',
        ], [], '0.0.0.0', false, [], false, (string) json_encode($data)));
    }

    /**
     * The option to click (as the candidate sees it) for each question: right ones, or wrong ones for $wrong.
     *
     * @param list<int> $wrong
     * @return array<int, int>
     */
    private function picks(int $attempt, array $wrong = []): array
    {
        $paper = json_decode((string) $this->db()->fetchValue('SELECT paper FROM cbt_attempts WHERE id = ?', [$attempt]), true);
        $out = [];
        foreach ($paper['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $correct = (int) $this->db()->fetchValue('SELECT correct FROM bank_questions WHERE id = ?', [$q['id']]);
                $shown = (int) array_search($correct, $q['perm'], true);
                $out[(int) $q['id']] = in_array($q['id'], $wrong, true) ? ($shown + 1) % count($q['perm']) : $shown;
            }
        }

        return $out;
    }

    public function testAFullSitting(): void
    {
        $exam = $this->exam(['section_count' => ['5', '40', '4', '4']]);
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->assertSame('draft', $this->db()->fetchValue('SELECT status FROM cbt_exams WHERE id = ?', [$exam]), 'Physics asks for more questions than its bank has');
        $this->assertStringContainsString('asks for 40 questions but its bank has 19', $this->get(self::HOST, '/teach/exams/' . $exam)->body);
        $this->assertRedirectTo('/teach/exams/' . $exam, $this->post(self::HOST, '/teach/exams/' . $exam, [
            'title' => 'SS 3 Mock UTME', 'section_bank' => [(string) $this->banks['Use'], (string) $this->banks['Physics'], (string) $this->banks['Chemistry'], (string) $this->banks['Biology']],
            'section_count' => ['5', '4', '4', '4'], 'section_label' => ['Use of English', 'Physics', 'Chemistry', 'Biology'], 'section_compulsory' => ['0'],
            'choose_count' => '2', 'duration_minutes' => '30', 'opens_date' => '2026-11-02', 'opens_time' => '09:00', 'closes_date' => '2026-11-02', 'closes_time' => '12:00',
            'audience' => 'everyone', 'max_attempts' => '1', 'results' => 'immediately', 'review' => '1', 'shuffle_questions' => '1', 'shuffle_options' => '1',
        ]));
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->assertSame('published', $this->db()->fetchValue('SELECT status FROM cbt_exams WHERE id = ?', [$exam]));

        $this->as('amaka@school.test');
        $this->assertStringContainsString('SS 3 Mock UTME', $this->get(self::HOST, '/exams')->body);
        $one = $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1']]);
        $this->assertSame(422, $one->status);
        $this->assertStringContainsString('Choose 2 subjects to sit.', $one->body);
        $attempt = $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '3']]));
        $this->assertSame($attempt, $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['2', '3']])), 'starting again returns to the same attempt');

        $paper = json_decode($this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->body, true);
        $this->assertSame(['Use of English', 'Physics', 'Biology'], array_column($paper['sections'], 'label'));
        $this->assertSame(1800, $paper['seconds_left']);
        foreach ($paper['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $this->assertSame(['id', 'n', 'prompt', 'passage', 'options', 'answer', 'flag'], array_keys($q), 'the paper never carries the answers');
            }
        }
        $this->assertSame(13, $paper['sections'][2]['questions'][3]['n']);

        $physics = array_column($paper['sections'][1]['questions'], 'id');
        $picks = $this->picks($attempt, [$physics[0], $physics[1]]);
        $saved = json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 2, 'answers' => $picks, 'flags' => [$physics[0]]])->body, true);
        $this->assertTrue($saved['ok']);
        $late = json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 1, 'answers' => array_fill_keys(array_keys($picks), null), 'flags' => []])->body, true);
        $this->assertSame(2, $late['seq'], 'a save that arrives late does not undo a newer one');
        $this->assertCount(13, json_decode((string) $this->db()->fetchValue('SELECT answers FROM cbt_attempts WHERE id = ?', [$attempt]), true));
        $again = json_decode($this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->body, true);
        $this->assertTrue($again['sections'][1]['questions'][0]['flag']);
        $this->assertSame($picks[$physics[2]], $again['sections'][1]['questions'][2]['answer'], 'answers come back as shown, after a reload');

        $this->timeIs('2026-11-02 08:12:00');
        $this->assertRedirectTo('/exams/attempts/' . $attempt . '/result', $this->post(self::HOST, '/exams/attempts/' . $attempt . '/submit'));
        $row = $this->db()->fetchOne('SELECT * FROM cbt_attempts WHERE id = ?', [$attempt]);
        $this->assertNotNull($row);
        $this->assertSame('candidate', $row['submit_reason']);
        $this->assertSame(11, (int) $row['correct_count']);
        $this->assertSame(85, (int) $row['percent']);
        $this->assertSame(['Use of English' => 100, 'Physics' => 50, 'Biology' => 100], array_column(json_decode((string) $row['result'], true)['sections'], 'scaled', 'label'));
        $result = $this->get(self::HOST, '/exams/attempts/' . $attempt . '/result');
        $this->assertStringContainsString('250', $result->body);
        $this->assertStringContainsString('/ 300', $result->body);
        $this->assertSame(13, (int) $this->db()->fetchValue('SELECT SUM(times_seen) FROM bank_questions'));
        $this->assertSame(11, (int) $this->db()->fetchValue('SELECT SUM(times_correct) FROM bank_questions'));
        $this->assertFalse(json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 9, 'answers' => [], 'flags' => []])->body, true)['ok']);
        $this->assertSame(422, $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2']])->status, 'one attempt only');

        $this->as('bayo@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->status, 'nobody else opens the attempt');

        $this->as('teacher@school.test');
        $results = $this->get(self::HOST, '/teach/exams/' . $exam . '/results');
        $this->assertStringContainsString('85%', $results->body);
        $this->assertStringContainsString('How each question did', $results->body);
        $csv = $this->get(self::HOST, '/teach/exams/' . $exam . '/results.csv');
        $this->assertStringContainsString('Physics (/100)', $csv->body);
        $this->assertStringContainsString('amaka@school.test', $csv->body);
        $this->assertStringContainsString('Not taken', $this->get(self::HOST, '/teach/exams/' . $exam . '/results')->body, 'Chemistry was not chosen');
    }

    public function testTheClockAccessCodesExtraTimeAndResultsAfterClosing(): void
    {
        $exam = $this->exam(['results' => 'after_close', 'access_code' => 'mock42', 'closes_time' => '10:00']);
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');

        $this->as('amaka@school.test');
        $this->timeIs('2026-11-02 07:59:00');
        $this->assertStringContainsString('This exam opens later.', $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2'], 'code' => 'MOCK42'])->body);
        $this->timeIs('2026-11-02 08:00:00');
        $this->assertStringContainsString('That access code is not right', $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2'], 'code' => 'nope'])->body);
        $attempt = $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2'], 'code' => ' mock42 ']));
        $picks = $this->picks($attempt);

        $this->timeIs('2026-11-02 08:30:20');
        $early = json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 1, 'answers' => array_slice($picks, 0, 3, true), 'flags' => []])->body, true);
        $this->assertTrue($early['ok'], 'thirty seconds of grace for a slow network');
        $this->timeIs('2026-11-02 08:30:31');
        $closed = json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 2, 'answers' => $picks, 'flags' => []])->body, true);
        $this->assertSame('closed', $closed['error']);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/exams/' . $exam . '/attempts/' . $attempt . '/extend', ['minutes' => '10']);
        $this->as('amaka@school.test');
        $this->assertTrue(json_decode($this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 2, 'answers' => $picks, 'flags' => []])->body, true)['ok'], 'ten more minutes from the teacher');

        $this->timeIs('2026-11-02 08:45:00');
        $result = $this->get(self::HOST, '/exams/attempts/' . $attempt . '/result');
        $this->assertSame(200, $result->status, 'out of time: the result page submits it');
        $row = $this->db()->fetchOne('SELECT submit_reason, submitted_at, percent FROM cbt_attempts WHERE id = ?', [$attempt]);
        $this->assertNotNull($row);
        $this->assertSame('time', $row['submit_reason']);
        $this->assertSame('2026-11-02 08:40:00', $row['submitted_at'], 'stamped at the deadline, not when it was noticed');
        $this->assertSame(100, (int) $row['percent']);
        $this->assertStringNotContainsString('100%', $result->body, 'results wait until the exam closes');
        $this->timeIs('2026-11-02 09:00:00');
        $this->assertStringContainsString('100%', $this->get(self::HOST, '/exams/attempts/' . $attempt . '/result')->body);

        $this->as('teacher@school.test');
        $this->assertRedirectTo('/teach/exams/' . $exam . '/results', $this->post(self::HOST, '/teach/exams/' . $exam . '/attempts/' . $attempt . '/reset'));
        $this->assertNull($this->db()->fetchValue('SELECT id FROM cbt_attempts WHERE id = ?', [$attempt]));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'cbt.attempt_reset'"));
    }

    public function testTheCronSubmitsAbandonedAttemptsAndClosingSubmitsTheRest(): void
    {
        $exam = $this->exam();
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $this->as('amaka@school.test');
        $first = $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2']]));
        $this->as('bayo@school.test');
        $second = $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['2', '3']]));

        $this->timeIs('2026-11-02 08:31:00');
        $tenant = $this->tenant;
        $this->assertSame(2, $this->app->get(CbtExams::class)->submitOverdue(static fn (int $id): ?Tenant => $id === $tenant->id ? $tenant : null));
        $this->assertSame(0, $this->app->get(CbtExams::class)->submitOverdue(static fn (int $id): Tenant => $tenant), 'nothing twice');
        $this->assertSame(['time', 'time'], array_column($this->db()->fetchAll('SELECT submit_reason FROM cbt_attempts WHERE id IN (?, ?)', [$first, $second]), 'submit_reason'));

        $this->as('teacher@school.test');
        $exam2 = $this->exam(['title' => 'Physics test', 'max_attempts' => '0']);
        $this->post(self::HOST, '/teach/exams/' . $exam2 . '/publish');
        $this->as('amaka@school.test');
        $third = $this->startedId($this->post(self::HOST, '/exams/' . $exam2 . '/start', ['subjects' => ['1', '2']]));
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/exams/' . $exam2 . '/close');
        $this->assertSame('closed', $this->db()->fetchValue('SELECT submit_reason FROM cbt_attempts WHERE id = ?', [$third]));
        $this->as('amaka@school.test');
        $this->assertStringContainsString('This exam has closed.', $this->post(self::HOST, '/exams/' . $exam2 . '/start', ['subjects' => ['1', '2']])->body);
    }

    public function testClassesPracticeAndTryingAnExam(): void
    {
        $this->as('admin@school.test');
        $made = $this->post(self::HOST, '/groups', ['name' => 'SS 3 Gold', 'level' => 'SS 3', 'period' => '2026']);
        $this->assertSame(1, preg_match('#^/groups/(\d+)$#', (string) $made->header('Location'), $m));
        $group = (int) ($m[1] ?? 0);
        $this->post(self::HOST, '/groups/' . $group . '/learners', ['emails' => 'amaka@school.test']);

        $this->as('teacher@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/teach/exams', ['title' => 'x', 'audience' => 'groups', 'choose_count' => '0'])->status);
        $exam = $this->exam(['audience' => 'groups', 'groups' => [(string) $group]]);
        $this->post(self::HOST, '/teach/exams/' . $exam . '/publish');
        $trial = $this->startedId($this->post(self::HOST, '/teach/exams/' . $exam . '/try'));
        $this->assertNull($this->db()->fetchValue('SELECT exam_id FROM cbt_attempts WHERE id = ?', [$trial]), 'a teacher trying it is not a candidate');
        $this->assertStringStartsWith('Trying: ', (string) $this->db()->fetchValue('SELECT title FROM cbt_attempts WHERE id = ?', [$trial]));
        $this->assertSame(200, $this->get(self::HOST, '/exams/attempts/' . $trial)->status);

        $this->as('bayo@school.test');
        $this->assertStringNotContainsString('SS 3 Mock UTME', $this->get(self::HOST, '/exams')->body);
        $this->assertSame(404, $this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2']])->status, 'set for another class');
        $this->assertSame(422, $this->post(self::HOST, '/exams/practice', ['banks' => [(string) $this->banks['Physics']], 'count' => '7', 'minutes' => '0'])->status);
        $practice = $this->startedId($this->post(self::HOST, '/exams/practice', ['banks' => [(string) $this->banks['Physics'], (string) $this->banks['Chemistry']], 'count' => '5', 'minutes' => '0']));
        $row = $this->db()->fetchOne('SELECT exam_id, question_count, title FROM cbt_attempts WHERE id = ?', [$practice]);
        $this->assertNotNull($row);
        $this->assertNull($row['exam_id']);
        $this->assertSame(10, (int) $row['question_count']);
        $this->assertTrue(json_decode($this->get(self::HOST, '/exams/attempts/' . $practice . '/paper')->body, true)['untimed']);
        $this->post(self::HOST, '/exams/attempts/' . $practice . '/submit');
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT SUM(times_seen) FROM bank_questions'), 'practice does not count in the question statistics');

        $this->as('amaka@school.test');
        $this->assertStringContainsString('SS 3 Mock UTME', $this->get(self::HOST, '/exams')->body);
        $this->startedId($this->post(self::HOST, '/exams/' . $exam . '/start', ['subjects' => ['1', '2']]));
    }
}
