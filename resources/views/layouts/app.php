<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var string $content */
$tenant = $v->shared('tenant');
$user = $v->shared('user');
$role = $v->shared('role');
$siteName = $tenant?->name ?? 'DML Learning Platform';
$title = ($pageTitle ?? '') !== '' && ($pageTitle ?? '') !== $siteName ? $pageTitle . ' – ' . $siteName : $siteName;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($title) ?></title>
<meta name="theme-color" content="<?= $v->e(\App\Modules\Tenancy\SchoolTheme::colour($tenant?->theme ?? 'teal')) ?>">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script src="<?= $v->asset('app.js') ?>" defer></script>
</head>
<body class="theme-<?= $v->e($tenant?->theme ?? 'teal') ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/">
      <?php if ($tenant?->hasLogo()): ?>
        <img class="brand-logo" src="/logo?v=<?= $v->e($tenant->logoVersion()) ?>" alt="" height="36">
      <?php else: ?>
        <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="3"/><path d="M8 12h5l3-5h3"/><circle cx="20" cy="7" r="2"/><path d="M13 12l3 5h3"/><circle cx="20" cy="17" r="2"/></svg>
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
      <li><a href="/verify">Check a certificate</a></li>
    </ul>
    <?php else: ?>
    <p class="footer-links">Learn it. Practise it. Prove it.</p>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
