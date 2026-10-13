<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var string $content
 *
 * Optional, from controllers, for search engines and link previews:
 * @var ?string $metaDescription one or two sentences about the page
 * @var ?bool $indexable true only for public pages (welcome, course list, course pages)
 * @var ?array<string, mixed> $jsonLd structured data for the page
 */
$tenant = $v->shared('tenant');
$user = $v->shared('user');
$role = $v->shared('role');
$siteName = $tenant?->name ?? 'DML Learning Platform';
$title = ($pageTitle ?? '') !== '' && ($pageTitle ?? '') !== $siteName ? $pageTitle . ' – ' . $siteName : $siteName;
$baseUrl = (string) $v->shared('base_url', '');
$path = (string) $v->shared('path', '/');
$description = trim((string) ($metaDescription ?? ''));
if ($description === '') {
    $description = $tenant !== null
        ? ($tenant->tagline !== '' ? $tenant->tagline . '. ' : '') . 'Courses, hands-on labs and certificates from ' . $tenant->name . '.'
        : 'DML Learning Platform by DML Technologies: courses, virtual labs and certificates for schools and training centres.';
}
$description = mb_strimwidth($description, 0, 300, '…');
$robotsOk = ($indexable ?? false) === true;
$canonical = $baseUrl . ($path === '/' ? '/' : $path);
$ogImage = $tenant !== null && $tenant->hasLogo() ? $baseUrl . '/logo?v=' . $tenant->logoVersion() : $baseUrl . '/assets/brand/og-image.jpg';
$ogImageIsLarge = !($tenant !== null && $tenant->hasLogo());
$themeKey = $tenant?->theme ?? \App\Modules\Tenancy\SchoolTheme::DEFAULT;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($title) ?></title>
<meta name="description" content="<?= $v->e($description) ?>">
<?php if ($robotsOk): ?>
<link rel="canonical" href="<?= $v->e($canonical) ?>">
<?php else: ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta property="og:site_name" content="<?= $v->e($siteName) ?>">
<meta property="og:title" content="<?= $v->e($pageTitle ?? $siteName) ?>">
<meta property="og:description" content="<?= $v->e($description) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $v->e($canonical) ?>">
<meta property="og:image" content="<?= $v->e($ogImage) ?>">
<meta name="twitter:card" content="<?= $ogImageIsLarge ? 'summary_large_image' : 'summary' ?>">
<meta name="application-name" content="<?= $v->e($siteName) ?>">
<meta name="theme-color" content="<?= $v->e(\App\Modules\Tenancy\SchoolTheme::colour($themeKey)) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="icon" href="/assets/brand/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script src="<?= $v->asset('app.js') ?>" defer></script>
<?php if (isset($jsonLd) && is_array($jsonLd) && $robotsOk): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
</head>
<body class="theme-<?= $v->e($themeKey) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/">
      <?php if ($tenant?->hasLogo()): ?>
        <img class="brand-logo" src="/logo?v=<?= $v->e($tenant->logoVersion()) ?>" alt="" height="36">
      <?php else: ?>
        <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><rect class="px-soft" x="1" y="2" width="4.5" height="4.5"/><rect x="6.5" y="2" width="4.5" height="4.5"/><rect x="12" y="2" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="2" width="4.5" height="4.5"/><rect x="6.5" y="7.5" width="4.5" height="4.5"/><rect class="px-soft" x="12" y="7.5" width="4.5" height="4.5"/><rect x="17.5" y="7.5" width="4.5" height="4.5"/><rect x="12" y="13" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="13" width="4.5" height="4.5"/><rect x="17.5" y="18.5" width="4.5" height="4.5"/></svg>
      <?php endif; ?>
      <span><?= $v->e($siteName) ?></span>
    </a>
    <?php if ($tenant !== null): ?>
      <?php if ($user !== null): ?>
      <nav class="nav" aria-label="Main">
        <?= $v->navLink('/', 'Home') ?>
        <?= $v->navLink('/courses', 'Courses') ?>
        <?php if ($v->can('course.manage')): ?><?= $v->navLink('/teach', 'Teaching') ?><?php endif; ?>
        <?php if ($v->can('groups.manage') || $v->can('course.manage')): ?><?= $v->navLink('/groups', $tenant->groupWord(true, true)) ?><?php endif; ?>
        <?php if ($v->can('school.manage')): ?><?= $v->navLink('/school', 'School', ['/people', '/activity', '/setup']) ?>
        <?php elseif ($v->can('members.manage')): ?><?= $v->navLink('/people', 'People') ?><?php endif; ?>
        <?php if ($user->isPlatformAdmin): ?><?= $v->navLink('/system', 'System') ?><?php endif; ?>
      </nav>
      <div class="account">
        <?php $unread = (int) $v->shared('unread', 0); ?>
        <a class="bell<?= $unread > 0 ? ' has-new' : '' ?>" href="/notifications"<?= $v->shared('path') === '/notifications' ? ' aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg>
          <span class="visually-hidden">Notifications<?= $unread > 0 ? ', ' . $v->e($unread >= 100 ? 'over 99' : (string) $unread) . ' new' : '' ?></span>
          <?php if ($unread > 0): ?><span class="bell-count" aria-hidden="true"><?= $unread >= 100 ? '99+' : $unread ?></span><?php endif; ?>
        </a>
        <a class="account-me" href="/account"<?= $v->shared('path') === '/account' ? ' aria-current="page"' : '' ?>>
          <span class="account-name"><?= $v->e($user->displayName) ?></span><?php if ($role !== null): ?> <span class="role"><?= $v->e($role->label()) ?></span><?php endif; ?>
        </a>
        <form method="post" action="/logout" class="inline">
          <?= $v->csrfField() ?>
          <button type="submit" class="linklike">Sign out</button>
        </form>
      </div>
      <?php else: ?>
      <nav class="nav" aria-label="Main"><?php if ($tenant->publicCatalogue): ?><?= $v->navLink('/courses', 'Courses') ?><?php endif; ?></nav>
      <div class="account"><?php if (!str_starts_with((string) $v->shared('path'), '/login')): ?><a class="button button-small" href="/login">Sign in</a><?php endif; ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</header>
