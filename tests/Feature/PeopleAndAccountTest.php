<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class PeopleAndAccountTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
    }

    private function signInAs(string $email, string $password = 'correct-horse-battery'): void
    {
        $this->login(self::HOST, $email, $password);
    }

    /** @return string the one-time password shown on the People page */
    private function addPerson(string $name, string $email, string $role): string
    {
        $this->assertRedirectTo('/people', $this->post(self::HOST, '/people', ['name' => $name, 'email' => $email, 'role' => $role]));
        $page = $this->get(self::HOST, '/people')->body;
        $this->assertSame(1, preg_match('#<span class="secret">([^<]+)</span>#', $page, $m), 'one-time password is shown');

        return html_entity_decode($m[1] ?? '');
    }

    public function testOwnerAddsLearnerWhoMustChooseAPasswordFirst(): void
    {
        $this->signInAs('owner@school.test');
        $otp = $this->addPerson('Tobi Learner', 'tobi@school.test', 'learner');

        // Shown once only.
        $this->assertStringNotContainsString($otp, $this->get(self::HOST, '/people')->body);

        $this->newBrowser();
        $this->signInAs('tobi@school.test', $otp);
        $this->assertRedirectTo('/account', $this->get(self::HOST, '/'));
        $this->assertRedirectTo('/account', $this->get(self::HOST, '/courses'));

        $response = $this->post(self::HOST, '/account/password', [
            'current_password' => $otp, 'new_password' => 'my own long password', 'confirm_password' => 'my own long password',
        ]);
        $this->assertRedirectTo('/', $response);
        $this->assertSame(200, $this->get(self::HOST, '/courses')->status, 'still signed in after choosing a password');

        $this->newBrowser();
        $this->signInAs('tobi@school.test', 'my own long password');
        $this->assertSame(200, $this->get(self::HOST, '/')->status);
    }

    public function testAddingSomeoneTwiceIsRefused(): void
    {
        $this->signInAs('owner@school.test');
        $this->addPerson('Tobi Learner', 'tobi@school.test', 'learner');

        $again = $this->post(self::HOST, '/people', ['name' => 'Tobi', 'email' => 'TOBI@school.test', 'role' => 'educator']);

        $this->assertSame(422, $again->status);
        $this->assertStringContainsString('already a member', $again->body);
    }

    public function testPeopleListFiltersByRoleFindsByNameAndPages(): void
    {
        $users = $this->app->get(\App\Modules\Identity\UserRepository::class);
        $members = $this->app->get(\App\Modules\Tenancy\MembershipRepository::class);
        $hash = \App\Modules\Identity\AuthService::hashPassword('correct-horse-battery');
        for ($i = 1; $i <= 57; $i++) {
            $u = $users->create(sprintf('learner%02d@school.test', $i), sprintf('Learner %02d', $i), $hash);
            $members->add($this->tenant->id, $u->id, Role::Learner);
        }
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->signInAs('owner@school.test');

        $page = $this->get(self::HOST, '/people')->body;
        $this->assertStringContainsString('59 members', $page);
        $this->assertStringContainsString('Showing 1–50 of 59', $page);
        $this->assertStringContainsString('aria-label="People pages"', $page);
        $this->assertSame(50, substr_count($page, '<tr id="person-'));

        $two = $this->get(self::HOST, '/people?page=2')->body;
        $this->assertSame(9, substr_count($two, '<tr id="person-'));
        $this->assertStringContainsString('Showing 51–59 of 59', $two);
        $this->assertSame(9, substr_count($this->get(self::HOST, '/people?page=99')->body, '<tr id="person-'), 'a page past the end shows the last one');

        $educators = $this->get(self::HOST, '/people?role=educator')->body;
        $this->assertSame(1, substr_count($educators, '<tr id="person-'));
        $this->assertStringContainsString('teacher@school.test', $educators);
        $this->assertStringContainsString('<option value="educator" selected>Educators (1)</option>', $educators);

        $found = $this->get(self::HOST, '/people?find=learner07')->body;
        $this->assertSame(1, substr_count($found, '<tr id="person-'));
        $this->assertStringContainsString('Learner 07', $found);

        $none = $this->get(self::HOST, '/people?find=' . rawurlencode('nobody_%'))->body;
        $this->assertStringContainsString('No one matches “nobody_%”.', $none);
        $this->assertStringNotContainsString('<table class="table people-table">', $none);

        $newest = $this->get(self::HOST, '/people?sort=newest&role=learner')->body;
        $this->assertLessThan(strpos($newest, 'Learner 56'), (int) strpos($newest, 'Learner 57'), 'newest first');
        $this->assertStringContainsString('href="/people?role=learner&amp;sort=newest&amp;page=2"', $newest, 'page links keep the filters');
    }

    public function testAdminCannotCreateOrManageOwners(): void
    {
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->signInAs('admin@school.test');
        $ownerId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'owner@school.test'");

        $create = $this->post(self::HOST, '/people', ['name' => 'Sneaky', 'email' => 'sneaky@school.test', 'role' => 'owner']);
        $this->assertSame(422, $create->status);

        $this->post(self::HOST, '/people/' . $ownerId . '/role', ['role' => 'learner']);
        $this->post(self::HOST, '/people/' . $ownerId . '/remove');
        $this->assertSame('owner', $this->db()->fetchValue('SELECT role FROM memberships WHERE user_id = ?', [$ownerId]));
        $this->assertSame('active', $this->db()->fetchValue('SELECT status FROM memberships WHERE user_id = ?', [$ownerId]));
    }

    public function testNobodyChangesOrRemovesThemselves(): void
    {
        $this->signInAs('owner@school.test');
        $me = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'owner@school.test'");

        $this->post(self::HOST, '/people/' . $me . '/role', ['role' => 'learner']);
        $this->post(self::HOST, '/people/' . $me . '/remove');

        $this->assertSame('owner', $this->db()->fetchValue('SELECT role FROM memberships WHERE user_id = ?', [$me]));
        $this->assertSame('active', $this->db()->fetchValue('SELECT status FROM memberships WHERE user_id = ?', [$me]));
    }

    public function testRoleChangeAndRemovalTakeEffectImmediately(): void
    {
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->signInAs('teacher@school.test');
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status);

        $teacherBrowser = $this->session;
        $this->newBrowser();
        $this->signInAs('owner@school.test');
        $this->post(self::HOST, '/people/' . $teacher->id . '/role', ['role' => 'learner']);

        $ownerBrowser = $this->session;
        $this->useBrowser($teacherBrowser);
        $this->assertSame(403, $this->get(self::HOST, '/teach')->status);

        $this->useBrowser($ownerBrowser);
        $this->post(self::HOST, '/people/' . $teacher->id . '/remove');
        $this->useBrowser($teacherBrowser);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/teach'));
    }

    public function testLearnersAndEducatorsCannotManagePeople(): void
    {
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->signInAs('teacher@school.test');

        $this->assertSame(403, $this->get(self::HOST, '/people')->status);
        $this->assertSame(403, $this->post(self::HOST, '/people', ['name' => 'X Y', 'email' => 'x@y.test', 'role' => 'learner'])->status);
        $this->assertSame(403, $this->get(self::HOST, '/activity')->status);
    }

    public function testPasswordChangeNeedsCurrentPasswordAndSignsOutOtherDevices(): void
    {
        $this->signInAs('owner@school.test');
        $first = $this->session;
        $this->newBrowser();
        $this->signInAs('owner@school.test');

        $wrong = $this->post(self::HOST, '/account/password', [
            'current_password' => 'not it', 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase',
        ]);
        $this->assertSame(422, $wrong->status);
        $this->assertStringContainsString('current password is not right', $wrong->body);

        $mismatch = $this->post(self::HOST, '/account/password', [
            'current_password' => 'correct-horse-battery', 'new_password' => 'a brand new passphrase', 'confirm_password' => 'something else',
        ]);
        $this->assertSame(422, $mismatch->status);

        $ok = $this->post(self::HOST, '/account/password', [
            'current_password' => 'correct-horse-battery', 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase',
        ]);
        $this->assertRedirectTo('/account', $ok);
        $this->assertSame(200, $this->get(self::HOST, '/account')->status, 'this device stays signed in');

        $this->useBrowser($first);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/account'), 'other device is signed out');
    }

    public function testActivityShowsReadableEventsToOwners(): void
    {
        $this->signInAs('owner@school.test');
        $this->addPerson('Tobi Learner', 'tobi@school.test', 'learner');

        $body = $this->get(self::HOST, '/activity')->body;

        $this->assertStringContainsString('added Tobi Learner as learner', $body);
        $this->assertStringContainsString('signed in', $body);
    }
}
