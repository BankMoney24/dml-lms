<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Container;
use App\Core\Database\Connection;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Cbt\CbtAttempt;
use App\Modules\Exams\Cbt\CbtExam;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Exams\Cbt\CbtRepository;
use App\Modules\Exams\Live\LiveClock;
use App\Modules\Exams\Live\LiveGame;
use App\Modules\Exams\Live\LiveQuiz;
use App\Modules\Exams\Live\LiveRepository;
use App\Modules\Exams\StarterKit\ExamStarterKit;
use App\Modules\Identity\CurrentUser;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Quizzes and exams in the sample school (ADR 0043). Through the real services,
 * with the sample clock:
 *
 * * day 20: a teacher adds the tests starter kit;
 * * days 58–70: the sciences mock UTME is open and most learners sit it, a few
 *   running out of time; the teacher closes it on day 72;
 * * the Mathematics class test is published and left open, with some attempts;
 * * a few learners set themselves practice tests;
 * * two finished live quiz games, on days 80 and 86;
 * * a few exam candidates who left the exam page now and then (ADR 0045);
 * * a shared report card for the teacher's first class, with remarks (ADR 0046).
 *
 * Only learners who had joined by then take part.
 */
final class SampleSchoolTests
{
    private Connection $db;
    private CbtExams $cbt;
    private CbtRepository $exams;
    private BankRepository $banks;
    private LiveGame $game;
    private LiveRepository $live;
    /** @var array<int, string> when each learner joined, by user id */
    private array $joined = [];

    public function __construct(private readonly Container $c, private readonly SampleClock $clock, private readonly Tenant $tenant)
    {
        $this->db = $c->get(Connection::class);
        $this->cbt = $c->get(CbtExams::class);
        $this->exams = $c->get(CbtRepository::class);
        $this->banks = $c->get(BankRepository::class);
        $this->game = $c->get(LiveGame::class);
        $this->live = $c->get(LiveRepository::class);
    }

