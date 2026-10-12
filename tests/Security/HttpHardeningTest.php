<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Role;
use Tests\Support\AppTestCase;

final class HttpHardeningTest extends AppTestCase
{
    public function testSecurityHeadersOnEveryResponseIncludingErrors(): void
    {
        $this->makeTenant('school', 'school.test');

        foreach (['/courses', '/does-not-exist', '/health'] as $path) {
            $response = $this->get('school.test', $path);
            $this->assertNotNull($response->header('Content-Security-Policy'), $path);
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            $this->assertSame('DENY', $response->header('X-Frame-Options'), $path);
            $this->assertNotNull($response->header('X-Request-Id'), $path);
        }
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $tenant = $this->makeTenant('school', 'school.test');
        $this->makeUser($tenant, 'teacher@school.test', Role::Educator);
        $this->login('school.test', 'teacher@school.test', 'correct-horse-battery');

        $response = $this->post('school.test', '/teach/courses', ['title' => 'No token'], false);

        $this->assertSame(419, $response->status);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM courses'));
    }

    public function testLoginFormItselfRequiresCsrf(): void
    {
        $this->makeTenant('school', 'school.test');

        $this->assertSame(419, $this->post('school.test', '/login', ['email' => 'a@b.c', 'password' => 'x'], false)->status);
    }

    public function testUnknownHostIsNotServed(): void
    {
        $this->assertSame(404, $this->get('unmapped.example', '/')->status);
    }

    public function testErrorsDoNotLeakInternals(): void
    {
        $this->makeTenant('school', 'school.test');
        $body = $this->get('school.test', '/courses/nope')->body;

        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('SQL', $body);
        $this->assertStringContainsString('Reference for support', $body);
    }

    public function testHealthEndpointsAnswerWithoutTenantOrSession(): void
    {
        $live = $this->get('anything.example', '/health');
        $ready = $this->get('anything.example', '/health/ready');

        $this->assertSame(200, $live->status);
        $this->assertSame(200, $ready->status);
        $this->assertStringContainsString('"database":"ok"', $ready->body);
    }
}
