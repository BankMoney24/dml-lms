<?php

declare(strict_types=1);

namespace App\Core\Log;

use Throwable;

/**
 * JSON-lines logger. Callers pass structured context; never pass passwords,
 * tokens, answers or other learner content.
 */
final class Logger
{
    public function __construct(private readonly string $file)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function error(string $message, array $context = [], ?Throwable $e = null): void
    {
        if ($e !== null) {
            $context['exception'] = $e::class;
            $context['exception_message'] = $e->getMessage();
            $context['at'] = $e->getFile() . ':' . $e->getLine();
        }
        $this->write('error', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
        ] + $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
