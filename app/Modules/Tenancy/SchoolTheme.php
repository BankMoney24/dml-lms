<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * The colour themes a school can choose. Each is a class in app.css that
 * redefines the primary colour for light and dark mode; every pair was
 * checked for WCAG AA contrast (links on the page background, button text
 * on the button), which a free colour picker could not guarantee. Themes
 * are classes rather than inline styles because the CSP forbids those.
 */
final class SchoolTheme
{
    public const DEFAULT = 'dml';

    /** key => [label, primary colour in light mode] */
    private const THEMES = [
        'dml' => ['DML blue', '#1670ad'],
        'teal' => ['Teal', '#0b7a6b'],
        'blue' => ['Blue', '#1d5fb8'],
        'green' => ['Green', '#2f7a2f'],
        'plum' => ['Plum', '#7a3d8f'],
        'red' => ['Red', '#b0302a'],
        'amber' => ['Amber', '#9a5800'],
        'navy' => ['Navy', '#14213d'],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::THEMES[$key]);
    }

    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return array_map(static fn (array $t): string => $t[0], self::THEMES);
    }

    public static function label(string $key): string
    {
        return self::THEMES[$key][0] ?? self::THEMES[self::DEFAULT][0];
    }

    /** For <meta name="theme-color">. */
    public static function colour(string $key): string
    {
        return self::THEMES[$key][1] ?? self::THEMES[self::DEFAULT][1];
    }
}
