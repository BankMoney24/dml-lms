<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Exams\Bank\BankQuestion;
use App\Modules\Exams\Bank\QuestionImport;
use App\Modules\Exams\Bank\SampleBanks;
use App\Modules\Exams\Cbt\CbtAttempt;
use App\Modules\Exams\Cbt\CbtExam;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Exams\Cbt\PaperBuilder;
use App\Modules\Exams\Live\LiveClock;
use App\Modules\Exams\Live\LiveGame;
use App\Modules\Exams\Live\LiveQuestion;
use App\Modules\Exams\StarterKit\SampleExams;
use App\Modules\Exams\StarterKit\SampleQuizzes;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Update 42 (ADR 0042): importing questions, drawing papers, marking and live quiz points. */
final class ExamsUnitTest extends TestCase
{
    public function testAikenTextReadsQuestionsWithOptionalExtras(): void
    {
        [$qs, $errors] = QuestionImport::aiken(<<<TXT
        What is the SI unit of force?
        A. joule
        B. watt
        C. newton
        D. pascal
        ANSWER: C
        EXPLANATION: 1 N = 1 kg m/s².
        TOPIC: Units
        DIFFICULTY: easy
        Two plus two is
        A) three
        B) four
        ANSWER: b

        A question that runs
        over two lines?
        A. yes
        B. no
        C. maybe
        D. never
        E. always
        ANSWER: E
        TXT);
        $this->assertSame([], $errors);
        $this->assertCount(3, $qs);
        $this->assertSame(['joule', 'watt', 'newton', 'pascal'], $qs[0]['options']);
        $this->assertSame(2, $qs[0]['correct']);
        $this->assertSame('Units', $qs[0]['topic']);
        $this->assertSame(1, $qs[0]['difficulty']);
        $this->assertSame('1 N = 1 kg m/s².', $qs[0]['explanation']);
        $this->assertSame(1, $qs[1]['correct'], 'questions need no blank line between them, and the letter can be lower case');
        $this->assertSame("A question that runs\nover two lines?", $qs[2]['prompt']);
        $this->assertSame(4, $qs[2]['correct']);
    }

    public function testAikenMistakesAreReportedByLineAndNothingIsKept(): void
    {
        [$qs, $errors] = QuestionImport::aiken("Q one\nA. a\nC. c\nANSWER: A\n\nQ two\nA. a\nB. b\n\nQ three\nA. a\nB. b\nANSWER: D\nDIFFICULTY: brutal");
        $text = implode(' ', $errors);
        $this->assertStringContainsString('Line 3: expected option B here.', $text);
        $this->assertStringContainsString('Line 6: this question has no ANSWER line', $text);
        $this->assertStringContainsString('Line 13: the answer D is not one of the options.', $text);
        $this->assertStringContainsString('Line 14: difficulty is easy, medium or hard.', $text);
        $this->assertSame([], QuestionImport::aiken('')[0]);
        $this->assertSame(['No questions were found. Check the format against the example.'], QuestionImport::aiken("\n\n")[1]);
    }

    public function testCsvReadsTheHeaderNamesAndChecksEveryRow(): void
    {
        $csv = "\u{FEFF}Question,Option A,Option B,Option C,option_d,E,Answer,Explanation,Topic,Difficulty\n"
            . "\"Which gas, at room temperature, is green?\",Chlorine,Oxygen,,,,A,It is yellow-green.,Gases,medium\n"
            . "Missing answer,x,y,,,,,,,\n"
            . "\n";
        [$qs, $errors] = QuestionImport::csv($csv);
        $this->assertSame(['Row 3: the answer must be a letter from A to E.'], array_slice($errors, 0, 1));
        $this->assertSame('Which gas, at room temperature, is green?', $qs[0]['prompt']);
        $this->assertSame(['Chlorine', 'Oxygen'], $qs[0]['options']);
        $this->assertSame(2, $qs[0]['difficulty']);
        $this->assertStringContainsString('The first row must name the columns', implode(' ', QuestionImport::csv("a,b\n1,2")[1]));
    }

    public function testTheSampleBanksAreWellFormed(): void
    {
        $count = 0;
        foreach (SampleBanks::all() as $bank) {
            $this->assertStringEndsWith('(sample)', $bank['name']);
            foreach ($bank['questions'] as $q) {
                $count++;
                $this->assertGreaterThanOrEqual(2, count($q[1]));
                $this->assertLessThanOrEqual(5, count($q[1]));
                $this->assertArrayHasKey($q[2], $q[1], $q[0]);
                $this->assertNotSame('', $q[3], 'every sample question explains its answer');
                $this->assertSame(count($q[1]), count(array_unique($q[1])), $q[0]);
                if (isset($q[6])) {
                    $this->assertArrayHasKey($q[6], $bank['passages']);
                }
            }
        }
        $this->assertGreaterThanOrEqual(200, $count);
        foreach (SampleBanks::all() as $bank) {
            $prompts = array_column($bank['questions'], 0);
            $this->assertSame(count($prompts), count(array_unique($prompts)), $bank['name'] . ' has no question twice, so topping up matches by wording');
        }
    }

    public function testTheSampleQuizzesAndExamsFitTheRules(): void
    {
        $titles = [];
        foreach (SampleQuizzes::all() as $quiz) {
            $titles[] = $quiz['title'];
            $this->assertStringEndsWith('(sample)', $quiz['title']);
            foreach ($quiz['questions'] as [$prompt, $answers, $correct, $seconds, $points]) {
                $this->assertLessThanOrEqual(500, mb_strlen($prompt));
                $this->assertGreaterThanOrEqual(2, count($answers), $prompt);
                $this->assertLessThanOrEqual(4, count($answers), $prompt . ': one answer per tile, A to D');
                $this->assertSame(count($answers), count(array_unique($answers)), $prompt);
                $this->assertLessThanOrEqual(120, max(0, ...array_map('mb_strlen', $answers)), $prompt);
                $this->assertArrayHasKey($correct, $answers, $prompt);
                $this->assertContains($seconds, LiveQuestion::SECONDS, $prompt);
                $this->assertArrayHasKey($points, LiveQuestion::POINTS, $prompt);
            }
        }
        $this->assertSame(count($titles), count(array_unique($titles)));
        $banks = array_column(SampleBanks::all(), 'name');
        foreach (SampleExams::all() as $exam) {
            $optional = 0;
            foreach ($exam['sections'] as [$bank, $count, $label, $compulsory]) {
                $this->assertContains($bank, $banks, $exam['title']);
                $this->assertGreaterThan(0, $count);
                $optional += $compulsory ? 0 : 1;
            }
            $this->assertTrue($exam['choose'] === 0 || $exam['choose'] < $optional, $exam['title'] . ': candidates choose some, not all, of the optional subjects');
            $this->assertArrayHasKey($exam['results'], CbtExam::RESULTS);
        }
    }

    public function testPapersDrawTheRightNumberAndKeepPassagesTogether(): void
    {
        $pool = [];
        for ($i = 1; $i <= 30; $i++) {
            $pool[] = ['id' => $i, 'passage' => $i >= 11 && $i <= 14 ? 7 : null, 'options' => $i % 2 === 0 ? 4 : 5];
        }
        $builder = new PaperBuilder(new Randomizer(new Mt19937(42)));
        $paper = $builder->section($pool, 20, true, true);
        $this->assertCount(20, $paper);
        $ids = array_column($paper, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $passage = array_keys(array_filter($ids, static fn (int $id): bool => $id >= 11 && $id <= 14));
        if (count($passage) > 1) {
            $this->assertSame(range($passage[0], $passage[0] + count($passage) - 1), $passage, 'questions on one passage sit together');
        }
        foreach ($paper as $q) {
            $sorted = $q['perm'];
            sort($sorted);
            $this->assertSame(range(0, $q['id'] % 2 === 0 ? 3 : 4), $sorted, 'every option appears once');
        }
        $this->assertNotSame(range(1, 30), array_column($builder->section($pool, 30, true, false), 'id'), 'shuffled');
        $fixed = $builder->section($pool, 30, false, false);
        $this->assertSame(range(1, 30), array_column($fixed, 'id'), 'in bank order without shuffling');
        $this->assertSame([0, 1, 2, 3], $fixed[1]['perm']);
        $this->assertSame([], $builder->section([], 10, true, true));
    }

    public function testMarkingScoresEachSubjectOutOf100(): void
    {
        $q = static fn (int $id, int $correct): BankQuestion => new BankQuestion($id, 1, 1, null, 'Q' . $id, ['a', 'b', 'c', 'd'], $correct, '', '', null, 0, 0, null);
        $attempt = CbtAttempt::fromRow([
            'id' => 1, 'tenant_id' => 1, 'exam_id' => 3, 'user_id' => 9, 'title' => 'Mock',
            'paper' => json_encode(['sections' => [
                ['label' => 'English', 'bank' => 1, 'questions' => [['id' => 1, 'perm' => [0, 1, 2, 3]], ['id' => 2, 'perm' => [3, 2, 1, 0]], ['id' => 3, 'perm' => [0, 1, 2, 3]]]],
                ['label' => 'Physics', 'bank' => 2, 'questions' => [['id' => 4, 'perm' => [0, 1, 2, 3]], ['id' => 5, 'perm' => [0, 1, 2, 3]]]],
            ]]),
            'answers' => '{"1": 0, "2": 2, "4": 1}', 'flags' => '[]', 'question_count' => 5,
            'started_at' => '2026-11-02 09:00:00', 'deadline_at' => '2026-11-02 11:00:00', 'extra_minutes' => 0, 'save_seq' => 3,
        ]);
        [$result, $correct, $right] = CbtExams::mark($attempt, [1 => $q(1, 0), 2 => $q(2, 2), 3 => $q(3, 1), 4 => $q(4, 3), 5 => $q(5, 0)]);
        $this->assertSame(2, $correct);
        $this->assertSame(['English' => 67, 'Physics' => 0], array_combine(array_column($result['sections'], 'label'), array_column($result['sections'], 'scaled')));
        $this->assertSame(67, $result['total']);
        $this->assertSame(200, $result['max']);
        $this->assertSame([1 => true, 2 => true, 3 => false, 4 => false, 5 => false], $right);

        $now = new \DateTimeImmutable('2026-11-02 10:59:40', new \DateTimeZone('UTC'));
        $this->assertSame(20, $attempt->secondsLeft($now));
        $this->assertFalse($attempt->isOverdue($now->modify('+45 seconds')), 'thirty seconds of grace');
        $this->assertTrue($attempt->isOverdue($now->modify('+51 seconds')));
    }

    public function testExamSubjectChoiceLikeTheUtme(): void
    {
        $exam = CbtExam::fromRow([
            'id' => 1, 'tenant_id' => 1, 'title' => 'Mock', 'instructions' => null, 'choose_count' => 3, 'duration_minutes' => 120,
            'sections' => json_encode([
                ['bank' => 1, 'count' => 60, 'label' => 'Use of English', 'compulsory' => true],
                ['bank' => 2, 'count' => 40, 'label' => 'Physics', 'compulsory' => false],
                ['bank' => 3, 'count' => 40, 'label' => 'Chemistry', 'compulsory' => false],
                ['bank' => 4, 'count' => 30, 'label' => 'Biology', 'compulsory' => false],
                ['bank' => 5, 'count' => 40, 'label' => 'Mathematics', 'compulsory' => false],
            ]),
            'opens_at' => '2026-11-02 08:00:00', 'closes_at' => '2026-11-02 18:00:00', 'audience' => 'everyone', 'max_attempts' => 1,
            'results' => 'after_close', 'review' => 1, 'shuffle_questions' => 1, 'shuffle_options' => 1, 'access_code' => '', 'status' => 'published', 'created_by' => 1,
        ]);
        $this->assertSame([0], $exam->compulsory());
        $this->assertSame([1, 2, 3, 4], $exam->optional());
        $this->assertSame(4, $exam->sectionsSat());
        $this->assertSame('170 to 180', $exam->questionsLabel());
        $this->assertNull($exam->accessCode);
        $this->assertFalse($exam->isOpen('2026-11-02 07:59:59'));
        $this->assertTrue($exam->isOpen('2026-11-02 08:00:00'));
        $this->assertFalse($exam->resultsVisible('2026-11-02 17:00:00'));
        $this->assertTrue($exam->resultsVisible('2026-11-02 18:00:00'));
    }

    public function testLiveQuizPointsRewardSpeedAndStreaks(): void
    {
        $this->assertSame(1000, LiveGame::points('standard', 0, 20000, 1));
        $this->assertSame(750, LiveGame::points('standard', 10000, 20000, 1));
        $this->assertSame(500, LiveGame::points('standard', 20000, 20000, 1), 'a right answer at the buzzer still earns half');
        $this->assertSame(500, LiveGame::points('standard', 90000, 20000, 2), 'no bonus for two in a row');
        $this->assertSame(1200, LiveGame::points('standard', 0, 20000, 3), 'three in a row: +200');
        $this->assertSame(1500, LiveGame::points('standard', 0, 20000, 9), 'the bonus stops at 500');
        $this->assertSame(2000, LiveGame::points('double', 0, 20000, 1));
        $this->assertSame(0, LiveGame::points('none', 0, 20000, 5));
        $this->assertSame(1762000000123, LiveClock::ms('2025-11-01 12:26:40.123'));
        $this->assertSame(1762000000000, LiveClock::ms('2025-11-01 12:26:40'));
    }
}
