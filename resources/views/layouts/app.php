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
$appearance = $user?->appearance ?? 'system';
// Signed-in pages in a school use the app shell: sidebar, search and account menu.
$shell = $tenant !== null && $user !== null;
?><!doctype html>
<html lang="en"<?= $appearance !== 'system' ? ' data-appearance="' . $v->e($appearance) . '"' : '' ?>>
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
<?php if ($shell): ?>
<?php
    $counts = (array) $v->shared('nav_counts', []);
    $unread = (int) $v->shared('unread', 0);
    $isStaffish = $v->can('course.manage') || $v->can('groups.manage');
    $isAdminish = $v->can('school.manage') || $v->can('members.manage') || $v->can('support.manage') || $v->can('audit.view');
?>
<body class="theme-<?= $v->e($themeKey) ?> has-shell">
<a class="skip" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Site menu">
    <div class="sidebar-head">
      <a class="side-brand" href="/">
        <?php if ($tenant->hasLogo()): ?>
          <span class="side-logo"><img src="/logo?v=<?= $v->e($tenant->logoVersion()) ?>" alt="" height="28"></span>
        <?php else: ?>
          <span class="side-logo side-logo-mark"><?php $pixelClass = ''; include __DIR__ . '/../partials/pixels.php'; ?></span>
        <?php endif; ?>
        <span class="side-school"><?= $v->e($siteName) ?></span>
      </a>
      <a class="side-close" href="#main" data-nav-close><?= $v->icon('close') ?><span class="visually-hidden">Close menu</span></a>
    </div>
    <nav class="side-nav" aria-label="Main">
      <ul>
        <?= $v->sideLink('/', 'Home', 'home') ?>
        <?= $v->sideLink('/courses', 'Courses', 'courses') ?>
        <?php if ($v->can('course.learn')): ?><?= $v->sideLink('/saved', 'Saved and notes', 'saved') ?><?php endif; ?>
        <?php if (($counts['children'] ?? 0) > 0): ?><?= $v->sideLink('/children', ($counts['children'] ?? 0) === 1 ? 'Your child' : 'Your children', 'child') ?><?php endif; ?>
      </ul>
      <?php if ($isStaffish): ?>
      <p class="side-label">Teaching</p>
      <ul>
        <?php if ($v->can('course.manage')): ?>
          <?= $v->sideLink('/teach', 'Your courses', 'teach', ['/teach/courses']) ?>
          <?= $v->sideLink('/teach/reviews', 'Reviews', 'review', [], (int) ($counts['reviews'] ?? 0)) ?>
          <?= $v->sideLink('/teach/labs', 'Lab catalogue', 'lab') ?>
        <?php endif; ?>
        <?= $v->sideLink('/groups', $tenant->groupWord(true, true), 'groups') ?>
      </ul>
      <?php endif; ?>
      <?php if ($isAdminish || $user->isPlatformAdmin): ?>
      <p class="side-label">School</p>
      <ul>
        <?php if ($v->can('school.manage')): ?><?= $v->sideLink('/school', 'Settings', 'school', ['/setup']) ?><?php endif; ?>
        <?php if ($v->can('members.manage')): ?><?= $v->sideLink('/people', 'People', 'people') ?><?php endif; ?>
        <?php if ($v->can('support.manage')): ?><?= $v->sideLink('/school/support', 'Help desk', 'inbox', [], (int) ($counts['support'] ?? 0)) ?><?php endif; ?>
        <?php if ($v->can('school.manage')): ?><?= $v->sideLink('/school/payments', 'Payments', 'payments') ?><?php endif; ?>
        <?php if ($v->can('audit.view')): ?><?= $v->sideLink('/activity', 'Activity', 'activity') ?><?php endif; ?>
        <?php if ($user->isPlatformAdmin): ?><?= $v->sideLink('/system', 'System', 'system') ?><?php endif; ?>
      </ul>
      <?php endif; ?>
    </nav>
    <div class="side-foot">
      <ul>
        <?= $v->sideLink('/help', 'Get help', 'help') ?>
      </ul>
      <p class="side-credit"><?php $pixelClass = ''; include __DIR__ . '/../partials/pixels.php'; ?>Powered by TridaxLabs</p>
    </div>
  </aside>
  <div class="shell-main">
    <header class="appbar">
      <a class="appbar-menu" href="#sidebar" data-nav-open aria-controls="sidebar"><?= $v->icon('menu') ?><span class="visually-hidden">Menu</span></a>
      <a class="appbar-brand" href="/"><?php if ($tenant->hasLogo()): ?><img src="/logo?v=<?= $v->e($tenant->logoVersion()) ?>" alt="" height="28"><?php endif; ?><span><?= $v->e($siteName) ?></span></a>
      <form class="search" role="search" method="get" action="/search" data-search>
        <label class="visually-hidden" for="site-search">Search</label>
        <?= $v->icon('search', 'search-icon') ?>
        <input id="site-search" name="q" type="search" placeholder="Search courses and lessons" autocomplete="off" maxlength="80"
          value="<?= $v->e(str_starts_with($path, '/search') ? (string) $v->shared('search_q', '') : '') ?>" aria-describedby="search-key">
        <kbd class="search-key" id="search-key" aria-hidden="true">/</kbd>
        <div class="search-pop" id="search-pop" hidden></div>
      </form>
      <a class="appbar-icon appbar-search-link" href="/search"><?= $v->icon('search') ?><span class="visually-hidden">Search</span></a>
      <a class="appbar-icon bell<?= $unread > 0 ? ' has-new' : '' ?>" href="/notifications"<?= $path === '/notifications' ? ' aria-current="page"' : '' ?>>
        <?= $v->icon('bell') ?>
        <span class="visually-hidden">Notifications<?= $unread > 0 ? ', ' . $v->e($unread >= 100 ? 'over 99' : (string) $unread) . ' new' : '' ?></span>
        <?php if ($unread > 0): ?><span class="bell-count" aria-hidden="true"><?= $unread >= 100 ? '99+' : $unread ?></span><?php endif; ?>
      </a>
      <details class="me" data-menu>
        <summary>
          <span class="avatar" aria-hidden="true"><?= $v->e($user->initials()) ?></span>
          <span class="me-text"><span class="me-name"><?= $v->e($user->displayName) ?></span><?php if ($role !== null): ?><span class="me-role"><?= $v->e($role->label()) ?></span><?php endif; ?></span>
          <?= $v->icon('chevron', 'me-chevron') ?>
          <span class="visually-hidden">Account menu</span>
        </summary>
        <div class="me-panel">
          <p class="me-who"><strong><?= $v->e($user->displayName) ?></strong><span><?= $v->e($user->email) ?></span></p>
          <ul class="me-links">
            <li><a href="/account"><?= $v->icon('people') ?>Your account</a></li>
            <li><a href="/account/calendar"><?= $v->icon('calendar') ?>Calendar link</a></li>
            <li><a href="/help"><?= $v->icon('help') ?>Get help</a></li>
          </ul>
          <form method="post" action="/account/appearance" class="me-look">
            <?= $v->csrfField() ?>
            <input type="hidden" name="back" value="<?= $v->e($path) ?>">
            <p class="me-look-label" id="look-label">Appearance</p>
            <div class="segmented" role="group" aria-labelledby="look-label">
              <?php foreach (['system' => ['device', 'Device'], 'light' => ['sun', 'Light'], 'dark' => ['moon', 'Dark']] as $look => [$lookIcon, $lookLabel]): ?>
              <button type="submit" name="appearance" value="<?= $look ?>" aria-pressed="<?= $appearance === $look ? 'true' : 'false' ?>"><?= $v->icon($lookIcon) ?><span><?= $lookLabel ?></span></button>
              <?php endforeach; ?>
            </div>
          </form>
          <form method="post" action="/logout" class="me-out">
            <?= $v->csrfField() ?>
            <button type="submit"><?= $v->icon('logout') ?>Sign out</button>
          </form>
        </div>
      </details>
    </header>
    <div class="netstatus" id="netstatus" role="status" aria-live="polite" hidden>You are offline. Pages you opened recently may still work; anything you send will need a connection.</div>
    <main id="main" class="shell-content">
      <?php if ($msg = $v->shared('flash_success')): ?><p class="notice notice-ok toast" role="status"><?= $v->e($msg) ?></p><?php endif; ?>
      <?php if ($msg = $v->shared('flash_error')): ?><p class="notice notice-err" role="alert"><?= $v->e($msg) ?></p><?php endif; ?>
      <?= $content ?>
    </main>
    <footer class="shell-foot">
      <p><?= $v->e($siteName) ?><?php if ($tenant->tagline !== ''): ?> <span class="muted"><?= $v->e($tenant->tagline) ?></span><?php endif; ?></p>
      <ul class="footer-links">
        <?php if ($tenant->contactEmail !== ''): ?><li><a href="mailto:<?= $v->e($tenant->contactEmail) ?>"><?= $v->e($tenant->contactEmail) ?></a></li><?php endif; ?>
        <?php if ($tenant->contactPhone !== ''): ?><li><a href="tel:<?= $v->e((string) preg_replace('/[^0-9+]/', '', $tenant->contactPhone)) ?>"><?= $v->e($tenant->contactPhone) ?></a></li><?php endif; ?>
        <li><a href="/help">Get help</a></li>
        <li><a href="/verify">Check a certificate</a></li>
      </ul>
    </footer>
  </div>
</div>
<div class="nav-scrim" data-nav-close hidden></div>
</body>
</html>
<?php else: ?>
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
<?php endif; ?>
