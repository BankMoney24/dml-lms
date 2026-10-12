<?php

declare(strict_types=1);

namespace App\Core\Validation;

use RuntimeException;

/** Field-level validation failure. Messages are user-facing and say how to fix the problem. */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed.');
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
