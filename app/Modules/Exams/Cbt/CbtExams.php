<?php

declare(strict_types=1);

namespace App\Modules\Exams\Cbt;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Core\View\Markdown;
use App\Modules\Audit\AuditLog;
use App\Modules\Exams\Bank\BankQuestion;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Computer-based tests (ADR 0042): setting an exam, sitting it, saving as the
 * candidate goes, submitting (by the candidate, or by the clock), marking, and
 * practice tests learners set themselves.
 *
 * The server keeps the time. A candidate's deadline is the start plus the
 * duration, or the exam's closing time if that comes first; answers arriving
 * more than CbtAttempt::GRACE_SECONDS after it are refused, and the attempt is
 * marked with what was saved. Scores: each section out of 100 (as UTME results
 * are reported, so four subjects make 400), and the share of all questions right.
 */
final class CbtExams
{
    public const MAX_SECTIONS = 8;
    public const MAX_PER_SECTION = 200;
    public const PRACTICE_COUNTS = [5, 10, 20, 40, 60];

    public function __construct(
        private readonly CbtRepository $exams,
        private readonly BankRepository $banks,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly PaperBuilder $papers = new PaperBuilder(),
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * Creates or updates an exam from the form.
     *
     * @param array<string, mixed> $input
     */
    public function save(Tenant $tenant, CurrentUser $by, array $input, ?CbtExam $exam = null): int
    {
        $by->authorise(Permission::CourseManage);
        $f = $this->examFields($tenant, $input, $exam);
        if ($exam === null) {
            $id = $this->exams->createExam($tenant->id, $f, $by->user->id);
            $this->audit->record($tenant->id, $by->user->id, 'cbt.created', 'cbt_exam', $id, ['title' => $f['title']]);
        } else {
            $id = $exam->id;
            $this->exams->updateExam($tenant->id, $id, $f);
        }
        $this->exams->setGroups($tenant->id, $id, $f['audience'] === 'groups' ? $f['groups'] : []);

        return $id;
    }

    /** Opens the exam to candidates once every section has enough questions. */
    public function publish(Tenant $tenant, CurrentUser $by, CbtExam $exam): void
    {
        $by->authorise(Permission::CourseManage);
        $problems = $this->problems($tenant, $exam);
        if ($problems !== []) {
            throw ValidationException::single('publish', implode(' ', $problems));
        }
        $this->exams->setStatus($tenant->id, $exam->id, 'published');
        $this->audit->record($tenant->id, $by->user->id, 'cbt.published', 'cbt_exam', $exam->id);
        if ($this->notifier !== null) {
            $when = [];
            if ($exam->opensAt !== null && $exam->opensAt > $this->clock->sqlNow()) {
                $when[] = 'Opens ' . self::local($tenant, $exam->opensAt);
            }
            if ($exam->closesAt !== null) {
                $when[] = 'closes ' . self::local($tenant, $exam->closesAt);
            }
            $body = $exam->durationMinutes . ' minutes, ' . $exam->questionsLabel() . ' questions.'
                . ($when === [] ? ' Open now.' : ' ' . ucfirst(implode(', ', $when)) . '.');
            $this->notifier->notifyMany($tenant->id, $this->exams->audience($tenant->id, $exam), Notifier::EXAM_SET, 'Exam set: ' . $exam->title, $body, '/exams/' . $exam->id);
        }
    }

    /**
     * Tells each candidate that results held until the exam closed are ready, once.
     *
     * @return int how many candidates were told
     */
    public function announceResults(Tenant $tenant, int $examId): int
    {
        $exam = $this->exams->findExam($tenant->id, $examId);
        $now = $this->clock->sqlNow();
        if ($exam === null || $exam->results !== 'after_close' || !$exam->resultsVisible($now) || !$this->exams->markAnnounced($tenant->id, $exam->id, $now)) {
            return 0;
        }
        $told = 0;
        foreach ($this->exams->latestSubmitted($tenant->id, $exam->id) as $c) {
            $this->notifier?->notify(
                $tenant->id,
                $c['user_id'],
                Notifier::EXAM_RESULTS,
                'Results ready: ' . $exam->title,
                'Your scores and, where the teacher allows it, your answers.',
                '/exams/attempts/' . $c['attempt_id'] . '/result',
            );
            $told++;
        }

        return $told;
    }

    /**
     * Announces results for every exam that has closed with results held back (cron).
     *
     * @param callable(int): ?Tenant $tenantById
     */
    public function announceDue(callable $tenantById): int
    {
        $n = 0;
        foreach ($this->exams->resultsDue($this->clock->sqlNow()) as $row) {
            $tenant = $tenantById($row['tenant_id']);
            if ($tenant instanceof Tenant) {
                $n += $this->db->transaction(fn (): int => $this->announceResults($tenant, $row['id']));
            }
        }

        return $n;
    }

    /**
     * Exams this learner can start now, or has started: for the home page.
     *
     * @return list<array{exam: CbtExam, attempt: ?CbtAttempt, minutes_left: ?int}>
     */
    public function toSit(Tenant $tenant, CurrentUser $who, int $limit = 4): array
    {
        if (!$who->can(Permission::CourseLearn)) {
            return [];
        }
        $out = [];
        foreach ($this->exams->examsFor($tenant->id, $who->user->id) as $exam) {
            if ($exam->status !== 'published') {
                continue;
            }
            $attempt = $this->openAttempt($tenant, $who->user->id, $exam);
            if ($attempt !== null || $this->cannotStart($tenant, $who, $exam) === null) {
                $out[] = ['exam' => $exam, 'attempt' => $attempt, 'minutes_left' => $attempt === null ? null : max(1, (int) ceil($attempt->secondsLeft($this->clock->now()) / 60))];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private static function local(Tenant $tenant, string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant->timezone))->format('j M, H:i');
    }

    /** Closes the exam: no new attempts; attempts in progress are submitted with what they saved. */
    public function close(Tenant $tenant, CurrentUser $by, CbtExam $exam): int
    {
        $by->authorise(Permission::CourseManage);
        $this->exams->setStatus($tenant->id, $exam->id, 'closed');
        $closed = 0;
        foreach ($this->exams->attemptsForExam($tenant->id, $exam->id) as $a) {
            if (!$a->isSubmitted() && $this->submit($tenant, $a->id, 'closed') !== null) {
                $closed++;
            }
        }
        $this->audit->record($tenant->id, $by->user->id, 'cbt.closed', 'cbt_exam', $exam->id, ['submitted' => $closed]);
        $this->announceResults($tenant, $exam->id);

        return $closed;
    }

    public function reopen(Tenant $tenant, CurrentUser $by, CbtExam $exam): void
    {
        $by->authorise(Permission::CourseManage);
        $this->exams->setStatus($tenant->id, $exam->id, 'published');
        $this->audit->record($tenant->id, $by->user->id, 'cbt.reopened', 'cbt_exam', $exam->id);
    }

    /**
     * Why an exam cannot be published yet (empty when it can).
     *
     * @return list<string>
     */
    public function problems(Tenant $tenant, CbtExam $exam): array
    {
        $out = [];
        foreach ($exam->sections as $s) {
            $bank = $this->banks->findBank($tenant->id, $s['bank']);
            $have = $bank === null ? 0 : $this->banks->drawableCount($tenant->id, $bank->id);
            if ($bank === null || $bank->archivedAt !== null) {
                $out[] = '“' . $s['label'] . '” uses a bank that is no longer available.';
            } elseif ($have < $s['count']) {
                $out[] = '“' . $s['label'] . '” asks for ' . $s['count'] . ' questions but its bank has ' . $have . '.';
            }
        }
        if ($exam->audience === 'groups' && $exam->groupIds === []) {
            $out[] = 'Choose at least one class to sit it, or set it for everyone.';
        }

        return $out;
    }

    /**
     * Why this learner cannot start the exam now, or null when they can.
     */
    public function cannotStart(Tenant $tenant, CurrentUser $who, CbtExam $exam): ?string
    {
        $now = $this->clock->sqlNow();
        if (!$who->can(Permission::CourseLearn)) {
            return 'Only learners sit exams. Use “Try it” on the exam page to see it as a candidate would.';
        }
        if ($exam->status !== 'published') {
            return $exam->status === 'closed' ? 'This exam has closed.' : 'This exam is not open yet.';
        }
        if ($exam->opensAt !== null && $exam->opensAt > $now) {
            return 'This exam opens later.';
        }
        if ($exam->closesAt !== null && $exam->closesAt <= $now) {
            return 'This exam has closed.';
        }
        if ($exam->audience === 'groups' && !$this->exams->inGroupOf($tenant->id, $exam->id, $who->user->id)) {
            return 'This exam is set for other classes.';
        }
        $attempts = $this->exams->attemptsAt($tenant->id, $exam->id, $who->user->id);
        if ($exam->maxAttempts !== null && count(array_filter($attempts, static fn (CbtAttempt $a): bool => $a->isSubmitted())) >= $exam->maxAttempts) {
            return $exam->maxAttempts === 1 ? 'You have already sat this exam.' : 'You have used all ' . $exam->maxAttempts . ' attempts.';
        }

        return null;
    }

    /** The attempt this learner has in progress for an exam, if any. */
    public function openAttempt(Tenant $tenant, int $userId, CbtExam $exam): ?CbtAttempt
    {
        foreach ($this->exams->attemptsAt($tenant->id, $exam->id, $userId) as $a) {
            if (!$a->isSubmitted()) {
                if ($a->isOverdue($this->clock->now())) {
                    $this->submit($tenant, $a->id, 'time');
                    continue;
                }

                return $a;
            }
        }

        return null;
    }

    /**
     * Starts an attempt (or returns the one in progress).
     *
     * @param list<int> $chosen indexes of the optional sections the candidate picked
     * @param bool $trial a teacher trying the exam: no attempt limits, no audience check
     */
    public function start(Tenant $tenant, CurrentUser $who, CbtExam $exam, array $chosen, string $code, bool $trial = false): int
    {
        if (!$trial) {
            $open = $this->openAttempt($tenant, $who->user->id, $exam);
            if ($open !== null) {
                return $open->id;
            }
            $why = $this->cannotStart($tenant, $who, $exam);
            if ($why !== null) {
                throw ValidationException::single('start', $why);
            }
        } else {
            $who->authorise(Permission::CourseManage);
        }
        if ($exam->accessCode !== null && !$trial && !hash_equals(strtoupper($exam->accessCode), strtoupper(trim($code)))) {
            throw ValidationException::single('code', 'That access code is not right. Ask the invigilator for it.');
        }
        $optional = $exam->optional();
        $chosen = array_values(array_unique(array_filter($chosen, static fn (int $i): bool => in_array($i, $optional, true))));
        if (count($chosen) !== $exam->chooseCount) {
            throw ValidationException::single('subjects', 'Choose ' . $exam->chooseCount . ' ' . ($exam->chooseCount === 1 ? 'subject' : 'subjects') . ' to sit.');
        }
        $indexes = array_merge($exam->compulsory(), $chosen);
        sort($indexes);
        $sections = [];
        $count = 0;
        foreach ($indexes as $i) {
            $s = $exam->sections[$i];
            $questions = $this->papers->section($this->banks->drawable($tenant->id, $s['bank']), $s['count'], $exam->shuffleQuestions, $exam->shuffleOptions);
            if ($questions === []) {
                throw ValidationException::single('start', '“' . $s['label'] . '” has no questions yet. Tell your teacher.');
            }
            $sections[] = ['label' => $s['label'], 'bank' => $s['bank'], 'questions' => $questions];
            $count += count($questions);
        }
        $now = $this->clock->now();
        $deadline = $now->modify('+' . $exam->durationMinutes . ' minutes')->format('Y-m-d H:i:s');
        if ($exam->closesAt !== null && $exam->closesAt < $deadline && !$trial) {
            $deadline = $exam->closesAt;
        }
        $id = $this->exams->createAttempt($tenant->id, $trial ? null : $exam->id, $who->user->id, ($trial ? 'Trying: ' : '') . $exam->title, ['sections' => $sections], $count, $deadline);
        if (!$trial) {
            $this->audit->record($tenant->id, $who->user->id, 'cbt.started', 'cbt_attempt', $id, ['exam' => $exam->id]);
        }

        return $id;
    }

    /**
     * Starts a practice test from banks marked for practice.
     *
     * @param list<int> $bankIds
     */
    public function startPractice(Tenant $tenant, CurrentUser $who, array $bankIds, int $perBank, int $minutes): int
    {
        if (!$who->can(Permission::CourseLearn) && !$who->can(Permission::CourseManage)) {
            throw ValidationException::single('banks', 'Sign in as a learner to practise.');
        }
        foreach ($this->exams->practiceFor($tenant->id, $who->user->id, 5) as $a) {
            if (!$a->isSubmitted() && !$a->isOverdue($this->clock->now())) {
                return $a->id;
            }
        }
        $practice = [];
        foreach ($this->banks->banks($tenant->id, false, true) as $b) {
            $practice[$b->id] = $b;
        }
        $bankIds = array_values(array_unique(array_filter($bankIds, static fn (int $id): bool => isset($practice[$id]))));
        $errors = [];
        if ($bankIds === []) {
            $errors['banks'] = 'Choose at least one subject.';
        } elseif (count($bankIds) > 4) {
            $errors['banks'] = 'Choose up to four subjects at a time.';
        }
        if (!in_array($perBank, self::PRACTICE_COUNTS, true)) {
            $errors['count'] = 'Choose how many questions in each subject.';
        }
        if ($minutes !== 0 && ($minutes < 5 || $minutes > 180)) {
            $errors['minutes'] = 'Choose a time from 5 minutes to 3 hours, or no time limit.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $sections = [];
        $count = 0;
        foreach ($bankIds as $bankId) {
            $questions = $this->papers->section($this->banks->drawable($tenant->id, $bankId), $perBank, true, true);
            if ($questions === []) {
                continue;
            }
            $sections[] = ['label' => $practice[$bankId]->name, 'bank' => $bankId, 'questions' => $questions];
            $count += count($questions);
        }
        if ($sections === []) {
            throw ValidationException::single('banks', 'Those subjects have no questions yet.');
        }
        $deadline = $this->clock->now()->modify('+' . ($minutes === 0 ? 24 * 60 : $minutes) . ' minutes')->format('Y-m-d H:i:s');
        $title = 'Practice: ' . implode(', ', array_column($sections, 'label'));

        return $this->exams->createAttempt($tenant->id, null, $who->user->id, mb_substr($title, 0, 160), ['sections' => $sections], $count, $deadline);
    }

    /**
     * What the exam room needs: the questions in this candidate's order (without
     * the answers), their saved choices in the same order, flags and the time left.
     * Prompts and passages come as HTML from the lesson Markdown renderer, which
     * only outputs tags it builds itself.
     *
     * @return array<string, mixed>
     */
    public function paper(Tenant $tenant, CbtAttempt $attempt): array
    {
        $questions = $this->banks->questionsByIds($tenant->id, $attempt->questionIds());
        $passages = $this->banks->passagesByIds($tenant->id, array_values(array_filter(array_map(static fn (BankQuestion $q): ?int => $q->passageId, $questions))));
        $markdown = new Markdown();
        $sections = [];
        $number = 0;
        foreach ($attempt->paper['sections'] as $s) {
            $items = [];
            foreach ($s['questions'] as $pq) {
                $q = $questions[$pq['id']] ?? null;
                $number++;
                if ($q === null) {
                    continue;
                }
                $perm = self::perm($pq['perm'], count($q->options));
                $chosen = $attempt->answers[$q->id] ?? null;
                $items[] = [
                    'id' => $q->id,
                    'n' => $number,
                    'prompt' => $markdown->toHtml($q->prompt),
                    'image' => $q->image(),
                    'passage' => $q->passageId,
                    'options' => array_map(static fn (int $i): string => $q->options[$i] ?? '', $perm),
                    'answer' => $chosen === null ? null : array_search($chosen, $perm, true),
                    'flag' => in_array($q->id, $attempt->flags, true),
                ];
            }
            $sections[] = ['label' => $s['label'], 'questions' => $items];
        }
        $passageOut = [];
        foreach ($passages as $p) {
            $passageOut[(string) $p->id] = ['title' => $p->title, 'body' => $markdown->toHtml($p->body)];
        }

        return [
            'title' => $attempt->title,
            'sections' => $sections,
            'passages' => $passageOut === [] ? new \stdClass() : $passageOut,
            'seconds_left' => $attempt->secondsLeft($this->clock->now()),
            'untimed' => $attempt->isPractice() && $attempt->secondsLeft($this->clock->now()) > 6 * 3600,
            'seq' => $attempt->saveSeq,
            'submitted' => $attempt->isSubmitted(),
        ];
    }

    /**
     * Saves changed answers and the full list of flags.
     *
     * @param array<int|string, mixed> $changes question id => option index as the candidate sees it, or null to clear
     * @param list<int> $flags
     * @return array{ok: bool, seq: int, seconds_left: int, submitted: bool, error?: string}
     */
    public function saveAnswers(Tenant $tenant, int $attemptId, int $userId, int $seq, array $changes, array $flags): array
    {
        return $this->db->transaction(function () use ($tenant, $attemptId, $userId, $seq, $changes, $flags): array {
            $a = $this->exams->findAttempt($tenant->id, $attemptId, true);
            $now = $this->clock->now();
            if ($a === null || $a->userId !== $userId) {
                return ['ok' => false, 'seq' => 0, 'seconds_left' => 0, 'submitted' => false, 'error' => 'not_found'];
            }
            if ($a->isSubmitted() || $a->isOverdue($now)) {
                return ['ok' => false, 'seq' => $a->saveSeq, 'seconds_left' => 0, 'submitted' => true, 'error' => 'closed'];
            }
            if ($seq <= $a->saveSeq) {
                // An older save arriving late (or a repeat): what is stored is newer.
                return ['ok' => true, 'seq' => $a->saveSeq, 'seconds_left' => $a->secondsLeft($now), 'submitted' => false];
            }
            $perms = $a->perms();
            $answers = $a->answers;
            foreach (array_slice($changes, 0, 500, true) as $qid => $shown) {
                $qid = (int) $qid;
                if (!isset($perms[$qid])) {
                    continue;
                }
                if ($shown === null || $shown === '') {
                    unset($answers[$qid]);
                    continue;
                }
                $shown = is_numeric($shown) ? (int) $shown : -1;
                if (isset($perms[$qid][$shown])) {
                    $answers[$qid] = $perms[$qid][$shown];
                }
            }
            $flags = array_values(array_unique(array_filter(array_map('intval', $flags), static fn (int $q): bool => isset($perms[$q]))));
            $this->exams->saveAnswers($tenant->id, $a->id, $answers, $flags, $seq);

            return ['ok' => true, 'seq' => $seq, 'seconds_left' => $a->secondsLeft($now), 'submitted' => false];
        });
    }

    /**
     * Marks and closes an attempt. Safe to call twice: the second call changes nothing.
     *
     * @return ?CbtAttempt the submitted attempt, or null when there was nothing to submit
     */
    public function submit(Tenant $tenant, int $attemptId, string $reason): ?CbtAttempt
    {
        $done = $this->db->transaction(function () use ($tenant, $attemptId, $reason): bool {
            $a = $this->exams->findAttempt($tenant->id, $attemptId, true);
            if ($a === null || $a->isSubmitted()) {
                return false;
            }
            $now = $this->clock->now();
            if ($reason === 'candidate' && $a->isOverdue($now)) {
                $reason = 'time';
            }
            $questions = $this->banks->questionsByIds($tenant->id, $a->questionIds());
            [$result, $correct, $right] = self::mark($a, $questions);
            $percent = $a->questionCount > 0 ? (int) round($correct / $a->questionCount * 100) : 0;
            // Submitted by the clock: stamped at the deadline, not when the server noticed.
            $at = $reason === 'candidate' ? $now->format('Y-m-d H:i:s') : min($a->deadlineAt, $now->format('Y-m-d H:i:s'));
            $this->exams->submit($tenant->id, $a->id, $reason, $result, $correct, $percent, $at);
            if (!$a->isPractice()) {
                $this->banks->recordResults($tenant->id, $right);
            }

            return true;
        });

        return $done ? $this->exams->findAttempt($tenant->id, $attemptId) : null;
    }

    /**
     * Marks an attempt against the bank's current answers.
     *
     * @param array<int, BankQuestion> $questions
     * @return array{0: array{sections: list<array{label: string, correct: int, count: int, scaled: int}>, total: int, max: int}, 1: int, 2: array<int, bool>}
     */
    public static function mark(CbtAttempt $a, array $questions): array
    {
        $sections = [];
        $correct = 0;
        $right = [];
        foreach ($a->paper['sections'] as $s) {
            $ok = 0;
            foreach ($s['questions'] as $pq) {
                $q = $questions[$pq['id']] ?? null;
                $hit = $q !== null && ($a->answers[$pq['id']] ?? null) === $q->correct;
                $right[$pq['id']] = $hit;
                $ok += $hit ? 1 : 0;
            }
            $count = count($s['questions']);
            $sections[] = ['label' => $s['label'], 'correct' => $ok, 'count' => $count, 'scaled' => $count > 0 ? (int) round($ok / $count * 100) : 0];
            $correct += $ok;
        }

        return [['sections' => $sections, 'total' => array_sum(array_column($sections, 'scaled')), 'max' => 100 * count($sections)], $correct, $right];
    }

    /** Submits every attempt whose time has run out (cron). */
    public function submitOverdue(callable $tenantById): int
    {
        $before = $this->clock->now()->modify('-' . CbtAttempt::GRACE_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->exams->overdue($before) as $row) {
            $tenant = $tenantById($row['tenant_id']);
            if ($tenant instanceof Tenant && $this->submit($tenant, $row['id'], 'time') !== null) {
                $n++;
            }
        }

        return $n;
    }

    public function extend(Tenant $tenant, CurrentUser $by, CbtAttempt $attempt, int $minutes): void
    {
        $by->authorise(Permission::CourseManage);
        if (!in_array($minutes, [5, 10, 15, 30], true) || $attempt->isSubmitted()) {
            throw ValidationException::single('minutes', 'Add 5, 10, 15 or 30 minutes to an attempt in progress.');
        }
        $this->exams->extend($tenant->id, $attempt->id, $minutes);
        $this->audit->record($tenant->id, $by->user->id, 'cbt.extended', 'cbt_attempt', $attempt->id, ['minutes' => $minutes, 'candidate' => $attempt->userId]);
    }

    /** Removes an attempt so the candidate can sit again (after a power cut, say). Recorded in Activity. */
    public function reset(Tenant $tenant, CurrentUser $by, CbtAttempt $attempt): void
    {
        $by->authorise(Permission::CourseManage);
        $this->exams->deleteAttempt($tenant->id, $attempt->id);
        $this->audit->record($tenant->id, $by->user->id, 'cbt.attempt_reset', 'cbt_attempt', $attempt->id, [
            'candidate' => $attempt->userId, 'exam' => $attempt->examId, 'percent' => $attempt->percent,
        ]);
    }

    /**
     * How each question did across submitted attempts: how many saw it, how many got it
     * right, and how many chose each option (in the bank's order).
     *
     * @param list<CbtAttempt> $attempts
     * @return list<array{question: BankQuestion, seen: int, right: int, picks: list<int>, blank: int, section: string}>
     */
    public function itemAnalysis(Tenant $tenant, array $attempts): array
    {
        $stats = [];
        $order = [];
        foreach ($attempts as $a) {
            if (!$a->isSubmitted()) {
                continue;
            }
            foreach ($a->paper['sections'] as $s) {
                foreach ($s['questions'] as $pq) {
                    $id = $pq['id'];
                    if (!isset($stats[$id])) {
                        $stats[$id] = ['seen' => 0, 'right' => 0, 'picks' => [0, 0, 0, 0, 0], 'blank' => 0, 'section' => $s['label']];
                        $order[] = $id;
                    }
                    $stats[$id]['seen']++;
                    $choice = $a->answers[$id] ?? null;
                    if ($choice === null) {
                        $stats[$id]['blank']++;
                    } elseif ($choice >= 0 && $choice < 5) {
                        $stats[$id]['picks'][$choice]++;
                    }
                }
            }
        }
        $questions = $this->banks->questionsByIds($tenant->id, $order);
        $out = [];
        foreach ($order as $id) {
            $q = $questions[$id] ?? null;
            if ($q === null) {
                continue;
            }
            $st = $stats[$id];
            $st['right'] = $st['picks'][$q->correct] ?? 0;
            $out[] = ['question' => $q, 'seen' => $st['seen'], 'right' => $st['right'], 'picks' => array_slice($st['picks'], 0, count($q->options)), 'blank' => $st['blank'],
                'section' => $st['section']];
        }
        usort($out, static fn (array $x, array $y): int => ($x['right'] / max(1, $x['seen'])) <=> ($y['right'] / max(1, $y['seen'])));

        return $out;
    }

    /**
     * @param list<int> $perm
     * @return list<int> a usable option order for a question with $n options
     */
    private static function perm(array $perm, int $n): array
    {
        $sorted = $perm;
        sort($sorted);

        return $sorted === range(0, max(0, $n - 1)) && count($perm) === $n ? $perm : range(0, max(0, $n - 1));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function examFields(Tenant $tenant, array $input, ?CbtExam $exam): array
    {
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
            $errors['title'] = 'Give the exam a title of 3 to 120 characters, for example "SS 3 Mock UTME, March".';
        }
        $instructions = trim((string) ($input['instructions'] ?? ''));
        if (mb_strlen($instructions) > 3000) {
            $errors['instructions'] = 'Keep the instructions under 3,000 characters.';
        }
        $banks = [];
        foreach ($this->banks->banks($tenant->id) as $b) {
            $banks[$b->id] = $b;
        }
        $sections = [];
        $rawSections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        foreach (array_slice($rawSections, 0, self::MAX_SECTIONS + 4) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bankId = (int) ($row['bank'] ?? 0);
            if ($bankId === 0) {
                continue;
            }
            $count = (int) ($row['count'] ?? 0);
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['label'] ?? '')));
            if (!isset($banks[$bankId])) {
                $errors['sections'] = 'Choose a question bank for each section.';
                continue;
            }
            if ($count < 1 || $count > self::MAX_PER_SECTION) {
                $errors['sections'] = 'Each section has 1 to ' . self::MAX_PER_SECTION . ' questions.';
            }
            $sections[] = [
                'bank' => $bankId,
                'count' => max(1, min(self::MAX_PER_SECTION, $count)),
                'label' => mb_substr($label !== '' ? $label : $banks[$bankId]->name, 0, 80),
                'compulsory' => in_array((string) ($row['compulsory'] ?? ''), ['1', 'on'], true),
            ];
        }
        if ($sections === []) {
            $errors['sections'] = 'Add at least one section: a question bank and how many questions to draw from it.';
        } elseif (count($sections) > self::MAX_SECTIONS) {
            $errors['sections'] = 'An exam has up to ' . self::MAX_SECTIONS . ' sections.';
        }
        $choose = (int) ($input['choose_count'] ?? 0);
        $optional = count(array_filter($sections, static fn (array $s): bool => !$s['compulsory']));
        if ($choose < 0 || ($choose > 0 && $choose > $optional)) {
            $errors['choose_count'] = 'Candidates can only choose from the sections that are not compulsory (' . $optional . ' here).';
        } elseif ($choose > 0 && $choose === $optional) {
            $errors['choose_count'] = 'Candidates would have to sit every optional section; set "choose" to 0 instead, so everyone sits everything.';
        }
        $duration = (int) ($input['duration_minutes'] ?? 0);
        if ($duration < 1 || $duration > 600) {
            $errors['duration_minutes'] = 'Set a time from 1 minute to 10 hours (the UTME gives 120 minutes for 180 questions).';
        }
        [$opens, $openErr] = $this->localTime($tenant, (string) ($input['opens_date'] ?? ''), (string) ($input['opens_time'] ?? ''));
        [$closes, $closeErr] = $this->localTime($tenant, (string) ($input['closes_date'] ?? ''), (string) ($input['closes_time'] ?? ''));
        if ($openErr !== null) {
            $errors['opens_date'] = $openErr;
        }
        if ($closeErr !== null) {
            $errors['closes_date'] = $closeErr;
        }
        if ($opens !== null && $closes !== null && $closes <= $opens) {
            $errors['closes_date'] = 'The exam must close after it opens.';
        }
        $audience = (string) ($input['audience'] ?? 'everyone') === 'groups' ? 'groups' : 'everyone';
        $groupIds = [];
        if ($audience === 'groups') {
            $valid = array_column($this->exams->groups($tenant->id), 'id');
            $groupIds = array_values(array_filter(array_map('intval', is_array($input['groups'] ?? null) ? $input['groups'] : []), static fn (int $g): bool => in_array($g, $valid, true)));
            if ($groupIds === []) {
                $errors['groups'] = 'Choose the classes that sit this exam.';
            }
        }
        $attemptsRaw = (string) ($input['max_attempts'] ?? '1');
        $maxAttempts = $attemptsRaw === '' || $attemptsRaw === '0' ? null : (int) $attemptsRaw;
        if ($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > 10)) {
            $errors['max_attempts'] = 'Allow 1 to 10 attempts, or any number.';
        }
        $results = (string) ($input['results'] ?? 'immediately');
        if (!isset(CbtExam::RESULTS[$results])) {
            $errors['results'] = 'Choose when candidates see their results.';
        }
        $code = strtoupper(trim((string) ($input['access_code'] ?? '')));
        if ($code !== '' && preg_match('/^[A-Z0-9]{4,12}$/', $code) !== 1) {
            $errors['access_code'] = 'An access code is 4 to 12 letters and numbers, or leave it empty.';
        }
        if ($exam !== null && $exam->status !== 'draft') {
            // Questions already drawn stay as they are; only time and access settings change for an open exam.
            $sections = $exam->sections;
            $choose = $exam->chooseCount;
            unset($errors['sections'], $errors['choose_count']);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $flag = static fn (string $k): bool => in_array((string) ($input[$k] ?? ''), ['1', 'on'], true);

        return [
            'tenant_id' => $tenant->id, 'title' => $title, 'instructions' => $instructions, 'sections' => $sections, 'choose_count' => $choose,
            'duration_minutes' => $duration, 'opens_at' => $opens, 'closes_at' => $closes, 'audience' => $audience, 'groups' => $groupIds,
            'max_attempts' => $maxAttempts, 'results' => $results, 'review' => $flag('review'), 'shuffle_questions' => $flag('shuffle_questions'),
            'shuffle_options' => $flag('shuffle_options'), 'access_code' => $code === '' ? null : $code,
        ];
    }

    /** @return array{0: ?string, 1: ?string} UTC time and an error */
    private function localTime(Tenant $tenant, string $date, string $time): array
    {
        if ($date === '' && $time === '') {
            return [null, null];
        }
        $time = $time === '' ? '00:00' : $time;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return [null, 'Choose a day and a time.'];
        }
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone($tenant->timezone));
        if ($local === false || $local->format('Y-m-d H:i') !== $date . ' ' . $time) {
            return [null, 'Choose a day and a time.'];
        }

        return [$local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), null];
    }
}
