<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * Looking after question banks (ADR 0042): banks, passages and questions, one
 * at a time or imported in bulk. Teachers and administrators only.
 */
final class QuestionBanks
{
    public const MAX_BANKS = 200;
    public const MAX_QUESTIONS = 5000;

    public function __construct(
        private readonly BankRepository $banks,
        private readonly Connection $db,
        private readonly AuditLog $audit,
    ) {
    }

    /** @param array<string, mixed> $input name, description, practice */
    public function createBank(Tenant $tenant, CurrentUser $by, array $input): int
    {
        $by->authorise(Permission::CourseManage);
        [$name, $description, $practice] = $this->bankFields($tenant, $input);
        if (count($this->banks->banks($tenant->id, true)) >= self::MAX_BANKS) {
            throw ValidationException::single('name', 'A school can have up to ' . self::MAX_BANKS . ' question banks. Archive or reuse one first.');
        }
        $id = $this->banks->createBank($tenant->id, $name, $description, $practice, $by->user->id);
        $this->audit->record($tenant->id, $by->user->id, 'bank.created', 'question_bank', $id, ['name' => $name]);

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function updateBank(Tenant $tenant, CurrentUser $by, QuestionBank $bank, array $input): void
    {
        $by->authorise(Permission::CourseManage);
        [$name, $description, $practice] = $this->bankFields($tenant, $input, $bank->id);
        $this->banks->updateBank($tenant->id, $bank->id, $name, $description, $practice);
    }

    public function archive(Tenant $tenant, CurrentUser $by, QuestionBank $bank, bool $archived): void
    {
        $by->authorise(Permission::CourseManage);
        $this->banks->setArchived($tenant->id, $bank->id, $archived);
        $this->audit->record($tenant->id, $by->user->id, $archived ? 'bank.archived' : 'bank.restored', 'question_bank', $bank->id);
    }

    /**
     * @param array<string, mixed> $input prompt, options (list), correct (letter or index), explanation, topic, difficulty, passage_id
     */
    public function addQuestion(Tenant $tenant, CurrentUser $by, QuestionBank $bank, array $input): int
    {
        $by->authorise(Permission::CourseManage);
        if ($bank->activeCount + $bank->retiredCount >= self::MAX_QUESTIONS) {
            throw ValidationException::single('prompt', 'A bank holds up to ' . self::MAX_QUESTIONS . ' questions. Start another bank for this subject.');
        }
        $q = $this->questionFields($tenant, $bank, $input);
        $id = $this->banks->createQuestion($tenant->id, $bank->id, $q, $by->user->id);
        $this->banks->touchBank($tenant->id, $bank->id);

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function editQuestion(Tenant $tenant, CurrentUser $by, QuestionBank $bank, BankQuestion $question, array $input): void
    {
        $by->authorise(Permission::CourseManage);
        $q = $this->questionFields($tenant, $bank, $input);
        $this->banks->updateQuestion($tenant->id, $question->id, $q);
        $this->banks->touchBank($tenant->id, $bank->id);
        if ($question->correct !== $q['correct']) {
            $this->audit->record($tenant->id, $by->user->id, 'bank.answer_changed', 'bank_question', $question->id);
        }
    }

    public function retire(Tenant $tenant, CurrentUser $by, BankQuestion $question, bool $retired): void
    {
        $by->authorise(Permission::CourseManage);
        $this->banks->setRetired($tenant->id, $question->id, $retired);
    }

    /**
     * Imports Aiken text or a CSV file. All or nothing.
     *
     * @return int how many questions were added
     */
    public function import(Tenant $tenant, CurrentUser $by, QuestionBank $bank, string $content, bool $isCsv): int
    {
        $by->authorise(Permission::CourseManage);
        if (trim($content) === '') {
            throw ValidationException::single('questions', 'Paste the questions, or choose a file.');
        }
        if (strlen($content) > 1_000_000) {
            throw ValidationException::single('questions', 'Import up to 1 MB at a time.');
        }
        [$questions, $errors] = $isCsv ? QuestionImport::csv($content) : QuestionImport::aiken($content);
        if ($errors !== []) {
            $more = count($errors) > 8 ? ' (and ' . (count($errors) - 8) . ' more)' : '';
            throw ValidationException::single('questions', 'Nothing was imported. ' . implode(' ', array_slice($errors, 0, 8)) . $more);
        }
        if ($bank->activeCount + $bank->retiredCount + count($questions) > self::MAX_QUESTIONS) {
            throw ValidationException::single('questions', 'That would take the bank past ' . self::MAX_QUESTIONS . ' questions.');
        }
        $this->db->transaction(function () use ($tenant, $by, $bank, $questions): void {
            foreach ($questions as $q) {
                $this->banks->createQuestion($tenant->id, $bank->id, ['passage_id' => null] + $q, $by->user->id);
            }
            $this->banks->touchBank($tenant->id, $bank->id);
        });
        $this->audit->record($tenant->id, $by->user->id, 'bank.imported', 'question_bank', $bank->id, ['questions' => count($questions)]);

        return count($questions);
    }

    /** @param array<string, mixed> $input title, body */
    public function savePassage(Tenant $tenant, CurrentUser $by, QuestionBank $bank, array $input, ?Passage $passage = null): int
    {
        $by->authorise(Permission::CourseManage);
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $errors = [];
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            $errors['title'] = 'Give the passage a title of 2 to 120 characters, for example "Read the passage and answer questions 1 to 5".';
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            $errors['body'] = 'Write the passage (up to 10,000 characters).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($passage !== null) {
            $this->banks->updatePassage($tenant->id, $passage->id, $title, $body);

            return $passage->id;
        }

        return $this->banks->createPassage($tenant->id, $bank->id, $title, $body);
    }

    public function deletePassage(Tenant $tenant, CurrentUser $by, Passage $passage): void
    {
        $by->authorise(Permission::CourseManage);
        $this->banks->deletePassage($tenant->id, $passage->id);
    }

    /**
     * Adds the sample banks (subject names ending "(sample)") that the school does not have yet.
     *
     * @return int how many banks were added
     */
    public function addSamples(Tenant $tenant, CurrentUser $by): int
    {
        $by->authorise(Permission::CourseManage);
        $added = 0;
        foreach (SampleBanks::all() as $sample) {
            if ($this->banks->nameTaken($tenant->id, $sample['name'])) {
                continue;
            }
            $this->db->transaction(function () use ($tenant, $by, $sample): void {
                $bankId = $this->banks->createBank($tenant->id, $sample['name'], SampleBanks::NOTE, true, $by->user->id);
                $passages = [];
                foreach ($sample['passages'] as $key => [$title, $body]) {
                    $passages[$key] = $this->banks->createPassage($tenant->id, $bankId, $title, $body);
                }
                foreach ($sample['questions'] as $q) {
                    $this->banks->createQuestion($tenant->id, $bankId, [
                        'passage_id' => isset($q[6]) ? ($passages[$q[6]] ?? null) : null,
                        'prompt' => $q[0], 'options' => $q[1], 'correct' => $q[2], 'explanation' => $q[3], 'topic' => $q[4], 'difficulty' => $q[5],
                    ], $by->user->id);
                }
            });
            $added++;
        }
        if ($added > 0) {
            $this->audit->record($tenant->id, $by->user->id, 'bank.samples_added', null, null, ['banks' => $added]);
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string, 2: bool}
     */
    private function bankFields(Tenant $tenant, array $input, int $exceptId = 0): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Name the bank after its subject, for example "Use of English" or "SS 2 Physics" (2 to 80 characters).';
        } elseif ($this->banks->nameTaken($tenant->id, $name, $exceptId)) {
            $errors['name'] = 'There is already a bank called "' . $name . '".';
        }
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'Keep the description under 500 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $description, in_array((string) ($input['practice'] ?? ''), ['1', 'on', 'yes'], true)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{passage_id: ?int, prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int}
     */
    private function questionFields(Tenant $tenant, QuestionBank $bank, array $input): array
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $raw = is_array($input['options'] ?? null) ? array_values($input['options']) : [];
        $options = [];
        $blankBefore = false;
        $gap = false;
        foreach (array_slice($raw, 0, 5) as $o) {
            $o = trim((string) preg_replace('/\s+/u', ' ', (string) $o));
            if ($o === '') {
                $blankBefore = true;
                continue;
            }
            $gap = $gap || $blankBefore;
            $options[] = $o;
        }
        $correctRaw = strtoupper(trim((string) ($input['correct'] ?? '')));
        $correct = preg_match('/^[A-E]$/', $correctRaw) === 1 ? ord($correctRaw) - 65 : (ctype_digit($correctRaw) ? (int) $correctRaw : -1);
        $explanation = trim((string) ($input['explanation'] ?? ''));
        $topic = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['topic'] ?? '')));
        $difficulty = (string) ($input['difficulty'] ?? '');
        $passageId = (int) ($input['passage_id'] ?? 0);
        $errors = [];
        if ($prompt === '' || mb_strlen($prompt) > 3000) {
            $errors['prompt'] = 'Write the question (up to 3,000 characters).';
        }
        if (count($options) < 2) {
            $errors['options'] = 'Give at least two options.';
        } elseif ($gap) {
            $errors['options'] = 'Fill the options in order from A, without gaps, so the letters match what candidates see.';
        } elseif (max(array_map('mb_strlen', $options)) > 500) {
            $errors['options'] = 'Keep each option under 500 characters.';
        } elseif (count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) {
            $errors['options'] = 'Two options are the same.';
        }
        if (!isset($errors['options']) && ($correct < 0 || $correct >= count($options))) {
            $errors['correct'] = 'Choose which option is correct.';
        }
        if (mb_strlen($explanation) > 2000) {
            $errors['explanation'] = 'Keep the explanation under 2,000 characters.';
        }
        if (mb_strlen($topic) > 80) {
            $errors['topic'] = 'Keep the topic under 80 characters.';
        }
        if ($difficulty !== '' && !in_array($difficulty, ['1', '2', '3'], true)) {
            $errors['difficulty'] = 'Choose easy, medium or hard.';
        }
        if ($passageId > 0) {
            $passage = $this->banks->findPassage($tenant->id, $passageId);
            if ($passage === null || $passage->bankId !== $bank->id) {
                $errors['passage_id'] = 'Choose one of this bank\'s passages.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'passage_id' => $passageId > 0 ? $passageId : null, 'prompt' => $prompt, 'options' => $options, 'correct' => $correct,
            'explanation' => $explanation, 'topic' => $topic, 'difficulty' => $difficulty === '' ? null : (int) $difficulty,
        ];
    }
}
