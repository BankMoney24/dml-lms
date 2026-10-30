<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0045: leaving the exam page is counted and shown to teachers; class pages show exam results. */
final class ExamIntegrityTest extends AppTestCase
{
    private const HOST = 'school.test';

    private SampleClock $clock;
    private Tenant $tenant;
    private int $bank;
    private int $group;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->clock->at(new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone('UTC')));
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'kemi@school.test', Role::Learner);

        $this->as('admin@school.test');
        $made = $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold', 'level' => 'SS 2', 'period' => '2026']);
        $this->group = (int) substr((string) $made->header('Location'), strlen('/groups/'));
        $this->post(self::HOST, '/groups/' . $this->group . '/teachers', ['teachers' => [(string) $teacher->id]]);
        $this->post(self::HOST, '/groups/' . $this->group . '/learners', ['emails' => 'tobi@school.test ada@school.test']);

        $this->as('teacher@school.test');
        $made = $this->post(self::HOST, '/teach/banks', ['name' => 'Physics', 'description' => '', 'practice' => '1']);
        $this->bank = (int) substr((string) $made->header('Location'), strlen('/teach/banks/'));
        $this->post(self::HOST, '/teach/banks/' . $this->bank . '/import', ['questions' => "One?\nA. yes\nB. no\nANSWER: A\n\nTwo?\nA. yes\nB. no\nANSWER: B"]);
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
            'title' => 'Physics test', 'section_bank' => [(string) $this->bank], 'section_count' => ['2'], 'section_label' => ['Physics'], 'section_compulsory' => ['0'],
            'choose_count' => '0', 'duration_minutes' => '20', 'audience' => 'everyone', 'max_attempts' => '1', 'results' => 'hidden', 'review' => '1',
            'shuffle_questions' => '1', 'shuffle_options' => '1',
        ]);
        $id = (int) substr((string) $made->header('Location'), strlen('/teach/exams/'));
        $this->post(self::HOST, '/teach/exams/' . $id . '/publish');

        return $id;
    }

    /** @param array<string, mixed> $data */
    private function json(string $path, array $data): Response
    {
        return $this->send(new Request('POST', $path, self::HOST, [], [], [
            'x-csrf-token' => $this->app->get(Csrf::class)->token(), 'content-type' => 'application/json', 'accept' => 'application/json',
        ], [], '0.0.0.0', false, [], false, (string) json_encode($data)));
    }

    private function start(int $exam): int
    {
        return (int) substr((string) $this->post(self::HOST, '/exams/' . $exam . '/start')->header('Location'), strlen('/exams/attempts/'));
    }

    /** @return array{0: int, 1: int} */
    private function away(int $attempt): array
    {
        $row = $this->db()->fetchOne('SELECT away_count, away_seconds FROM cbt_attempts WHERE id = ?', [$attempt]);

        return [(int) ($row['away_count'] ?? -1), (int) ($row['away_seconds'] ?? -1)];
    }

    public function testLeavingTheExamPageIsCountedAndShownToTeachersOnly(): void
    {
        $exam = $this->exam();
        $this->as('tobi@school.test');
        $attempt = $this->start($exam);
        $room = $this->get(self::HOST, '/exams/attempts/' . $attempt)->body;
        $this->assertStringContainsString('Your teacher sees if you leave this page or switch apps.', $room, 'candidates are told');
        $paper = json_decode($this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->body, true);
        $this->assertSame(['count' => 0, 'seconds' => 0], $paper['away']);
        $this->assertTrue($paper['watched']);

        $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 0, 'answers' => [], 'flags' => [], 'away' => ['count' => 2, 'seconds' => 40]]);
        $this->assertSame([2, 40], $this->away($attempt));
        $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 0, 'answers' => [], 'flags' => [], 'away' => ['count' => 1, 'seconds' => 5]]);
        $this->assertSame([2, 40], $this->away($attempt), 'running totals never go down');
        $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 0, 'answers' => [], 'flags' => [], 'away' => 'lots']);
        $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 0, 'answers' => [], 'flags' => [], 'away' => ['count' => 99999999, 'seconds' => -5]]);
        $this->assertSame([10000, 40], $this->away($attempt), 'nonsense is ignored or capped');
        $this->db()->execute('UPDATE cbt_attempts SET away_count = 2 WHERE id = ?', [$attempt]);

        $this->as('ada@school.test');
        $this->assertSame(404, $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 9, 'answers' => [], 'flags' => [], 'away' => ['count' => 50, 'seconds' => 500]])->status);
        $this->assertSame([2, 40], $this->away($attempt), 'only the candidate');

        $this->as('tobi@school.test');
        $this->json('/exams/attempts/' . $attempt . '/submit', ['seq' => 1, 'answers' => [], 'flags' => [], 'away' => ['count' => 3, 'seconds' => 65]]);
        $this->assertSame([3, 65], $this->away($attempt), 'the last count travels with the submission');
        $this->json('/exams/attempts/' . $attempt . '/save', ['seq' => 5, 'answers' => [], 'flags' => [], 'away' => ['count' => 9, 'seconds' => 900]]);
        $this->assertSame([3, 65], $this->away($attempt), 'nothing after submitting');
        $this->assertStringNotContainsString('Left the exam page', $this->get(self::HOST, '/exams/attempts/' . $attempt . '/result')->body);

        $this->as('teacher@school.test');
        $this->assertStringContainsString('3 times, 1 min 5 s', $this->get(self::HOST, '/teach/exams/' . $exam . '/results')->body);
        $this->assertStringContainsString('Left the exam page: <strong>3 times, 1 min 5 s</strong>', $this->get(self::HOST, '/teach/exams/' . $exam . '/attempts/' . $attempt)->body);
        $csv = $this->get(self::HOST, '/teach/exams/' . $exam . '/results.csv')->body;
        $this->assertStringContainsString('"left the page (times)","away (seconds)"', $csv);
        $this->assertStringContainsString(',3,65', $csv);
    }

    public function testPracticeIsNotWatched(): void
    {
        $this->as('kemi@school.test');
        $made = $this->post(self::HOST, '/exams/practice', ['banks' => [(string) $this->bank], 'count' => '5', 'minutes' => '0']);
        $attempt = (int) substr((string) $made->header('Location'), strlen('/exams/attempts/'));
        $this->assertStringNotContainsString('Your teacher sees if you leave', $this->get(self::HOST, '/exams/attempts/' . $attempt)->body);
        $this->assertFalse(json_decode($this->get(self::HOST, '/exams/attempts/' . $attempt . '/paper')->body, true)['watched']);
    }

    public function testTheClassPageShowsEachLearnersExamResults(): void
    {
        $exam = $this->exam();
        $this->as('tobi@school.test');
        $attempt = $this->start($exam);
        $answers = [];
        foreach (json_decode((string) $this->db()->fetchValue('SELECT paper FROM cbt_attempts WHERE id = ?', [$attempt]), true)['sections'][0]['questions'] as $q) {
            $answers[$q['id']] = array_search((int) $this->db()->fetchValue('SELECT correct FROM bank_questions WHERE id = ?', [$q['id']]), $q['perm'], true);
        }
        $this->json('/exams/attempts/' . $attempt . '/submit', ['seq' => 1, 'answers' => $answers, 'flags' => [], 'away' => ['count' => 1, 'seconds' => 12]]);
        $this->as('ada@school.test');
        $this->start($exam);

        $this->as('teacher@school.test');
        $page = $this->get(self::HOST, '/groups/' . $this->group)->body;
        $this->assertStringContainsString('id="class-exams-h"', $page);
        $this->assertStringContainsString('href="/teach/exams/' . $exam . '/results">Physics test', $page);
        $this->assertStringContainsString('100% right', $page);
        $this->assertStringContainsString('Left the page once', $page);
        $this->assertStringContainsString('Sitting now', $page);
        $this->assertStringContainsString('1 of 2 sat', $page);
        $csv = $this->get(self::HOST, '/groups/' . $this->group . '/exams.csv');
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString('"Physics test (total)","Physics test (out of)","Physics test (% right)"', $csv->body);
        $this->assertStringContainsString('"tobi@school.test",100,100,100', $csv->body);
        $this->assertStringContainsString('"ada@school.test","sitting"', $csv->body);

        $this->as('tobi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/groups/' . $this->group . '/exams.csv')->status, 'learners cannot download class results');
    }
}
