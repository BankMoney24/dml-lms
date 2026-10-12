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
      <?php if ($user !== null): ?>
      <nav class="nav" aria-label="Main">
        <?= $v->navLink('/', 'Home') ?>
        <?= $v->navLink('/courses', 'Courses') ?>
        <?php if ($v->can('course.manage')): ?><?= $v->navLink('/teach', 'Teaching') ?><?php endif; ?>
        <?php if ($v->can('members.manage')): ?><?= $v->navLink('/people', 'People') ?><?php endif; ?>
        <?php if ($v->can('audit.view')): ?><?= $v->navLink('/activity', 'Activity') ?><?php endif; ?>
      </nav>
      <div class="account">
        <a href="/account"<?= $v->shared('path') === '/account' ? ' aria-current="page"' : '' ?>>
          <?= $v->e($user->displayName) ?><?php if ($role !== null): ?> <span class="role">· <?= $v->e($role->label()) ?></span><?php endif; ?>
        </a>
        <form method="post" action="/logout" class="inline">
          <?= $v->csrfField() ?>
          <button type="submit" class="linklike">Sign out</button>
        </form>
      </div>
      <?php else: ?>
      <nav class="nav" aria-label="Main"><?= $v->navLink('/courses', 'Courses') ?></nav>
      <div class="account"><a class="button button-small" href="/login">Sign in</a></div>
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
  <div class="wrap"><?= $v->e($siteName) ?> · Learn it. Practise it. Prove it.<?php if ($tenant !== null): ?> · <a href="/verify">Check a certificate</a><?php endif; ?></div>
</footer>
</body>
</html>