    /**
     * @param list<CurrentUser> $learners
     * @return list<string> lines for the log
     */
    public function run(CurrentUser $teacher, array $learners, DateTimeImmutable $start): array
    {
        foreach ($this->db->fetchAll('SELECT user_id, created_at FROM memberships WHERE tenant_id = ?', [$this->tenant->id]) as $r) {
            $this->joined[(int) $r['user_id']] = (string) $r['created_at'];
        }
        $this->clock->at($start->modify('+20 days')->setTime(10, 0));
        $added = $this->c->get(ExamStarterKit::class)->install($this->tenant, $teacher);
        $log = [sprintf('Tests starter kit: %d banks, %d live quizzes, %d draft exams.', $added['banks'], $added['quizzes'], $added['exams'])];

        $mock = $this->exam('Mock UTME: sciences (sample)');
        $maths = $this->exam('Mathematics class test (sample)');
        $sat = 0;
        if ($mock !== null) {
            $this->clock->at($start->modify('+57 days')->setTime(16, 0));
            $this->cbt->publish($this->tenant, $teacher, $mock);
            $mock = $this->exams->findExam($this->tenant->id, $mock->id) ?? $mock;
            foreach ($learners as $learner) {
                if (mt_rand(1, 100) > 70) {
                    continue;
                }
                $when = $start->modify('+' . mt_rand(58, 70) . ' days')->setTime(mt_rand(8, 17), mt_rand(0, 59));
                if (!$this->joinedBy($learner, $when)) {
                    continue;
                }
                $this->clock->at($when);
                $chosen = array_slice(self::shuffled($mock->optional()), 0, $mock->chooseCount);
                $sat += $this->sit($learner, $this->cbt->start($this->tenant, $learner, $mock, $chosen, ''), $mock->durationMinutes) ? 1 : 0;
            }
            $this->clock->at($start->modify('+72 days')->setTime(9, 0));
            $closed = $this->exams->findExam($this->tenant->id, $mock->id);
            if ($closed !== null) {
                $this->cbt->close($this->tenant, $teacher, $closed);
            }
        }
        if ($maths !== null) {
            $this->clock->at($start->modify('+82 days')->setTime(8, 0));
            $this->cbt->publish($this->tenant, $teacher, $maths);
            $maths = $this->exams->findExam($this->tenant->id, $maths->id) ?? $maths;
            foreach ($learners as $learner) {
                $when = $start->modify('+' . mt_rand(82, 89) . ' days')->setTime(mt_rand(8, 20), mt_rand(0, 59));
                if (mt_rand(1, 100) > 35 || !$this->joinedBy($learner, $when) || $when > $this->now()) {
                    continue;
                }
                $this->clock->at($when);
                $sat += $this->sit($learner, $this->cbt->start($this->tenant, $learner, $maths, [], ''), $maths->durationMinutes) ? 1 : 0;
            }
        }
        $log[] = sprintf('%d CBT exam attempts, marked.', $sat);

        $practice = 0;
        $practiceBanks = array_map(static fn ($b): int => $b->id, $this->banks->banks($this->tenant->id, false, true));
        foreach ($learners as $learner) {
            $when = $start->modify('+' . mt_rand(40, 88) . ' days')->setTime(mt_rand(17, 22), mt_rand(0, 59));
            if ($practiceBanks === [] || mt_rand(1, 100) > 25 || !$this->joinedBy($learner, $when)) {
                continue;
            }
            $this->clock->at($when);
            $picked = array_slice(self::shuffled($practiceBanks), 0, mt_rand(1, 2));
            $id = $this->cbt->startPractice($this->tenant, $learner, $picked, 10, mt_rand(0, 1) === 1 ? 20 : 0);
            $practice += $this->sit($learner, $id, 20) ? 1 : 0;
        }
        $log[] = sprintf('%d practice tests.', $practice);

        // A shared report card for the teacher's first class, from the mock and the maths test (ADR 0046).
        if ($mock !== null && $maths !== null) {
            $this->clock->at($start->modify('+89 days')->setTime(15, 0));
            $groupId = (int) $this->db->fetchValue(
                "SELECT m.group_id FROM learner_group_members m JOIN learner_groups g ON g.tenant_id = m.tenant_id AND g.id = m.group_id
                 WHERE m.tenant_id = ? AND m.user_id = ? AND m.role = 'teacher' AND g.archived_at IS NULL ORDER BY m.group_id LIMIT 1",
                [$this->tenant->id, $teacher->user->id],
            );
            $group = $groupId > 0 ? $this->c->get(\App\Modules\Groups\GroupRepository::class)->find($this->tenant->id, $groupId) : null;
            if ($group !== null) {
                $cards = $this->c->get(\App\Modules\ReportCards\ReportCards::class);
                $cardId = $cards->create($this->tenant, $group, $teacher, [
                    'title' => 'First term (sample)', 'grading' => 'waec', 'next_term' => 'Next term begins in three weeks.',
                    'exam' => [(string) $mock->id, (string) $maths->id], 'label' => ['Mock UTME', 'Maths test'], 'weight' => ['40', '60'],
                ]);
                $card = $this->c->get(\App\Modules\ReportCards\ReportCardRepository::class)->find($this->tenant->id, $cardId);
                $members = $this->c->get(\App\Modules\Groups\GroupRepository::class)->members($this->tenant->id, $group->id, \App\Modules\Groups\GroupRepository::LEARNER);
                if ($card !== null) {
                    $sheet = $cards->sheet($this->tenant, $card, $members);
                    foreach ($members as $m) {
                        $avg = $sheet['rows'][$m['user_id']]['average'] ?? null;
                        $remark = match (true) {
                            $avg === null => 'Missed this term\'s tests. Please see me so we can plan how to catch up.',
                            $avg >= 70 => 'An excellent term. Keep up the steady work.',
                            $avg >= 50 => 'A good term. Revise the topics you found hard and practise past-style questions.',
                            default => 'You can do better. Ask questions in class and use the practice tests every week.',
                        };
                        $cards->saveRemarks($this->tenant, $group, $card, $teacher, $m['user_id'], $remark, '');
                    }
                    $cards->publish($this->tenant, $group, $card, $teacher);
                    $log[] = 'A shared report card for ' . $group->name . '.';
                }
            }
        }

        $games = 0;
        $players = 0;
        foreach ([['Friday general knowledge (sample)', 80], ['Science quick check (sample)', 86]] as [$title, $day]) {
            $quiz = $this->quiz($title);
            $when = $start->modify('+' . $day . ' days')->setTime(14, 30);
            if ($quiz === null || $when > $this->now()) {
                continue;
            }
            $this->clock->at($when);
            $in = array_values(array_filter(self::shuffled($learners), fn (CurrentUser $l): bool => $this->joinedBy($l, $when)));
            $players += $this->play($teacher, $quiz, array_slice($in, 0, mt_rand(8, 16)));
            $games++;
        }
        $log[] = sprintf('%d live quiz games with %d players.', $games, $players);

        return $log;
    }

