<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View\Pager;
use PHPUnit\Framework\TestCase;

final class PagerTest extends TestCase
{
    public function testPagesClampAndCountRows(): void
    {
        $pager = new Pager(3, 50, 120, '/people');
        $this->assertSame(3, $pager->pages);
        $this->assertSame(100, $pager->offset());
        $this->assertSame([101, 120], [$pager->from(), $pager->to()]);

        $past = new Pager(9, 50, 120, '/people');
        $this->assertSame(3, $past->page, 'past the end shows the last page');

        $empty = new Pager(1, 50, 0, '/people');
        $this->assertSame([1, 0, 0], [$empty->pages, $empty->from(), $empty->to()]);
    }

    public function testLinksKeepFiltersAndDropPageOne(): void
    {
        $pager = new Pager(2, 10, 100, '/people', ['role' => 'learner', 'find' => '', 'sort' => 'name']);
        $this->assertSame('/people?role=learner&sort=name', $pager->url(1));
        $this->assertSame('/people?role=learner&sort=name&page=3', $pager->url(3));
    }

    public function testWindowShowsEndsAndNeighboursWithGaps(): void
    {
        $this->assertSame([1, null, 8, 9, 10, 11, 12, null, 20], (new Pager(10, 10, 200, '/x'))->window());
        $this->assertSame([1, 2, 3], (new Pager(1, 10, 30, '/x'))->window());
    }

    public function testPageFromAcceptsOnlyWholeNumbers(): void
    {
        foreach (['abc' => 1, '-2' => 1, '0' => 1, '4' => 4, '99999999' => 1] as $raw => $want) {
            $this->assertSame($want, Pager::pageFrom((string) $raw), (string) $raw);
        }
        $this->assertSame(1, Pager::pageFrom(null));
    }
}
