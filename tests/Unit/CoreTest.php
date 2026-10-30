<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap\Application;
use App\Core\Config;
use App\Core\Database\Migrator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\ViewHelpers;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public function testRedirectRefusesOffSiteLocations(): void
    {
        $this->assertSame('/', Response::redirect('https://evil.example/')->header('Location'));
        $this->assertSame('/', Response::redirect('//evil.example/')->header('Location'));
        $this->assertSame('/courses', Response::redirect('/courses')->header('Location'));
    }

    public function testHostNormalisationStripsPortAndCase(): void
    {
        $this->assertSame('learn.example.ng', Request::normaliseHost('Learn.Example.NG:8443'));
        $this->assertSame('learn.example.ng', Request::normaliseHost('learn.example.ng.'));
    }

    public function testEscapingAndParagraphsNeutraliseMarkup(): void
    {
        $v = new ViewHelpers([], 'dev');

        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $v->e('<script>alert("x")</script>'));
        $html = $v->paragraphs("First <b>para</b>\n\nSecond\nline");
        $this->assertSame('<p data-own-words>First &lt;b&gt;para&lt;/b&gt;</p><p data-own-words>Second<br>' . "\n" . 'line</p>', $html);
    }

    public function testEnvFileParsing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# comment\nAPP_ENV=staging\nDB_PASSWORD=\"p@ss=word\"\nlower=ignored\n");
        $values = Config::parseEnvFile($file);
        unlink($file);

        $this->assertSame(['APP_ENV' => 'staging', 'DB_PASSWORD' => 'p@ss=word'], $values);
    }

    public function testMigrationSplitterIgnoresComments(): void
    {
        $sql = "-- header; with semicolon\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);\n";

        $this->assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], Migrator::splitStatements($sql));
    }

    public function testRolesGrantLeastPrivilege(): void
    {
        $this->assertNotContains(Permission::CourseManage, Role::Learner->permissions());
        $this->assertNotContains(Permission::CourseLearn, Role::Educator->permissions());
        $this->assertSame([], Role::Guardian->permissions());
        $this->assertContains(Permission::AuditView, Role::Owner->permissions());
    }

    public function testDsnPrefersSocketOverHost(): void
    {
        $tcp = new Config(['DB_HOST' => 'db', 'DB_PORT' => '3307', 'DB_DATABASE' => 'lms']);
        $socket = new Config(['DB_HOST' => 'db', 'DB_SOCKET' => '/var/lib/mysql/mysql.sock', 'DB_DATABASE' => 'lms']);

        $this->assertSame('mysql:host=db;port=3307;dbname=lms;charset=utf8mb4', Application::dsn($tcp));
        $this->assertSame('mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=lms;charset=utf8mb4', Application::dsn($socket));
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNotSame([], AuthService::passwordProblems('short'));
        $this->assertSame([], AuthService::passwordProblems('a long enough passphrase'));
    }
}
