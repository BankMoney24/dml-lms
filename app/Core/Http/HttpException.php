<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * An expected, user-presentable failure. The message is shown to the user,
 * so it must never contain internal detail.
 */
class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $publicMessage)
    {
        parent::__construct($publicMessage, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'We could not find that page.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'You do not have permission to do that.');
    }

    public static function badRequest(string $message = 'The request could not be processed.'): self
    {
        return new self(400, $message);
    }
}
