<?php
/**
 * One blog post (ADR 0034). Also the school's preview of a draft.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Blog\BlogPost $post
 * @var bool $preview
 * @var list<\App\Modules\Blog\BlogPost> $more
 */
$problems = $preview ? \App\Modules\Blog\Blog::problems($post) : [];
$at = $post->publishedAt ?? $post->updatedAt;
?>
<?php if ($preview): ?>
<div class="notice notice-err" role="status">
  <p><strong>Preview.</strong> <?= $post->isPublished() ? 'This is how the saved post looks.' : 'This draft is not on your blog yet.' ?> <a href="/school/blog/<?= $post->id ?>">Back to editing</a></p>
  <?php if ($problems !== []): ?><ul><?php foreach ($problems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
<?= $v->breadcrumbs(['Home' => '/', 'Blog' => '/blog', $post->title => null]) ?>
<article class="site-page blog-post">
  <header class="page-head">
    <p class="post-kind"><a href="/blog?category=<?= $v->e($post->category) ?>"><?= $v->e($post->categoryLabel()) ?></a></p>
    <h1><?= $v->e($post->title) ?></h1>
    <?php if ($post->summary !== ''): ?><p class="lead"><?= $v->e($post->summary) ?></p><?php endif; ?>
    <p class="post-byline small muted">
      <?php if ($post->isPublished()): ?><time datetime="<?= $v->datetime($at, 'Y-m-d') ?>"><?= $v->datetime($at, 'j F Y') ?></time><?php else: ?>Not published yet<?php endif; ?>,
      <?= $v->e($v->plural($post->readingMinutes(), 'minute')) ?> to read<?= $post->authorName !== '' ? ', by ' . $v->e($post->authorName) : '' ?>
    </p>
  </header>

  <div class="prose"><?= $v->markdown($post->body) ?></div>

  <?php if ($post->isPublished() && $post->updatedAt !== '' && substr($post->updatedAt, 0, 10) !== substr((string) $post->publishedAt, 0, 10)): ?>
    <p class="small muted site-updated">Updated <?= $v->datetime($post->updatedAt, 'j F Y') ?></p>
  <?php endif; ?>
</article>

<?php if ($more !== []): ?>
<section class="post-more" aria-labelledby="more-h">
  <h2 id="more-h">More from the blog</h2>
  <ul class="post-more-list">
    <?php foreach ($more as $m): ?>
    <li>
      <p class="post-kind"><?= $v->e($m->categoryLabel()) ?></p>
      <a href="<?= $v->e($m->path()) ?>"><?= $v->e($m->title) ?></a>
      <p class="small muted"><?= $v->datetime($m->publishedAt ?? $m->updatedAt, 'j M Y') ?></p>
    </li>
    <?php endforeach; ?>
  </ul>
  <p><a href="/blog">Every post</a></p>
</section>
<?php endif; ?>
