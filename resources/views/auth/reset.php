<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="auth">
  <svg class="brand-mark auth-mark" viewBox="0 0 24 24" aria-hidden="true"><rect class="px-soft" x="1" y="2" width="4.5" height="4.5"/><rect x="6.5" y="2" width="4.5" height="4.5"/><rect x="12" y="2" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="2" width="4.5" height="4.5"/><rect x="6.5" y="7.5" width="4.5" height="4.5"/><rect class="px-soft" x="12" y="7.5" width="4.5" height="4.5"/><rect x="17.5" y="7.5" width="4.5" height="4.5"/><rect x="12" y="13" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="13" width="4.5" height="4.5"/><rect x="17.5" y="18.5" width="4.5" height="4.5"/></svg>
  <?php if (!$valid): ?>
    <header class="page-head">
      <h1>This link no longer works</h1>
    </header>
    <p>Links work once and expire after a while. Ask for a new one, or ask your school administrator to send you another invitation.</p>
    <p class="actions"><a class="button" href="/password/forgot">Get a new link</a> <a class="button button-quiet" href="/login">Sign in</a></p>
  <?php else: ?>
    <header class="page-head">
      <h1><?= $invite ? 'Set up your account' : 'Choose a new password' ?></h1>
      <p class="lead">for <?= $v->e($email) ?> at <?= $v->e($v->shared('tenant')?->name) ?></p>
    </header>
    <?= $v->errorSummary($errors) ?>
    <form method="post" class="form" novalidate>
      <?= $v->csrfField() ?>
      <input type="text" name="username" value="<?= $v->e($email) ?>" autocomplete="username" hidden>
      <?= $v->field('new_password', 'New password', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'autofocus' => true,
          'error' => $errors['new_password'] ?? null, 'hint' => 'At least 12 characters. A short sentence you will remember works well.']) ?>
      <?= $v->field('confirm_password', 'Type it again', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['confirm_password'] ?? null]) ?>
      <button type="submit" class="button"><?= $invite ? 'Set password' : 'Change password' ?></button>
    </form>
  <?php endif; ?>
</section>
