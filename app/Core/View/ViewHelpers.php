<?php

declare(strict_types=1);

namespace App\Core\View;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Helper object exposed to templates as `$v`. Besides escaping, it renders
 * the small set of shared interface pieces (fields, error summary, badges,
 * empty states, navigation links) so every screen builds them the same way.
 * Every value that reaches HTML passes through e().
 */
final class ViewHelpers
{
    /** @param array<string, mixed> $shared */
    public function __construct(private readonly array $shared, private readonly string $assetVersion)
    {
    }

    /** Escapes a value for an HTML text or double-quoted attribute context. */
    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . $this->e($this->shared['csrf_token'] ?? '') . '">';
    }

    public function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/') . '?v=' . rawurlencode($this->assetVersion);
    }

    /**
     * Renders teacher-authored plain text as paragraphs. Content is escaped;
     * no HTML from authors is trusted in this release.
     */
    public function paragraphs(string $text): string
    {
        $blocks = preg_split("/\R{2,}/", trim($text)) ?: [];
        $html = '';
        foreach ($blocks as $block) {
            // Someone's own writing: the school's word swap (ADR 0041) leaves it as written.
            $html .= '<p data-own-words>' . nl2br($this->e(trim($block)), false) . '</p>';
        }

        return $html;
    }

    /**
     * Renders lesson text and learner answers written in the Markdown subset
     * described in Markdown. Output contains only tags it builds itself.
     */
    public function markdown(string $text): string
    {
        return (new Markdown())->toHtml($text);
    }

    public function can(string $permission): bool
    {
        $permissions = $this->shared['permissions'] ?? [];

        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    /**
     * Main navigation link; marks the current section for assistive technology and styling.
     *
     * @param list<string> $alsoFor other sections that belong under this link (e.g. /people under School)
     */
    public function navLink(string $href, string $label, array $alsoFor = []): string
    {
        $path = (string) ($this->shared['path'] ?? '/');
        $current = false;
        foreach ([$href, ...$alsoFor] as $prefix) {
            $current = $current || ($prefix === '/' ? $path === '/' : ($path === $prefix || str_starts_with($path, $prefix . '/')));
        }

        return sprintf(
            '<a href="%s"%s>%s</a>',
            $this->e($href),
            $current ? ' aria-current="page"' : '',
            $this->e($label),
        );
    }

    /** A decorative icon from the sprite; the text beside it carries the meaning. */
    public function icon(string $name, string $class = ''): string
    {
        return '<svg class="icon' . ($class !== '' ? ' ' . $this->e($class) : '') . '" aria-hidden="true" focusable="false"><use href="'
            . $this->e($this->asset('icons.svg')) . '#' . $this->e(preg_replace('/[^a-z-]/', '', $name)) . '"></use></svg>';
    }

    /**
     * A sidebar link with its icon; marks the current section like navLink().
     *
     * @param list<string> $alsoFor
     */
    public function sideLink(string $href, string $label, string $icon, array $alsoFor = [], int $count = 0, ?string $short = null): string
    {
        $path = (string) ($this->shared['path'] ?? '/');
        // With $alsoFor, the link's own section is matched exactly (so /teach is not current on /teach/reviews).
        $current = $href === '/' ? $path === '/' : ($path === $href || ($alsoFor === [] && str_starts_with($path, $href . '/')));
        foreach ($alsoFor as $prefix) {
            $current = $current || $path === $prefix || str_starts_with($path, $prefix . '/');
        }

        // On tablets the menu is a narrow rail: it shows the short label, and screen readers still hear the full one.
        return sprintf(
            '<li><a href="%s"%s>%s<span class="side-text">%s</span><span class="side-short" aria-hidden="true">%s</span>%s</a></li>',
            $this->e($href),
            $current ? ' aria-current="page"' : '',
            $this->icon($icon),
            $this->e($label),
            $this->e($short ?? $label),
            $count > 0 ? '<span class="side-count">' . ($count > 99 ? '99+' : $count) . '<span class="visually-hidden"> waiting</span></span>' : '',
        );
    }

    /**
     * One labelled form control with hint and inline error, wired with
     * aria-describedby / aria-invalid.
     *
     * @param array{
     *   type?: string, value?: string|null, error?: string|null, hint?: string|null, id?: string,
     *   required?: bool, optional?: bool, rows?: int, options?: array<string, string>,
     *   autocomplete?: string, inputmode?: string, maxlength?: int, autofocus?: bool, class?: string
     * } $o
     */
    public function field(string $name, string $label, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $id = $o['id'] ?? self::idFor($name);
        $value = (string) ($o['value'] ?? '');
        $error = $o['error'] ?? null;
        $hint = $o['hint'] ?? null;

        $describedBy = [];
        if ($hint !== null) {
            $describedBy[] = $id . '-hint';
        }
        if ($error !== null) {
            $describedBy[] = $id . '-error';
        }

        $attrs = ['id' => $id, 'name' => $name];
        foreach (['autocomplete', 'inputmode', 'maxlength'] as $key) {
            if (isset($o[$key])) {
                $attrs[$key] = (string) $o[$key];
            }
        }
        $flags = '';
        if ($o['required'] ?? false) {
            $flags .= ' required';
        }
        if ($o['autofocus'] ?? false) {
            $flags .= ' autofocus';
        }
        if ($describedBy !== []) {
            $attrs['aria-describedby'] = implode(' ', $describedBy);
        }
        if ($error !== null) {
            $attrs['aria-invalid'] = 'true';
        }
        $attrHtml = '';
        foreach ($attrs as $key => $val) {
            $attrHtml .= ' ' . $key . '="' . $this->e($val) . '"';
        }
        $attrHtml .= $flags;

        $control = match ($type) {
            'textarea' => sprintf('<textarea%s rows="%d">%s</textarea>', $attrHtml, $o['rows'] ?? 4, $this->e($value)),
            'select' => sprintf('<select%s>%s</select>', $attrHtml, $this->options($o['options'] ?? [], $value)),
            default => sprintf('<input type="%s"%s value="%s">', $this->e($type), $attrHtml, $type === 'password' ? '' : $this->e($value)),
        };
        if ($type === 'password' && ($o['reveal'] ?? true)) {
            // Show / Hide appears only with scripts (app.js); without them this is a plain password box.
            $control = '<div class="reveal-row">' . $control . self::revealButton($id) . '</div>';
        }

        $html = '<div class="field' . (isset($o['class']) ? ' ' . $this->e($o['class']) : '') . '">';
        $html .= sprintf(
            '<label for="%s">%s%s</label>',
            $this->e($id),
            $this->e($label),
            ($o['optional'] ?? false) ? ' <span class="optional">(optional)</span>' : '',
        );
        if ($hint !== null) {
            $html .= sprintf('<p class="hint" id="%s-hint">%s</p>', $this->e($id), $this->e($hint));
        }
        $html .= $control;
        if ($error !== null) {
            $html .= sprintf('<p class="field-error" id="%s-error">%s</p>', $this->e($id), $this->e($error));
        }

        return $html . '</div>';
    }

    /** The Show / Hide button that sits beside a password box (hidden until app.js wires it up). */
    public static function revealButton(string $inputId): string
    {
        return '<button type="button" class="button button-quiet button-small reveal" data-reveal="' . htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" hidden><span data-reveal-word>Show</span><span class="visually-hidden"> password</span></button>';
    }

    /**
     * Summary at the top of a form that failed validation; each message links to its field.
     *
     * @param array<string, string> $errors field name => message
     * @param array<string, string> $ids    field name => element id, when it differs from the name
     */
    public function errorSummary(array $errors, array $ids = []): string
    {
        if ($errors === []) {
            return '';
        }
        $items = '';
        foreach ($errors as $field => $message) {
            $items .= sprintf('<li><a href="#%s">%s</a></li>', $this->e($ids[$field] ?? self::idFor($field)), $this->e($message));
        }

        return '<div class="notice notice-err error-summary" role="alert"><p><strong>'
            . (count($errors) === 1 ? 'There is a problem' : 'There are ' . count($errors) . ' problems')
            . '</strong></p><ul>' . $items . '</ul></div>';
    }

    /** Small status label. Tone: neutral, ok, draft, done, danger. */
    public function badge(string $text, string $tone = 'neutral'): string
    {
        return sprintf('<span class="badge badge-%s">%s</span>', $this->e($tone), $this->e($text));
    }

    /** An empty screen is an invitation to act: say what goes here and how to start. */
    public function emptyState(string $title, string $text, ?string $href = null, ?string $action = null): string
    {
        $html = '<div class="empty-state"><h2>' . $this->e($title) . '</h2><p>' . $this->e($text) . '</p>';
        if ($href !== null && $action !== null) {
            $html .= '<p><a class="button" href="' . $this->e($href) . '">' . $this->e($action) . '</a></p>';
        }

        return $html . '</div>';
    }

    /** @param array<string, string|null> $trail label => href (null for the current page) */
    public function breadcrumbs(array $trail): string
    {
        $parts = [];
        foreach ($trail as $label => $href) {
            $parts[] = $href === null
                ? '<span aria-current="page">' . $this->e($label) . '</span>'
                : '<a href="' . $this->e($href) . '">' . $this->e($label) . '</a>';
        }

        return '<nav class="crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">/</span>', $parts) . '</nav>';
    }

    /** Formats a stored UTC timestamp in the institution's time zone. */
    public function datetime(?string $utc, string $format = 'j M Y, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $tenant = $this->shared['tenant'] ?? null;
        $zone = is_object($tenant) && property_exists($tenant, 'timezone') ? (string) $tenant->timezone : 'UTC';
        try {
            $time = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));

            return $this->e($time->format($format));
        } catch (Throwable) {
            return $this->e($utc);
        }
    }

    /** "1 lesson" / "3 lessons". */
    public function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    /** @param array<string, string> $options value => label */
    private function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->e($value),
                (string) $value === $selected ? ' selected' : '',
                $this->e($label),
            );
        }

        return $html;
    }

    private static function idFor(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $name), '-');
    }
}
