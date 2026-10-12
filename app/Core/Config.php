<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Configuration read from environment variables, optionally seeded from a
 * .env file that lives outside the release directory in production.
 */
final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(?string $envFile): self
    {
        $values = [];
        if ($envFile !== null && is_readable($envFile)) {
            $values = self::parseEnvFile($envFile);
        }
        // Real environment variables win over the file.
        foreach (getenv() as $key => $value) {
            $values[$key] = $value;
        }

        return new self($values);
    }

    /** @return array<string, string> */
    public static function parseEnvFile(string $path): array
    {
        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new RuntimeException(sprintf('Missing required configuration value %s.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->get($key, '') ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }
}
