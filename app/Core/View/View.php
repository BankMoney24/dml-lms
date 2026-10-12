<?php

declare(strict_types=1);

namespace App\Core\View;

use RuntimeException;

/**
 * Renders plain PHP templates from resources/views. Templates receive their
 * data as variables plus a `$v` helper for escaping and shared context.
 * Every dynamic value in HTML must go through $v->e() (or a helper that does).
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $directory, private readonly string $assetVersion)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function resetShared(): void
    {
        $this->shared = [];
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $helpers = new ViewHelpers($this->shared, $this->assetVersion);
        $content = $this->renderFile($template, $data, $helpers);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content], $helpers);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data, ViewHelpers $v): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#', $template) !== 1) {
            throw new RuntimeException('Invalid template name.');
        }
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Template %s not found.', $template));
        }

        $render = static function (string $__file, array $__data, ViewHelpers $v): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $data, $v);
    }
}
