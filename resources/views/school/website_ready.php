<?php
/**
 * School → Website → Put your pages on the website: every page not yet published,
 * what stops it, what each full draft decides, and one form to publish the
 * ones the administrator has read.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var array<string, array{page: ?\App\Modules\Site\SitePage, title: string, problems: list<string>, hasDraft: bool, choices: list<string>, gaps: bool}> $rows
 * @var bool $blogLive the blog has a published post, so it is in the menu
 * @var int $blogDrafts
 */
$blogLive ??= true;
$blogDrafts ??= 0;
$schoolTab = 'website';
$draftable = array_filter($rows, static fn (array $r): bool => $r['gaps'] && $r['hasDraft']);
$ready = array_filter($rows, static fn (array $r): bool => $r['problems'] === []);
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Website' => '/school/website', 'Put your pages on the website' => null]) ?>
  <h1>Put your pages on the website</h1>
  <p class="lead">Visitors only see pages you publish. Until then, About us, Contact and the policies are missing from your website's menu and footer.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if ($rows === []): ?>
  <?= $v->emptyState('Every page is on your website', 'About us, Admissions, Contact, the questions and answers, Careers and every policy are published and linked from the footer.', '/school/website', 'Back to Website') ?>
  <?php if (!$blogLive): ?>
  <p class="notice ready-blog">Your blog is not on the website yet: it appears in the menu once one post is published. <?= $blogDrafts > 0 ? $v->e($v->plural($blogDrafts, 'draft post')) . ' ' . ($blogDrafts === 1 ? 'is' : 'are') . ' waiting.' : 'Start with the ready-made welcome posts.' ?> <a href="/school/blog">Open the blog</a></p>
  <?php endif; ?>
<?php else: ?>
<ol class="ready-steps">
  <li class="ready-step<?= $draftable === [] ? ' is-done' : '' ?>">
    <h2>Write the missing pages</h2>
    <?php if ($draftable !== []): ?>
      <p><?= $v->e($v->plural(count($draftable), 'page')) ?> <?= count($draftable) === 1 ? 'is' : 'are' ?> not written yet or still <?= count($draftable) === 1 ? 'has' : 'have' ?> gaps: <?= $v->e(implode(', ', array_map(static fn (array $r): string => $r['title'], $draftable))) ?>. A full draft is written for each from your school's settings and courses. Pages you have written yourself are kept.</p>
      <form method="post" action="/school/website/ready/drafts">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Write <?= count($draftable) === 1 ? 'the draft' : 'the ' . count($draftable) . ' drafts' ?></button>
      </form>
      <p class="small muted">Nothing goes live yet. You read each page and publish it in the next step.</p>
    <?php else: ?>
      <p class="muted">Every page has a draft.</p>
    <?php endif; ?>
  </li>
  <li class="ready-step">
    <h2>Read them, then publish</h2>
    <p>Open each page with <strong>Read it</strong>. Where a draft makes a choice for your school, it is listed under the page: change the page if the choice is not right for you.</p>
    <form method="post" action="/school/website/ready/publish" class="ready-form">
      <?= $v->csrfField() ?>
      <ul class="ready-list">
        <?php foreach ($rows as $slug => $r): $ok = $r['problems'] === []; ?>
        <li class="ready-item<?= $ok ? '' : ' is-blocked' ?>">
          <label class="choice ready-tick">
            <input type="checkbox" name="pages[]" value="<?= $v->e($slug) ?>"<?= $ok ? ' checked' : ' disabled' ?>>
            <span><strong><?= $v->e($r['title']) ?></strong> <span class="small muted">/<?= $v->e($slug) ?></span></span>
          </label>
          <div class="ready-detail">
            <?php if ($ok): ?>
              <?= $v->badge('Ready to publish', 'done') ?>
            <?php else: ?>
              <?= $v->badge('Not ready', 'draft') ?>
              <ul class="small ready-problems"><?php foreach ($r['problems'] as $problem): ?><li><?= $v->e($problem) ?></li><?php endforeach; ?></ul>
              <?php if ($slug === 'contact'): ?><p class="small"><a href="/school#s-look">Add the email or phone in Settings</a></p><?php endif; ?>
            <?php endif; ?>
            <?php if ($r['choices'] !== [] && $r['page'] !== null && !$r['gaps']): ?>
              <details class="ready-choices">
                <summary>What the draft decides</summary>
                <ul class="small"><?php foreach ($r['choices'] as $choice): ?><li><?= $v->e($choice) ?></li><?php endforeach; ?></ul>
              </details>
            <?php endif; ?>
            <p class="small ready-links">
              <?php if ($r['page'] !== null): ?><a href="/school/website/<?= $v->e($slug) ?>/preview">Read it<span class="visually-hidden">: <?= $v->e($r['title']) ?></span></a><?php endif; ?>
              <a href="/school/website/<?= $v->e($slug) ?>">Edit<span class="visually-hidden"> <?= $v->e($r['title']) ?></span></a>
            </p>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <button type="submit" class="button"<?= $ready === [] ? ' disabled' : '' ?>>Publish the ticked pages</button>
      <?php if ($ready === []): ?><p class="small muted">Write the drafts first, or fill the gaps in your own drafts.</p><?php endif; ?>
    </form>
  </li>
  <?php if (!$blogLive): ?>
  <li class="ready-step">
    <h2>News on your blog</h2>
    <p>The blog appears in the menu once one post is published. <?= $blogDrafts > 0
        ? $v->e($v->plural($blogDrafts, 'draft post')) . ' ' . ($blogDrafts === 1 ? 'is' : 'are') . ' waiting for you to read and publish.'
        : 'Start with the ready-made posts (a welcome, how to study here, and your courses), or write your own.' ?></p>
    <p><a class="button button-quiet" href="/school/blog">Open the blog</a></p>
  </li>
  <?php endif; ?>
</ol>
<?php endif; ?>
