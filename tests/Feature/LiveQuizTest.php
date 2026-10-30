<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Session\ArraySession;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0042: live quizzes — hosting, joining by PIN, timed answers, points, the leaderboard and results. */
final class LiveQuizTest extends AppTestCase
{
    private const HOST = 'school.test';

    private SampleClock $clock;
    private Tenant $tenant;
    private ArraySession $teacher;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->timeIs('2026-11-02 10:00:00.000');
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $this->as('teacher@school.test');
        $this->teacher = $this->session;
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

    /**
     * Writes a two-question quiz and opens a game.
     *
     * @return array{int, int, string} quiz, game and PIN
     */
    private function game(bool $guests = true): array
    {
        $this->useBrowser($this->teacher);
        $made = $this->post(self::HOST, '/teach/live', ['title' => 'Friday science quiz']);
        $this->assertSame(1, preg_match('#^/teach/live/(\d+)$#', (string) $made->header('Location'), $m), $made->body);
        $quiz = (int) ($m[1] ?? 0);
        $this->assertSame(422, $this->post(self::HOST, '/teach/live/' . $quiz . '/host')->status, 'nothing to play yet');
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM live_sessions'));
        $bad = $this->post(self::HOST, '/teach/live/' . $quiz . '/questions', ['prompt' => 'Too many', 'options' => ['a', 'b', 'c', 'd', 'e'], 'correct' => 'A', 'seconds' => '20',
            'points' => 'standard']);
        $this->assertSame(422, $bad->status, 'a live question has up to four answers, one per tile');
        $this->post(self::HOST, '/teach/live/' . $quiz . '/questions', ['prompt' => 'Water boils at sea level at', 'options' => ['50 °C', '100 °C', '150 °C'], 'correct' => 'B',
            'seconds' => '20', 'points' => 'standard']);
        $this->post(self::HOST, '/teach/live/' . $quiz . '/questions', ['prompt' => 'The closest star to Earth is', 'options' => ['the Sun', 'Sirius'], 'correct' => 'A',
            'seconds' => '10', 'points' => 'double']);
        $hosted = $this->post(self::HOST, '/teach/live/' . $quiz . '/host', $guests ? ['guests' => '1'] : []);
        $this->assertSame(1, preg_match('#^/teach/live/games/(\d+)$#', (string) $hosted->header('Location'), $g), $hosted->body);
        $game = (int) ($g[1] ?? 0);
        $pin = (string) $this->db()->fetchValue('SELECT pin FROM live_sessions WHERE id = ?', [$game]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        return [$quiz, $game, $pin];
    }

    /** @return array<string, mixed> */
    private function host(int $game, string $action = ''): array
    {
        $this->useBrowser($this->teacher);
        if ($action !== '') {
            $this->post(self::HOST, '/teach/live/games/' . $game . '/advance', ['action' => $action]);
        }

        return json_decode($this->get(self::HOST, '/teach/live/games/' . $game . '/state')->body, true);
    }

    /** @return array<string, mixed> */
    private function player(ArraySession $who, int $version = 0): array
    {
        $this->useBrowser($who);

        return json_decode($this->get(self::HOST, '/play/state?v=' . $version)->body, true);
    }

    /** @return array<string, mixed> */
    private function answer(ArraySession $who, int $position, int $choice): array
    {
        $this->useBrowser($who);

        return json_decode($this->post(self::HOST, '/play/answer', ['position' => (string) $position, 'choice' => (string) $choice])->body, true);
    }

    private function guest(string $pin, string $name): ArraySession
    {
        $this->newBrowser();
        $this->assertSame(200, $this->post(self::HOST, '/play', ['pin' => $pin])->status, 'asks for a name');
        $this->assertRedirectTo('/play/game', $this->post(self::HOST, '/play', ['pin' => $pin, 'nickname' => $name, 'step' => 'name']));

        return $this->session;
    }

    public function testAGameFromLobbyToPodium(): void
    {
        [$quiz, $game, $pin] = $this->game();

        $this->newBrowser();
        $this->assertStringContainsString('No game is open with that PIN', $this->post(self::HOST, '/play', ['pin' => '000000', 'nickname' => 'Bola'])->body);
        $this->as('amaka@school.test');
        $this->assertRedirectTo('/play/game', $this->post(self::HOST, '/play', ['pin' => substr($pin, 0, 3) . ' ' . substr($pin, 3)]));
        $amaka = $this->session;
        $tobi = $this->guest($pin, 'Tobi');
        $this->assertStringContainsString('already has that name', $this->post(self::HOST, '/play', ['pin' => $pin, 'nickname' => ' tobi ', 'step' => 'name'])->body);
        $zara = $this->guest($pin, 'Zara');

        $lobby = $this->host($game);
        $this->assertSame('lobby', $lobby['status']);
        $this->assertSame(['Zara', 'Tobi', 'Test Learner'], array_column($lobby['names'], 'name'));
        $this->assertSame('lobby', $this->player($amaka)['status']);

        $q1 = $this->host($game, 'start');
        $this->assertSame('question', $q1['status']);
        $this->assertSame(20000, $q1['ms_left']);
        $this->assertArrayNotHasKey('correct', $q1, 'the answer stays hidden until the reveal');
        $seen = $this->player($amaka);
        $this->assertSame(['50 °C', '100 °C', '150 °C'], $seen['question']['options']);
        $this->assertArrayNotHasKey('correct', $seen);
        $this->timeIs('2026-11-02 10:00:01.000');
        $this->player($tobi);
        $this->player($zara);

        $this->timeIs('2026-11-02 10:00:04.000');
        $this->assertSame(['ok' => false, 'error' => 'choice'], $this->answer($amaka, 0, 3));
        $this->assertSame(['ok' => false, 'error' => 'closed'], $this->answer($amaka, 1, 1), 'only the question on screen');
        $this->assertSame(['ok' => true], $this->answer($amaka, 0, 1));
        $this->assertSame(['ok' => false, 'error' => 'already'], $this->answer($amaka, 0, 0), 'one answer each');
        $this->timeIs('2026-11-02 10:00:11.000');
        $this->assertSame(['ok' => true], $this->answer($tobi, 0, 1));
        $this->assertSame(2, $this->host($game)['answered']);

        $this->timeIs('2026-11-02 10:00:21.000');
        $this->assertSame(['ok' => false, 'error' => 'closed'], $this->answer($zara, 0, 1), 'too late');
        $reveal = $this->host($game);
        $this->assertSame('reveal', $reveal['status'], 'time up: the answer shows by itself');
        $this->assertSame(1, $reveal['correct']);
        $this->assertSame([0, 2, 0], $reveal['counts']);
        $mine = $this->player($amaka);
        $this->assertTrue($mine['right']);
        $this->assertSame(900, $mine['points'], 'four seconds of twenty: a tenth less than full points, counted from when the question reached this phone');
        $this->assertSame(750, $this->player($tobi)['points'], 'ten seconds after seeing it');
        $this->assertFalse($this->player($zara)['right']);

        $scores = $this->host($game, 'next');
        $this->assertSame('scores', $scores['status']);
        $this->assertSame(['Test Learner', 'Tobi', 'Zara'], array_column($scores['leaders'], 'name'));
        $tobiNow = $this->player($tobi);
        $this->assertSame(2, $tobiNow['rank']);
        $this->assertSame(['name' => 'Test Learner', 'by' => 150], $tobiNow['behind']);
        $same = $this->player($tobi, (int) $tobiNow['version']);
        $this->assertTrue($same['same'], 'polling with the current version sends almost nothing');

        $this->useBrowser($this->teacher);
        $this->post(self::HOST, '/teach/live/games/' . $game . '/remove', ['player' => (string) $this->db()->fetchValue("SELECT id FROM live_players WHERE nickname = 'Zara'")]);
        $this->assertSame('removed', $this->player($zara)['status']);

        $this->timeIs('2026-11-02 10:01:00.000');
        $this->host($game, 'next');
        $this->player($amaka);
        $this->player($tobi);
        $this->timeIs('2026-11-02 10:01:02.000');
        $this->assertSame(['ok' => false, 'error' => 'gone'], $this->answer($zara, 1, 0));
        $this->answer($tobi, 1, 0);
        $this->answer($amaka, 1, 1);
        $last = $this->host($game);
        $this->assertSame('reveal', $last['status'], 'everyone still playing has answered');
        $this->assertTrue($last['last']);
        $this->assertSame(1800, $this->player($tobi)['points'], 'double points, two seconds of ten');

        $end = $this->host($game, 'next');
        $this->assertSame('finished', $end['status']);
        $this->assertSame([['Tobi', 2550], ['Test Learner', 900]], array_map(static fn (array $l): array => [$l['name'], $l['score']], $end['leaders']));
        $this->assertSame(1, $this->player($tobi)['rank']);

        $this->useBrowser($this->teacher);
        $results = $this->get(self::HOST, '/teach/live/games/' . $game . '/results');
        $this->assertSame(200, $results->status);
        $this->assertStringContainsString('Water boils at sea level at', $results->body);
        $csv = $this->get(self::HOST, '/teach/live/games/' . $game . '/results.csv');
        $this->assertStringContainsString('Tobi', $csv->body);
        $this->assertStringContainsString('2550', $csv->body);

        $this->newBrowser();
        $this->assertStringContainsString('No game is open with that PIN', $this->post(self::HOST, '/play', ['pin' => $pin, 'nickname' => 'Late'])->body, 'a finished game frees its PIN');
        $this->assertNotSame(200, $this->get(self::HOST, '/teach/live/' . $quiz)->status, 'someone not signed in cannot open the editor');
    }

    public function testMembersOnlyGamesAndWhoMayHost(): void
    {
        [, $game, $pin] = $this->game(false);
        $this->newBrowser();
        $this->assertStringContainsString('This game is for members of the school', $this->post(self::HOST, '/play', ['pin' => $pin, 'nickname' => 'Guest'])->body);
        $this->as('amaka@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/teach/live')->status);
        $this->assertSame(403, $this->get(self::HOST, '/teach/live/games/' . $game . '/state')->status);
        $this->assertRedirectTo('/play/game', $this->post(self::HOST, '/play', ['pin' => $pin]));
        $this->assertRedirectTo('/play/game', $this->post(self::HOST, '/play', ['pin' => $pin]), 'joining twice keeps one player');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM live_players WHERE session_id = ?', [$game]));

        $this->useBrowser($this->teacher);
        $this->post(self::HOST, '/teach/live/games/' . $game . '/advance', ['action' => 'end']);
        $this->assertSame('finished', $this->db()->fetchValue('SELECT status FROM live_sessions WHERE id = ?', [$game]));
    }
}
