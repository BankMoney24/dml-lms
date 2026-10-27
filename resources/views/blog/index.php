<?php
/**
 * The school's blog (ADR 0034): newest first, filtered by kind of post.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Blog\BlogPost> $posts
 * @var \App\Core\View\Pager $pager
 * @var ?string $category
 * @var array<string, int> $counts
 * @var \App\Modules\Tenancy\Tenant $school
 */
use App\Modules\Blog\BlogPost;

$total = array_sum($counts);
?>
<?= $v->breadcrumbs(['Home' => '/', 'Blog' => $category === null ? null : '/blog'] + ($category === null ? [] : [BlogPost::CATEGORIES[$category][1] => null])) ?>
<header class="page-head blog-head">
  <h1><?= $category === null ? 'Blog' : $v->e(BlogPost::CATEGORIES[$category][1]) ?></h1>
  <p class="lead">News, events, stories and guides from <?= $v->e($school->name) ?>.</p>
</header>

<?php if (count(array_filter($counts)) > 1): ?>
<nav class="tabs" aria-label="Kind of post">
  <a href="/blog"<?= $category === null ? ' aria-current="page"' : '' ?>>All <span class="tab-count">(<?= $total ?>)</span></a>
  <?php foreach (BlogPost::CATEGORIES as $key => [, $plural]): if (($counts[$key] ?? 0) === 0) { continue; } ?>
    <a href="/blog?category=<?= $key ?>"<?= $category === $key ? ' aria-current="page"' : '' ?>><?= $v->e($plural) ?> <span class="tab-count">(<?= (int) $counts[$key] ?>)</span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($posts === []): ?>
  <p class="muted">Nothing here yet. <a href="/blog">See every post</a>.</p>
<?php else: ?>
<ol class="post-list" role="list">
  <?php foreach ($posts as $i => $post):
      $lead = $i === 0 && $pager->page === 1 && $category === null;
      $at = $post->publishedAt ?? $post->updatedAt; ?>
  <li class="post-item<?= $lead ? ' post-lead' : '' ?>">
    <time class="post-date" datetime="<?= $v->datetime($at, 'Y-m-d') ?>"><span class="post-day"><?= $v->datetime($at, 'j') ?></span><span class="post-month"><?= $v->datetime($at, 'M Y') ?></span></time>
    <div class="post-body">
      <p class="post-kind"><?= $v->e($post->categoryLabel()) ?></p>
      <h2 class="post-title"><a href="<?= $v->e($post->path()) ?>"><?= $v->e($post->title) ?></a></h2>
      <?php if ($post->summary !== ''): ?><p class="post-summary"><?= $v->e($post->summary) ?></p><?php endif; ?>
      <p class="post-meta small muted"><?= $v->e($v->plural($post->readingMinutes(), 'minute')) ?> to read<?= $post->authorName !== '' ? ', by ' . $v->e($post->authorName) : '' ?></p>
    </div>
  </li>
  <?php endforeach; ?>
</ol>
<?php $pagerLabel = 'Blog pages'; include __DIR__ . '/../partials/pager.php'; ?>
<?php endif; ?>

<p class="blog-feed small"><a href="/blog/feed.xml" type="application/rss+xml">Follow with a feed reader (RSS)</a></p>
