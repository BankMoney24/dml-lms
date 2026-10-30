<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * Live quizzes (ADR 0042): a teacher puts questions on the class screen,
 * everyone answers on their own phone, and quick right answers score most.
 *
 * Flow: lobby (players join with the PIN) → question → reveal (the right
 * answer and how the class answered) → scores (the top five) → next question
 * … → after the last reveal, finished (the podium).
 *
 * The server keeps the time. A question closes when its time is up or when
 * every player has answered; whoever asks next (the host's screen or any
 * player) moves it on. Points for a right answer: 1000 (2000 for double
 * points), less up to half for time taken, counted from when that player's
 * device first showed the question, so a slow connection is not a slow
 * answer. Three or more right in a row add a streak bonus: 200 for three,
 * 300 for four, and so on up to 500.
 */
final class LiveGame
{
    public const MAX_PLAYERS = 200;
    public const MAX_QUESTIONS = 100;
    /** Answers this late after the time is up still count (network delay). */
    public const GRACE_MS = 500;
    private const BLOCKED = ['fuck', 'shit', 'bitch', 'cunt', 'dick', 'pussy', 'asshole', 'bastard', 'whore', 'slut', 'nigg'];

    public function __construct(
        private readonly LiveRepository $live,
        private readonly BankRepository $banks,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function createQuiz(Tenant $tenant, CurrentUser $by, string $title): int
    {
        $by->authorise(Permission::CourseManage);

        return $this->live->createQuiz($tenant->id, self::title($title), $by->user->id);
    }

    public function renameQuiz(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, string $title): void
    {
        $by->authorise(Permission::CourseManage);
        $this->live->renameQuiz($tenant->id, $quiz->id, self::title($title));
    }

    public function archive(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, bool $archived): void
    {
        $by->authorise(Permission::CourseManage);
        $this->live->setArchived($tenant->id, $quiz->id, $archived);
    }

    /** @param array<string, mixed> $input prompt, options (list), correct (index or letter), seconds, points */
    public function saveQuestion(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, array $input, ?LiveQuestion $question = null): int
    {
        $by->authorise(Permission::CourseManage);
        $q = self::questionFields($input);
        if ($question !== null) {
            $this->live->updateQuestion($tenant->id, $question->id, $q);
            $id = $question->id;
        } else {
            if ($quiz->questionCount >= self::MAX_QUESTIONS) {
                throw ValidationException::single('prompt', 'A live quiz has up to ' . self::MAX_QUESTIONS . ' questions.');
            }
            $id = $this->live->addQuestion($tenant->id, $quiz->id, $q);
        }
        $this->live->touchQuiz($tenant->id, $quiz->id);

        return $id;
    }

    public function deleteQuestion(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, LiveQuestion $question): void
    {
        $by->authorise(Permission::CourseManage);
        $this->live->deleteQuestion($tenant->id, $question->id);
        $this->live->reorder($tenant->id, $quiz->id, array_map(static fn (LiveQuestion $q): int => $q->id, $this->live->questions($tenant->id, $quiz->id)));
        $this->live->touchQuiz($tenant->id, $quiz->id);
    }

    public function move(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, LiveQuestion $question, int $step): void
    {
        $by->authorise(Permission::CourseManage);
        $ids = array_map(static fn (LiveQuestion $q): int => $q->id, $this->live->questions($tenant->id, $quiz->id));
        $at = array_search($question->id, $ids, true);
        $to = $at === false ? -1 : $at + $step;
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        $moved = $ids;
        $moved[$at] = $ids[$to];
        $moved[$to] = $ids[$at];
        $this->live->reorder($tenant->id, $quiz->id, array_values($moved));
    }

    /**
     * Copies questions from a bank at random: those with up to four options that fit on a screen.
     *
     * @return int how many were added
     */
    public function addFromBank(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, int $bankId, int $count, int $seconds): int
    {
        $by->authorise(Permission::CourseManage);
        $bank = $this->banks->findBank($tenant->id, $bankId);
        if ($bank === null || $bank->archivedAt !== null) {
            throw ValidationException::single('bank', 'Choose a question bank.');
        }
        if ($count < 1 || $count > 50 || !in_array($seconds, LiveQuestion::SECONDS, true)) {
            throw ValidationException::single('count', 'Choose how many questions (1 to 50) and the time for each.');
        }
        $room = self::MAX_QUESTIONS - $quiz->questionCount;
        $fits = array_values(array_filter(
            $this->banks->allQuestions($tenant->id, $bank->id),
            static fn ($q): bool => count($q->options) >= 2 && count($q->options) <= 4 && mb_strlen($q->prompt) <= 500
                && max(0, ...array_map('mb_strlen', $q->options)) <= 120,
        ));
        shuffle($fits);
        $added = 0;
        foreach (array_slice($fits, 0, min($count, $room)) as $q) {
            $this->live->addQuestion($tenant->id, $quiz->id, ['prompt' => $q->prompt, 'options' => $q->options, 'correct' => $q->correct, 'seconds' => $seconds, 'points' => 'standard']);
            $added++;
        }
        if ($added === 0) {
            throw ValidationException::single('bank', 'That bank has no questions that fit a live quiz (up to four short options).');
        }
        $this->live->touchQuiz($tenant->id, $quiz->id);

        return $added;
    }

    /** Opens a game for the class to join. */
    public function host(Tenant $tenant, CurrentUser $by, LiveQuiz $quiz, bool $guests, bool $funNames): int
    {
        $by->authorise(Permission::CourseManage);
        $questions = $this->live->questions($tenant->id, $quiz->id);
        if ($questions === []) {
            throw ValidationException::single('host', 'Add a question first.');
        }
        do {
            $pin = (string) random_int(100000, 999999);
        } while ($this->live->pinInUse($tenant->id, $pin));
        $id = $this->live->createSession($tenant->id, $quiz->id, $by->user->id, $pin, $quiz->title, array_map(static fn (LiveQuestion $q): array => $q->snapshot(), $questions), $guests, $funNames);
        $this->audit->record($tenant->id, $by->user->id, 'live.hosted', 'live_session', $id, ['quiz' => $quiz->id]);

        return $id;
    }

    /**
     * Joins a game. Members join under their own name (or a made-up one when the
     * host chose fun names); guests choose a nickname, if the host allows guests.
     *
     * @return int the player id
     */
    public function join(Tenant $tenant, LiveSession $session, ?CurrentUser $who, string $nickname): int
    {
        if ($session->status === 'finished') {
            throw ValidationException::single('pin', 'That game has ended.');
        }
        if ($who !== null) {
            $existing = $this->live->playerForUser($tenant->id, $session->id, $who->user->id);
            if ($existing !== null) {
                if ($existing->removedAt !== null) {
                    throw ValidationException::single('pin', 'The host removed you from this game.');
                }

                return $existing->id;
            }
        } elseif (!$session->allowGuests) {
            throw ValidationException::single('pin', 'This game is for members of the school. Sign in to join.');
        }
        if ($this->live->activeCount($tenant->id, $session->id) >= self::MAX_PLAYERS) {
            throw ValidationException::single('pin', 'This game is full (' . self::MAX_PLAYERS . ' players).');
        }
        $taken = fn (string $n): bool => $this->live->nicknameTaken($session->id, $n);
        if ($session->funNames) {
            $name = FunNames::pick($taken);
        } elseif ($who !== null) {
            $name = mb_substr($who->user->displayName, 0, 24);
            for ($i = 2; $taken($name) && $i < 50; $i++) {
                $name = mb_substr($who->user->displayName, 0, 20) . ' ' . $i;
            }
        } else {
            $name = self::nickname($nickname);
            if ($taken($name)) {
                throw ValidationException::single('nickname', 'Someone in this game already has that name. Try another.');
            }
        }

        return $this->live->addPlayer($tenant->id, $session->id, $who?->user->id, $name);
    }

    /**
     * Moves the game on, for the host's buttons.
     *
     * @param string $action start | reveal | next | end
     */
    public function advance(Tenant $tenant, CurrentUser $host, int $sessionId, string $action): void
    {
        $this->db->transaction(function () use ($tenant, $host, $sessionId, $action): void {
            $s = $this->live->findSession($tenant->id, $sessionId, true);
            if ($s === null || $s->hostId !== $host->user->id) {
                return;
            }
            match (true) {
                $action === 'end' => $this->live->setState($tenant->id, $s->id, 'finished', $s->position, null, null),
                $action === 'start' && $s->status === 'lobby' => $this->open($s, 0),
                $action === 'reveal' && $s->status === 'question' => $this->reveal($s),
                $action === 'next' && $s->status === 'reveal' && $s->isLast() => $this->live->setState($tenant->id, $s->id, 'finished', $s->position, null, null),
                $action === 'next' && $s->status === 'reveal' => $this->live->setState($tenant->id, $s->id, 'scores', $s->position, null, null),
                $action === 'next' && $s->status === 'scores' => $this->open($s, (int) $s->position + 1),
                default => null,
            };
        });
    }

    /** Closes the current question when its time is up. Cheap when nothing is due. */
    public function tick(Tenant $tenant, LiveSession $s): LiveSession
    {
        if ($s->status !== 'question' || $s->msLeft(LiveClock::nowMs($this->clock->now())) > 0) {
            return $s;
        }
        $this->db->transaction(function () use ($tenant, $s): void {
            $locked = $this->live->findSession($tenant->id, $s->id, true);
            if ($locked !== null && $locked->status === 'question' && $locked->position === $s->position) {
                $this->reveal($locked);
            }
        });

        return $this->live->findSession($tenant->id, $s->id) ?? $s;
    }

    /**
     * Records a player's answer to the current question.
     *
     * @return array{ok: bool, error?: string}
     */
    public function answer(Tenant $tenant, int $sessionId, int $playerId, int $position, int $choice): array
    {
        return $this->db->transaction(function () use ($tenant, $sessionId, $playerId, $position, $choice): array {
            $s = $this->live->findSession($tenant->id, $sessionId, true);
            $player = $s === null ? null : $this->live->findPlayer($tenant->id, $s->id, $playerId);
            $q = $s?->current();
            if ($s === null || $player === null || $player->removedAt !== null || $q === null) {
                return ['ok' => false, 'error' => 'gone'];
            }
            $now = $this->clock->now();
            $nowMs = LiveClock::nowMs($now);
            if ($s->status !== 'question' || $s->position !== $position || $s->endsAt === null || $nowMs > LiveClock::ms($s->endsAt) + self::GRACE_MS) {
                return ['ok' => false, 'error' => 'closed'];
            }
            if ($choice < 0 || $choice >= count($q['options'])) {
                return ['ok' => false, 'error' => 'choice'];
            }
            $from = LiveClock::ms((string) $s->startedAt);
            if ($player->seenPosition === $position && $player->seenAt !== null) {
                $from = max($from, LiveClock::ms($player->seenAt));
            }
            $limit = max(1000, $q['seconds'] * 1000);
            $ms = max(0, min($limit, $nowMs - $from));
            $correct = $choice === $q['correct'];
            $points = $correct ? self::points($q['points'], $ms, $limit, $player->streak + 1) : 0;
            if (!$this->live->addAnswer($tenant->id, $s->id, $player->id, $position, $choice, $correct, $points, $ms, LiveClock::sql($now))) {
                return ['ok' => false, 'error' => 'already'];
            }
            $this->live->scorePlayer($tenant->id, $player->id, $points, $correct);
            if ($this->live->answeredCount($tenant->id, $s->id, $position) >= $this->live->activeCount($tenant->id, $s->id)) {
                $this->reveal($s);
            }

            return ['ok' => true];
        });
    }

    /** Points for a right answer after $ms of $limit ms, with $streak right answers in a row (this one included). */
    public static function points(string $kind, int $ms, int $limit, int $streak): int
    {
        $base = match ($kind) {
            'double' => 2000,
            'none' => 0,
            default => 1000,
        };
        if ($base === 0) {
            return 0;
        }
        $speed = (int) round($base * (1 - (min($ms, $limit) / max(1, $limit)) / 2));
        $bonus = $streak >= 3 ? min(500, 100 * ($streak - 1)) : 0;

        return $speed + $bonus;
    }

    public function removePlayer(Tenant $tenant, CurrentUser $host, LiveSession $session, int $playerId): void
    {
        if ($session->hostId !== $host->user->id) {
            return;
        }
        $this->live->removePlayer($tenant->id, $session->id, $playerId);
    }

    /**
     * What the host's screen shows now.
     *
     * @return array<string, mixed>
     */
    public function hostState(Tenant $tenant, LiveSession $s): array
    {
        $s = $this->tick($tenant, $s);
        $nowMs = LiveClock::nowMs($this->clock->now());
        $players = $this->live->players($tenant->id, $s->id);
        $q = $s->current();
        $out = [
            'status' => $s->status, 'version' => $s->version, 'pin' => $s->pin, 'title' => $s->title,
            'position' => $s->position, 'total' => count($s->questions), 'players' => count($players),
        ];
        if ($s->status === 'lobby') {
            $out['names'] = array_map(static fn (LivePlayer $p): array => ['id' => $p->id, 'name' => $p->nickname], array_reverse($players));
        }
        if ($q !== null && in_array($s->status, ['question', 'reveal'], true)) {
            $out['question'] = ['prompt' => $q['prompt'], 'options' => $q['options'], 'seconds' => $q['seconds'], 'points' => $q['points']];
            $out['ms_left'] = $s->status === 'question' ? $s->msLeft($nowMs) : 0;
            $out['answered'] = $this->live->answeredCount($tenant->id, $s->id, (int) $s->position);
        }
        if ($q !== null && $s->status === 'reveal') {
            $dist = $this->live->distribution($tenant->id, $s->id, (int) $s->position);
            $out['correct'] = $q['correct'];
            $out['counts'] = array_map(static fn (int $i): int => $dist[$i] ?? 0, array_keys($q['options']));
            $out['last'] = $s->isLast();
        }
        if (in_array($s->status, ['scores', 'finished'], true)) {
            $top = array_slice($players, 0, $s->status === 'finished' ? 10 : 5);
            $out['leaders'] = array_map(static fn (LivePlayer $p): array => ['name' => $p->nickname, 'score' => $p->score, 'streak' => $p->streak, 'correct' => $p->correctCount], $top);
        }

        return $out;
    }

    /**
     * What one player's device shows now. With $version equal to the game's, only
     * "same" (and whether the player is still in) comes back.
     *
     * @return array<string, mixed>
     */
    public function playerState(Tenant $tenant, LiveSession $s, LivePlayer $player, int $version): array
    {
        $s = $this->tick($tenant, $s);
        if ($player->removedAt !== null) {
            return ['status' => 'removed', 'version' => $s->version];
        }
        $now = $this->clock->now();
        $q = $s->current();
        if ($s->status === 'question' && $player->seenPosition !== $s->position) {
            $this->live->seen($tenant->id, $player->id, $s->position, LiveClock::sql($now));
        } else {
            $this->live->seen($tenant->id, $player->id, null, null);
        }
        if ($version === $s->version) {
            return ['same' => true, 'version' => $s->version, 'ms_left' => $s->status === 'question' ? $s->msLeft(LiveClock::nowMs($now)) : 0];
        }
        $out = ['status' => $s->status, 'version' => $s->version, 'title' => $s->title, 'name' => $player->nickname, 'position' => $s->position, 'total' => count($s->questions)];
        if ($q !== null && $s->status === 'question') {
            $mine = $this->live->answerOf($player->id, (int) $s->position);
            $out['question'] = ['prompt' => $q['prompt'], 'options' => $q['options'], 'seconds' => $q['seconds']];
            $out['ms_left'] = $s->msLeft(LiveClock::nowMs($now));
            $out['answered'] = $mine !== null;
            $out['choice'] = $mine['choice'] ?? null;
        }
        if ($q !== null && $s->status === 'reveal') {
            $fresh = $this->live->findPlayer($tenant->id, $s->id, $player->id) ?? $player;
            $mine = $this->live->answerOf($player->id, (int) $s->position);
            $out['question'] = ['prompt' => $q['prompt'], 'options' => $q['options']];
            $out['correct'] = $q['correct'];
            $out['choice'] = $mine['choice'] ?? null;
            $out['right'] = $mine['correct'] ?? false;
            $out['points'] = $mine['points'] ?? 0;
            $out['score'] = $fresh->score;
            $out['streak'] = $fresh->streak;
        }
        if (in_array($s->status, ['scores', 'finished'], true)) {
            $players = $this->live->players($tenant->id, $s->id);
            foreach ($players as $i => $p) {
                if ($p->id === $player->id) {
                    $out['rank'] = 1 + count(array_filter($players, static fn (LivePlayer $o): bool => $o->score > $p->score));
                    $out['score'] = $p->score;
                    $out['correct_count'] = $p->correctCount;
                    $ahead = $i > 0 ? $players[$i - 1] : null;
                    $out['behind'] = $ahead !== null && $ahead->score > $p->score ? ['name' => $ahead->nickname, 'by' => $ahead->score - $p->score] : null;
                }
            }
            $out['players'] = count($players);
        }

        return $out;
    }

    /**
     * Everything about a finished (or abandoned) game, for the results page and CSV.
     *
     * @return array{players: list<array{player: LivePlayer, rank: int, answers: array<int, array{choice: int, correct: bool, points: int, ms: int}>}>,
     *               questions: list<array{prompt: string, options: list<string>, correct: int, counts: list<int>, answered: int, right: int, avg_ms: int}>}
     */
    public function results(Tenant $tenant, LiveSession $s): array
    {
        $players = $this->live->players($tenant->id, $s->id);
        $answers = $this->live->answers($tenant->id, $s->id);
        $byPlayer = [];
        $stats = [];
        foreach ($answers as $a) {
            $byPlayer[$a['player_id']][$a['position']] = ['choice' => $a['choice'], 'correct' => $a['correct'], 'points' => $a['points'], 'ms' => $a['ms']];
        }
        $active = array_flip(array_map(static fn (LivePlayer $p): int => $p->id, $players));
        foreach ($s->questions as $i => $q) {
            $counts = array_fill(0, max(1, count($q['options'])), 0);
            $ms = [];
            $right = 0;
            foreach ($answers as $a) {
                if ($a['position'] !== $i || !isset($active[$a['player_id']])) {
                    continue;
                }
                $counts[$a['choice']] = ($counts[$a['choice']] ?? 0) + 1;
                $ms[] = $a['ms'];
                $right += $a['correct'] ? 1 : 0;
            }
            $stats[] = ['prompt' => $q['prompt'], 'options' => $q['options'], 'correct' => $q['correct'], 'counts' => array_values($counts), 'answered' => count($ms), 'right' => $right,
                'avg_ms' => $ms === [] ? 0 : (int) round(array_sum($ms) / count($ms))];
        }
        $rows = [];
        foreach ($players as $p) {
            $rows[] = ['player' => $p, 'rank' => 1 + count(array_filter($players, static fn (LivePlayer $o): bool => $o->score > $p->score)), 'answers' => $byPlayer[$p->id] ?? []];
        }

        return ['players' => $rows, 'questions' => $stats];
    }

    /** Ends games left open for more than a day (cron). */
    public function endStale(): int
    {
        $n = 0;
        foreach ($this->live->stale($this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s')) as $row) {
            $this->live->setState($row['tenant_id'], $row['id'], 'finished', null, null, null);
            $n++;
        }

        return $n;
    }

    private function open(LiveSession $s, int $position): void
    {
        $q = $s->questions[$position] ?? null;
        if ($q === null) {
            $this->live->setState($s->tenantId, $s->id, 'finished', $s->position, null, null);

            return;
        }
        $now = $this->clock->now();
        $ends = $now->modify('+' . ($q['seconds'] * 1000) . ' milliseconds');
        $this->live->setState($s->tenantId, $s->id, 'question', $position, LiveClock::sql($now), LiveClock::sql($ends));
    }

    private function reveal(LiveSession $s): void
    {
        $this->live->breakStreaksOfSilent($s->tenantId, $s->id, (int) $s->position);
        $this->live->setState($s->tenantId, $s->id, 'reveal', $s->position, $s->startedAt, $s->endsAt);
    }

    private static function title(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            throw ValidationException::single('title', 'Give the quiz a title of 2 to 120 characters, for example "Cells: quick check".');
        }

        return $title;
    }

    private static function nickname(string $nickname): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $nickname));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 20 || preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} ._'-]*$/u", $name) !== 1) {
            throw ValidationException::single('nickname', 'Choose a name of 2 to 20 letters or numbers.');
        }
        $flat = (string) preg_replace('/[^a-z]/', '', strtolower($name));
        foreach (self::BLOCKED as $word) {
            if (str_contains($flat, $word)) {
                throw ValidationException::single('nickname', 'Choose a name you would be happy to see on the class screen.');
            }
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{prompt: string, options: list<string>, correct: int, seconds: int, points: string}
     */
    private static function questionFields(array $input): array
    {
        $prompt = trim((string) preg_replace('/[ \t]+/u', ' ', (string) ($input['prompt'] ?? '')));
        $raw = is_array($input['options'] ?? null) ? array_values($input['options']) : [];
        $options = [];
        $gap = false;
        $blank = false;
        foreach (array_slice($raw, 0, 4) as $o) {
            $o = trim((string) preg_replace('/\s+/u', ' ', (string) $o));
            if ($o === '') {
                $blank = true;
                continue;
            }
            $gap = $gap || $blank;
            $options[] = $o;
        }
        $c = strtoupper(trim((string) ($input['correct'] ?? '')));
        $correct = preg_match('/^[A-D]$/', $c) === 1 ? ord($c) - 65 : (ctype_digit($c) ? (int) $c : -1);
        $seconds = (int) ($input['seconds'] ?? 20);
        $points = (string) ($input['points'] ?? 'standard');
        $errors = [];
        if ($prompt === '' || mb_strlen($prompt) > 500) {
            $errors['prompt'] = 'Write the question (up to 500 characters, so it fits the class screen).';
        }
        $sent = count(array_filter($raw, static fn ($o): bool => trim((string) $o) !== ''));
        if (count($options) < 2 || $sent > 4) {
            $errors['options'] = 'Give two to four answers. For true or false, write True and False.';
        } elseif ($gap) {
            $errors['options'] = 'Fill the answers in order from A, without gaps.';
        } elseif (max(array_map('mb_strlen', $options)) > 120) {
            $errors['options'] = 'Keep each answer under 120 characters, so it fits a phone.';
        }
        if (!isset($errors['options']) && ($correct < 0 || $correct >= count($options))) {
            $errors['correct'] = 'Choose the right answer.';
        }
        if (!in_array($seconds, LiveQuestion::SECONDS, true)) {
            $errors['seconds'] = 'Choose a time limit.';
        }
        if (!isset(LiveQuestion::POINTS[$points])) {
            $errors['points'] = 'Choose how many points it is worth.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['prompt' => $prompt, 'options' => $options, 'correct' => $correct, 'seconds' => $seconds, 'points' => $points];
    }
}
