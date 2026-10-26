<?php

declare(strict_types=1);

namespace App\Core\View;

/**
 * Page numbers for long lists. The page comes from ?page=, every other query value
 * (filters, sort) is kept in the links, and pages past the end show the last one.
 */
final class Pager
{
    public readonly int $page;
    public readonly int $pages;

    /** @param array<string, string> $query the list's other query values, kept in every link */
    public function __construct(int $page, public readonly int $perPage, public readonly int $total, public readonly string $path, public readonly array $query = [])
    {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = min(max(1, $page), $this->pages);
    }

    /** Reads ?page= from a raw query value (anything that is not a positive whole number means page 1). */
    public static function pageFrom(?string $raw): int
    {
        return $raw !== null && ctype_digit($raw) && strlen($raw) < 7 ? max(1, (int) $raw) : 1;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }

    public function url(int $page): string
    {
        $q = array_filter($this->query, static fn (string $v): bool => $v !== '');
        if ($page > 1) {
            $q['page'] = (string) $page;
        }

        return $this->path . ($q === [] ? '' : '?' . http_build_query($q));
    }

    /**
     * Page numbers to show: the first, the last, and two either side of the current one; null marks a gap.
     *
     * @return list<int|null>
     */
    public function window(): array
    {
        $out = [];
        $last = 0;
        for ($p = 1; $p <= $this->pages; $p++) {
            if ($p === 1 || $p === $this->pages || abs($p - $this->page) <= 2) {
                if ($last !== 0 && $p - $last > 1) {
                    $out[] = null;
                }
                $out[] = $p;
                $last = $p;
            }
        }

        return $out;
    }
}
