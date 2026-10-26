<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\DataDictionary;
use Tests\Support\AppTestCase;

final class DataDictionaryTest extends AppTestCase
{
    public function testEveryTableIsDescribed(): void
    {
        $dictionary = new DataDictionary($this->db());
        $this->assertSame([], $dictionary->undescribed(), 'describe new tables in app/Core/Database/DataDictionary.php and regenerate docs/data-dictionary.md');
        $md = $dictionary->markdown();
        $this->assertStringContainsString('## payment_refunds', $md);
        $this->assertStringContainsString('| `completion_min_average` | tinyint', $md);
    }

    public function testTheCommittedDictionaryMatchesTheSchema(): void
    {
        $committed = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/data-dictionary.md');
        foreach ((new DataDictionary($this->db()))->undescribed() === [] ? array_keys(DataDictionary::TABLES) : [] as $table) {
            $this->assertStringContainsString('## ' . $table . "\n", $committed, 'run: php bin/console schema:dictionary > docs/data-dictionary.md');
        }
    }
}
