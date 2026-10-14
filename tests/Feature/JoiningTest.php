<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Joining\ProfileRepository;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

/** ADR 0020: signing up, the welcome questions, requests to teach or follow a child, and the code playground. */
final class JoiningTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const PASSWORD = 'a long enough passphrase';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
    }

    private function joining(string $mode, ?string $code = null, bool $parents = true): void
    {
        $this->app->get(TenantRepository::class)->updateJoining($this->tenant->id, $mode, $code, $parents);
    }

    /** @param array<string, string> $overrides */
    private function signUp(array $overrides = []): Response
    {
        return $this->post(self::HOST, '/join', $overrides + [
            'name' => 'Chidi Okafor', 'email' => 'chidi@example.test', 'password' => self::PASSWORD,
            'code' => '', 'terms' => '1', 'age' => '1', 'website' => '',
        ]);
    }

    private function as(string $email, string $password = 'correct-horse-battery'): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, $password));
    }

    private function role(string $email): ?string
    {
        $value = $this->db()->fetchValue(
            'SELECT m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND u.email = ? AND m.status = \'active\'',
            [$this->tenant->id, $email],
        );

        return $value === null ? null : (string) $value;
    }

    private function userCount(): int
    {
        return (int) $this->db()->fetchValue('SELECT COUNT(*) FROM users');
    }

    public function testSignUpIsClosedUntilTheSchoolOpensIt(): void
    {
        $page = $this->get(self::HOST, '/join');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('there is no sign-up form here', $page->body);
        $this->assertStringContainsString('noindex', $page->body);
        $this->assertStringNotContainsString('href="/join"', $this->get(self::HOST, '/login')->body);

        $before = $this->userCount();
        $refused = $this->signUp();
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('there is no sign-up form here', $refused->body);
        $this->assertSame($before, $this->userCount());
    }

    public function testOpenSignUpMakesALearnerAndStartsTheWelcomeQuestions(): void
    {
        $this->joining('open');
        $this->assertStringContainsString('Create an account', $this->get(self::HOST, '/login')->body);
        $page = $this->get(self::HOST, '/join');
        $this->assertStringContainsString('Create your account', $page->body);
        $this->assertStringNotContainsString('noindex', $page->body, 'an open join page may be listed');

        $this->assertRedirectTo('/setup/welcome', $this->signUp(['email' => 'Chidi@Example.test']));
        $this->assertSame('learner', $this->role('chidi@example.test'), 'people who sign up are always learners');
        $user = $this->app->get(UserRepository::class)->findByEmail('chidi@example.test');
        $this->assertNotNull($user);
        $this->assertFalse($user->emailVerified);
        $profile = $this->app->get(ProfileRepository::class)->find($this->tenant->id, $user->id);
        $this->assertNotNull($profile);
        $this->assertSame('self', $profile->joinedVia);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'member.self_registered'"));

        // Signed in straight away; the home page offers the questions until answered or dismissed.
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('What do you want to learn?', $home);
        $this->assertRedirectTo('/', $this->post(self::HOST, '/welcome/skip'));
        $this->assertStringNotContainsString('What do you want to learn?', $this->get(self::HOST, '/')->body);
        $this->assertRedirectTo('/', $this->get(self::HOST, '/join'), 'signed-in people skip the join page');
    }

    public function testSignUpChecksEverythingAndIgnoresBots(): void
    {
        $this->joining('open');
        $before = $this->userCount();

        $bad = $this->signUp(['name' => 'X', 'email' => 'not-an-email', 'password' => 'short', 'terms' => '0', 'age' => '0']);
        $this->assertSame(422, $bad->status);
        foreach (['Enter your full name', 'Enter an email address', 'agree to the school', '18 or older'] as $message) {
            $this->assertStringContainsString($message, $bad->body);
        }
        $this->assertStringContainsString('value="X"', $bad->body, 'what they typed is kept');

        $weak = $this->signUp(['password' => 'short']);
        $this->assertSame(422, $weak->status);
        $this->assertStringContainsString('Choose a password', $weak->body);

        $this->assertRedirectTo('/join', $this->signUp(['website' => 'http://spam.test']), 'the hidden field catches bots');
        $this->assertSame($before, $this->userCount());
    }

    public function testJoinCodeIsRequiredAndForgivingAboutFormat(): void
    {
        $this->joining('code', 'K7PQ2XMA');
        $this->assertStringContainsString('value="K7PQ-2XMA"', $this->send(new Request('GET', '/join', self::HOST, ['code' => 'K7PQ-2XMA']))->body);

        $wrong = $this->signUp(['code' => 'AAAA-BBBB']);
        $this->assertSame(422, $wrong->status);
        $this->assertStringContainsString('That join code is not right', $wrong->body);
        $this->assertSame(422, $this->signUp(['code' => ''])->status);

        $this->assertRedirectTo('/setup/welcome', $this->signUp(['code' => ' k7pq-2xma ']));
        $this->assertSame('learner', $this->role('chidi@example.test'));
    }

    public function testCodeModeWithoutACodeLetsNobodyIn(): void
    {
        $this->joining('code', null);
        $this->assertSame(422, $this->signUp(['code' => ''])->status);
        $this->assertNull($this->role('chidi@example.test'));
    }

    public function testExistingAccountJoinsWithItsOwnPasswordButRemovedMembersCannotReturn(): void
    {
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'ada@example.test', Role::Learner, self::PASSWORD);
        $this->joining('open');

        $wrong = $this->signUp(['email' => 'ada@example.test', 'password' => 'not the right one at all']);
        $this->assertSame(422, $wrong->status);
        $this->assertNull($this->role('ada@example.test'));

        $this->assertRedirectTo('/setup/welcome', $this->signUp(['email' => 'ada@example.test', 'name' => 'Someone Else']));
        $this->assertSame('learner', $this->role('ada@example.test'));
        $user = $this->app->get(UserRepository::class)->findByEmail('ada@example.test');
        $this->assertNotNull($user);
        $this->assertNotSame('Someone Else', $user->displayName, 'joining never renames an existing account');

        $again = $this->signUp(['email' => 'ada@example.test']);
        $this->assertSame(422, $again->status, 'already a member');

        $this->app->get(MembershipRepository::class)->remove($this->tenant->id, $user->id);
        $this->newBrowser();
        $removed = $this->signUp(['email' => 'ada@example.test']);
        $this->assertSame(422, $removed->status);
        $this->assertStringContainsString('closed by the school', $removed->body);
        $this->assertNull($this->role('ada@example.test'));
    }

    public function testEmailConfirmationLinkConfirmsButCannotSetAPassword(): void
    {
        $this->configure('MAIL_DRIVER', 'file');
        $this->joining('open');
        $this->signUp();

        $mail = $this->deliverMail();
        $this->assertCount(1, $mail);
        $this->assertSame('chidi@example.test', $mail[0]->toEmail);
        $link = $this->linkIn($mail[0], '/account/confirm/');
        $token = substr($link, strlen('/account/confirm/'));

        $this->assertStringContainsString('Please confirm', $this->get(self::HOST, '/')->body);
        $this->assertSame(410, $this->get(self::HOST, '/password/reset/' . $token)->status, 'a confirmation link is not a reset link');

        // Unconfirmed people cannot ask to teach yet.
        $early = $this->post(self::HOST, '/join/teach', ['subjects' => 'Python', 'experience' => str_repeat('I teach coding. ', 3), 'link' => '']);
        $this->assertSame(422, $early->status);
        $this->assertStringContainsString('Confirm your email address first', $early->body);

        $this->assertRedirectTo('/account', $this->get(self::HOST, $link));
        $user = $this->app->get(UserRepository::class)->findByEmail('chidi@example.test');
        $this->assertNotNull($user);
        $this->assertTrue($user->emailVerified);
        $this->assertStringNotContainsString('Please confirm', $this->get(self::HOST, '/')->body);

        $this->get(self::HOST, $link);
        $this->assertStringContainsString('was already used', $this->get(self::HOST, '/account')->body, 'the link works once');
    }

    public function testWelcomeQuestionsSaveTheProfileAndOrderSuggestions(): void
    {
        $courses = $this->app->get(CourseRepository::class);
        $teacher = $this->app->get(UserRepository::class)->findByEmail('teacher@school.test');
        $this->assertNotNull($teacher);
        $python = $courses->create($this->tenant->id, $teacher->id, 'python-basics', 'Python programming basics', 'Write your first programs.');
        $courses->markPublished($this->tenant->id, $python->id);
        $cooking = $courses->create($this->tenant->id, $teacher->id, 'baking', 'Baking bread', 'Flour, water, salt.');
        $courses->markPublished($this->tenant->id, $cooking->id);

        $this->joining('open');
        $this->signUp();
        $this->assertSame(200, $this->get(self::HOST, '/setup/welcome/goals')->status);

        $none = $this->post(self::HOST, '/setup/welcome/goals', ['action' => 'continue']);
        $this->assertSame(422, $none->status);
        $this->assertStringContainsString('Choose at least one', $none->body);
        $this->assertRedirectTo('/setup/welcome/experience', $this->post(self::HOST, '/setup/welcome/goals', ['goals' => ['code', 'security'], 'action' => 'continue']));

        $badGithub = $this->post(self::HOST, '/setup/welcome/experience', ['experience' => 'new', 'github' => 'not a handle!', 'portfolio' => '', 'action' => 'continue']);
        $this->assertSame(422, $badGithub->status);
        $this->assertRedirectTo('/setup/welcome/rhythm', $this->post(self::HOST, '/setup/welcome/experience', [
            'experience' => 'new', 'github' => 'https://github.com/chidi-dev', 'portfolio' => '', 'action' => 'continue',
        ]));
        $this->assertRedirectTo('/setup/welcome/review', $this->post(self::HOST, '/setup/welcome/rhythm', ['weekly_goal' => '3', 'action' => 'continue']));
        $this->assertRedirectTo('/?welcome=1', $this->post(self::HOST, '/setup/welcome/review', ['action' => 'finish']));

        $user = $this->app->get(UserRepository::class)->findByEmail('chidi@example.test');
        $this->assertNotNull($user);
        $profile = $this->app->get(ProfileRepository::class)->find($this->tenant->id, $user->id);
        $this->assertNotNull($profile);
        $this->assertSame(['code', 'security'], $profile->goals);
        $this->assertSame('new', $profile->experience);
        $this->assertSame(3, $profile->weeklyGoal);
        $this->assertSame('chidi-dev', $profile->github, 'a GitHub link is reduced to the username');
        $this->assertNotNull($profile->onboardedAt);

        $home = $this->send(new Request('GET', '/', self::HOST, ['welcome' => '1']))->body;
        $this->assertStringContainsString('Suggested for you', $home);
        $this->assertStringContainsString('For you: Learn to code', $home);
        $this->assertLessThan(strpos($home, 'Baking bread') ?: PHP_INT_MAX, strpos($home, 'Python programming basics'), 'the fitting course comes first');
        $this->assertStringContainsString('Your weekly goal is 3 days', $home);
        $this->assertStringNotContainsString('What do you want to learn?', $home);

        // Teachers see the learner's GitHub on the People page.
        $this->as('owner@school.test');
        $this->assertStringContainsString('https://github.com/chidi-dev', $this->get(self::HOST, '/people')->body);
    }

    public function testEveryoneHasTheirOwnWelcomeRun(): void
    {
        $this->joining('open');
        $this->signUp();
        $this->post(self::HOST, '/setup/welcome/goals', ['goals' => ['data'], 'action' => 'continue']);

        $this->newBrowser();
        $this->signUp(['email' => 'bisi@example.test', 'name' => 'Bisi Ade']);
        $goals = $this->get(self::HOST, '/setup/welcome/goals');
        $this->assertSame(200, $goals->status, 'a second learner starts at the beginning');
        $this->assertStringNotContainsString('value="data" checked', $goals->body);
    }

    public function testAskingToTeachAndTheSchoolsDecision(): void
    {
        $this->makeUser($this->tenant, 'kemi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);

        $this->as('kemi@school.test');
        $this->assertStringContainsString('Ask to teach here', $this->get(self::HOST, '/account')->body);
        $short = $this->post(self::HOST, '/join/teach', ['subjects' => 'Python', 'experience' => 'Short.', 'link' => 'ftp://x']);
        $this->assertSame(422, $short->status);
        $this->assertRedirectTo('/join/teach', $this->post(self::HOST, '/join/teach', [
            'subjects' => 'Python for beginners', 'experience' => 'I have taught coding clubs for three years.', 'link' => 'https://kemi.example.test',
        ]));
        $this->assertSame(422, $this->post(self::HOST, '/join/teach', ['subjects' => 'More', 'experience' => str_repeat('Again and again. ', 3), 'link' => ''])->status, 'one request at a time');

        $this->as('tobi@school.test');
        $this->post(self::HOST, '/join/teach', ['subjects' => 'Maths', 'experience' => 'Tutoring my classmates every week.', 'link' => '']);

        $this->as('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/joining')->status, 'educators do not decide');

        $this->as('owner@school.test');
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'join.request'"));
        $page = $this->get(self::HOST, '/school/joining')->body;
        $this->assertStringContainsString('2 waiting', $page);
        $this->assertStringContainsString('Python for beginners', $page);
        $this->assertMatchesRegularExpression('#href="/school/joining"[^>]*>.*?<span[^>]*>2<#s', $page, 'the menu shows the count');

        $ids = array_map('intval', array_column($this->db()->fetchAll('SELECT id FROM join_requests ORDER BY id'), 'id'));
        $this->assertRedirectTo('/school/joining', $this->post(self::HOST, '/school/joining/requests/' . $ids[0] . '/approve'));
        $this->assertSame('educator', $this->role('kemi@school.test'));
        $this->post(self::HOST, '/school/joining/requests/' . $ids[1] . '/decline');
        $this->assertSame('learner', $this->role('tobi@school.test'));
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'join.decision'"));
        $this->post(self::HOST, '/school/joining/requests/' . $ids[1] . '/approve');
        $this->assertSame('learner', $this->role('tobi@school.test'), 'a decided request stays decided');

        $this->as('kemi@school.test');
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status);
    }

    public function testParentsCanAskAndTheSchoolCanTurnThatOff(): void
    {
        $this->assertSame(200, $this->get(self::HOST, '/join/parent')->status);
        $bad = $this->post(self::HOST, '/join/parent', ['name' => '', 'email' => 'x', 'child' => '', 'relationship' => 'pirate', 'phone' => 'abc', 'message' => '', 'website' => '']);
        $this->assertSame(422, $bad->status);

        $form = [
            'name' => 'Ngozi Eze', 'email' => 'ngozi@example.test', 'phone' => '0803 123 4567', 'child' => 'Ada Eze',
            'relationship' => 'mother', 'message' => 'Please', 'agree' => '1', 'website' => '',
        ];
        $this->assertRedirectTo('/join/parent?sent=1', $this->post(self::HOST, '/join/parent', $form));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM join_requests WHERE kind = 'guardian' AND child_name = 'Ada Eze'"));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM guardian_links'), 'nothing is linked automatically');

        for ($i = 0; $i < 3; $i++) {
            $this->post(self::HOST, '/join/parent', $form);
        }
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM join_requests WHERE kind = 'guardian'"), 'three a day per email address');

        $this->joining('closed', null, false);
        $this->assertSame(404, $this->get(self::HOST, '/join/parent')->status);
        $this->assertSame(404, $this->post(self::HOST, '/join/parent', $form)->status);
    }

    public function testOnlySchoolManagersChangeTheJoiningSettings(): void
    {
        $this->makeUser($this->tenant, 'kemi@school.test', Role::Learner);
        $this->as('kemi@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/school/joining/settings', ['registration' => 'open'])->status);
        $this->assertSame(403, $this->get(self::HOST, '/school/joining')->status);

        $this->as('owner@school.test');
        $this->assertRedirectTo('/school/joining', $this->post(self::HOST, '/school/joining/settings', ['registration' => 'code', 'parent_requests' => '1']));
        $school = $this->app->get(TenantRepository::class)->findById($this->tenant->id);
        $this->assertNotNull($school);
        $this->assertSame('code', $school->registration);
        $this->assertNotNull($school->joinCode);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $school->joinCode);

        $this->post(self::HOST, '/school/joining/code');
        $changed = $this->app->get(TenantRepository::class)->findById($this->tenant->id);
        $this->assertNotNull($changed);
        $this->assertNotSame($school->joinCode, $changed->joinCode);

        $this->post(self::HOST, '/school/joining/settings', ['registration' => 'nonsense']);
        $closed = $this->app->get(TenantRepository::class)->findById($this->tenant->id);
        $this->assertNotNull($closed);
        $this->assertSame('closed', $closed->registration);
        $this->assertFalse($closed->parentRequests);
    }

    public function testPlaygroundSnippetsArePrivateAndLimited(): void
    {
        $this->makeUser($this->tenant, 'kemi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);

        $this->as('kemi@school.test');
        $page = $this->get(self::HOST, '/playground');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('sandbox="allow-scripts"', $page->body);
        $this->assertStringNotContainsString('allow-same-origin', $page->body);

        $this->assertRedirectTo('/playground', $this->post(self::HOST, '/playground', ['title' => 'Empty', 'code' => "  \n"]));
        $saved = $this->post(self::HOST, '/playground', ['title' => 'Subnets', 'code' => "console.log('<b>hi</b>');\nconsole.log(2);"]);
        $id = (int) $this->db()->fetchValue('SELECT id FROM code_snippets');
        $this->assertRedirectTo('/playground?snippet=' . $id, $saved);
        $open = $this->send(new Request('GET', '/playground', self::HOST, ['snippet' => (string) $id]))->body;
        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $open, 'code is shown as text');
        $this->assertStringContainsString('2 lines', $open);

        $this->as('tobi@school.test');
        $this->assertSame(404, $this->send(new Request('GET', '/playground', self::HOST, ['snippet' => (string) $id]))->status);
        $this->post(self::HOST, '/playground', ['id' => (string) $id, 'title' => 'Mine now', 'code' => 'x']);
        $this->post(self::HOST, '/playground/' . $id . '/delete');
        $this->assertSame('Subnets', (string) $this->db()->fetchValue('SELECT title FROM code_snippets WHERE id = ?', [$id]), 'nobody else can change or delete it');

        $tobi = $this->app->get(UserRepository::class)->findByEmail('tobi@school.test');
        $this->assertNotNull($tobi);
        for ($i = 1; $i < 50; $i++) {
            $this->db()->execute(
                'INSERT INTO code_snippets (tenant_id, user_id, title, code, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$this->tenant->id, $tobi->id, 'S' . $i, 'x'],
            );
        }
        $this->assertSame(50, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM code_snippets WHERE user_id = ?', [$tobi->id]), 'his own "Mine now" plus 49');
        $this->post(self::HOST, '/playground', ['title' => 'One more', 'code' => 'y']);
        $this->assertSame(50, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM code_snippets WHERE user_id = ?', [$tobi->id]));
    }
}
