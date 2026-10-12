<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Core\Database\Connection;

final class TestDatabase
{
    private static ?Connection $connection = null;

    /** @return array<string, string> */
    public static function config(): array
    {
        $database = getenv('DB_TEST_DATABASE') ?: 'dml_lms_test';
        if (!str_contains($database, 'test')) {
            // Guard against pointing the destructive test bootstrap at a real database.
            throw new \RuntimeException('DB_TEST_DATABASE must contain "test".');
        }

        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'test-key-0123456789abcdef0123456789abcdef',
            'PLATFORM_HOSTS' => 'platform.test',
            'DB_HOST' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_TEST_PORT') ?: '3306',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => getenv('DB_TEST_USERNAME') ?: 'dml',
            'DB_PASSWORD' => getenv('DB_TEST_PASSWORD') === false ? 'dml-local' : (string) getenv('DB_TEST_PASSWORD'),
            'STORAGE_PATH' => sys_get_temp_dir() . '/dml-lms-test-storage',
        ];
    }

    public static function connection(): Connection
    {
        if (self::$connection === null) {
            $c = new Config(self::config());
            self::$connection = Connection::connect(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c->get('DB_HOST'), $c->get('DB_PORT'), $c->get('DB_DATABASE')),
                (string) $c->get('DB_USERNAME'),
                (string) $c->get('DB_PASSWORD', ''),
            );
        }

        return self::$connection;
    }

    public static function truncateAll(): void
    {
        $db = self::connection();
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->fetchAll('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            if ($table !== 'schema_migrations' && preg_match('/^[a-z_]+$/', $table) === 1) {
                $db->exec('TRUNCATE TABLE `' . $table . '`');
            }
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