<div class="netstatus" id="netstatus" role="status" aria-live="polite" hidden>You are offline. Pages you opened recently may still work; anything you send will need a connection.</div>
<main id="main" class="wrap">
  <?php if ($msg = $v->shared('flash_success')): ?><p class="notice notice-ok" role="status"><?= $v->e($msg) ?></p><?php endif; ?>
  <?php if ($msg = $v->shared('flash_error')): ?><p class="notice notice-err" role="alert"><?= $v->e($msg) ?></p><?php endif; ?>
  <?= $content ?>
</main>
<footer class="footer">
  <div class="wrap footer-inner">
    <p class="footer-name"><?= $v->e($siteName) ?><?php if (($tenant?->tagline ?? '') !== ''): ?> <span class="footer-tagline"><?= $v->e($tenant->tagline) ?></span><?php endif; ?></p>
    <?php if ($tenant !== null): ?>
    <ul class="footer-links">
      <?php if ($tenant->contactEmail !== ''): ?><li><a href="mailto:<?= $v->e($tenant->contactEmail) ?>"><?= $v->e($tenant->contactEmail) ?></a></li><?php endif; ?>
      <?php if ($tenant->contactPhone !== ''): ?><li><a href="tel:<?= $v->e((string) preg_replace('/[^0-9+]/', '', $tenant->contactPhone)) ?>"><?= $v->e($tenant->contactPhone) ?></a></li><?php endif; ?>
      <?php if ($tenant->website !== ''): ?><li><a href="<?= $v->e($tenant->website) ?>" rel="noopener"><?= $v->e((string) preg_replace('#^https?://(www\.)?#', '', rtrim($tenant->website, '/'))) ?></a></li><?php endif; ?>
      <?php if ($user !== null): ?><li><a href="/help">Get help</a></li><?php endif; ?>
      <li><a href="/verify">Check a certificate</a></li>
    </ul>
    <?php else: ?>
    <p class="footer-links">Learn. Build. Grow.</p>
    <?php endif; ?>
    <p class="footer-credit"><?php $pixelClass = 'pixels-light'; include __DIR__ . '/../partials/pixels.php'; ?>DML Technologies learning platform, powered by TridaxLabs</p>
  </div>
</footer>
</body>
</html>
