<?php
/**
 * A full-screen page with nothing around the work (ADR 0042): the CBT exam
 * room, the live quiz on the class screen and on players' phones. No sidebar,
 * search or menus to wander into; a small bar with the school and one way out.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $content
 * @var ?string $pageTitle
 * @var ?string $focusClass extra body class: focus-exam, focus-stage, focus-play
 * @var ?string $exitHref where "Leave" goes; null hides it
 * @var ?string $exitLabel
 * @var ?string $focusScript an extra script from /assets (cbt.js, live.js)
 */
$tenant = $v->shared('tenant');
$user = $v->shared('user');
$siteName = $tenant?->name ?? 'DML Learning Platform';
$themeKey = $tenant?->theme ?? \App\Modules\Tenancy\SchoolTheme::DEFAULT;
$appearance = $user?->appearance ?? 'system';
$exitHref ??= null;
$exitLabel ??= 'Leave';
$focusClass ??= '';
$focusScript ??= null;
?><!doctype html>
<html lang="en-NG"<?= $appearance !== 'system' ? ' data-appearance="' . $v->e($appearance) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e(($pageTitle ?? 'Test') . ' – ' . $siteName) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $v->e(\App\Modules\Tenancy\SchoolTheme::colour($themeKey)) ?>">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<script src="<?= $v->asset('app.js') ?>" defer></script>
<?php if ($focusScript !== null): ?><script src="<?= $v->asset($focusScript) ?>" defer></script><?php endif; ?>
</head>
<body class="theme-<?= $v->e($themeKey) ?> focus <?= $v->e($focusClass) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="focus-bar">
  <span class="focus-school">
    <?php if ($tenant?->hasLogo()): ?><img src="/logo?v=<?= $v->e($tenant->logoVersion()) ?>" alt="" height="24"><?php endif; ?>
    <span><?= $v->e($siteName) ?></span>
  </span>
  <?php if ($exitHref !== null): ?><a class="focus-exit" href="<?= $v->e($exitHref) ?>"><?= $v->icon('close') ?><?= $v->e($exitLabel) ?></a><?php endif; ?>
</header>
<main id="main" class="focus-main">
  <?php if ($msg = $v->shared('flash_success')): ?><p class="notice notice-ok" role="status"><?= $v->e($msg) ?></p><?php endif; ?>
  <?php if ($msg = $v->shared('flash_error')): ?><p class="notice notice-err" role="alert"><?= $v->e($msg) ?></p><?php endif; ?>
  <?= $content ?>
</main>
</body>
</html>
