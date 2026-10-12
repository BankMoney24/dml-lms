<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var array<string, string> $errors */
?>
<section class="narrow">
  <?= $v->breadcrumbs(['Your account' => '/account', 'Two-step sign-in' => null]) ?>
  <header class="page-head">
    <h1>Two-step sign-in</h1>
    <p class="lead">After your password, you also enter a code from an app on your phone. Someone who learns your password still cannot sign in.</p>
  </header>

  <?php if ($notice !== null): ?><p class="notice notice-ok" role="status"><?= $v->e($notice) ?></p><?php endif; ?>

  <?php if ($codes !== null): ?>
  <div class="notice notice-done">
    <p><strong>Save these recovery codes now.</strong> Each one lets you sign in once if you lose your phone. They will not be shown again.</p>
    <ul class="recovery-codes"><?php foreach ($codes as $c): ?><li><code><?= $v->e($c) ?></code></li><?php endforeach; ?></ul>
    <p class="small">Print them or write them down, and keep them somewhere safe.</p>
    <p><a class="button button-small" href="/">Done</a></p>
  </div>
  <?php endif; ?>

  <?php if ($enabled): ?>
    <p><?= $v->badge('On', 'ok') ?> <?= $v->e($v->plural($remaining, 'recovery code')) ?> left.</p>

    <section class="section" aria-labelledby="codes-h">
      <h2 id="codes-h">New recovery codes</h2>
      <p class="muted">Makes 10 new codes and cancels the old ones.</p>
      <form method="post" action="/account/two-step/recovery-codes" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('password', 'Your password', ['type' => 'password', 'autocomplete' => 'current-password', 'error' => $errors['password'] ?? null, 'id' => 'codes-password']) ?>
        <button type="submit" class="button button-quiet">Make new codes</button>
      </form>
    </section>

    <?php if (!$required): ?>
    <section class="danger-zone" aria-labelledby="off-h">
      <h2 id="off-h">Turn off two-step sign-in</h2>
      <?= $v->errorSummary(array_intersect_key($errors, ['code' => 1]), ['code' => 'off-code']) ?>
      <form method="post" action="/account/two-step/disable" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('password', 'Your password', ['type' => 'password', 'autocomplete' => 'current-password', 'id' => 'off-password']) ?>
        <?= $v->field('code', 'Code from your app', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'id' => 'off-code', 'error' => $errors['code'] ?? null]) ?>
        <button type="submit" class="button button-danger button-small">Turn off</button>
      </form>
    </section>
    <?php else: ?>
      <p class="small muted">Your role requires two-step sign-in, so it cannot be turned off.</p>
    <?php endif; ?>

  <?php elseif ($codes === null): ?>
    <?php if ($required): ?><p class="notice">Your role needs two-step sign-in before you can continue.</p><?php endif; ?>
    <ol class="steps">
      <li>
        <h2>Get an authenticator app</h2>
        <p>Google Authenticator, Microsoft Authenticator or Authy, free from your phone’s app store.</p>
      </li>
      <li>
        <h2>Add this account</h2>
        <p>In the app choose <em>Add account</em> → <em>Enter a setup key</em>, and type:</p>
        <p><span class="secret"><?= $v->e($secretGrouped) ?></span></p>
        <p class="small muted">Account name: anything you like, such as your email. Type: time-based. On this phone already? <a href="<?= $v->e($otpauth) ?>">Open in your authenticator app</a>.</p>
      </li>
      <li>
        <h2>Enter the code it shows</h2>
        <?= $v->errorSummary($errors) ?>
        <form method="post" action="/account/two-step/enable" class="form" novalidate>
          <?= $v->csrfField() ?>
          <?= $v->field('code', '6-digit code', ['inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => 10, 'error' => $errors['code'] ?? null, 'class' => 'code-field']) ?>
          <button type="submit" class="button">Turn on two-step sign-in</button>
        </form>
      </li>
    </ol>
  <?php endif; ?>
</section>
