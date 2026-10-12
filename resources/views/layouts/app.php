<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var string $content */
$tenant = $v->shared('tenant');
$user = $v->shared('user');
$siteName = $tenant?->name ?? 'DML Learning Platform';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e(($pageTitle ?? '') !== '' && ($pageTitle ?? '') !== $siteName ? $pageTitle . ' – ' . $siteName : $siteName) ?></title>
<meta name="theme-color" content="#0B7A6B">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script src="<?= $v->asset('app.js') ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/">
      <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="3"/><path d="M8 12h5l3-5h3"/><circle cx="20" cy="7" r="2"/><path d="M13 12l3 5h3"/><circle cx="20" cy="17" r="2"/></svg>
      <span><?= $v->e($siteName) ?></span>
    </a>
    <?php if ($tenant !== null): ?>
    <nav class="nav" aria-label="Main">
      <a href="/courses">Courses</a>
      <?php if ($v->can('course.manage')): ?><a href="/teach">Teaching</a><?php endif; ?>
      <?php if ($user !== null): ?>
        <form method="post" action="/logout" class="inline">
          <?= $v->csrfField() ?>
          <button type="submit" class="linklike">Sign out</button>
        </form>
      <?php else: ?>
        <a href="/login" class="nav-cta">Sign in</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
<div class="netstatus" id="netstatus" role="status" aria-live="polite" hidden>You are offline. Pages you opened recently may still work; anything you send will need a connection.</div>
<main id="main" class="wrap">
  <?php if ($msg = $v->shared('flash_success')): ?><p class="notice notice-ok" role="status"><?= $v->e($msg) ?></p><?php endif; ?>
  <?php if ($msg = $v->shared('flash_error')): ?><p class="notice notice-err" role="alert"><?= $v->e($msg) ?></p><?php endif; ?>
  <?= $content ?>
</main>
<footer class="footer wrap">
  <p><?= $v->e($siteName) ?><?php if ($user !== null): ?> · Signed in as <?= $v->e($user->displayName) ?><?php endif; ?></p>
</footer>
</body>
</html>
