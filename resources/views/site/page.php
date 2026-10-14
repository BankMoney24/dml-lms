<?php
/**
 * A school's public page (About, Contact, policies). Written by the school.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Site\SitePage $page
 * @var \App\Modules\Tenancy\Tenant $school
 * @var bool $preview
 * @var list<string> $problems
 */
?>
<?php if ($preview): ?>
<div class="notice notice-err" role="status">
  <p><strong>Preview.</strong> <?= $page->isPublished() ? 'This is how the saved page looks.' : 'This draft is not on your website yet.' ?> <a href="/school/website/<?= $v->e($page->slug) ?>">Back to editing</a></p>
  <?php if ($problems !== []): ?><ul><?php foreach ($problems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
<?= $v->breadcrumbs(['Home' => '/', $page->title => null]) ?>
<article class="site-page">
  <header class="page-head">
    <h1><?= $v->e($page->title) ?></h1>
    <?php if ($page->summary !== ''): ?><p class="lead"><?= $v->e($page->summary) ?></p><?php endif; ?>
  </header>

  <?php if ($page->slug === 'contact'): ?>
  <ul class="contact-list">
    <?php if ($school->contactEmail !== ''): ?><li><span class="contact-label">Email</span><a href="mailto:<?= $v->e($school->contactEmail) ?>"><?= $v->e($school->contactEmail) ?></a></li><?php endif; ?>
    <?php if ($school->contactPhone !== ''): ?><li><span class="contact-label">Phone</span><a href="tel:<?= $v->e((string) preg_replace('/[^0-9+]/', '', $school->contactPhone)) ?>"><?= $v->e($school->contactPhone) ?></a></li><?php endif; ?>
    <?php if ($school->website !== ''): ?><li><span class="contact-label">Website</span><a href="<?= $v->e($school->website) ?>" rel="noopener"><?= $v->e((string) preg_replace('#^https?://(www\.)?#', '', rtrim($school->website, '/'))) ?></a></li><?php endif; ?>
    <?php foreach ($school->socialLinks as $network => $url): ?>
      <li><span class="contact-label"><?= $v->e(\App\Modules\Site\SitePages::SOCIAL[$network][0] ?? ucfirst($network)) ?></span><a href="<?= $v->e($url) ?>" rel="noopener me"><?= $v->e((string) preg_replace('#^https://(www\.)?#', '', $url)) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if (trim($page->body) !== ''): ?><div class="prose"><?= $v->markdown($page->body) ?></div><?php endif; ?>

  <?php if ($page->slug === 'about'): ?>
  <section class="site-credit" aria-labelledby="credit-h">
    <h2 id="credit-h">About this website</h2>
    <p><?= $v->e($school->name) ?>'s website and learning platform were developed and are powered by <a href="https://tridax.org">TridaxLabs</a>.</p>
  </section>
  <?php endif; ?>

  <?php if ($page->updatedAt !== ''): ?><p class="small muted site-updated">Page updated <?= $v->datetime($page->updatedAt, 'j F Y') ?></p><?php endif; ?>
</article>
