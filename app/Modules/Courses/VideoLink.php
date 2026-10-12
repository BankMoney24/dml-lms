<?php

declare(strict_types=1);

namespace App\Modules\Courses;

/**
 * A video hosted on YouTube or Vimeo. Only these two are embedded, through
 * their privacy-enhanced players (allowed in the CSP frame-src); anything
 * else is refused at authoring time so the page never frames an unknown site.
 */
final class VideoLink
{
    private function __construct(public readonly string $provider, public readonly string $id)
    {
    }

    public static function parse(string $url): ?self
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower((string) preg_replace('/^(www\.|m\.)/', '', $parts['host']));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $id = null;
        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            if ($path === '/watch' && is_string($query['v'] ?? null)) {
                $id = $query['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live)/([^/]+)#', $path, $m) === 1) {
                $id = $m[1];
            }
        } elseif ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#^/(?:video/)?(\d{5,12})(?:/|$)#', $path, $m) === 1) {
                return new self('vimeo', $m[1]);
            }

            return null;
        }

        if ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1) {
            return new self('youtube', $id);
        }

        return null;
    }

    public function embedUrl(): string
    {
        return $this->provider === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' . $this->id . '?rel=0'
            : 'https://player.vimeo.com/video/' . $this->id . '?dnt=1';
    }

    public function watchUrl(): string
    {
        return $this->provider === 'youtube'
            ? 'https://www.youtube.com/watch?v=' . $this->id
            : 'https://vimeo.com/' . $this->id;
    }

    public function providerLabel(): string
    {
        return $this->provider === 'youtube' ? 'YouTube' : 'Vimeo';
    }

    /** Normalised form stored in the database. */
    public function canonical(): string
    {
        return $this->watchUrl();
    }
}
