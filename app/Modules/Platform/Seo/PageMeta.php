<?php

declare(strict_types=1);

namespace App\Modules\Platform\Seo;

use App\Modules\Tenancy\Tenant;

/**
 * Everything a page tells search engines and link previews, built in one
 * place (ADR 0019). Controllers pass a few optional view variables; the rest
 * comes from the school and sensible defaults:
 *
 *   pageTitle        the page's own name ("Courses"); the school name is added
 *   metaDescription  one or two sentences; a default is used if missing
 *   indexable        true only for public pages meant for search results
 *   canonicalPath    the path to declare canonical (defaults to the request path)
 *   jsonLd           one schema.org node, or a list of them (merged into an @graph)
 *   seoCrumbs        [name => path, …] for a BreadcrumbList (Home is added)
 *   ogImage          an absolute or site-relative image URL, ogImageAlt its text,
 *                    ogImageSize [width, height] when known
 *   ogType           "website" by default
 *
 * Private pages always get noindex and no canonical, social image or structured data.
 */
final class PageMeta
{
    public const TITLE_MAX = 70;
    public const DESCRIPTION_MAX = 160;

    /**
     * @param array<string, mixed> $vars view variables
     * @return array{
     *   title: string, description: string, robots: string, canonical: ?string, lang: string, locale: string,
     *   siteName: string, ogTitle: string, ogType: string, ogImage: ?string, ogImageAlt: string, ogImageSize: ?array{int, int},
     *   twitterCard: string, verification: array<string, string>, jsonLd: ?array<string, mixed>
     * }
     */
    public static function build(array $vars, ?Tenant $tenant, string $baseUrl, string $path): array
    {
        $siteName = $tenant !== null ? $tenant->name : 'DML Learning Platform';
        $pageTitle = trim((string) ($vars['pageTitle'] ?? ''));
        $title = $pageTitle !== '' && $pageTitle !== $siteName ? self::clip($pageTitle, self::TITLE_MAX - mb_strlen($siteName) - 3) . ' – ' . $siteName : $siteName;

        $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($vars['metaDescription'] ?? '')));
        if ($description === '') {
            $description = $tenant !== null
                ? ($tenant->tagline !== '' ? rtrim($tenant->tagline, '.') . '. ' : '') . 'Courses, hands-on labs and certificates from ' . $tenant->name . '.'
                : 'DML Learning Platform by DML Technologies: courses, virtual labs and certificates for schools and training centres.';
        }
        $description = self::clip($description, self::DESCRIPTION_MAX);

        $indexable = ($vars['indexable'] ?? false) === true;
        $canonicalPath = (string) ($vars['canonicalPath'] ?? $path);
        $canonical = $indexable ? $baseUrl . ($canonicalPath === '/' ? '/' : $canonicalPath) : null;

        $locale = $tenant !== null ? $tenant->locale : 'en-NG';
        $lang = preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) === 1 ? $locale : 'en';

        // Social image: the page's own, else a share card for the school.
        $image = null;
        $imageAlt = '';
        $imageSize = null;
        if ($indexable) {
            $given = $vars['ogImage'] ?? null;
            if (is_string($given) && $given !== '') {
                $image = str_starts_with($given, '/') ? $baseUrl . $given : $given;
                $imageAlt = (string) ($vars['ogImageAlt'] ?? $pageTitle);
                $size = $vars['ogImageSize'] ?? null;
                $imageSize = is_array($size) && count($size) === 2 ? [(int) $size[0], (int) $size[1]] : null;
            } elseif ($tenant !== null) {
                $image = $baseUrl . '/share/site.png?v=' . ShareCard::version($tenant);
                $imageAlt = $siteName . ($tenant->tagline !== '' ? ': ' . $tenant->tagline : '');
                $imageSize = [ShareCard::WIDTH, ShareCard::HEIGHT];
            } else {
                $image = $baseUrl . '/assets/brand/og-image.jpg';
                $imageAlt = 'DML Technologies: learn, build, grow';
                $imageSize = [1200, 630];
            }
        }

        $verification = [];
        if ($indexable && $path === '/' && $tenant !== null) {
            foreach (['google' => 'google-site-verification', 'bing' => 'msvalidate.01'] as $engine => $meta) {
                $code = $tenant->searchVerification[$engine] ?? '';
                if (preg_match('/^[A-Za-z0-9_-]{8,120}$/', $code) === 1) {
                    $verification[$meta] = $code;
                }
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'robots' => $indexable ? 'index, follow, max-image-preview:large' : 'noindex, nofollow',
            'canonical' => $canonical,
            'lang' => $lang,
            'locale' => str_replace('-', '_', $lang),
            'siteName' => $siteName,
            'ogTitle' => $pageTitle !== '' ? $pageTitle : $siteName,
            'ogType' => (string) ($vars['ogType'] ?? 'website'),
            'ogImage' => $image,
            'ogImageAlt' => self::clip($imageAlt, 420),
            'ogImageSize' => $imageSize,
            'twitterCard' => $imageSize !== null && $imageSize[0] >= 600 ? 'summary_large_image' : 'summary',
            'verification' => $verification,
            'jsonLd' => $indexable ? self::graph($vars, $baseUrl, $canonical) : null,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     * @return ?array<string, mixed>
     */
    private static function graph(array $vars, string $baseUrl, ?string $canonical): ?array
    {
        $nodes = [];
        $given = $vars['jsonLd'] ?? null;
        if (is_array($given) && $given !== []) {
            foreach (array_is_list($given) ? $given : [$given] as $node) {
                if (is_array($node)) {
                    unset($node['@context']);
                    $nodes[] = $node;
                }
            }
        }
        $crumbs = $vars['seoCrumbs'] ?? null;
        if (is_array($crumbs) && $crumbs !== [] && $canonical !== null) {
            $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $baseUrl . '/']];
            foreach ($crumbs as $name => $crumbPath) {
                $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => (string) $name, 'item' => $baseUrl . (string) $crumbPath];
            }
            $nodes[] = ['@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumbs', 'itemListElement' => $items];
        }

        return $nodes === [] ? null : ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    /** Shortens at a word boundary and adds an ellipsis. */
    public static function clip(string $text, int $max): string
    {
        $max = max(10, $max);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:–-") . '…';
    }

    /**
     * JSON for a <script type="application/ld+json"> block: no "</script>" or HTML can break out.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
