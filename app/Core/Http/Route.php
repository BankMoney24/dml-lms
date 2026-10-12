<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Route
{
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                       $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly array $handler,
        public readonly array $middleware,
        public readonly string $regex,
    ) {
    }
}
