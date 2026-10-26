<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Learning\CompletionRule;
use PHPUnit\Framework\TestCase;

final class CompletionRuleTest extends TestCase
{
    public function testCompleteWhenEveryMandatoryLessonIsDone(): void
    {
        $this->assertTrue((new CompletionRule())->isComplete([1, 2, 3], [3, 1, 2, 9]));
    }

    public function testIncompleteWhenAnyMandatoryLessonIsMissing(): void
    {
        $this->assertFalse((new CompletionRule())->isComplete([1, 2, 3], [1, 2]));
    }

    public function testCourseWithNoMandatoryLessonsIsNeverComplete(): void
    {
        $this->assertFalse((new CompletionRule())->isComplete([], [1]));
    }

    public function testAverageIsNeededOnlyWhenTheCourseSetsOne(): void
    {
        $rule = new CompletionRule();
        $this->assertFalse($rule->isComplete([1, 2], [1, 2], 70, 69));
        $this->assertTrue($rule->isComplete([1, 2], [1, 2], 70, 70));
        $this->assertTrue($rule->isComplete([1, 2], [1, 2], null, 10), 'no average set');
        $this->assertTrue($rule->isComplete([1, 2], [1, 2], 70, null), 'no required quizzes, nothing to average');
        $this->assertFalse($rule->isComplete([1, 2], [1], 70, 100), 'lessons still come first');
    }
}
