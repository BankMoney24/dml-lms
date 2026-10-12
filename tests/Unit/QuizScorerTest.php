<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Assessments\QuizOption;
use App\Modules\Assessments\QuizQuestion;
use App\Modules\Assessments\QuizScorer;
use PHPUnit\Framework\TestCase;

final class QuizScorerTest extends TestCase
{
    /** @return list<QuizQuestion> */
    private function questions(): array
    {
        return [
            new QuizQuestion(1, 'A', [new QuizOption(11, 'yes', true), new QuizOption(12, 'no', false)]),
            new QuizQuestion(2, 'B', [new QuizOption(21, 'yes', true), new QuizOption(22, 'no', false)]),
            new QuizQuestion(3, 'C', [new QuizOption(31, 'yes', true), new QuizOption(32, 'no', false)]),
        ];
    }

    public function testAllCorrectScoresOneHundredAndPasses(): void
    {
        $result = (new QuizScorer())->score($this->questions(), [1 => 11, 2 => 21, 3 => 31], 70);

        $this->assertSame(100, $result['percent']);
        $this->assertSame(3, $result['correct']);
        $this->assertTrue($result['passed']);
    }

    public function testPercentIsFlooredSoLearnersAreNotRoundedOverThePassMark(): void
    {
        // 2 of 3 = 66.67% — must not round up to 67 and pass a 67% threshold.
        $result = (new QuizScorer())->score($this->questions(), [1 => 11, 2 => 21, 3 => 32], 67);

        $this->assertSame(66, $result['percent']);
        $this->assertFalse($result['passed']);
    }

    public function testThresholdIsInclusive(): void
    {
        $result = (new QuizScorer())->score($this->questions(), [1 => 11, 2 => 21, 3 => 32], 66);

        $this->assertTrue($result['passed']);
    }

    public function testOptionsFromAnotherQuestionAreIgnored(): void
    {
        // Tampered form: answering Q1 with Q2's correct option.
        $result = (new QuizScorer())->score($this->questions(), [1 => 21], 0);

        $this->assertSame(0, $result['correct']);
        $this->assertSame([], $result['answers']);
    }

    public function testEmptyQuizNeverPasses(): void
    {
        $result = (new QuizScorer())->score([], [], 0);

        $this->assertFalse($result['passed']);
        $this->assertSame(0, $result['percent']);
    }

    public function testLearnerViewNeverExposesTheCorrectAnswer(): void
    {
        $view = $this->questions()[0]->forLearner();

        $this->assertStringNotContainsString('correct', json_encode($view, JSON_THROW_ON_ERROR));
        $this->assertSame([['id' => 11, 'label' => 'yes'], ['id' => 12, 'label' => 'no']], $view['options']);
    }
}
