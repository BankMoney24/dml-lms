<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/**
 * Deterministic random numbers from a session's seed. The same seed always
 * gives the same values, so a lab's numbers (and its answers) are
 * recomputed on the server when the learner submits; the answers are never
 * stored in the browser or sent to it unless the simulation needs them.
 */
final class Seed
{
    private int $counter = 0;

    public function __construct(private readonly string $seed)
    {
    }

    public static function fresh(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->next() % ($max - $min + 1);
    }

    public function chance(int $percent = 50): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    private function next(): int
    {
        $bytes = hash('sha256', $this->seed . ':' . $this->counter++, true);
        $value = unpack('J', substr($bytes, 0, 8));

        return (is_array($value) ? (int) $value[1] : 0) & PHP_INT_MAX;
    }
}
