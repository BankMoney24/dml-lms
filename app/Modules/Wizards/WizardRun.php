<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

/**
 * Saved progress through one wizard: each step's answers, which steps are
 * answered or skipped, and where the person was.
 */
final class WizardRun
{
    /**
     * @param array<string, array<string, mixed>> $data
     * @param list<string>                        $done
     * @param list<string>                        $skipped
     */
    public function __construct(
        public array $data,
        public array $done,
        public array $skipped,
        public string $current,
        public readonly ?string $updatedAt = null,
        public readonly ?string $updatedByName = null,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function answer(string $step, array $values): void
    {
        $this->data[$step] = $values;
        $this->done = array_values(array_unique([...$this->done, $step]));
        $this->skipped = array_values(array_diff($this->skipped, [$step]));
    }

    /** @param array<string, mixed> $values */
    public function keep(string $step, array $values): void
    {
        $this->data[$step] = $values;
    }

    public function skip(string $step): void
    {
        $this->skipped = array_values(array_unique([...$this->skipped, $step]));
        $this->done = array_values(array_diff($this->done, [$step]));
    }

    public function isHandled(string $step): bool
    {
        return in_array($step, $this->done, true) || in_array($step, $this->skipped, true);
    }

    public function toJson(): string
    {
        return json_encode(
            ['data' => $this->data, 'done' => $this->done, 'skipped' => $this->skipped],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function fromJson(string $json, string $current, ?string $updatedAt = null, ?string $updatedByName = null): self
    {
        $state = json_decode($json, true);
        $state = is_array($state) ? $state : [];
        $data = [];
        foreach (is_array($state['data'] ?? null) ? $state['data'] : [] as $key => $values) {
            if (is_string($key) && is_array($values)) {
                $data[$key] = $values;
            }
        }
        $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, 'is_string')) : [];

        return new self($data, $strings($state['done'] ?? null), $strings($state['skipped'] ?? null), $current, $updatedAt, $updatedByName);
    }
}
