<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use Tests\Support\AppTestCase;

final class CourseAuthoringTest extends AppTestCase
{
    private const HOST = 'school.test';

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($tenant, 'teacher@school.test', Role::Educator);
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
    }

    public function testInvalidCourseKeepsWhatWasTypedAndExplainsTheFix(): void
    {
        $response = $this->post(self::HOST, '/teach/courses', ['title' => 'x', 'summary' => 'Keep me <b>safe</b>']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('between 3 and 190 characters', $response->body);
        $this->assertStringContainsString('Keep me &lt;b&gt;safe&lt;/b&gt;', $response->body);
        $this->assertStringContainsString('aria-invalid="true"', $response->body);
    }

    public function testBuildAndPublishACourse(): void
    {
        $created = $this->post(self::HOST, '/teach/courses', ['title' => 'Basic Electricity', 'summary' => '']);
        $editUrl = (string) $created->header('Location');
        $this->assertMatchesRegularExpression('#^/teach/courses/\d+$#', $editUrl);

        $this->post(self::HOST, $editUrl . '/modules', ['module_title' => 'Circuits']);
        $moduleId = (string) $this->db()->fetchValue('SELECT id FROM course_modules');
        $this->post(self::HOST, $editUrl . '/lessons', [
            'module_id' => $moduleId, 'type' => 'quiz', 'lesson_title' => 'Check', 'body' => '', 'pass_threshold' => '80',
        ]);

        // A quiz with no questions blocks publishing.
        $blocked = $this->post(self::HOST, $editUrl . '/publish');
        $this->assertSame(422, $blocked->status);
        $this->assertStringContainsString('Add at least one question', $blocked->body);

        $lessonId = (string) $this->db()->fetchValue('SELECT id FROM lessons');
        $this->post(self::HOST, $editUrl . '/lessons/' . $lessonId . '/questions', [
            'prompt' => 'V = 6, R = 3. What is I?',
            'options' => ['2 A', '18 A', '', ''],
            'correct' => '0',
        ]);
        $this->assertRedirectTo($editUrl, $this->post(self::HOST, $editUrl . '/publish'));

        $this->assertSame('published', $this->db()->fetchValue('SELECT status FROM courses'));
        $this->assertSame(80, (int) $this->db()->fetchValue('SELECT pass_threshold FROM assessments'));
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM question_options'));
        $this->assertStringContainsString('Basic Electricity', $this->get(self::HOST, '/courses')->body);
    }

    public function testQuestionNeedsACorrectFilledOption(): void
    {
        $created = $this->post(self::HOST, '/teach/courses', ['title' => 'Basic Electricity', 'summary' => '']);
        $editUrl = (string) $created->header('Location');
        $this->post(self::HOST, $editUrl . '/modules', ['module_title' => 'Circuits']);
        $this->post(self::HOST, $editUrl . '/lessons', [
            'module_id' => (string) $this->db()->fetchValue('SELECT id FROM course_modules'),
            'type' => 'quiz', 'lesson_title' => 'Check', 'body' => '',
        ]);
        $lessonId = (string) $this->db()->fetchValue('SELECT id FROM lessons');

        $response = $this->post(self::HOST, $editUrl . '/lessons/' . $lessonId . '/questions', [
            'prompt' => 'Which?', 'options' => ['A', 'B', '', ''], 'correct' => '3',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Mark which filled-in option is correct', $response->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM questions'));
    }

    public function testCourseSlugsStayUniqueWithinATenant(): void
    {
        $this->post(self::HOST, '/teach/courses', ['title' => 'Intro to Robotics', 'summary' => '']);
        $this->post(self::HOST, '/teach/courses', ['title' => 'Intro to Robotics', 'summary' => '']);

        $slugs = array_column($this->db()->fetchAll('SELECT slug FROM courses ORDER BY id'), 'slug');
        $this->assertSame(['intro-to-robotics', 'intro-to-robotics-2'], $slugs);
    }
}