    /** Answers a paper with a skill level of its own and submits it; a few candidates run out of time. */
    private function sit(CurrentUser $learner, int $attemptId, int $minutes): bool
    {
        $attempt = $this->exams->findAttempt($this->tenant->id, $attemptId);
        if (!$attempt instanceof CbtAttempt || $attempt->isSubmitted()) {
            return false;
        }
        $questions = $this->banks->questionsByIds($this->tenant->id, $attempt->questionIds());
        $skill = mt_rand(30, 92) / 100;
        $changes = [];
        $flags = [];
        foreach ($attempt->perms() as $qid => $perm) {
            $q = $questions[$qid] ?? null;
            if ($q === null || mt_rand(1, 100) > 94) {
                continue;
            }
            $right = (int) array_search($q->correct, $perm, true);
            $changes[$qid] = mt_rand(1, 100) <= (int) ($skill * 100) ? $right : ($right + mt_rand(1, count($perm) - 1)) % count($perm);
            if (mt_rand(1, 100) <= 6) {
                $flags[] = $qid;
            }
        }
        $outOfTime = mt_rand(1, 100) <= 8;
        $this->clock->shift(60 * ($outOfTime ? $minutes - 1 : mt_rand((int) max(3, $minutes * 0.4), (int) max(4, $minutes * 0.95))));
        $this->cbt->saveAnswers($this->tenant, $attempt->id, $learner->user->id, 1, $changes, $flags);
        if ($attempt->examId !== null && mt_rand(1, 100) <= 15) {
            // A few candidates left the exam page now and then (ADR 0045).
            $this->cbt->recordAway($this->tenant, $attempt->id, $learner->user->id, ['count' => mt_rand(1, 3), 'seconds' => mt_rand(5, 90)]);
        }
        if ($outOfTime) {
            $this->clock->shift(150);
        }
        $this->cbt->submit($this->tenant, $attempt->id, $outOfTime ? 'time' : 'candidate');

        return true;
    }

    /**
     * Plays a whole game: everyone answers each question at their own speed, then the host reveals and moves on.
     *
     * @param list<CurrentUser> $players
     */
    private function play(CurrentUser $host, LiveQuiz $quiz, array $players): int
    {
        $sessionId = $this->game->host($this->tenant, $host, $quiz, false, false);
        $joined = [];
        foreach ($players as $p) {
            $session = $this->live->findSession($this->tenant->id, $sessionId) ?? throw new \LogicException('Game missing');
            $this->clock->shift(mt_rand(3, 20));
            $joined[] = ['id' => $this->game->join($this->tenant, $session, $p, ''), 'skill' => mt_rand(40, 95)];
        }
        $this->clock->shift(30);
        $this->game->advance($this->tenant, $host, $sessionId, 'start');
        while (true) {
            $s = $this->live->findSession($this->tenant->id, $sessionId) ?? throw new \LogicException('Game missing');
            $q = $s->current();
            if ($s->status !== 'question' || $q === null || $s->startedAt === null) {
                break;
            }
            $startMs = LiveClock::ms($s->startedAt);
            $limit = $q['seconds'] * 1000;
            $answers = [];
            foreach ($joined as $p) {
                if (mt_rand(1, 100) <= 92) {
                    $right = mt_rand(1, 100) <= $p['skill'];
                    $choice = $right ? $q['correct'] : ($q['correct'] + mt_rand(1, count($q['options']) - 1)) % count($q['options']);
                    $answers[] = [mt_rand(1200, (int) ($limit * 0.85)), $p['id'], $choice];
                }
            }
            sort($answers);
            foreach ($answers as [$ms, $playerId, $choice]) {
                $this->clock->at(self::fromMs($startMs + $ms));
                $this->game->answer($this->tenant, $sessionId, $playerId, (int) $s->position, $choice);
            }
            $this->clock->at(self::fromMs($startMs + $limit + 400));
            $this->game->advance($this->tenant, $host, $sessionId, 'reveal');
            $this->clock->shift(8);
            $this->game->advance($this->tenant, $host, $sessionId, 'next');
            $after = $this->live->findSession($this->tenant->id, $sessionId);
            if ($after === null || $after->status === 'finished') {
                break;
            }
            $this->clock->shift(6);
            $this->game->advance($this->tenant, $host, $sessionId, 'next');
        }

        return count($joined);
    }

    private function exam(string $title): ?CbtExam
    {
        foreach ($this->exams->exams($this->tenant->id) as $row) {
            if ($row['exam']->title === $title) {
                return $row['exam'];
            }
        }

        return null;
    }

    private function quiz(string $title): ?LiveQuiz
    {
        foreach ($this->live->quizzes($this->tenant->id) as $q) {
            if ($q->title === $title) {
                return $q;
            }
        }

        return null;
    }

    private function joinedBy(CurrentUser $learner, DateTimeImmutable $when): bool
    {
        return isset($this->joined[$learner->user->id]) && $this->joined[$learner->user->id] <= $when->format('Y-m-d H:i:s') && $when <= $this->now();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function fromMs(int $ms): DateTimeImmutable
    {
        $t = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($ms, 1000), ($ms % 1000) * 1000), new DateTimeZone('UTC'));

        return $t === false ? new DateTimeImmutable('@' . intdiv($ms, 1000)) : $t;
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
