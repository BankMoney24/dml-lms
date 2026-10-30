<?php

declare(strict_types=1);

namespace App\Core\View;

/**
 * Puts a school's own word in place of one of the platform's (ADR 0041): a
 * school that says "student" sees "student" wherever the platform's text says
 * "learner", in the same case and number ("Learners" becomes "Students").
 *
 * It works on a finished HTML page: text between tags, and the attributes
 * people read (placeholder, title, aria-label, alt, data-label, a button's
 * value, and a page description). It leaves alone what people wrote
 * themselves and anything that is code:
 *   - <script>, <style>, <textarea>, <pre> and <code>, with everything inside;
 *   - any element marked data-own-words, or with the class "prose" (lesson
 *     text, posts and pages written by the school), with everything inside;
 *   - every other attribute (links, form field names and values, data).
 */
final class WordSwap
{
    private const RAW = ['script', 'style', 'textarea', 'pre', 'code'];
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    private const READ_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt', 'data-label', 'aria-description'];

    /** @var array<string, array{0: string, 1: string}> lower-case word => [singular, plural] */
    private array $words = [];

    private string $pattern;

    /** @param array<string, array{0: string, 1: string}> $words platform word => the school's [singular, plural] */
    public function __construct(array $words)
    {
        foreach ($words as $from => $to) {
            if (preg_match('/^[a-z]+$/', $from) === 1 && $from !== strtolower($to[0])) {
                $this->words[$from] = $to;
            }
        }
        // Not inside an address or a file name: "/learners", "learners.csv", "?learner=", "learner-id".
        $this->pattern = $this->words === [] ? '' : '/(?<![\/=?&#.@-])\b(' . implode('|', array_keys($this->words)) . ')(s)?\b(?![\/=@-]|\.\w)/i';
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }

    public function page(string $html): string
    {
        if ($this->pattern === '') {
            return $html;
        }
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        $skipping = null;
        $depth = 0;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if ($skipping === null && $part !== '') {
                    $parts[$i] = $this->text($part);
                }
                continue;
            }
            if (str_starts_with($part, '<!--') || preg_match('/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9-]*)/', $part, $m) !== 1) {
                continue;
            }
            $closing = $m[1] === '/';
            $name = strtolower($m[2]);
            if ($skipping !== null) {
                if ($name === $skipping && !in_array($name, self::VOID, true) && !str_ends_with($part, '/>')) {
                    $depth += $closing ? -1 : 1;
                    if ($depth === 0) {
                        $skipping = null;
                    }
                }
                continue;
            }
            if ($closing) {
                continue;
            }
            if (in_array($name, self::RAW, true) || preg_match('/\sdata-own-words(?=[\s=>\/])|\sclass="[^"]*\bprose\b/', $part) === 1) {
                if (!in_array($name, self::VOID, true) && !str_ends_with($part, '/>')) {
                    $skipping = $name;
                    $depth = 1;
                }
                continue;
            }
            $parts[$i] = $this->tag($name, $part);
        }

        return implode('', $parts);
    }

    /** Swaps the words in plain text (or an attribute value, still escaped). */
    public function text(string $text): string
    {
        $found = false;
        foreach (array_keys($this->words) as $word) {
            $found = $found || stripos($text, $word) !== false;
        }
        if (!$found) {
            return $text;
        }

        return (string) preg_replace_callback($this->pattern, function (array $m): string {
            [$one, $many] = $this->words[strtolower($m[1])];
            $word = ($m[2] ?? '') !== '' ? $many : $one;
            if ($m[1] === strtoupper($m[1])) {
                return strtoupper($word);
            }

            return ctype_upper($m[1][0]) ? ucfirst($word) : $word;
        }, $text);
    }

    private function tag(string $name, string $tag): string
    {
        $attributes = self::READ_ATTRIBUTES;
        if ($name === 'input' && preg_match('/\stype="(submit|button)"/i', $tag) === 1) {
            $attributes[] = 'value';
        }
        if ($name === 'meta' && preg_match('/\s(name|property)="[^"]*(description|title)"/i', $tag) === 1) {
            $attributes[] = 'content';
        }

        return (string) preg_replace_callback(
            '/(\s(?:' . implode('|', array_map(static fn (string $a): string => preg_quote($a, '/'), $attributes)) . ')=")([^"]*)(")/i',
            fn (array $m): string => $m[1] . $this->text($m[2]) . $m[3],
            $tag,
        );
    }
}
