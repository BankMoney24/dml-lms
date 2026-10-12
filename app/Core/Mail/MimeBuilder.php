<?php

declare(strict_types=1);

namespace App\Core\Mail;

/** Builds an RFC 5322 / MIME message: UTF-8, quoted-printable, text with optional HTML alternative. */
final class MimeBuilder
{
    public function __construct(private readonly string $fromEmail, private readonly string $fromName)
    {
    }

    public function fromEmail(): string
    {
        return $this->fromEmail;
    }

    public function build(Message $message, ?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable('now');
        $domain = substr(strrchr($this->fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date' => $date->format(DATE_RFC2822),
            'From' => self::address($this->fromEmail, $this->fromName),
            'To' => self::address($message->toEmail, $message->toName),
            'Subject' => self::encodeHeader($message->subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Auto-Submitted' => 'auto-generated',
        ];

        $text = self::qp($message->text);
        if ($message->html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
            $body = $text;
        } else {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--$boundary\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . $text . "\r\n"
                . "--$boundary\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($message->html) . "\r\n"
                . "--$boundary--\r\n";
        }

        $head = '';
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        return $head . "\r\n" . $body;
    }

    private static function qp(string $content): string
    {
        $normalised = (string) preg_replace("/\r\n|\r|\n/", "\r\n", $content);

        return quoted_printable_encode($normalised);
    }

    private static function address(string $email, ?string $name): string
    {
        if ($name === null || $name === '') {
            return '<' . $email . '>';
        }

        return self::encodeHeader($name, true) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $value, bool $phrase = false): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $phrase ? '"' . addcslashes($value, '"\\') . '"' : $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
