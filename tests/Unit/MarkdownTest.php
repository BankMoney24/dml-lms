<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private function html(string $source): string
    {
        return (new Markdown())->toHtml($source);
    }

    public function testRawHtmlIsAlwaysShownAsText(): void
    {
        $html = $this->html("<script>alert(1)</script>\n\n**<img src=x onerror=alert(1)>**\n\n- <b>x</b>\n\n| <i>h</i> |\n|---|\n| <svg> |");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOnlySafeLinkTargetsBecomeLinks(): void
    {
        $this->assertStringContainsString(
            '<a href="https://example.org/a?b=1&amp;c=2" rel="noopener noreferrer nofollow" class="external">docs</a>',
            $this->html('[docs](https://example.org/a?b=1&c=2)'),
        );
        $this->assertStringContainsString('<a href="/courses">all</a>', $this->html('[all](/courses)'));
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example', 'vbscript:x'] as $bad) {
            $html = $this->html('[click](' . $bad . ')');
            $this->assertStringNotContainsString('<a ', $html, $bad);
        }
        $this->assertStringNotContainsString('" onmouseover', $this->html('[x](https://a.example/"onmouseover="alert(1))'));
    }

    public function testCodeBlocksKeepTextExactly(): void
    {
        $html = $this->html("```bash\nsudo echo \"**not bold**\" > /tmp/x && ls <dir>\n  indented\n```");

        $this->assertStringContainsString('<span class="code-lang">bash</span>', $html);
        $this->assertStringContainsString('sudo echo &quot;**not bold**&quot; &gt; /tmp/x &amp;&amp; ls &lt;dir&gt;' . "\n" . '  indented', $html);
        $this->assertStringNotContainsString('<strong>', $html);
    }

    public function testHeadingsListsCalloutsAndTables(): void
    {
        $html = $this->html(<<<'MD'
        # Top
        ### Sub

        - one
        - two
          - nested `code`

        1. first
        2. second

        > **Warning:** Be careful.

        | Cmd | Use |
        |---|---:|
        | `ls` | list |
        MD);

        $this->assertStringContainsString('<h2>Top</h2><h3>Sub</h3>', $html, 'the page owns h1');
        $this->assertStringContainsString('<ul><li>one</li><li>two<ul><li>nested <code>code</code></li></ul></li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<aside class="callout callout-warning"><p class="callout-title">Warning</p><p>Be careful.</p></aside>', $html);
        $this->assertStringContainsString('<th scope="col" class="align-right">Use</th>', $html);
        $this->assertStringContainsString('<td><code>ls</code></td>', $html);
    }

    public function testEverydayTextIsNotMangled(): void
    {
        $html = $this->html("Use snake_case_names and 2*3*4 and file_*.txt.\nSecond line.");

        $this->assertSame('<p>Use snake_case_names and 2*3*4 and file_*.txt.<br>Second line.</p>', $html);
    }

    public function testPlainTextFromBeforeMarkdownStillReadsTheSame(): void
    {
        $this->assertSame('<p>Para one.</p><p>Para two<br>with a break.</p>', $this->html("Para one.\n\nPara two\nwith a break."));
    }

    public function testDeepNestingIsBounded(): void
    {
        $html = $this->html(str_repeat('> ', 200) . 'deep');

        $this->assertStringContainsString('deep', $html);
        $this->assertLessThan(20, substr_count($html, '<blockquote>'));
    }
}
