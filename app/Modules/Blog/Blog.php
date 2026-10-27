<?php

declare(strict_types=1);

namespace App\Modules\Blog;

use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * The school's blog (ADR 0034): news, events, stories and guides, written and
 * published by the school's administrators. Text is Markdown, shown with the same
 * safe renderer as lessons (no raw HTML, no script links).
 */
final class Blog
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly BlogRepository $posts,
        private readonly AuditLog $audit,
        private readonly ?StarterPosts $starters = null,
    ) {
    }

    /**
     * Saves the starter posts as drafts, skipping any whose address is already taken.
     * Returns how many were added. Nothing is published.
     */
    public function addStarters(Tenant $tenant, CurrentUser $admin, string $clientIp): int
    {
        $admin->authorise(Permission::SchoolManage);

        return $this->saveStarters($tenant, $admin->user->id, $clientIp);
    }

    /** For the console: the author is the school's owner. */
    public function saveStarters(Tenant $tenant, int $authorId, string $clientIp = ''): int
    {
        $added = 0;
        foreach ($this->starters?->posts($tenant) ?? [] as $p) {
            if ($this->posts->slugExists($tenant->id, $p['slug'])) {
                continue;
            }
            $id = $this->posts->create($tenant->id, $p, $authorId);
            $this->audit->record($tenant->id, $authorId, 'blog.post_saved', 'blog_post', $id, ['slug' => $p['slug'], 'via' => 'starter'], $clientIp);
            $added++;
        }

        return $added;
    }

    /**
     * @param array{title?: string, summary?: string, body?: string, category?: string, slug?: string} $in
     */
    public function save(Tenant $tenant, CurrentUser $admin, ?BlogPost $post, array $in, string $clientIp): BlogPost
    {
        $admin->authorise(Permission::SchoolManage);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')));
        $summary = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['summary'] ?? '')));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $category = (string) ($in['category'] ?? 'news');
        $slugIn = strtolower(trim((string) ($in['slug'] ?? '')));
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) {
            $errors['title'] = 'Give the post a title between 3 and 160 characters.';
        }
        if (mb_strlen($summary) > 300) {
            $errors['summary'] = 'Keep the summary under 300 characters; search results show about 155.';
        }
        if (mb_strlen($body) < 40) {
            $errors['body'] = 'Write the post: at least a few sentences.';
        } elseif (mb_strlen($body) > 60000) {
            $errors['body'] = 'Keep the post under 60,000 characters.';
        }
        if (!isset(BlogPost::CATEGORIES[$category])) {
            $errors['category'] = 'Choose what kind of post this is.';
        }
        // A published post keeps its address, so links people shared keep working.
        if ($post !== null && $post->isPublished()) {
            $slug = $post->slug;
        } else {
            $slug = $slugIn !== '' ? $slugIn : self::slugFrom($title);
            if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 100) {
                $errors['slug'] = 'Use small letters, numbers and dashes, for example open-day-2026.';
            } elseif ($this->posts->slugExists($tenant->id, $slug, $post?->id)) {
                if ($slugIn !== '') {
                    $errors['slug'] = 'Another post already uses this address. Choose another.';
                } else {
                    $base = $slug;
                    for ($n = 2; $this->posts->slugExists($tenant->id, $slug, $post?->id); $n++) {
                        $slug = $base . '-' . $n;
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data = ['slug' => $slug, 'title' => $title, 'summary' => $summary, 'body' => $body, 'category' => $category];
        if ($post === null) {
            $id = $this->posts->create($tenant->id, $data, $admin->user->id);
        } else {
            $id = $post->id;
            $this->posts->update($tenant->id, $id, $data);
        }
        $this->audit->record($tenant->id, $admin->user->id, 'blog.post_saved', 'blog_post', $id, ['slug' => $slug], $clientIp);

        return $this->posts->find($tenant->id, $id) ?? throw new \RuntimeException('Post not stored.');
    }

    /** @return list<string> what stops a post going live; empty when it can */
    public static function problems(BlogPost $post): array
    {
        $out = [];
        if (preg_match('/\[\[[^\]]*\]\]/u', $post->title . $post->summary . $post->body) === 1) {
            $out[] = 'Replace or delete the parts in [[double brackets]] with your own words.';
        }
        if (mb_strlen($post->summary) < 50) {
            $out[] = 'Write a summary of at least 50 characters: it shows on the blog page and in search results.';
        }

        return $out;
    }

    public function publish(Tenant $tenant, CurrentUser $admin, BlogPost $post, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $problems = self::problems($post);
        if ($problems !== []) {
            throw ValidationException::single('post', implode(' ', $problems));
        }
        $this->posts->setStatus($tenant->id, $post->id, BlogPost::PUBLISHED);
        $this->audit->record($tenant->id, $admin->user->id, 'blog.post_published', 'blog_post', $post->id, ['slug' => $post->slug], $clientIp);
    }

    public function unpublish(Tenant $tenant, CurrentUser $admin, BlogPost $post, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $this->posts->setStatus($tenant->id, $post->id, BlogPost::DRAFT);
        $this->audit->record($tenant->id, $admin->user->id, 'blog.post_unpublished', 'blog_post', $post->id, ['slug' => $post->slug], $clientIp);
    }

    public function delete(Tenant $tenant, CurrentUser $admin, BlogPost $post, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        if ($post->isPublished()) {
            throw ValidationException::single('post', 'Take the post off the blog before deleting it.');
        }
        $this->posts->delete($tenant->id, $post->id);
        $this->audit->record($tenant->id, $admin->user->id, 'blog.post_deleted', 'blog_post', $post->id, ['slug' => $post->slug], $clientIp);
    }

    public static function slugFrom(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? '' : $ascii), '-'));

        return rtrim(substr($slug === '' ? 'post' : $slug, 0, 90), '-');
    }
}
