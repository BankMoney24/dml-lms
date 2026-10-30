<?php

declare(strict_types=1);

namespace App\Modules\ReportCards;

use App\Core\Clock;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Exams\Cbt\CbtExam;
use App\Modules\Exams\Cbt\CbtRepository;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Groups\LearnerGroup;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\LiveClasses\ClassSessionRepository;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;

/**
 * Term report cards for a class (ADR 0046).
 *
 * A card names the CBT exams that count this term and their weights, for example
 * a mid-term test worth 30 and the end-of-term exam worth 70. Each subject (an exam
 * section such as "Physics") is scored out of 100:
 *
 *   score = Σ weight × section score ÷ Σ weight, over the exams that include the subject.
 *
 * A learner who missed an exam scores 0 for its subjects (shown as "absent"). A subject
 * the learner did not take in an exam they sat (an optional UTME-style subject) is left
 * out of that exam's part, and a subject with no part at all is left off their card.
 * The average is over the subjects on the card. Positions share places on a tie.
 *
 * Nothing is copied: the card is worked out from the attempts every time it is shown.
 */
final class ReportCards
{
    public const MAX_COMPONENTS = 6;

    public function __construct(
        private readonly ReportCardRepository $cards,
        private readonly CbtRepository $exams,
        private readonly GroupRepository $groups,
        private readonly GroupService $groupService,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly Notifier $notifier,
        private readonly ?GuardianRepository $guardians = null,
        private readonly ?ClassSessionRepository $classes = null,
    ) {
    }

    /** @param array<string, mixed> $input title, grading, next_term, exam[], label[], weight[] */
    public function create(Tenant $tenant, LearnerGroup $group, CurrentUser $by, array $input): int
    {
        $this->authorise($tenant, $group, $by);
        [$title, $components, $grading, $nextTerm] = $this->fields($tenant, $group, $input);
        $id = $this->cards->create($tenant->id, $group->id, $title, $components, $grading, $nextTerm, $by->user->id);
        $this->audit->record($tenant->id, $by->user->id, 'report_card.created', 'report_card', $id, ['group' => $group->id, 'title' => $title]);

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function update(Tenant $tenant, LearnerGroup $group, ReportCard $card, CurrentUser $by, array $input): void
    {
        $this->authorise($tenant, $group, $by);
        [$title, $components, $grading, $nextTerm] = $this->fields($tenant, $group, $input);
        $this->cards->update($tenant->id, $card->id, $title, $components, $grading, $nextTerm);
    }

    /** Shows the card to the class and to parents who may see results, and tells them. */
    public function publish(Tenant $tenant, LearnerGroup $group, ReportCard $card, CurrentUser $by): void
    {
        $this->authorise($tenant, $group, $by);
        if ($card->isPublished()) {
            return;
        }
        $this->cards->setStatus($tenant->id, $card->id, 'published');
        $this->audit->record($tenant->id, $by->user->id, 'report_card.published', 'report_card', $card->id);
        foreach ($this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER) as $l) {
            $this->notifier->notify($tenant->id, $l['user_id'], 'report_card.published', 'Report card ready: ' . $card->title, null, '/report-cards/' . $card->id);
            foreach ($this->guardians?->guardiansOf($tenant->id, $l['user_id']) ?? [] as $link) {
                if ($link->status === 'active' && $link->canResults) {
                    $first = explode(' ', trim($l['name']))[0];
                    $where = '/children/' . $l['user_id'] . '/report-cards/' . $card->id;
                    $this->notifier->notify($tenant->id, $link->guardianId, 'report_card.published', $first . "'s report card is ready: " . $card->title, null, $where);
                }
            }
        }
    }

    public function unpublish(Tenant $tenant, LearnerGroup $group, ReportCard $card, CurrentUser $by): void
    {
        $this->authorise($tenant, $group, $by);
        $this->cards->setStatus($tenant->id, $card->id, 'draft');
        $this->audit->record($tenant->id, $by->user->id, 'report_card.unpublished', 'report_card', $card->id);
    }

    public function delete(Tenant $tenant, LearnerGroup $group, ReportCard $card, CurrentUser $by): void
    {
        $this->authorise($tenant, $group, $by);
        if ($card->isPublished()) {
            throw ValidationException::single('card', 'Take the report card back from the class first; then it can be deleted.');
        }
        $this->cards->delete($tenant->id, $card->id);
        $this->audit->record($tenant->id, $by->user->id, 'report_card.deleted', 'report_card', $card->id, ['title' => $card->title]);
    }

