<?php

declare(strict_types=1);

use App\Core\Database\Migrator;
use Tests\Support\TestDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

// Integration tests need a disposable MySQL database. The schema is rebuilt
// once per run; individual tests truncate tables.
if (getenv('DML_SKIP_DB') !== '1') {
    $db = TestDatabase::connection();
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->fetchAll('SHOW TABLES') as $row) {
        $table = (string) array_values($row)[0];
        if (preg_match('/^[a-z_]+$/', $table) === 1) {
            $db->exec('DROP TABLE `' . $table . '`');
        }
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->migrate();
}
