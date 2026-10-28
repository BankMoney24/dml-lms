<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Applications\ApplicationRepository;
use App\Modules\Identity\Role;
use App\Modules\Support\SupportRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 37: finding things in the help desk and in course applications. */
final class HelpDeskAndApplicationsListsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    public function testTheHelpDeskFindsByWordsAndKind(): void
    {
        $ada = $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $bo = $this->makeUser($this->tenant, 'bo@school.test', Role::Learner);
        $support = $this->app->get(SupportRepository::class);
        $support->create($this->tenant->id, $ada->id, 'payment', 'Receipt missing for June');
        $support->create($this->tenant->id, $bo->id, 'account', 'Cannot sign in on my phone');

        $this->as('owner@school.test');
        $all = $this->get(self::HOST, '/school/support')->body;
        $this->assertStringContainsString('Receipt missing', $all);
        $this->assertStringContainsString('Cannot sign in', $all);

        $receipt = $this->get(self::HOST, '/school/support?q=receipt')->body;
        $this->assertStringContainsString('Receipt missing', $receipt);
        $this->assertStringNotContainsString('Cannot sign in', $receipt);
        $this->assertStringContainsString('Needs an answer <span class="tab-count">(1)</span>', $receipt, 'counts follow the words');

        $byEmail = $this->get(self::HOST, '/school/support?q=bo%40school')->body;
        $this->assertStringContainsString('Cannot sign in', $byEmail);
        $account = $this->get(self::HOST, '/school/support?kind=account')->body;
        $this->assertStringNotContainsString('Receipt missing', $account);
        $this->assertStringContainsString('Nothing matches', $this->get(self::HOST, '/school/support?q=zzz-nothing')->body);
        $this->assertStringContainsString('Receipt missing', $this->get(self::HOST, '/school/support?kind=not-a-kind')->body, 'an unknown kind is ignored');
    }

    public function testApplicationsFindByNameAndCourse(): void
    {
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $one = $this->makeCourse($this->tenant, $teacher, 'robotics')['course'];
        $two = $this->makeCourse($this->tenant, $teacher, 'coding')['course'];
        $apps = $this->app->get(ApplicationRepository::class);
        $apps->create($this->tenant->id, $one->id, null, 'Chiamaka Obi', 'chi@example.org', '0803 000 0001', '');
        $apps->create($this->tenant->id, $two->id, null, 'Tunde Bello', 'tunde@example.org', '', '');

        $this->as('owner@school.test');
        $all = $this->get(self::HOST, '/teach/applications')->body;
        $this->assertStringContainsString('Chiamaka Obi', $all);
        $this->assertStringContainsString('Tunde Bello', $all);
        $chi = $this->get(self::HOST, '/teach/applications?q=chiamaka')->body;
        $this->assertStringContainsString('Chiamaka Obi', $chi);
        $this->assertStringNotContainsString('Tunde Bello', $chi);
        $coding = $this->get(self::HOST, '/teach/applications?course=' . $two->id)->body;
        $this->assertStringContainsString('Tunde Bello', $coding);
        $this->assertStringNotContainsString('Chiamaka Obi', $coding);
        $this->assertStringContainsString('href="/teach/applications?q=chiamaka&amp;show=offered"', $chi, 'the tabs keep the words');
    }

    public function testTheSetupWizardWarnsBeforeLeavingWithChanges(): void
    {
        $this->as('owner@school.test');
        $page = $this->get(self::HOST, '/setup/school');
        for ($i = 0; $i < 3 && in_array($page->status, [301, 302, 303], true); $i++) {
            $page = $this->get(self::HOST, (string) $page->header('Location'));
        }
        $this->assertSame(200, $page->status);
        $body = $page->body;
        $this->assertStringContainsString('wizard-form" novalidate data-unsaved', $body);
    }
}
