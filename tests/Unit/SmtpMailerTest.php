<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mail\MailException;
use App\Core\Mail\Message;
use App\Core\Mail\MimeBuilder;
use App\Core\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

/**
 * Runs SmtpMailer against a scripted SMTP server in a child process and
 * checks the conversation: EHLO, AUTH, envelope, dot-stuffed DATA, QUIT.
 */
final class SmtpMailerTest extends TestCase
{
    /** @param array<string, string> $replies command prefix => reply */
    private function serve(array $replies, callable $client): string
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed for the scripted SMTP server.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error ?? 'could not listen');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $log = tempnam(sys_get_temp_dir(), 'smtp');

        $pid = pcntl_fork();
        if ($pid === 0) {
            $conn = stream_socket_accept($server, 5);
            if ($conn !== false) {
                $transcript = '';
                fwrite($conn, "220 test ESMTP\r\n");
                $inData = false;
                while (($line = fgets($conn)) !== false) {
                    $transcript .= $line;
                    if ($inData) {
                        if ($line === ".\r\n") {
                            $inData = false;
                            fwrite($conn, "250 queued\r\n");
                        }
                        continue;
                    }
                    $reply = "500 unknown\r\n";
                    foreach ($replies as $prefix => $answer) {
                        if (stripos($line, $prefix) === 0) {
                            $reply = $answer;
                            break;
                        }
                    }
                    fwrite($conn, $reply);
                    if (stripos($line, 'DATA') === 0 && str_starts_with($reply, '354')) {
                        $inData = true;
                    }
                    if (stripos($line, 'QUIT') === 0) {
                        break;
                    }
                }
                file_put_contents((string) $log, $transcript);
            }
            // Leave without running shutdown handlers: the child shares the
            // parent's open sockets (e.g. the test database connection), and
            // a normal exit would close them for the parent too.
            if (function_exists('posix_getpid')) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            exit(0);
        }

        fclose($server);
        try {
            $client($port);
        } finally {
            pcntl_waitpid($pid, $status);
        }
        $transcript = (string) file_get_contents((string) $log);
        unlink((string) $log);

        return $transcript;
    }

    private const HAPPY = [
        'EHLO' => "250-test\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n",
        'AUTH PLAIN' => "235 ok\r\n",
        'MAIL FROM' => "250 ok\r\n",
        'RCPT TO' => "250 ok\r\n",
        'DATA' => "354 go\r\n",
        'QUIT' => "221 bye\r\n",
    ];

    public function testDeliversWithAuthAndDotStuffing(): void
    {
        $transcript = $this->serve(self::HAPPY, function (int $port): void {
            $mailer = new SmtpMailer(new MimeBuilder('no-reply@school.ng', 'School'), '127.0.0.1', $port, 'none', 'user', 'secret', false, 5);
            $mailer->send(new Message('ada@example.org', 'Ada', 'Hello', "Hi Ada\n.hidden line\n"));
        });

        $this->assertStringContainsString('AUTH PLAIN ' . base64_encode("\0user\0secret"), $transcript);
        $this->assertStringContainsString("MAIL FROM:<no-reply@school.ng>\r\n", $transcript);
        $this->assertStringContainsString("RCPT TO:<ada@example.org>\r\n", $transcript);
        $this->assertStringContainsString("\r\n..hidden line", $transcript, 'leading dot is doubled');
        $this->assertStringContainsString("QUIT\r\n", $transcript);
    }

    public function testRejectedRecipientIsPermanent(): void
    {
        $replies = ['RCPT TO' => "550 no such user\r\n"] + self::HAPPY;
        $caught = null;
        $this->serve($replies, function (int $port) use (&$caught): void {
            try {
                (new SmtpMailer(new MimeBuilder('no-reply@school.ng', 'School'), '127.0.0.1', $port, 'none', null, null, false, 5))
                    ->send(new Message('ghost@example.org', null, 'Hi', 'x'));
            } catch (MailException $e) {
                $caught = $e;
            }
        });

        $this->assertInstanceOf(MailException::class, $caught);
        $this->assertTrue($caught->permanent);
    }

    public function testAuthFailureDoesNotLeakServerText(): void
    {
        $replies = ['AUTH PLAIN' => "535 bad credentials for user@example.org\r\n"] + self::HAPPY;
        $caught = null;
        $this->serve($replies, function (int $port) use (&$caught): void {
            try {
                (new SmtpMailer(new MimeBuilder('no-reply@school.ng', 'School'), '127.0.0.1', $port, 'none', 'user@example.org', 'pw', false, 5))
                    ->send(new Message('ada@example.org', null, 'Hi', 'x'));
            } catch (MailException $e) {
                $caught = $e;
            }
        });

        $this->assertInstanceOf(MailException::class, $caught);
        $this->assertStringNotContainsString('user@example.org', $caught->getMessage());
        $this->assertFalse($caught->permanent);
    }
}
