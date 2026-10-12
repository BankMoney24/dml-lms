<?php

declare(strict_types=1);

namespace App\Core\View;

/** Helper object exposed to templates as `$v`. */
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
            $html .= '<p>' . nl2br($this->e(trim($block)), false) . '</p>';
        }

        return $html;
    }

    public function can(string $permission): bool
    {
        $permissions = $this->shared['permissions'] ?? [];

        return is_array($permissions) && in_array($permission, $permissions, true);
    }
}
