<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Explicit service container. Every service is registered with a factory in
 * bootstrap/services.php — there is no reflection autowiring, so the object
 * graph stays readable and greppable.
 */
final class Container
{
    /** @var array<string, Closure(Container): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @param Closure(Container): object $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
