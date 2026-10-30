<?php

declare(strict_types=1);

namespace App\Modules\Exams\StarterKit;

use App\Modules\Audit\AuditLog;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Bank\QuestionBanks;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Exams\Cbt\CbtRepository;
use App\Modules\Exams\Live\LiveGame;
use App\Modules\Exams\Live\LiveRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * The tests starter kit (ADR 0043): sample question banks, ready-to-host live
 * quizzes and draft CBT exams, so a school can try everything on its first day.
 *
 * Installing is safe to repeat. Banks are topped up with new sample questions,
 * and quizzes and exams are added only when no quiz or exam has the same title
 * (archived ones included, so one a teacher removed does not come back).
 */
final class ExamStarterKit
{
    public function __construct(
        private readonly QuestionBanks $questionBanks,
        private readonly BankRepository $banks,
        private readonly LiveGame $game,
        private readonly LiveRepository $live,
        private readonly CbtExams $cbt,
        private readonly CbtRepository $exams,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array{banks: int, questions: int, quizzes: int, exams: int} what was added */
    public function install(Tenant $tenant, CurrentUser $by): array
    {
        $by->authorise(Permission::CourseManage);
        $added = $this->questionBanks->addSamples($tenant, $by);
        $quizzes = $this->quizzes($tenant, $by);
        $exams = $this->exams($tenant, $by);
        if ($quizzes + $exams > 0) {
            $this->audit->record($tenant->id, $by->user->id, 'tests.starter_kit', null, null, ['quizzes' => $quizzes, 'exams' => $exams]);
        }

        return $added + ['quizzes' => $quizzes, 'exams' => $exams];
    }

    /**
     * What the kit holds, and how many of its banks, quizzes and exams this school does not have yet.
     *
     * @return array{banks: int, questions: int, quizzes: int, exams: int, missing: int}
     */
    public function summary(Tenant $tenant): array
    {
        $samples = \App\Modules\Exams\Bank\SampleBanks::all();

        return [
            'banks' => count($samples),
            'questions' => array_sum(array_map(static fn (array $b): int => count($b['questions']), $samples)),
            'quizzes' => count(SampleQuizzes::all()),
            'exams' => count(SampleExams::all()),
            'missing' => $this->missing($tenant),
        ];
    }

    /** How many of the kit's sample questions, quizzes and exams the school does not have yet: zero means everything is there. */
    public function missing(Tenant $tenant): int
    {
        $have = [];
        foreach ($this->banks->banks($tenant->id, true) as $b) {
            $have[mb_strtolower($b->name)] = $b;
        }
        $missing = 0;
        foreach (\App\Modules\Exams\Bank\SampleBanks::all() as $s) {
            $bank = $have[mb_strtolower($s['name'])] ?? null;
            if ($bank === null) {
                $missing += count($s['questions']);
            } elseif ($bank->archivedAt === null) {
                // Sample questions added since the school took the bank count too.
                $prompts = array_map(static fn ($q): string => $q->prompt, $this->banks->allQuestions($tenant->id, $bank->id, true));
                $missing += count(array_diff(array_column($s['questions'], 0), $prompts));
            }
        }
        $quizTitles = $this->quizTitles($tenant);
        foreach (SampleQuizzes::all() as $q) {
            $missing += isset($quizTitles[mb_strtolower($q['title'])]) ? 0 : 1;
        }
        $examTitles = $this->examTitles($tenant);
        foreach (SampleExams::all() as $e) {
            $missing += isset($examTitles[mb_strtolower($e['title'])]) ? 0 : 1;
        }

        return $missing;
    }

    private function quizzes(Tenant $tenant, CurrentUser $by): int
    {
        $titles = $this->quizTitles($tenant);
        $added = 0;
        foreach (SampleQuizzes::all() as $sample) {
            if (isset($titles[mb_strtolower($sample['title'])])) {
                continue;
            }
            $quizId = $this->game->createQuiz($tenant, $by, $sample['title']);
            foreach ($sample['questions'] as [$prompt, $answers, $correct, $seconds, $points]) {
                // The quiz is fetched again each time so its question count is current.
                $quiz = $this->live->findQuiz($tenant->id, $quizId) ?? throw new \LogicException('The quiz just made is missing.');
                $this->game->saveQuestion($tenant, $by, $quiz, [
                    'prompt' => $prompt, 'options' => $answers, 'correct' => (string) $correct, 'seconds' => (string) $seconds, 'points' => $points,
                ]);
            }
            $added++;
        }

        return $added;
    }

    private function exams(Tenant $tenant, CurrentUser $by): int
    {
        $titles = $this->examTitles($tenant);
        $bankIds = [];
        foreach ($this->banks->banks($tenant->id) as $b) {
            $bankIds[mb_strtolower($b->name)] = $b->id;
        }
        $added = 0;
        foreach (SampleExams::all() as $sample) {
            if (isset($titles[mb_strtolower($sample['title'])])) {
                continue;
            }
            $sections = [];
            foreach ($sample['sections'] as [$bankName, $count, $label, $compulsory]) {
                $bankId = $bankIds[mb_strtolower($bankName)] ?? null;
                $have = $bankId === null ? 0 : $this->banks->drawableCount($tenant->id, $bankId);
                if ($bankId === null || $have === 0) {
                    continue;
                }
                $sections[] = ['bank' => (string) $bankId, 'count' => (string) min($count, $have), 'label' => $label, 'compulsory' => $compulsory ? '1' : ''];
            }
            $optional = count(array_filter($sections, static fn (array $s): bool => $s['compulsory'] === ''));
            if ($sections === [] || ($sample['choose'] > 0 && $optional <= $sample['choose'])) {
                // A bank the layout needs was archived: leave this exam out rather than change its shape.
                continue;
            }
            $this->cbt->save($tenant, $by, [
                'title' => $sample['title'], 'instructions' => $sample['instructions'], 'sections' => $sections,
                'choose_count' => (string) $sample['choose'], 'duration_minutes' => (string) $sample['minutes'],
                'opens_date' => '', 'opens_time' => '', 'closes_date' => '', 'closes_time' => '',
                'audience' => 'everyone', 'max_attempts' => (string) $sample['attempts'], 'results' => $sample['results'],
                'review' => '1', 'shuffle_questions' => '1', 'shuffle_options' => '1', 'access_code' => '',
            ]);
            $added++;
        }

        return $added;
    }

    /** @return array<string, true> */
    private function quizTitles(Tenant $tenant): array
    {
        $out = [];
        foreach ([...$this->live->quizzes($tenant->id), ...$this->live->quizzes($tenant->id, true)] as $q) {
            $out[mb_strtolower($q->title)] = true;
        }

        return $out;
    }

    /** @return array<string, true> */
    private function examTitles(Tenant $tenant): array
    {
        $out = [];
        foreach ($this->exams->exams($tenant->id) as $row) {
            $out[mb_strtolower($row['exam']->title)] = true;
        }

        return $out;
    }
}
