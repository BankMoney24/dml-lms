<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Site\SitePage $page
 * @var bool $saved
 * @var list<string> $problems
 * @var \App\Modules\Tenancy\Tenant $school
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$base = '/school/website/' . $page->slug;
?>
<?= $v->breadcrumbs(['Website' => '/school/website', $page->title => null]) ?>
<header class="page-head page-head-row">
  <div>
    <h1><?= $v->e($page->title) ?></h1>
    <p class="page-head-meta"><?= $page->isPublished() ? $v->badge('Published', 'ok') . ' <a href="' . $v->e($page->path()) . '">View on the website</a>' : $v->badge($saved ? 'Draft' : 'Not saved yet', 'draft') ?></p>
  </div>
  <?php if ($saved): ?><a class="button button-quiet" href="<?= $base ?>/preview">Preview</a><?php endif; ?>
</header>

<div class="teach-layout">
  <section aria-labelledby="write-h">
    <h2 id="write-h" class="visually-hidden">Write the page</h2>
    <?= $v->errorSummary($errors) ?>
    <form method="post" action="<?= $base ?>" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('title', 'Page title', ['value' => $old['title'], 'error' => $errors['title'] ?? null, 'maxlength' => 120]) ?>
      <?= $v->field('summary', 'Summary', ['type' => 'textarea', 'rows' => 2, 'value' => $old['summary'], 'error' => $errors['summary'] ?? null, 'maxlength' => 300,
          'hint' => 'One or two sentences shown under the title, in search results and when the link is shared. About 150 characters is ideal.']) ?>
      <?= $v->field('body', $page->slug === 'contact' ? 'Text under your contact details' : 'Page text', [
          'type' => 'textarea', 'rows' => 22, 'value' => $old['body'], 'error' => $errors['body'] ?? null, 'class' => 'field-code', 'optional' => $page->slug === 'contact',
          'hint' => 'Headings start with ##, lists with -, bold is **like this**. Replace every [[gap]] with your own words, or delete it.',
      ]) ?>
      <?php include __DIR__ . '/../teach/_format_help.php'; ?>
      <button type="submit" class="button"><?= $page->isPublished() ? 'Save changes' : 'Save draft' ?></button>
    </form>
  </section>

  <aside>
    <section class="panel" aria-labelledby="live-h">
      <h2 id="live-h"><?= $page->isPublished() ? 'On your website' : 'Before it goes live' ?></h2>
      <?php if ($page->isPublished()): ?>
        <p>Anyone can read this page at <a href="<?= $v->e($page->path()) ?>"><?= $v->e($page->path()) ?></a>. Saved changes show straight away.</p>
        <form method="post" action="<?= $base ?>/unpublish"><?= $v->csrfField() ?><button type="submit" class="button button-quiet button-small">Take it off the website</button></form>
      <?php elseif (!$saved): ?>
        <p>This is a starter draft. Save it, fill in the gaps, then publish.</p>
      <?php elseif ($problems !== []): ?>
        <ul class="checklist-plain"><?php foreach ($problems as $p): ?><li class="is-off"><?= $v->e($p) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <p>Ready. Read the preview once more: this page speaks for your school.</p>
        <form method="post" action="<?= $base ?>/publish"><?= $v->csrfField() ?><button type="submit" class="button button-small">Publish</button></form>
      <?php endif; ?>
      <?php if (in_array($page->slug, ['privacy', 'terms', 'refunds', 'safeguarding'], true)): ?>
        <p class="small muted">Policies are legal documents. Have someone who knows the law that applies to your school check this one before you publish it.</p>
      <?php endif; ?>
    </section>
  </aside>
</div>
