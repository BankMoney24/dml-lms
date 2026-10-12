<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Mail\MailException;
use App\Core\Mail\Mailer;
use App\Core\Mail\Message;
use App\Modules\Identity\Role;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class EmailFlowsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config['MAIL_DRIVER'] = 'file';
        $this->app = $this->freshApp($this->session);
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
    }

    private function requestReset(string $email): string
    {
        return $this->post(self::HOST, '/password/forgot', ['email' => $email])->body;
    }

    public function testResetLinkWorksOnceAndReplacesThePassword(): void
    {
        $page = $this->requestReset('TOBI@school.test');
        $this->assertStringContainsString('Check your email', $page);

        $mail = $this->deliverMail();
        $this->assertCount(1, $mail);
        $this->assertSame('tobi@school.test', $mail[0]->toEmail);
        $this->assertStringContainsString('Reset your password for School Academy', $mail[0]->subject);
        $link = $this->linkIn($mail[0], '/password/reset/');

        $this->assertSame(200, $this->get(self::HOST, $link)->status);
        $done = $this->post(self::HOST, $link, ['new_password' => 'brand new passphrase', 'confirm_password' => 'brand new passphrase']);
        $this->assertRedirectTo('/login', $done);

        $this->assertSame(410, $this->get(self::HOST, $link)->status, 'link is single-use');
        $this->assertSame(422, $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery')->status, 'old password stops working');
        $this->assertRedirectTo('/', $this->login(self::HOST, 'tobi@school.test', 'brand new passphrase'));
    }

    public function testUnknownAndForeignAddressesGetTheSamePageAndNoEmail(): void
    {
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'stranger@other.test', Role::Learner);

        $known = $this->requestReset('tobi@school.test');
        $unknown = $this->requestReset('nobody@school.test');
        $foreign = $this->requestReset('stranger@other.test');

        $normalise = static fn (string $html): string => (string) preg_replace('/<strong>[^<]*<\/strong>|value="[^"]*"|Reference[^<]*/', '', $html);
        $this->assertSame($normalise($known), $normalise($unknown));
        $this->assertSame($normalise($known), $normalise($foreign));
        $this->assertCount(1, $this->deliverMail(), 'only the real member got an email');
    }

    public function testResetRequestsAreRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestReset('tobi@school.test');
        }

        $this->assertCount(3, $this->deliverMail());
    }

    public function testExpiredLinkIsRefused(): void
    {
        $this->requestReset('tobi@school.test');
        $link = $this->linkIn($this->deliverMail()[0], '/password/reset/');
        $this->db()->execute('UPDATE account_tokens SET expires_at = ?', ['2020-01-01 00:00:00']);

        $this->assertSame(410, $this->get(self::HOST, $link)->status);
        $this->assertSame(410, $this->post(self::HOST, $link, ['new_password' => 'brand new passphrase', 'confirm_password' => 'brand new passphrase'])->status);
    }

    public function testLinkFromAnotherSchoolDoesNotWorkHere(): void
    {
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'tobi@school.test', Role::Learner);
        $this->post('other.test', '/password/forgot', ['email' => 'tobi@school.test']);
        $link = $this->linkIn($this->deliverMail()[0], '/password/reset/');

        $this->assertSame(410, $this->get(self::HOST, $link)->status);
    }

    public function testInvitationEmailReplacesOneTimePassword(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/people', ['name' => 'Chidi Okafor', 'email' => 'chidi@school.test', 'role' => 'educator']);

        $page = $this->get(self::HOST, '/people')->body;
        $this->assertStringContainsString('We emailed an invitation', $page);
        $this->assertStringNotContainsString('class="secret"', $page, 'no one-time password shown');

        $mail = $this->deliverMail();
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('as educator', $mail[0]->text);
        $link = $this->linkIn($mail[0], '/password/reset/');

        $this->newBrowser();
        $this->assertStringContainsString('Set up your account', $this->get(self::HOST, $link)->body);
        $this->post(self::HOST, $link, ['new_password' => 'chidi chooses this', 'confirm_password' => 'chidi chooses this']);
        $this->assertRedirectTo('/', $this->login(self::HOST, 'chidi@school.test', 'chidi chooses this'));
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status, 'no forced password change after accepting the invitation');
    }

    public function testExistingAccountGetsANoticeNotAnInvitation(): void
    {
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'shared@example.org', Role::Educator);
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');

        $this->post(self::HOST, '/people', ['name' => 'Shared Person', 'email' => 'shared@example.org', 'role' => 'learner']);
        $mail = $this->deliverMail();

        $this->assertCount(1, $mail);
        $this->assertStringContainsString('You now have access to School Academy', $mail[0]->subject);
        $this->assertStringNotContainsString('/password/reset/', $mail[0]->text);
    }

    public function testAdminCanResendASignInLink(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $tobi = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'tobi@school.test'");

        $this->post(self::HOST, '/people/' . $tobi . '/send-link');

        $mail = $this->deliverMail();
        $this->assertCount(1, $mail);
        $this->assertSame('tobi@school.test', $mail[0]->toEmail);
    }

    public function testForgotPageExplainsWhatToDoWhenEmailIsOff(): void
    {
        $this->configure('MAIL_DRIVER', 'disabled');

        $body = $this->get(self::HOST, '/password/forgot')->body;

        $this->assertStringContainsString('Ask your school administrator', $body);
        $this->assertStringNotContainsString('Email me a link', $body);
    }

    public function testOutboxRetriesWithBackoffThenGivesUpAndErasesBodies(): void
    {
        $failing = new class implements Mailer {
            public int $calls = 0;

            public function send(Message $message): void
            {
                $this->calls++;
                throw new MailException('Connection refused');
            }
        };
        $outbox = new MailOutbox($this->db(), $failing, new \App\Core\Clock(), new \App\Core\Log\Logger(sys_get_temp_dir() . '/dml-test-mail.log'));
        $id = $outbox->queue($this->tenant->id, new Message('tobi@school.test', null, 'Hi', 'secret link here'));

        $outbox->deliverDue();
        $row = $this->db()->fetchOne('SELECT status, attempts, next_attempt_at, text_body FROM mail_outbox WHERE id = ?', [$id]);
        $this->assertSame('queued', $row['status'] ?? null);
        $this->assertSame(1, (int) ($row['attempts'] ?? 0));
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) ($row['next_attempt_at'] ?? ''), 'waits before retrying');

        // Make it due repeatedly until it is abandoned.
        for ($i = 0; $i < 6; $i++) {
            $this->db()->execute('UPDATE mail_outbox SET next_attempt_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $id]);
            $outbox->deliverDue();
        }
        $row = $this->db()->fetchOne('SELECT status, attempts, text_body, last_error FROM mail_outbox WHERE id = ?', [$id]);
        $this->assertSame('failed', $row['status'] ?? null);
        $this->assertSame(6, (int) ($row['attempts'] ?? 0));
        $this->assertNull($row['text_body'] ?? null, 'body with the link is erased');
        $this->assertSame('Connection refused', $row['last_error'] ?? null);
        $this->assertSame(6, $failing->calls);
    }

    public function testSentMessagesKeepNoBody(): void
    {
        $this->requestReset('tobi@school.test');
        $this->deliverMail();

        $row = $this->db()->fetchOne('SELECT status, text_body, html_body FROM mail_outbox');
        $this->assertSame('sent', $row['status'] ?? null);
        $this->assertNull($row['text_body'] ?? null);
        $this->assertNull($row['html_body'] ?? null);
    }
}
