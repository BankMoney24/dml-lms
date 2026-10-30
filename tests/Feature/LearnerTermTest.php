<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 41 (ADR 0041): the school's word for the people who learn there, on every page. */
final class LearnerTermTest extends AppTestCase
{
    private const HOST = 'words.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('words', self::HOST);
        $this->makeUser($this->tenant, 'owner@words.test', Role::Owner);
        $this->newBrowser();
        $this->login(self::HOST, 'owner@words.test', 'correct-horse-battery');
    }

    public function testPagesUseTheSchoolsWord(): void
    {
        $people = $this->get(self::HOST, '/people')->body;
        $this->assertStringContainsString('>Learner</option>', $people);

        $this->db()->execute("UPDATE tenants SET learner_term = 'student' WHERE id = ?", [$this->tenant->id]);
        $people = $this->get(self::HOST, '/people')->body;
        $this->assertStringContainsString('>Student</option>', $people);
        $this->assertStringNotContainsString('>Learner</option>', $people);
        $this->assertStringContainsString('value="learner"', $people, 'form values are not words people read');

        $settings = $this->get(self::HOST, '/school')->body;
        $this->assertMatchesRegularExpression('#<dt>People who learn here</dt><dd data-own-words>Students#', $settings);

        $this->db()->execute("UPDATE tenants SET learner_term = 'pupil' WHERE id = ?", [$this->tenant->id]);
        $this->assertStringContainsString('>Pupil</option>', $this->get(self::HOST, '/people')->body);
    }
}
