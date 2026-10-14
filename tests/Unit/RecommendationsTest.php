<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Courses\Course;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Joining\Recommendations;
use App\Modules\Joining\RegistrationService;
use PHPUnit\Framework\TestCase;

final class RecommendationsTest extends TestCase
{
    private static function course(int $id, string $title, string $summary = '', ?string $level = null, ?string $track = null): Course
    {
        return Course::fromRow([
            'id' => $id, 'tenant_id' => 1, 'slug' => 'c' . $id, 'title' => $title, 'summary' => $summary,
            'status' => 'published', 'level' => $level, 'track' => $track,
        ]);
    }

    /** @param list<string> $goals */
    private static function profile(array $goals, ?string $experience = null): LearnerProfile
    {
        return LearnerProfile::fromRow(['tenant_id' => 1, 'user_id' => 1, 'goals' => implode(',', $goals), 'experience' => $experience]);
    }

    public function testMatchesWholeWordsOnly(): void
    {
        $courses = [
            self::course(1, 'Science practicals', 'A hands-on approach to experiments.'),
            self::course(2, 'Building web apps', 'Your first programs in JavaScript.'),
            self::course(3, 'Staying safe online', 'Passwords and phishing.'),
        ];

        $ranked = Recommendations::rank(self::profile(['code']), $courses);
        $this->assertSame([2], array_map(static fn (array $r): int => $r['course']->id, $ranked), '"approach" is not about apps');
        $this->assertSame(['Learn to code'], $ranked[0]['reasons']);

        $both = Recommendations::rank(self::profile(['code', 'security']), $courses);
        $this->assertSame([2, 3], array_map(static fn (array $r): int => $r['course']->id, $both));
    }

    public function testLevelDecidesBetweenEqualMatchesAndSchoolTrackCounts(): void
    {
        $courses = [
            self::course(1, 'Advanced Python', '', 'advanced'),
            self::course(2, 'Python for beginners', '', 'beginner'),
            self::course(3, 'SS2 Physics', '', 'beginner', 'school'),
        ];

        $newcomer = Recommendations::rank(self::profile(['code', 'school'], 'new'), $courses);
        $this->assertSame(2, $newcomer[0]['course']->id);
        $this->assertContains('Right for new to this', $newcomer[0]['reasons']);
        $this->assertSame(1, $newcomer[2]['course']->id, 'too hard comes last');
        $this->assertSame(['School subjects', 'Right for new to this'], $newcomer[1]['reasons']);

        $pro = Recommendations::rank(self::profile(['code'], 'pro'), $courses);
        $this->assertSame(1, $pro[0]['course']->id);
        $this->assertSame([], Recommendations::rank(self::profile([]), $courses), 'no goals, no suggestions');
    }

    public function testGithubHandlesAndJoinCodes(): void
    {
        $this->assertSame('ada-dev', LearnerProfile::githubHandle('https://github.com/ada-dev/'));
        $this->assertSame('ada', LearnerProfile::githubHandle('@ada'));
        $this->assertTrue(LearnerProfile::validGithub('ada-dev'));
        $this->assertFalse(LearnerProfile::validGithub('-ada'));
        $this->assertFalse(LearnerProfile::validGithub('ada--dev'));
        $this->assertFalse(LearnerProfile::validGithub('not a handle'));

        $this->assertSame('K7PQ2XMA', RegistrationService::normaliseCode(' k7pq-2xma '));
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', RegistrationService::newCode());
        }
    }
}
