<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="auth">
  <svg class="brand-mark auth-mark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="3"/><path d="M8 12h5l3-5h3"/><circle cx="20" cy="7" r="2"/><path d="M13 12l3 5h3"/><circle cx="20" cy="17" r="2"/></svg>
  <header class="page-head">
    <h1>Sign in</h1>
    <p class="lead">to <?= $v->e($v->shared('tenant')?->name) ?></p>
  </header>
  <?php if ($error !== null): ?>
    <p class="notice notice-err" role="alert" id="login-error"><?= $v->e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/login" class="form" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('email', 'Email address', [
        'type' => 'email', 'value' => $email, 'autocomplete' => 'username', 'required' => true,
        'autofocus' => $error === null, 'maxlength' => 254,
    ]) ?>
    <?= $v->field('password', 'Password', [
        'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'autofocus' => $error !== null,
    ]) ?>
    <button type="submit" class="button">Sign in</button>
  </form>
  <p class="section"><a href="/password/forgot">Forgotten your password?</a></p>
  <p class="muted small">No account yet? Ask your school administrator. Younger learners: ask a parent or teacher to help you sign in.</p>
</section>
