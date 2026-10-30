<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

final class SchoolSetupTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const W = '/setup/school';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        // Tenant ids restart after each test's truncation; clear logos left by earlier tests.
        foreach (glob(sys_get_temp_dir() . '/dml-lms-test-storage/uploads/*/brand/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** @param array<string, mixed> $data */
    private function step(string $step, array $data = [], string $action = 'continue'): Response
    {
        return $this->post(self::HOST, self::W . '/' . $step, $data + ['action' => $action]);
    }

    private function school(): Tenant
    {
        return $this->app->get(TenantRepository::class)->findById($this->tenant->id) ?? throw new \LogicException();
    }

    /** A real PNG of the given size, built without GD. */
    private static function png(int $width = 64, int $height = 32): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $raw = str_repeat("\0" . str_repeat("\x1d\x5f\xb8", $width), $height);

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
    }

    /** Answers welcome .. features with sensible values; leaves the run at the people step. */
    private function answerUpToPeople(): void
    {
        $this->step('welcome');
        $this->step('type', ['kind' => 'school']);
        $this->step('structure', ['calendar' => 'terms', 'levels' => "JSS 1\nJSS 2\n\nJSS 2\nJSS 3", 'group_term' => 'stream']);
        $this->step('brand', [
            'name' => 'Sunrise College', 'tagline' => 'Learn by doing', 'theme' => 'blue',
            'contact_email' => 'Office@Sunrise.test', 'contact_phone' => '', 'website' => 'sunrise.test',
        ]);
        $this->step('features', ['certificates' => '1', 'public_catalogue' => '1']);
    }

    public function testOwnersAreAskedToSetUpTheSchoolAndOthersAreNot(): void
    {
        $this->as('owner@school.test');
        $this->assertStringContainsString('Finish setting up your school', $this->get(self::HOST, '/')->body);
        $this->assertStringContainsString('href="/school"', $this->get(self::HOST, '/')->body);

        $this->as('teacher@school.test');
        $this->assertStringNotContainsString('Finish setting up', $this->get(self::HOST, '/')->body);
        $this->assertSame(404, $this->get(self::HOST, self::W)->status, 'educators cannot run the wizard');
        $this->assertSame(404, $this->post(self::HOST, self::W . '/type', ['kind' => 'school', 'action' => 'continue'])->status);
        $this->assertSame(403, $this->get(self::HOST, '/school')->status);

        $this->as('learner@school.test');
        $this->assertSame(404, $this->get(self::HOST, self::W . '/welcome')->status);
    }

    public function testStepsOpenInOrderAndUnknownOnesDoNot(): void
    {
        $this->as('owner@school.test');
        $this->assertRedirectTo(self::W . '/welcome', $this->get(self::HOST, self::W));
        $this->assertRedirectTo(self::W . '/welcome', $this->get(self::HOST, self::W . '/brand'), 'cannot jump ahead');
        $this->assertSame(404, $this->get(self::HOST, self::W . '/billing')->status);
        $this->assertSame(404, $this->get(self::HOST, '/setup/nothing')->status);

        $this->assertRedirectTo(self::W . '/type', $this->step('welcome'));
        $bad = $this->step('type', ['kind' => 'castle']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Choose the type that fits best', $bad->body);
        $this->assertRedirectTo(self::W . '/structure', $this->step('type', ['kind' => 'university']));

        $structure = $this->get(self::HOST, self::W . '/structure')->body;
        $this->assertStringContainsString('100 Level', $structure, 'the type suggests levels');
        $this->assertStringContainsString('value="semesters" checked', $structure);
        $this->assertStringContainsString('value="group" checked', $structure, 'a university calls them groups by default');
    }

    public function testFullSetupAppliesEverythingOnlyAfterConfirmation(): void
    {
        $this->as('owner@school.test');
        $this->step('welcome');
        $this->step('type', ['kind' => 'school']);
        $this->assertSame(422, $this->step('structure', ['calendar' => 'terms', 'learner_term' => 'scholar'])->status, 'only the offered words');
        $this->step('structure', ['calendar' => 'terms', 'levels' => "JSS 1\nJSS 2\n\nJSS 2\nJSS 3", 'group_term' => 'stream', 'learner_term' => 'student']);
        $brand = $this->upload(self::HOST, self::W . '/brand', [
            'action' => 'continue', 'name' => 'Sunrise College', 'tagline' => 'Learn by doing', 'theme' => 'blue',
            'contact_email' => 'Office@Sunrise.test', 'contact_phone' => '+234 801 234 5678', 'website' => 'sunrise.test',
        ], ['logo' => [$this->fakeUpload('logo.png', self::png())]]);
        $this->assertRedirectTo(self::W . '/features', $brand);
        $this->step('features', ['certificates' => '0', 'public_catalogue' => '0', 'packages' => ['linux-server-administration']]);
        $this->assertRedirectTo(self::W . '/review', $this->step('people', ['people' => "Ngozi Eze, ngozi@sunrise.test, admin\nKemi Ade; kemi@sunrise.test; teacher"]));

        // Nothing has changed yet.
        $this->assertSame('School Academy', $this->school()->name);
        $this->assertFalse($this->school()->isSetUp());
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM users WHERE email = 'ngozi@sunrise.test'"));

        $review = $this->get(self::HOST, self::W . '/review')->body;
        $expected = ['Sunrise College', 'JSS 1, JSS 2, JSS 3', 'https://sunrise.test', 'office@sunrise.test',
            'Linux Server Administration (added as drafts)', '1 admin, 1 educator', 'Launch your school', 'Streams', 'Students'];
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $review);
        }

        $this->assertRedirectTo('/school', $this->step('review', [], 'finish'));
        $school = $this->school();
        $this->assertSame(['Sunrise College', 'school', 'blue', 'terms', ['JSS 1', 'JSS 2', 'JSS 3'], 'office@sunrise.test', 'https://sunrise.test'], [
            $school->name, $school->kind, $school->theme, $school->calendar, $school->levels, $school->contactEmail, $school->website,
        ]);
        $this->assertFalse($school->certificatesEnabled);
        $this->assertFalse($school->publicCatalogue);
        $this->assertSame('Streams', $school->groupWord(true, true));
        $this->assertSame('Students', $school->learnerWord(true, true));
        $this->assertTrue($school->isSetUp());
        $this->assertTrue($school->hasLogo());
        $this->assertSame('image/png', $school->logoType);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM wizard_runs'), 'the run is gone once applied');

        $course = $this->db()->fetchOne("SELECT status FROM courses WHERE tenant_id = ? AND title = 'Linux Server Administration'", [$this->tenant->id]);
        $this->assertSame('draft', $course['status'] ?? null, 'starter courses arrive as drafts');
        $this->assertSame(['admin', 'educator'], array_column($this->db()->fetchAll(
            "SELECT m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND u.email IN ('ngozi@sunrise.test', 'kemi@sunrise.test') ORDER BY u.email DESC",
            [$this->tenant->id],
        ), 'role'));

        // Without email, one-time passwords are shown exactly once.
        $page = $this->get(self::HOST, '/school')->body;
        $this->assertStringContainsString('Your school is ready', $page);
        $this->assertSame(2, preg_match_all('#<span class="secret">[A-Za-z0-9]{4}(-[A-Za-z0-9]{4}){3}</span>#', $page));
        $this->assertStringNotContainsString('class="secret"', $this->get(self::HOST, '/school')->body);

        // Completion is logged without what was typed.
        $event = $this->db()->fetchOne("SELECT metadata FROM audit_events WHERE action = 'wizard.finished'");
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('sunrise', strtolower((string) $event['metadata']));
    }

    public function testTheSchoolsLookAndSwitchesReachEveryone(): void
    {
        $this->as('owner@school.test');
        $this->step('welcome');
        $this->step('type', ['kind' => 'school']);
        $this->step('structure', [], 'skip');
        $this->upload(self::HOST, self::W . '/brand', [
            'action' => 'continue', 'name' => 'Sunrise College', 'tagline' => 'Learn by doing', 'theme' => 'plum',
            'contact_email' => 'office@sunrise.test', 'contact_phone' => '', 'website' => '',
        ], ['logo' => [$this->fakeUpload('logo.png', self::png())]]);
        $this->step('features', ['certificates' => '1', 'public_catalogue' => '0']);
        $this->step('people', [], 'skip');
        $this->step('review', [], 'finish');

        $this->newBrowser();
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('<body class="theme-plum">', $home);
        $this->assertStringContainsString('Learn by doing', $home);
        $this->assertStringContainsString('mailto:office@sunrise.test', $home);
        $this->assertStringContainsString('src="/logo?v=', $home);
        $this->assertStringNotContainsString('href="/courses"', $home, 'course list is for members only');
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/courses'));

        $logo = $this->get(self::HOST, '/logo');
        $this->assertSame(200, $logo->status);
        $this->assertSame('image/png', $logo->header('Content-Type'));
        $this->assertSame('public, max-age=604800', $logo->header('Cache-Control'));
        $this->assertSame(self::png(), (string) file_get_contents((string) $logo->filePath()));
        $this->assertSame(404, $this->get('other.test', '/logo')->status);

        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->assertSame(200, $this->get(self::HOST, '/courses')->status, 'members still see courses');
    }

    public function testEveryInvitationLineIsCheckedBeforeAnyoneIsContacted(): void
    {
        $this->as('owner@school.test');
        $this->answerUpToPeople();

        $bad = $this->step('people', ['people' => "Ngozi Eze, ngozi@sunrise.test, admin\nnot a line\nTwin, ngozi@sunrise.test\nTeacher, teacher@school.test\nBoss, boss@sunrise.test, owner"]);
        $this->assertSame(422, $bad->status);
        $messages = [
            'Line 2: write it as name, email, role.',
            'Line 3: ngozi@sunrise.test is already on line 1.',
            'Line 4: teacher@school.test is already a member here.',
            'Line 5: the role should be admin, educator or learner.',
        ];
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $bad->body);
        }
        $this->assertStringContainsString('not a line', $bad->body, 'what was typed is kept');
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM users WHERE email LIKE '%@sunrise.test'"));
    }

    public function testBrandRejectsFakePicturesAndBadContactDetails(): void
    {
        $this->as('owner@school.test');
        $this->step('welcome');
        $this->step('type', ['kind' => 'other']);
        $this->step('structure', [], 'skip');

        $fake = $this->upload(self::HOST, self::W . '/brand', ['action' => 'continue', 'name' => 'Sunrise', 'theme' => 'blue'], ['logo' => [$this->fakeUpload('logo.png', '<?php echo 1;')]]);
        $this->assertSame(422, $fake->status);
        $this->assertStringContainsString('Use a PNG, JPEG or WebP picture', $fake->body);

        $svgBytes = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $svg = $this->upload(self::HOST, self::W . '/brand', ['action' => 'continue', 'name' => 'Sunrise', 'theme' => 'blue'], ['logo' => [$this->fakeUpload('logo.svg', $svgBytes)]]);
        $this->assertSame(422, $svg->status);

        $tiny = $this->upload(self::HOST, self::W . '/brand', ['action' => 'continue', 'name' => 'Sunrise', 'theme' => 'blue'], ['logo' => [$this->fakeUpload('logo.png', self::png(8, 8))]]);
        $this->assertStringContainsString('between 32 and 4000 pixels', $tiny->body);

        $bad = $this->step('brand', ['name' => 'S', 'theme' => 'rainbow', 'contact_email' => 'nope', 'contact_phone' => 'call me', 'website' => 'javascript:alert(1)']);
        $this->assertSame(422, $bad->status);
        foreach (['name', 'theme', 'contact_email', 'contact_phone', 'website'] as $field) {
            $this->assertStringContainsString('id="' . $field . '-error"', $bad->body, $field);
        }
        $this->assertSame([], glob(sys_get_temp_dir() . '/dml-lms-test-storage/uploads/' . $this->tenant->id . '/brand/*') ?: [], 'refused pictures are not stored');
    }

    public function testSaveAndExitResumesAndDiscardRemovesThePendingLogo(): void
    {
        $this->as('owner@school.test');
        $this->step('welcome');
        $this->step('type', ['kind' => 'polytechnic']);
        $this->step('structure', [], 'skip');
        $saved = $this->upload(self::HOST, self::W . '/brand', ['action' => 'save', 'name' => 'Sunrise Poly', 'theme' => 'green'], ['logo' => [$this->fakeUpload('logo.png', self::png())]]);
        $this->assertRedirectTo('/school', $saved);

        // Another admin, another browser, picks up where it stopped.
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->as('admin@school.test');
        $this->assertRedirectTo(self::W . '/brand', $this->get(self::HOST, self::W));
        $brand = $this->get(self::HOST, self::W . '/brand')->body;
        $this->assertStringContainsString('value="Sunrise Poly"', $brand);
        $this->assertStringContainsString('/school/logo-preview?v=', $brand);
        $this->assertSame(200, $this->get(self::HOST, '/school/logo-preview')->status);
        $this->assertStringContainsString('Setup in progress', $this->get(self::HOST, '/school')->body);
        $pending = glob(sys_get_temp_dir() . '/dml-lms-test-storage/uploads/' . $this->tenant->id . '/brand/*') ?: [];
        $this->assertCount(1, $pending);

        $this->assertRedirectTo('/school', $this->post(self::HOST, self::W . '/discard'));
        $this->assertFileDoesNotExist($pending[0]);
        $this->assertSame('School Academy', $this->school()->name, 'nothing was applied');
        $this->assertRedirectTo(self::W . '/welcome', $this->get(self::HOST, self::W));
    }

    public function testChangingSettingsLaterStartsFromWhatTheSchoolHas(): void
    {
        $this->as('owner@school.test');
        $this->answerUpToPeople();
        $this->step('people', [], 'skip');
        $this->step('review', [], 'finish');

        // Looking at a step is not an unsaved change.
        $this->assertSame(200, $this->get(self::HOST, self::W . '/features')->status);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM wizard_runs'));
        $this->assertStringNotContainsString('unsaved changes', $this->get(self::HOST, '/school')->body);

        // Any step opens directly, filled in from the school's settings.
        $brand = $this->send(new \App\Core\Http\Request('GET', self::W . '/brand', self::HOST, ['return' => 'review']));
        $this->assertSame(200, $brand->status);
        $this->assertStringContainsString('value="Sunrise College"', $brand->body);
        $this->assertStringContainsString('name="return" value="review"', $brand->body);
        $this->assertRedirectTo(self::W . '/review', $this->step('brand', [
            'name' => 'Sunrise College Lagos', 'theme' => 'navy', 'contact_email' => 'office@sunrise.test', 'return' => 'review',
        ]));
        $review = $this->get(self::HOST, self::W . '/review')->body;
        $this->assertStringContainsString('Save changes', $review);
        $this->assertStringContainsString('JSS 1, JSS 2, JSS 3', $review, 'untouched steps show current settings');

        $this->step('review', [], 'finish');
        $school = $this->school();
        $this->assertSame(['Sunrise College Lagos', 'navy', ['JSS 1', 'JSS 2', 'JSS 3']], [$school->name, $school->theme, $school->levels]);
        $this->assertStringContainsString('Settings saved', $this->get(self::HOST, '/school')->body);
    }

    public function testCertificatesSwitchedOffAreNotIssued(): void
    {
        $this->db()->execute('UPDATE tenants SET certificates_enabled = 0 WHERE id = ?', [$this->tenant->id]);
        $teacher = $this->app->get(\App\Modules\Identity\UserRepository::class)->findByEmail('teacher@school.test') ?? throw new \LogicException();
        $fixture = $this->makeCourse($this->tenant, $teacher);

        $this->as('learner@school.test');
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, '/courses/physics-101/lessons/' . $fixture['reading']->id . '/complete');
        $this->post(self::HOST, '/courses/physics-101/lessons/' . $fixture['quiz']->id . '/attempts', [
            'answers' => $this->answers($fixture['assessment_id'], true), 'idempotency_key' => bin2hex(random_bytes(16)),
        ]);

        $this->assertSame('completed', $this->db()->fetchValue('SELECT status FROM enrolments'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM certificates'));
        $this->assertStringNotContainsString('Your certificate is ready', $this->get(self::HOST, '/courses/physics-101')->body);
    }
}
