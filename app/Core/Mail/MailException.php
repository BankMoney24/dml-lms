<?php

declare(strict_types=1);

namespace App\Core\Mail;

use RuntimeException;

/**
 * Delivery failure. $permanent means retrying will not help (for example the
 * server rejected the recipient); otherwise the outbox retries with backoff.
 */
final class MailException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
