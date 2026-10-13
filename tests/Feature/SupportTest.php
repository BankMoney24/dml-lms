<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Help requests (SUP-001): ask, answer, track, with safe attachments and tenant-aware access. */
final class SupportTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    private function ask(string $subject = 'Cannot open the subnet lab'): int
    {
        $response = $this->upload(self::HOST, '/help', [
            'category' => 'course', 'subject' => $subject, 'body' => 'The page stays blank after I press Start.',
        ], ['attachments' => [$this->fakeUpload('screen.pdf', self::pdfBytes('blank page'))]]);
        $this->assertSame(303, $response->status);
        $this->assertSame(1, preg_match('#^/help/(\d+)$#', (string) $response->header('Location'), $m));

        return (int) ($m[1] ?? 0);
    }

    /** @return list<string> */
    private function notificationsFor(string $email): array
    {
        return array_map('strval', array_column($this->db()->fetchAll(
            'SELECT n.title FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = ? ORDER BY n.id',
            [$email],
        ), 'title'));
    }

    public function testALearnerAsksAndTheSchoolAnswers(): void
    {
        $this->signIn('tobi@school.test');
        $this->assertStringContainsString('href="/help"', $this->get(self::HOST, '/')->body, 'Get help in the footer');
        $id = $this->ask();
        $thread = $this->get(self::HOST, '/help/' . $id)->body;
        $this->assertStringContainsString('Cannot open the subnet lab', $thread);
        $this->assertStringContainsString('Waiting for the school', $thread);
        $this->assertStringContainsString('screen.pdf', $thread);
        $file = (int) $this->db()->fetchValue('SELECT id FROM files WHERE support_message_id IS NOT NULL');
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $file)->status);
        $this->assertSame(['New help request: Cannot open the subnet lab'], $this->notificationsFor('admin@school.test'));
        $this->assertSame([], $this->notificationsFor('teacher@school.test'), 'only support staff are told');

        $this->signIn('ada@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/help/' . $id)->status, 'someone else\'s request');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $file)->status);
        $this->assertSame(404, $this->post(self::HOST, '/help/' . $id . '/reply', ['body' => 'Me too'])->status);

        $this->signIn('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/support')->status);
        $this->assertSame(404, $this->get(self::HOST, '/help/' . $id)->status);

        $this->signIn('admin@school.test');
        $desk = $this->get(self::HOST, '/school/support')->body;
        $this->assertStringContainsString('Needs an answer (1)', $desk);
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $file)->status, 'staff open attachments');
        $this->assertRedirectTo('/help/' . $id, $this->post(self::HOST, '/help/' . $id . '/reply', ['body' => 'Try the latest Chrome; we fixed it.']));
        $this->assertSame('answered', $this->db()->fetchValue('SELECT status FROM support_requests WHERE id = ?', [$id]));
        $this->assertContains('The school answered: Cannot open the subnet lab', $this->notificationsFor('tobi@school.test'));

        $this->signIn('tobi@school.test');
        $this->assertStringContainsString('Try the latest Chrome', $this->get(self::HOST, '/help/' . $id)->body);
        $this->post(self::HOST, '/help/' . $id . '/status', ['resolved' => '1']);
        $this->assertSame('resolved', $this->db()->fetchValue('SELECT status FROM support_requests WHERE id = ?', [$id]));
        $this->post(self::HOST, '/help/' . $id . '/reply', ['body' => 'It broke again.']);
        $this->assertSame('open', $this->db()->fetchValue('SELECT status FROM support_requests WHERE id = ?', [$id]), 'a reply reopens it');
        $this->assertContains('New reply: Cannot open the subnet lab', $this->notificationsFor('admin@school.test'));
        $this->assertSame(4, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'support.%'"));
    }

    public function testRequestsAreCheckedAndLimited(): void
    {
        $this->signIn('tobi@school.test');
        $bad = $this->post(self::HOST, '/help', ['category' => 'nonsense', 'subject' => 'x', 'body' => '']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Choose what your question is about', $bad->body);

        for ($i = 1; $i <= 5; $i++) {
            $this->post(self::HOST, '/help', ['category' => 'other', 'subject' => 'Question number ' . $i, 'body' => 'Details.']);
        }
        $sixth = $this->post(self::HOST, '/help', ['category' => 'other', 'subject' => 'Question number 6', 'body' => 'Details.']);
        $this->assertSame(422, $sixth->status);
        $this->assertStringContainsString('already have 5 requests waiting', $sixth->body);
        $this->assertSame(5, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM support_requests'));
    }
}
