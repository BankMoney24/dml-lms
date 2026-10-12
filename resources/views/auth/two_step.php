<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="auth">
  <svg class="brand-mark auth-mark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="3"/><path d="M8 12h5l3-5h3"/><circle cx="20" cy="7" r="2"/><path d="M13 12l3 5h3"/><circle cx="20" cy="17" r="2"/></svg>
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
