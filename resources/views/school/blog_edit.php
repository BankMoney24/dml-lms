<?php
/**
 * Write or edit a blog post (ADR 0034).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var ?\App\Modules\Blog\BlogPost $post
 * @var list<string> $problems
 * @var array<string, string> $errors
 * @var array{title: string, summary: string, body: string, category: string, slug: string} $old
 */
use App\Modules\Blog\BlogPost;

$action = $post === null ? '/school/blog' : '/school/blog/' . $post->id;
$categories = [];
foreach (BlogPost::CATEGORIES as $key => [$label]) {
    $categories[$key] = $label;
}
?>
<?= $v->breadcrumbs(['Website' => '/school/website', 'Blog' => '/school/blog', $post === null ? 'New post' : $post->title => null]) ?>
<header class="page-head page-head-row">
  <div>
    <h1><?= $post === null ? 'New post' : $v->e($post->title) ?></h1>
    <?php if ($post !== null): ?>
    <p class="page-head-meta"><?= $post->isPublished() ? $v->badge('Published', 'ok') . ' <a href="' . $v->e($post->path()) . '">View on the blog</a>' : $v->badge('Draft', 'draft') ?></p>
    <?php endif; ?>
  </div>
  <?php if ($post !== null): ?><a class="button button-quiet" href="/school/blog/<?= $post->id ?>/preview">Preview</a><?php endif; ?>
</header>

<div class="teach-layout">
  <section aria-labelledby="write-h">
    <h2 id="write-h" class="visually-hidden">Write the post</h2>
    <?= $v->errorSummary($errors) ?>
    <form method="post" action="<?= $action ?>" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('title', 'Title', ['value' => $old['title'], 'error' => $errors['title'] ?? null, 'maxlength' => 160]) ?>
      <?= $v->field('category', 'Kind of post', ['type' => 'select', 'options' => $categories, 'value' => $old['category'], 'error' => $errors['category'] ?? null]) ?>
      <?= $v->field('summary', 'Summary', ['type' => 'textarea', 'rows' => 2, 'value' => $old['summary'], 'error' => $errors['summary'] ?? null, 'maxlength' => 300,
          'hint' => 'One or two sentences shown on the blog page, in search results and when the link is shared.']) ?>
      <?= $v->field('body', 'Post', ['type' => 'textarea', 'rows' => 20, 'value' => $old['body'], 'error' => $errors['body'] ?? null, 'class' => 'field-code',
          'hint' => 'Headings start with ##, lists with -, bold is **like this**, links are [words](https://…).']) ?>
      <?php include __DIR__ . '/../teach/_format_help.php'; ?>
      <?php if ($post === null || !$post->isPublished()): ?>
        <?= $v->field('slug', 'Web address', ['value' => $old['slug'], 'error' => $errors['slug'] ?? null, 'maxlength' => 120, 'optional' => true, 'autocomplete' => 'off',
            'hint' => 'The end of the link, after /blog/. Small letters, numbers and dashes. Leave it empty to make one from the title. It cannot change once the post is published.']) ?>
      <?php else: ?>
        <p class="small muted">Web address: <?= $v->e($post->path()) ?> (kept as it is, so links people shared keep working).</p>
      <?php endif; ?>
      <button type="submit" class="button"><?= $post?->isPublished() ? 'Save changes' : 'Save draft' ?></button>
    </form>
  </section>

  <aside>
    <section class="panel" aria-labelledby="live-h">
      <h2 id="live-h"><?= $post?->isPublished() ? 'On your blog' : 'Before it goes live' ?></h2>
      <?php if ($post === null): ?>
        <p>Save a draft first. Then preview it and publish when it is ready.</p>
      <?php elseif ($post->isPublished()): ?>
        <p>Anyone can read this post at <a href="<?= $v->e($post->path()) ?>"><?= $v->e($post->path()) ?></a>. Saved changes show straight away.</p>
        <form method="post" action="/school/blog/<?= $post->id ?>/unpublish"><?= $v->csrfField() ?><button type="submit" class="button button-quiet button-small">Take it off the blog</button></form>
      <?php elseif ($problems !== []): ?>
        <ul class="checklist-plain"><?php foreach ($problems as $p): ?><li class="is-off"><?= $v->e($p) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <p>Ready. Read the preview once more: posts speak for your school.</p>
        <form method="post" action="/school/blog/<?= $post->id ?>/publish"><?= $v->csrfField() ?><button type="submit" class="button button-small">Publish</button></form>
      <?php endif; ?>
    </section>
    <section class="panel" aria-labelledby="tips-h">
      <h2 id="tips-h">Good posts</h2>
      <ul class="small">
        <li>Say what happened, when and who it is for, in the first two sentences.</li>
        <li>Name learners or show their work only with their (or their parents') permission.</li>
        <li>Give dates, prices and deadlines exactly, and update the post if they change.</li>
      </ul>
    </section>
    <?php if ($post !== null && !$post->isPublished()): ?>
    <form method="post" action="/school/blog/<?= $post->id ?>/delete" class="delete-post">
      <?= $v->csrfField() ?>
      <button type="submit" class="linklike small danger-link">Delete this draft</button>
    </form>
    <?php endif; ?>
  </aside>
</div>
