<?php

declare(strict_types=1);

namespace App\Core\Mail;

use InvalidArgumentException;

/** One outgoing email. Header values are validated so user input can never add headers. */
final class Message
{
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid recipient address.');
        }
        foreach ([$toEmail, $toName ?? '', $subject] as $header) {
            if (preg_match('/[\r\n\0]/', $header) === 1) {
                throw new InvalidArgumentException('Header values must not contain line breaks.');
            }
        }
    }
}
