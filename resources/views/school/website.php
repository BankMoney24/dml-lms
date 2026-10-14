<?php
/**
 * School → Website: the public pages, official profiles and search console codes.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var array<string, \App\Modules\Site\SitePage> $pages
 * @var string $baseUrl
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use App\Modules\Site\SitePages;

$schoolTab = 'website';
$live = count(array_filter($pages, static fn ($p): bool => $p->isPublished()));
?>
<header class="page-head">
  <h1>Your school</h1>
  <p class="lead">The public pages of <?= $v->e($school->name) ?>'s website, and how search engines and social networks find it.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<div class="teach-layout">
  <section aria-labelledby="pages-h">
    <h2 id="pages-h">Pages <span class="muted small"><?= $live ?> of <?= count(SitePages::PAGES) ?> published</span></h2>
    <p class="muted">Published pages appear in the footer of every page and in your sitemap. Each one starts as a draft with gaps in [[double brackets]] for facts only your school can give; it can go live once they are filled in.</p>
    <ul class="site-page-list">
      <?php foreach (SitePages::PAGES as $slug => [$title, , $hint]): $p = $pages[$slug] ?? null; ?>
      <li>
        <a href="/school/website/<?= $slug ?>"><span class="site-page-title"><?= $v->e($p?->title ?? $title) ?></span><span class="small muted"><?= $v->e($hint) ?></span></a>
        <?= $p === null ? $v->badge('Not started') : ($p->isPublished() ? $v->badge('Published', 'ok') : $v->badge('Draft', 'draft')) ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <aside>
    <section class="panel" aria-labelledby="find-h">
      <h2 id="find-h">Search engines and social networks</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/school/website-settings" class="form" novalidate>
        <?= $v->csrfField() ?>
        <fieldset class="field">
          <legend>Official profiles</legend>
          <p class="hint">Only pages your school runs. They are linked from the contact page and tell search engines these accounts are yours.</p>
          <?php foreach (SitePages::SOCIAL as $key => [$label, $hosts]): ?>
            <?= $v->field('social_' . $key, $label, ['value' => $old['social_' . $key] ?? '', 'error' => $errors['social_' . $key] ?? null, 'inputmode' => 'url', 'maxlength' => 200, 'optional' => true]) ?>
          <?php endforeach; ?>
        </fieldset>
        <fieldset class="field">
          <legend>Prove you own the site</legend>
          <p class="hint">In Google Search Console or Bing Webmaster Tools, add <strong><?= $v->e($baseUrl) ?></strong>, choose the "HTML tag" (or "meta tag") method and paste the tag or its code here. A DNS record works too and needs nothing here.</p>
          <?= $v->field('verify_google', 'Google Search Console code', ['value' => $old['verify_google'] ?? '', 'error' => $errors['verify_google'] ?? null, 'maxlength' => 300, 'optional' => true, 'autocomplete' => 'off']) ?>
          <?= $v->field('verify_bing', 'Bing Webmaster Tools code', ['value' => $old['verify_bing'] ?? '', 'error' => $errors['verify_bing'] ?? null, 'maxlength' => 300, 'optional' => true, 'autocomplete' => 'off']) ?>
        </fieldset>
        <button type="submit" class="button button-small">Save</button>
      </form>
      <p class="small muted">Your sitemap is at <a href="/sitemap.xml"><?= $v->e($baseUrl) ?>/sitemap.xml</a>. Submit it in both tools once the site is verified.</p>
    </section>
  </aside>
</div>
