<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="auth">
  <svg class="brand-mark auth-mark" viewBox="0 0 24 24" aria-hidden="true"><rect class="px-soft" x="1" y="2" width="4.5" height="4.5"/><rect x="6.5" y="2" width="4.5" height="4.5"/><rect x="12" y="2" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="2" width="4.5" height="4.5"/><rect x="6.5" y="7.5" width="4.5" height="4.5"/><rect class="px-soft" x="12" y="7.5" width="4.5" height="4.5"/><rect x="17.5" y="7.5" width="4.5" height="4.5"/><rect x="12" y="13" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="13" width="4.5" height="4.5"/><rect x="17.5" y="18.5" width="4.5" height="4.5"/></svg>
  <header class="page-head">
    <h1>Enter your code</h1>
    <p class="lead">Open your authenticator app and enter the 6-digit code for <?= $v->e($v->shared('tenant')?->name) ?>.</p>
  </header>
  <?php if ($error !== null): ?><p class="notice notice-err" role="alert" id="code-error"><?= $v->e($error) ?></p><?php endif; ?>
  <form method="post" action="/login/verify" class="form" novalidate>
    <?= $v->csrfField() ?>
    <div class="field">
      <label for="code">Code</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus class="code-input"<?= $error !== null ? ' aria-invalid="true" aria-describedby="code-error"' : '' ?>>
    </div>
    <button type="submit" class="button">Continue</button>
  </form>
  <p class="small muted section">Lost your phone? Enter one of your recovery codes instead (it looks like <code>abcd-efgh</code>). No recovery codes? Ask an owner of your school to reset your two-step sign-in.</p>
</section>
