<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Tenancy\Tenant;
use PHPUnit\Framework\TestCase;

final class GroupTermTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private static function tenant(array $extra): Tenant
    {
        return Tenant::fromRow($extra + ['id' => 1, 'slug' => 's', 'name' => 'S', 'status' => 'active', 'timezone' => 'Africa/Lagos', 'locale' => 'en']);
    }

    public function testTheWordFollowsTheSchoolsChoiceOrItsKind(): void
    {
        $this->assertSame('class', self::tenant(['kind' => 'school'])->groupWord());
        $this->assertSame('Cohorts', self::tenant(['kind' => 'training_centre'])->groupWord(true, true));
        $this->assertSame('groups', self::tenant(['kind' => 'university'])->groupWord(true));
        $this->assertSame('group', self::tenant([])->groupWord());
        $this->assertSame('Streams', self::tenant(['kind' => 'school', 'group_term' => 'stream'])->groupWord(true, true));
        $this->assertSame('class', self::tenant(['kind' => 'school', 'group_term' => 'nonsense'])->groupWord(), 'unknown values are ignored');
    }
}
