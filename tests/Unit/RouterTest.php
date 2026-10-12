<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\HttpException;
use App\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/courses/{slug}/lessons/{lesson:int}', [self::class, 'x']);
        $router->post('/teach/courses', [self::class, 'y']);

        return $router;
    }

    public function testMatchesTypedParameters(): void
    {
        [, $params] = $this->router()->match('GET', '/courses/physics-101/lessons/42');

        $this->assertSame(['slug' => 'physics-101', 'lesson' => '42'], $params);
    }

    public function testRejectsNonNumericIntParameter(): void
    {
        $this->expectException(HttpException::class);
        $this->router()->match('GET', '/courses/physics-101/lessons/1%20OR%201=1');
    }

    public function testRejectsSlugWithUnsafeCharacters(): void
    {
        $this->expectException(HttpException::class);
        $this->router()->match('GET', '/courses/../etc/lessons/1');
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router()->match('GET', '/teach/courses');
            $this->fail('Expected exception');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status);
        }
    }

    public function testHeadIsServedByGetRoutes(): void
    {
        [$route] = $this->router()->match('HEAD', '/courses/a/lessons/1');

        $this->assertSame('GET', $route->method);
    }
}
