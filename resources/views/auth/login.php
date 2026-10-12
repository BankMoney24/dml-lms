<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="narrow">
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