    /**
     * The class teacher writes the teacher's remark; only someone who manages every class
     * (the head or an administrator) writes the head's remark.
     */
    public function saveRemarks(Tenant $tenant, LearnerGroup $group, ReportCard $card, CurrentUser $by, int $learnerId, string $teacher, string $head): void
    {
        $this->authorise($tenant, $group, $by);
        if (!in_array($learnerId, array_column($this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER), 'user_id'), true)) {
            throw ValidationException::single('teacher_remark', 'This learner is not in the class.');
        }
        $teacher = self::remark($teacher, 'teacher_remark');
        $head = self::remark($head, 'head_remark');
        if (!$this->groupService->managesAll($by)) {
            $head = $this->cards->remarks($tenant->id, $card->id)[$learnerId]['head'] ?? '';
        }
        $this->cards->saveRemarks($tenant->id, $card->id, $learnerId, $teacher, $head, $by->user->id);
    }

    /**
     * The whole class's card: subjects, each learner's scores, averages, positions and attendance.
     *
     * @param list<array{user_id: int, name: string, email: string, added_at: string}> $learners
     * @return array{
     *   subjects: list<string>,
     *   components: list<array{exam: ?CbtExam, label: string, weight: int}>,
     *   rows: array<int, array{subjects: array<string, array{parts: array<int, int|string>, score: ?int, grade: ?string, word: ?string, position: ?int}>,
     *     average: ?float, position: ?int, absent: list<string>, attendance: ?array{held: int, present: int, late: int, rate: ?int}}>,
     *   ranked: int,
     *   subjectCounts: array<string, int>
     * }
     */
    public function sheet(Tenant $tenant, ReportCard $card, array $learners): array
    {
        $components = [];
        foreach ($card->components as $c) {
            $components[] = ['exam' => $this->exams->findExam($tenant->id, $c['exam']), 'label' => $c['label'], 'weight' => $c['weight']];
        }
        $subjects = [];
        foreach ($components as $c) {
            foreach ($c['exam']->sections ?? [] as $s) {
                if (!in_array($s['label'], $subjects, true)) {
                    $subjects[] = $s['label'];
                }
            }
        }
        $userIds = array_map(static fn (array $l): int => $l['user_id'], $learners);
        $scores = $this->cards->examScores($tenant->id, array_values(array_filter(array_map(static fn (array $c): ?int => $c['exam']?->id, $components))), $userIds);
        $attendance = $this->classes?->groupSummary($tenant->id, $card->groupId, $this->clock->sqlNow())['rows'] ?? null;

        $rows = [];
        foreach ($userIds as $uid) {
            $bySubject = [];
            $absent = [];
            foreach ($components as $i => $c) {
                if ($c['exam'] !== null && !isset($scores[$uid][$c['exam']->id])) {
                    $absent[] = $c['label'];
                }
            }
            foreach ($subjects as $subject) {
                $parts = [];
                $weighted = 0;
                $weights = 0;
                $sat = false;
                foreach ($components as $i => $c) {
                    if ($c['exam'] === null || !in_array($subject, array_column($c['exam']->sections, 'label'), true)) {
                        continue; // this exam does not have the subject
                    }
                    $mine = $scores[$uid][$c['exam']->id] ?? null;
                    if ($mine === null) {
                        $parts[$i] = 'absent';
                        $weights += $c['weight'];
                        continue;
                    }
                    if (!array_key_exists($subject, $mine)) {
                        continue; // sat the exam but not this optional subject
                    }
                    $parts[$i] = $mine[$subject];
                    $weighted += $c['weight'] * $mine[$subject];
                    $weights += $c['weight'];
                    $sat = true;
                }
                $score = $sat && $weights > 0 ? (int) round($weighted / $weights) : null;
                [$grade, $word] = $score === null ? [null, null] : Grading::grade($card->grading, $score);
                if ($score !== null || $parts !== []) {
                    $bySubject[$subject] = ['parts' => $parts, 'score' => $score, 'grade' => $grade, 'word' => $word, 'position' => null];
                }
            }
            $taken = array_filter(array_column($bySubject, 'score'), static fn (?int $s): bool => $s !== null);
            $a = $attendance[$uid] ?? null;
            $rows[$uid] = [
                'subjects' => $bySubject,
                'average' => $taken === [] ? null : round(array_sum($taken) / count($taken), 1),
                'position' => null,
                'absent' => $absent,
                'attendance' => $a === null ? null : ['held' => $a['held'], 'present' => $a['present'], 'late' => $a['late'], 'rate' => $a['rate']],
            ];
        }
        $subjectCounts = [];
        $subjectPositions = [];
        foreach ($subjects as $subject) {
            $col = [];
            foreach ($rows as $uid => $r) {
                if (($r['subjects'][$subject]['score'] ?? null) !== null) {
                    $col[$uid] = (int) $r['subjects'][$subject]['score'];
                }
            }
            $subjectCounts[$subject] = count($col);
            $subjectPositions[$subject] = Grading::positions($col);
        }
        $averages = [];
        foreach ($rows as $uid => $r) {
            if ($r['average'] !== null) {
                $averages[$uid] = $r['average'];
            }
        }
        $positions = Grading::positions($averages);
        $final = [];
        foreach ($rows as $uid => $r) {
            $cells = [];
            foreach ($r['subjects'] as $subject => $cell) {
                $cell['position'] = $subjectPositions[$subject][$uid] ?? null;
                $cells[$subject] = $cell;
            }
            $final[$uid] = ['subjects' => $cells, 'average' => $r['average'], 'position' => $positions[$uid] ?? null, 'absent' => $r['absent'], 'attendance' => $r['attendance']];
        }

        return ['subjects' => $subjects, 'components' => $components, 'rows' => $final, 'ranked' => count($averages), 'subjectCounts' => $subjectCounts];
    }

    /** @return list<CbtExam> exams a card for this class can use: published or closed, for the class or everyone */
    public function examsFor(Tenant $tenant, LearnerGroup $group): array
    {
        return $this->exams->forGroup($tenant->id, $group->id, 50);
    }

    private function authorise(Tenant $tenant, LearnerGroup $group, CurrentUser $by): void
    {
        if (!$this->groupService->mayTeach($tenant, $group, $by)) {
            throw \App\Core\Http\HttpException::forbidden();
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: list<array{exam: int, label: string, weight: int}>, 2: string, 3: string}
     */
    private function fields(Tenant $tenant, LearnerGroup $group, array $input): array
    {
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
            $errors['title'] = 'Give the report card a title of 3 to 120 characters, for example "First term 2026/2027".';
        }
        $grading = (string) ($input['grading'] ?? 'waec');
        if (!isset(Grading::SCALES[$grading])) {
            $errors['grading'] = 'Choose a grading scale.';
        }
        $nextTerm = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['next_term'] ?? '')));
        if (mb_strlen($nextTerm) > 160) {
            $errors['next_term'] = 'Keep this under 160 characters.';
        }
        $allowed = [];
        foreach ($this->examsFor($tenant, $group) as $e) {
            $allowed[$e->id] = $e;
        }
        $examIds = is_array($input['exam'] ?? null) ? array_values($input['exam']) : [];
        $labels = is_array($input['label'] ?? null) ? array_values($input['label']) : [];
        $weights = is_array($input['weight'] ?? null) ? array_values($input['weight']) : [];
        $components = [];
        foreach ($examIds as $i => $raw) {
            $examId = (int) $raw;
            if ($examId === 0) {
                continue;
            }
            if (!isset($allowed[$examId])) {
                $errors['exam'] = 'Choose exams that this class sat (published exams for the class or for everyone).';
                continue;
            }
            if (in_array($examId, array_column($components, 'exam'), true)) {
                $errors['exam'] = 'Each exam can count once.';
                continue;
            }
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($labels[$i] ?? '')));
            $weight = (int) ($weights[$i] ?? 0);
            if ($weight < 1 || $weight > 100) {
                $errors['weight'] = 'Give each exam a weight from 1 to 100, for example 30 for a mid-term test and 70 for the exam.';
            }
            $components[] = ['exam' => $examId, 'label' => mb_substr($label !== '' ? $label : $allowed[$examId]->title, 0, 40), 'weight' => max(1, min(100, $weight))];
        }
        if ($components === [] && !isset($errors['exam'])) {
            $errors['exam'] = 'Choose at least one exam that counts.';
        }
        if (count($components) > self::MAX_COMPONENTS) {
            $errors['exam'] = 'A report card counts up to ' . self::MAX_COMPONENTS . ' exams.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$title, $components, $grading, $nextTerm];
    }

    private static function remark(string $text, string $field): string
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $text)));
        if (mb_strlen($text) > 600) {
            throw ValidationException::single($field, 'Keep a remark under 600 characters.');
        }

        return $text;
    }
}
