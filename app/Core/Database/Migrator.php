<?php

declare(strict_types=1);

namespace App\Core\Database;

use RuntimeException;

/**
 * Applies forward-only SQL migrations from database/migrations in filename
 * order. Applied files are recorded with a checksum; editing an applied
 * migration is detected and refused — write a new migration instead.
 */
final class Migrator
{
    public function __construct(private readonly Connection $db, private readonly string $directory)
    {
    }

    /** @return list<string> names of migrations applied by this run */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->appliedChecksums();
        $ran = [];
        if ($this->files() === []) {
            // An empty migrations directory means a broken build or checkout, never "up to date".
            throw new RuntimeException(sprintf('No migration files found in %s.', $this->directory));
        }

        foreach ($this->files() as $name => $path) {
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                if (!hash_equals($applied[$name], $checksum)) {
                    throw new RuntimeException(sprintf(
                        'Migration %s was changed after it was applied. Add a new migration instead.',
                        $name,
                    ));
                }
                continue;
            }

            // MySQL DDL commits implicitly, so each statement runs on its own and
            // the migration is recorded only after all statements succeed.
            foreach (self::splitStatements($sql) as $statement) {
                $this->db->exec($statement);
            }
            $this->db->execute(
                'INSERT INTO schema_migrations (name, checksum, applied_at) VALUES (?, ?, UTC_TIMESTAMP())',
                [$name, $checksum],
            );
            $ran[] = $name;
        }

        return $ran;
    }

    /** @return array<string, bool> migration name => applied? */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedChecksums();
        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[$name] = isset($applied[$name]);
        }

        return $status;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        // Strip full-line comments, then split on semicolons at line ends.
        $lines = array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    private function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(190) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return array<string, string> */
    private function appliedChecksums(): array
    {
        $rows = $this->db->fetchAll('SELECT name, checksum FROM schema_migrations');
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['name']] = (string) $row['checksum'];
        }

        return $map;
    }

    /** @return array<string, string> name => path, sorted */
    private function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $map = [];
        foreach ($files as $path) {
            $map[basename($path)] = $path;
        }

        return $map;
    }
}
