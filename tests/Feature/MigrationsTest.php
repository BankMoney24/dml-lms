<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Migrator;
use RuntimeException;
use Tests\Support\AppTestCase;

final class MigrationsTest extends AppTestCase
{
    public function testEmptyMigrationDirectoryIsAnErrorNotUpToDate(): void
    {
        $dir = sys_get_temp_dir() . '/dml-empty-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->expectException(RuntimeException::class);
            (new Migrator($this->db(), $dir))->migrate();
        } finally {
            rmdir($dir);
        }
    }

    public function testRepositoryMigrationsAreAllAppliedAndRerunIsANoOp(): void
    {
        $migrator = new Migrator($this->db(), dirname(__DIR__, 2) . '/database/migrations');

        $this->assertSame([], $migrator->migrate());
        $status = $migrator->status();
        $this->assertGreaterThanOrEqual(2, count($status));
        $this->assertNotContains(false, $status);
    }
}
