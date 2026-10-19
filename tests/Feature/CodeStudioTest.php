<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Identity\Role;
use App\Modules\Playground\Challenges;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0027: the Code studio (JavaScript, Python, web pages), its sealed runners and the coding challenges. */
final class CodeStudioTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'bo@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'parent@school.test', Role::Guardian);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    /** @param array<string, string> $query */
    private function page(string $path, array $query = []): \App\Core\Http\Response
    {
        return $this->send(new Request('GET', $path, self::HOST, $query));
    }

    public function testEachLanguageOpensInItsOwnMode(): void
    {
        $this->as('ada@school.test');
        $js = $this->get(self::HOST, '/playground')->body;
        $this->assertStringContainsString('main.js', $js);
        $this->assertStringContainsString('Input: one line for each prompt()', $js);
        $this->assertStringContainsString('/assets/studio.js', $js);

        $py = $this->page('/playground', ['lang' => 'python'])->body;
        $this->assertStringContainsString('main.py', $py);
        $this->assertStringContainsString('data-language="python"', $py);
        $this->assertStringContainsString('input(&quot;What is your name? &quot;)', $py, 'the Python examples');

        $web = $this->page('/playground', ['lang' => 'web'])->body;
        $this->assertStringContainsString('index.html', $web);
        $this->assertStringContainsString('sandbox="allow-scripts" title="Preview of your page"', $web);
        $this->assertStringNotContainsString('allow-same-origin', $web);

        $this->assertStringContainsString('main.js', $this->page('/playground', ['lang' => 'cobol'])->body, 'unknown languages fall back to JavaScript');
    }

    public function testSnippetsKeepTheirLanguage(): void
    {
        $this->as('ada@school.test');
        $saved = $this->post(self::HOST, '/playground', ['title' => 'Loop', 'language' => 'python', 'code' => "for i in range(3):\n    print(i)\n"]);
        $id = (int) $this->db()->fetchValue('SELECT id FROM code_snippets');
        $this->assertRedirectTo('/playground?snippet=' . $id, $saved);
        $this->assertSame('python', $this->db()->fetchValue('SELECT language FROM code_snippets WHERE id = ?', [$id]));
        $open = $this->page('/playground', ['snippet' => (string) $id])->body;
        $this->assertStringContainsString('main.py', $open);
        $this->assertStringContainsString('snippet-lang-python', $open);

        $this->post(self::HOST, '/playground', ['title' => 'Page', 'language' => 'perl', 'code' => '<p>hi</p>']);
        $this->assertSame('javascript', $this->db()->fetchValue('SELECT language FROM code_snippets ORDER BY id DESC LIMIT 1'), 'only known languages are stored');

        $this->as('bo@school.test');
        $this->assertSame(404, $this->page('/playground', ['snippet' => (string) $id])->status, 'snippets are private');
    }

    public function testChallengesShowTheirTestsAndRecordPractice(): void
    {
        $this->as('ada@school.test');
        $list = $this->get(self::HOST, '/playground/challenges')->body;
        $this->assertStringContainsString('0 of ' . count(Challenges::all()) . '</strong> solved', $list);
        $this->assertStringContainsString('FizzBuzz', $list);

        $py = $this->page('/playground/challenges/grade', ['lang' => 'python'])->body;
        $this->assertStringContainsString('def grade(score):', $py, 'Python starter code');
        $this->assertStringContainsString('<code>grade(69)</code>', $py);
        $this->assertStringContainsString('&quot;py_expected&quot;:&quot;&#039;B&#039;&quot;', $py, 'expected values as Python writes them');
        $js = $this->get(self::HOST, '/playground/challenges/even')->body;
        $this->assertStringContainsString('function isEven(n)', $js);
        $this->assertSame(404, $this->get(self::HOST, '/playground/challenges/no-such-thing')->status);

        $this->post(self::HOST, '/playground/challenges/grade', ['language' => 'python', 'code' => 'def something_else(x): return 1']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM code_challenge_solves'), 'the function has to be there');
        $code = "def grade(score):\n    return 'A' if score >= 70 else 'F'\n";
        $this->assertRedirectTo('/playground/challenges/grade?lang=python', $this->post(self::HOST, '/playground/challenges/grade', ['language' => 'python', 'code' => $code]));
        $this->post(self::HOST, '/playground/challenges/grade', ['language' => 'python', 'code' => $code . '# again']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM code_challenge_solves'), 'solving again updates the record');
        $this->assertStringContainsString('# again', $this->page('/playground/challenges/grade', ['lang' => 'python'])->body, 'your last solution comes back');
        $this->assertStringContainsString('1 of ' . count(Challenges::all()) . '</strong> solved', $this->get(self::HOST, '/playground/challenges')->body);
        $this->assertSame(400, $this->post(self::HOST, '/playground/challenges/grade', ['language' => 'ruby', 'code' => 'x'])->status);

        $this->as('parent@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/playground/challenges')->status, 'for people who learn or teach');
    }

    public function testThePythonExpectedValuesMatchPythonsOwnWriting(): void
    {
        $this->assertSame("'A'", Challenges::pyLiteral('A'));
        $this->assertSame("'It\\'s'", Challenges::pyLiteral("It's"));
        $this->assertSame('True', Challenges::pyLiteral(true));
        $this->assertSame('[3, 9, 2]', Challenges::pyLiteral([3, 9, 2]));
        $this->assertSame('98.6', Challenges::pyLiteral(98.6));
        $this->assertSame('32.0', Challenges::pyLiteral(32.0));
        foreach (array_keys(Challenges::all()) as $key) {
            $this->assertNotSame([], Challenges::cases($key));
            $this->assertStringContainsString('def ', Challenges::starter($key, 'python'));
            $this->assertStringContainsString('function ', Challenges::starter($key, 'javascript'));
        }
    }

    public function testTheWebPreviewIsSealedOff(): void
    {
        $r = $this->get(self::HOST, '/labs/web-runner');
        $this->assertSame(200, $r->status);
        $csp = (string) $r->header('Content-Security-Policy');
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringNotContainsString("'self' ", str_replace("frame-ancestors 'self'", '', $csp), 'nothing may load from this site');
        $this->assertStringContainsString('webReady', $r->body);

        $runner = $this->get(self::HOST, '/labs/runner')->body;
        $this->assertStringContainsString("mode === 'python'", $runner, 'the runner runs Python too');
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/vendor/skulpt/skulpt.min.js');
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/vendor/skulpt/LICENSE.txt');
    }
}
