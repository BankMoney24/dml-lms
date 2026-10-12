<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Small explicit router. Patterns use {name} for a path segment and
 * {name:int} for a positive integer segment.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                       $middleware service ids resolved by the kernel
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = new Route(strtoupper($method), $pattern, $handler, $middleware, self::compile($pattern));
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @return array{0: Route, 1: array<string, string>}
     * @throws HttpException
     */
    public function match(string $method, string $path): array
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $path, $matches) !== 1) {
                continue;
            }
            $effective = $method === 'HEAD' ? 'GET' : $method;
            if ($route->method !== $effective) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return [$route, array_map('strval', $params)];
        }

        if ($methodMismatch) {
            throw new HttpException(405, 'That action is not available here.');
        }
        throw HttpException::notFound();
    }

    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-z_]+)(?::(int))?\}/',
            static fn (array $m): string => isset($m[2]) && $m[2] === 'int'
                ? '(?P<' . $m[1] . '>[1-9][0-9]{0,18})'
                : '(?P<' . $m[1] . '>[a-z0-9][a-z0-9-]{0,119})',
            $pattern,
        );

        return '#^' . $regex . '$#';
    }
}
