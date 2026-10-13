<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="auth">
  <svg class="brand-mark auth-mark" viewBox="0 0 24 24" aria-hidden="true"><rect class="px-soft" x="1" y="2" width="4.5" height="4.5"/><rect x="6.5" y="2" width="4.5" height="4.5"/><rect x="12" y="2" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="2" width="4.5" height="4.5"/><rect x="6.5" y="7.5" width="4.5" height="4.5"/><rect class="px-soft" x="12" y="7.5" width="4.5" height="4.5"/><rect x="17.5" y="7.5" width="4.5" height="4.5"/><rect x="12" y="13" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="13" width="4.5" height="4.5"/><rect x="17.5" y="18.5" width="4.5" height="4.5"/></svg>
  <?= $v->breadcrumbs(['Sign in' => '/login', 'Reset your password' => null]) ?>
  <?php if (!$available): ?>
    <header class="page-head">
      <h1>Reset your password</h1>
    </header>
    <p>Ask your school administrator to send you a new sign-in link or a one-time password. Younger learners: ask a parent or teacher to help.</p>
  <?php elseif ($sent): ?>
    <header class="page-head">
      <h1>Check your email</h1>
    </header>
    <p>If <strong><?= $v->e($email) ?></strong> belongs to an account at <?= $v->e($v->shared('tenant')?->name) ?>, we have sent it a link to choose a new password. The link works for 60 minutes.</p>
    <p class="muted">Nothing arrived after a few minutes? Check your spam folder, or ask your school administrator.</p>
    <p><a href="/login">Back to sign in</a></p>
  <?php else: ?>
    <header class="page-head">
      <h1>Reset your password</h1>
      <p class="lead">Enter the email address you sign in with. We will email you a link to choose a new password.</p>
    </header>
    <form method="post" action="/password/forgot" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('email', 'Email address', ['type' => 'email', 'autocomplete' => 'username', 'required' => true, 'autofocus' => true, 'maxlength' => 254]) ?>
      <button type="submit" class="button">Email me a link</button>
    </form>
  <?php endif; ?>
</section>
