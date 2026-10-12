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
}
