<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Development/staging driver: writes each message as an .eml file instead of
 * sending it. Never selected for production delivery (see MailSettings).
 */
final class FileMailer implements Mailer
{
    /** @var list<Message> messages sent in this process, for tests */
    public array $sent = [];

    public function __construct(private readonly MimeBuilder $mime, private readonly ?string $directory)
    {
    }

    public function send(Message $message): void
    {
        $this->sent[] = $message;
        if ($this->directory === null) {
            return;
        }
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
        $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        if (@file_put_contents($this->directory . '/' . $name, $this->mime->build($message)) === false) {
            throw new MailException('Could not write the message file.');
        }
    }
}
