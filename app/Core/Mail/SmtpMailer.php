<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Minimal, dependency-free SMTP client: EHLO, STARTTLS or implicit TLS,
 * AUTH PLAIN/LOGIN, one message per connection. Works with a local MTA or
 * any provider's SMTP relay (Brevo, Zoho, SES, Mailgun, Google Workspace).
 */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $socket = null;

    /** @param 'tls'|'ssl'|'none' $encryption */
    public function __construct(
        private readonly MimeBuilder $mime,
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly bool $verifyPeer = true,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function send(Message $message): void
    {
        try {
            $this->connect();
            $this->expect([220]);
            $capabilities = $this->ehlo();

            if ($this->encryption === 'tls') {
                if (!in_array('STARTTLS', $capabilities, true)) {
                    throw new MailException('The mail server does not offer STARTTLS.', true);
                }
                $this->command('STARTTLS', [220]);
                $this->startTls();
                $capabilities = $this->ehlo();
            }

            if ($this->username !== null && $this->username !== '') {
                $this->authenticate($capabilities);
            }

            $this->command('MAIL FROM:<' . $this->mime->fromEmail() . '>', [250]);
            $this->command('RCPT TO:<' . $message->toEmail . '>', [250, 251], permanentOn5xx: true);
            $this->command('DATA', [354]);
            $this->write(self::dotStuff($this->mime->build($message)) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function startTls(): void
    {
        $socket = $this->socket;
        $problem = '';
        set_error_handler(static function (int $severity, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });
        try {
            $ok = $socket !== null
                && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) === true;
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            $hint = str_contains($problem, 'certificate verify failed')
                ? ' The server certificate was not trusted; check MAIL_HOST matches the certificate name.'
                : '';
            throw new MailException('Could not start TLS with the mail server.' . $hint);
        }
    }

    private function connect(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $this->host, $this->port),
            $errno,
            $error,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new MailException(sprintf('Could not connect to the mail server (%s).', $error !== '' ? $error : (string) $errno));
        }
        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;
    }

    /** @return list<string> upper-cased capability keywords, e.g. STARTTLS, AUTH, PLAIN, LOGIN */
    private function ehlo(): array
    {
        $hostname = gethostname();
        $lines = $this->command('EHLO ' . (is_string($hostname) && $hostname !== '' ? $hostname : 'localhost'), [250]);
        $caps = [];
        foreach (array_slice($lines, 1) as $line) {
            foreach (preg_split('/[\s=]+/', strtoupper(substr($line, 4))) ?: [] as $word) {
                if ($word !== '') {
                    $caps[] = $word;
                }
            }
        }

        return $caps;
    }

    /** @param list<string> $capabilities */
    private function authenticate(array $capabilities): void
    {
        $user = (string) $this->username;
        $pass = (string) $this->password;
        if (in_array('PLAIN', $capabilities, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], secret: true);

            return;
        }
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($user), [334], secret: true);
        $this->command(base64_encode($pass), [235], secret: true);
    }

    /**
     * @param list<int> $expected
     * @return list<string> response lines
     */
    private function command(string $line, array $expected, bool $secret = false, bool $permanentOn5xx = false): array
    {
        $this->write($line . "\r\n");

        return $this->expect($expected, $secret ? 'authentication' : strtok($line, ' :'), $permanentOn5xx);
    }

    /**
     * @param list<int> $expected
     * @return list<string>
     */
    private function expect(array $expected, string|false $step = 'greeting', bool $permanentOn5xx = false): array
    {
        $lines = [];
        $socket = $this->socket;
        while ($socket !== null && ($line = fgets($socket, 1024)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ($lines === []) {
            throw new MailException('The mail server closed the connection or timed out.');
        }
        $code = (int) substr($lines[count($lines) - 1], 0, 3);
        if (!in_array($code, $expected, true)) {
            // Never echo the server text for authentication steps; it can contain the username.
            $detail = $step === 'authentication' ? 'authentication failed' : mb_substr(end($lines), 0, 200);
            throw new MailException(sprintf('Mail server refused %s: %s', (string) $step, $detail), $permanentOn5xx && $code >= 500);
        }

        return $lines;
    }

    private function write(string $data): void
    {
        $socket = $this->socket;
        if ($socket === null || fwrite($socket, $data) === false) {
            throw new MailException('Could not write to the mail server.');
        }
    }

    /** Lines starting with "." get an extra "." so they are not read as the end of DATA. */
    private static function dotStuff(string $data): string
    {
        return (string) preg_replace('/^\./m', '..', $data);
    }
}
