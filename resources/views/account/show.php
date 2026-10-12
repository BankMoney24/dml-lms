<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Identity\CurrentUser $me */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
$mustChange = $me->user->mustChangePassword;
$passwordErrors = array_intersect_key($errors, array_flip(['current_password', 'new_password', 'confirm_password']));
?>
<section class="narrow">
  <header class="page-head">
    <h1><?= $mustChange ? 'Choose your password' : 'Your account' ?></h1>
    <p class="lead"><?= $v->e($me->user->email) ?> · <?= $v->e($me->membership?->role->label() ?? '') ?></p>
  </header>

  <?php if ($mustChange): ?>
    <p class="notice">You signed in with a one-time password. Choose your own to continue. Enter the one-time password as your current password.</p>
  <?php endif; ?>

  <section aria-labelledby="pw-h">
    <h2 id="pw-h"><?= $mustChange ? 'New password' : 'Change password' ?></h2>
    <?= $v->errorSummary($passwordErrors) ?>
    <form method="post" action="/account/password" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('current_password', $mustChange ? 'One-time password' : 'Current password', ['type' => 'password', 'autocomplete' => 'current-password', 'error' => $errors['current_password'] ?? null, 'required' => true]) ?>
      <?= $v->field('new_password', 'New password', ['type' => 'password', 'autocomplete' => 'new-password', 'error' => $errors['new_password'] ?? null, 'required' => true,
          'hint' => 'At least 12 characters. A short sentence you will remember works well.']) ?>
      <?= $v->field('confirm_password', 'Type the new password again', ['type' => 'password', 'autocomplete' => 'new-password', 'error' => $errors['confirm_password'] ?? null, 'required' => true]) ?>
      <button type="submit" class="button"><?= $mustChange ? 'Set password and continue' : 'Change password' ?></button>
    </form>
    <?php if (!$mustChange): ?><p class="small muted">Changing your password signs you out on every other device.</p><?php endif; ?>
  </section>

  <?php if (!$mustChange): ?>
  <section class="section" aria-labelledby="name-h">
    <h2 id="name-h">Your name</h2>
    <form method="post" action="/account" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('display_name', 'Name shown to teachers and classmates', ['value' => $old['display_name'], 'error' => $errors['display_name'] ?? null, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
      <button type="submit" class="button button-quiet">Save name</button>
    </form>
  </section>
  <?php endif; ?>
</section>
