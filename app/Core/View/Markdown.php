<?php

declare(strict_types=1);

namespace App\Core\View;

/**
 * Renders the small Markdown dialect used for lesson text and learner
 * answers. Safety comes from construction: every piece of author text is
 * HTML-escaped, and the only tags in the output are the ones written here.
 * Raw HTML in the source is shown as text, never interpreted.
 *
 * Supported:
 *   # / ## / ### / ####   headings (# and ## both become <h2>; the page owns <h1>)
 *   blank line            new paragraph; a single line break stays a line break
 *   - item / 1. item      lists, nested by indenting two or more spaces
 *   ```lang ... ```       code block with a language label
 *   `code`  **bold**  *italic*  [text](https://… or /path)
 *   > text                quote; "> **Note:**", "Tip:", "Warning:", "Important:" make callouts
 *   | a | b |             tables (second line |---|:---:|---:| sets alignment)
 *   ---                   horizontal rule
 */
final class Markdown
{
    private const MAX_DEPTH = 6;
    private const CALLOUTS = ['note' => 'Note', 'tip' => 'Tip', 'warning' => 'Warning', 'important' => 'Important'];

    public function toHtml(string $source): string
    {
        $source = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $source);

        return $this->blocks(explode("\n", $source), 0);
    }

    /** Plain-text word count, for reading-time estimates. */
    public static function wordCount(string $source): int
    {
        return str_word_count((string) preg_replace('/```.*?```/s', ' ', $source));
    }

    /** @param list<string> $lines */
    private function blocks(array $lines, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<p>' . $this->e(implode("\n", $lines)) . '</p>';
        }
        $html = '';
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '') {
                $i++;
                continue;
            }

            // Fenced code block.
            if (preg_match('/^\s*```\s*([A-Za-z0-9_+#.-]{0,20})\s*$/', $line, $m) === 1) {
                $code = [];
                $i++;
                while ($i < $count && preg_match('/^\s*```\s*$/', $lines[$i]) !== 1) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // closing fence (or end of text)
                $html .= $this->codeBlock($m[1], $code);
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*\s*$/', $trimmed, $m) === 1) {
                $level = max(2, strlen($m[1]));
                $html .= sprintf('<h%d>%s</h%d>', $level, $this->inline($m[2]), $level);
                $i++;
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                $html .= '<hr>';
                $i++;
                continue;
            }

            if (str_starts_with($trimmed, '>')) {
                $quoted = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '>')) {
                    $quoted[] = (string) preg_replace('/^\s*>\s?/', '', $lines[$i]);
                    $i++;
                }
                $html .= $this->quote($quoted, $depth);
                continue;
            }

            if ($this->isTableStart($lines, $i)) {
                $rows = [];
                while ($i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = $lines[$i];
                    $i++;
                }
                $html .= $this->table($rows);
                continue;
            }

            if ($this->listMarker($line) !== null) {
                [$block, $i] = $this->collectList($lines, $i);
                $html .= $this->listHtml($block, $depth);
                continue;
            }

            // Paragraph: until a blank line or the start of another block.
            $para = [];
            while ($i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines, $i)) {
                $para[] = trim($lines[$i]);
                $i++;
            }
            if ($para === []) {
                // A line that looks like a block start but did not parse as one.
                $para[] = trim($lines[$i]);
                $i++;
            }
            $html .= '<p>' . implode('<br>', array_map($this->inline(...), $para)) . '</p>';
        }

        return $html;
    }

    /** @param list<string> $code */
    private function codeBlock(string $language, array $code): string
    {
        // Remove the indentation shared by every line (code inside lists).
        $indent = PHP_INT_MAX;
        foreach ($code as $line) {
            if (trim($line) !== '') {
                $indent = min($indent, $this->indent($line));
            }
        }
        $indent = $indent === PHP_INT_MAX ? 0 : $indent;
        $text = implode("\n", array_map(static fn (string $l): string => (string) substr($l, $indent), $code));
        $language = strtolower($language);
        $label = $language === '' ? '' : sprintf('<span class="code-lang">%s</span>', $this->e($language));

        return sprintf(
            '<div class="code-block">%s<pre><code%s>%s</code></pre></div>',
            $label,
            $language === '' ? '' : ' class="language-' . $this->e($language) . '"',
            $this->e($text),
        );
    }

    /** @param list<string> $lines */
    private function quote(array $lines, int $depth): string
    {
        $first = trim($lines[0] ?? '');
        if (preg_match('/^\*\*(Note|Tip|Warning|Important):?\*\*:?\s*(.*)$/i', $first, $m) === 1) {
            $kind = strtolower($m[1]);
            $lines[0] = $m[2];

            return sprintf(
                '<aside class="callout callout-%s"><p class="callout-title">%s</p>%s</aside>',
                $kind,
                self::CALLOUTS[$kind],
                $this->blocks($lines, $depth + 1),
            );
        }

        return '<blockquote>' . $this->blocks($lines, $depth + 1) . '</blockquote>';
    }

    /** @param list<string> $lines */
    private function isTableStart(array $lines, int $i): bool
    {
        return str_contains($lines[$i], '|')
            && isset($lines[$i + 1])
            && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1;
    }

    /** @param list<string> $rows */
    private function table(array $rows): string
    {
        $cells = static function (string $row): array {
            $row = trim($row);
            $row = (string) preg_replace('/^\||\|$/', '', $row);

            return array_map('trim', explode('|', $row));
        };
        $head = $cells($rows[0]);
        $align = array_map(static function (string $spec): string {
            $left = str_starts_with($spec, ':');
            $right = str_ends_with($spec, ':');

            return match (true) {
                $left && $right => 'center',
                $right => 'right',
                default => '',
            };
        }, $cells($rows[1] ?? ''));

        $cell = function (string $tag, string $text, int $col) use ($align): string {
            $class = ($align[$col] ?? '') !== '' ? ' class="align-' . $align[$col] . '"' : '';
            $scope = $tag === 'th' ? ' scope="col"' : '';

            return sprintf('<%s%s%s>%s</%s>', $tag, $scope, $class, $this->inline($text), $tag);
        };

        $html = '<div class="table-wrap"><table class="table md-table"><thead><tr>';
        foreach ($head as $col => $text) {
            $html .= $cell('th', $text, $col);
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 2) as $row) {
            $html .= '<tr>';
            $values = $cells($row);
            foreach (array_keys($head) as $col) {
                $html .= $cell('td', $values[$col] ?? '', $col);
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @param list<string> $lines lines of one list, starting with a marker line
     */
    private function listHtml(array $lines, int $depth): string
    {
        $baseIndent = $this->indent($lines[0]);
        $ordered = $this->listMarker($lines[0]) === 'ol';
        $start = 1;
        if ($ordered && preg_match('/^\s*(\d{1,6})[.)]/', $lines[0], $m) === 1) {
            $start = (int) $m[1];
        }

        $items = [];
        foreach ($lines as $line) {
            if ($this->indent($line) === $baseIndent && $this->listMarker($line) !== null) {
                $items[] = [(string) preg_replace('/^\s*(?:[-*+]|\d{1,6}[.)])\s+/', '', $line)];
            } elseif ($items !== []) {
                $items[count($items) - 1][] = $line;
            }
        }

        $html = $ordered ? ($start !== 1 ? sprintf('<ol start="%d">', $start) : '<ol>') : '<ul>';
        foreach ($items as $item) {
            $first = array_shift($item);
            $rest = $item;
            $text = [$first];
            // Continuation lines of the same item (not nested blocks) join the first line.
            while ($rest !== [] && $this->listMarker($rest[0]) === null && !str_contains($rest[0], '```') && trim($rest[0]) !== '') {
                $text[] = trim(array_shift($rest));
            }
            $html .= '<li>' . implode('<br>', array_map($this->inline(...), $text));
            if ($rest !== []) {
                $html .= $this->blocks($this->dedent($rest), $depth + 1);
            }
            $html .= '</li>';
        }

        return $html . ($ordered ? '</ol>' : '</ul>');
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function dedent(array $lines): array
    {
        $indent = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $indent = min($indent, $this->indent($line));
            }
        }
        $indent = $indent === PHP_INT_MAX ? 0 : $indent;

        return array_map(static fn (string $l): string => (string) substr($l, $indent), $lines);
    }

    /**
     * Lines of one list: items at the first item's indentation, plus anything
     * indented further (nested lists, code, continuation paragraphs).
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the list's lines and the index after it
     */
    private function collectList(array $lines, int $i): array
    {
        $count = count($lines);
        $base = $this->indent($lines[$i]);
        $type = $this->listMarker($lines[$i]);
        $block = [];
        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $j = $i + 1;
                while ($j < $count && trim($lines[$j]) === '') {
                    $j++;
                }
                $next = $lines[$j] ?? '';
                $continues = $j < $count
                    && ($this->indent($next) > $base || ($this->indent($next) === $base && $this->listMarker($next) === $type));
                if (!$continues) {
                    break;
                }
                $block[] = '';
                $i++;
                continue;
            }
            $indent = $this->indent($line);
            if ($indent < $base || ($indent === $base && $this->listMarker($line) !== $type)) {
                break;
            }
            $block[] = $line;
            $i++;
        }

        return [$block, $i];
    }

    /** @param list<string> $lines */
    private function startsBlock(array $lines, int $i): bool
    {
        $line = $lines[$i];
        $trimmed = trim($line);

        return preg_match('/^\s*```/', $line) === 1
            || preg_match('/^#{1,4}\s/', $trimmed) === 1
            || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1
            || str_starts_with($trimmed, '>')
            || $this->listMarker($line) !== null
            || $this->isTableStart($lines, $i);
    }

    private function listMarker(string $line): ?string
    {
        if (preg_match('/^\s*[-*+]\s+\S/', $line) === 1) {
            return 'ul';
        }
        if (preg_match('/^\s*\d{1,6}[.)]\s+\S/', $line) === 1) {
            return 'ol';
        }

        return null;
    }

    private function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /** Inline formatting. Text between recognised tokens is escaped. */
    private function inline(string $text): string
    {
        $pattern = '/`([^`]+)`'                                   // code
            . '|\[([^\]]+)\]\(([^)\s]+)\)'                        // link
            . '|\*\*(?=\S)(.+?)(?<=\S)\*\*(?!\*)'                // bold
            . '|(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/u'; // italic

        $out = '';
        $offset = 0;
        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            [$match, $start] = $m[0];
            $match = (string) $match;
            $out .= $this->e(substr($text, $offset, $start - $offset));
            $offset = $start + strlen($match);

            $code = $m[1][0] ?? null;
            $label = $m[2][0] ?? null;
            $bold = $m[4][0] ?? null;
            $italic = $m[5][0] ?? null;
            if ($code !== null) {
                $out .= '<code>' . $this->e($code) . '</code>';
            } elseif ($label !== null) {
                $out .= $this->link($label, (string) ($m[3][0] ?? ''));
            } elseif ($bold !== null) {
                $out .= '<strong>' . $this->inline($bold) . '</strong>';
            } elseif ($italic !== null) {
                $out .= '<em>' . $this->inline($italic) . '</em>';
            }
        }

        return $out . $this->e(substr($text, $offset));
    }

    private function link(string $label, string $url): string
    {
        $safe = preg_match('#^(https?://[^\s"<>]+|/[^/\s"<>][^\s"<>]*|/|\#[\w-]+)$#i', $url) === 1;
        if (!$safe) {
            // Unknown scheme (javascript:, data:, …): show the text without a link.
            return $this->e($label);
        }
        $external = preg_match('#^https?://#i', $url) === 1;

        return sprintf(
            '<a href="%s"%s>%s</a>',
            $this->e($url),
            $external ? ' rel="noopener noreferrer nofollow" class="external"' : '',
            $this->inline($label),
        );
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
