<?php
/**
 * School → Website → Blog (ADR 0034).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Blog\BlogPost> $posts
 * @var list<string> $starterChoices
 */
$schoolTab = 'website';
$live = count(array_filter($posts, static fn ($p): bool => $p->isPublished()));
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['Website' => '/school/website', 'Blog' => null]) ?>
    <h1>Blog</h1>
    <p class="lead">News, events, learners' stories and guides. Published posts appear at <?= $live > 0 ? '<a href="/blog">/blog</a>' : '/blog' ?>, in your sitemap and in the blog's RSS feed.</p>
  </div>
  <a class="button" href="/school/blog/new">Write a post</a>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if ($posts === []): ?>
  <section class="empty-state">
    <h2>No posts yet</h2>
    <p>A short post about something real is enough: the start of term, an open day, what a class built, how to pay fees safely. The blog appears on your website, with a link in the menu, once the first post is published.</p>
    <p><a class="button button-small" href="/school/blog/new">Write the first post</a></p>
  </section>
<?php endif; ?>

<details class="panel blog-starters"<?= $posts === [] ? ' open' : '' ?>>
  <summary>Add starter drafts</summary>
  <p class="small">Three or four ready-written drafts to edit: a welcome post, learning on a phone with little data, how to spot a payment scam and, if you have everyday learning courses, a post about them. They use only your school's settings and courses, and stay drafts until you publish them. They make this choice for you:</p>
  <ul class="small"><?php foreach ($starterChoices as $choice): ?><li><?= $v->e($choice) ?></li><?php endforeach; ?></ul>
  <form method="post" action="/school/blog/starters"><?= $v->csrfField() ?><button type="submit" class="button button-small button-quiet">Add the starter drafts</button></form>
</details>

<?php if ($posts !== []): ?>
<h2 class="visually-hidden">Posts</h2>
<div class="table-wrap">
  <table class="table table-stack" data-sortable>
    <thead><tr><th scope="col">Title</th><th scope="col">Kind</th><th scope="col">Status</th><th scope="col">Date</th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p): $when = $p->publishedAt ?? $p->updatedAt; ?>
      <tr>
        <th scope="row" class="blog-admin-title"><a href="/school/blog/<?= $p->id ?>"><?= $v->e($p->title) ?></a><span class="small muted"><?= $v->e($p->path()) ?></span></th>
        <td data-label="Kind"><?= $v->e($p->categoryLabel()) ?></td>
        <td data-label="Status"><?= $p->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft') ?></td>
        <td data-label="Date" data-sort="<?= $v->e($when) ?>"><?= $v->datetime($when, 'j M Y') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
