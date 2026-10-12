<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. All queries are parameterised; there is deliberately no
 * API that accepts user input as SQL text.
 */
final class Connection
{
    private int $transactionDepth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function connect(string $dsn, string $user, string $password): self
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '+00:00'");

        return new self($pdo);
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /** @param array<string|int, scalar|null> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int, scalar|null> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string|int, scalar|null> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /** Runs a raw statement with no parameters. Only for trusted migration SQL. */
    public function exec(string $trustedSql): void
    {
        $this->pdo->exec($trustedSql);
    }

    /**
     * Runs $work inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $work();
            } finally {
                $this->transactionDepth--;
            }
        }

        $this->pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int, scalar|null> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }
}
