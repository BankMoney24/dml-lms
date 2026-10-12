<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="narrow">
  <h1>Sign in</h1>
  <p class="muted">to <?= $v->e($v->shared('tenant')?->name) ?></p>
  <?php if ($error !== null): ?>
    <p class="notice notice-err" role="alert" id="login-error"><?= $v->e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/login" class="form" novalidate>
    <?= $v->csrfField() ?>
    <div class="field">
      <label for="email">Email address</label>
      <input id="email" name="email" type="email" autocomplete="username" required value="<?= $v->e($email) ?>"
        <?= $error !== null ? 'aria-describedby="login-error" aria-invalid="true"' : 'autofocus' ?>>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required <?= $error !== null ? 'autofocus' : '' ?>>
    </div>
    <button type="submit" class="button">Sign in</button>
  </form>
  <p class="muted small">Forgotten your password, or no account yet? Ask your teacher or school administrator. Younger learners: ask a parent or teacher to help you sign in.</p>
</section>
