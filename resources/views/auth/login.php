<?php
/** @var \App\Core\View\ViewHelpers $v */
$school = $v->shared('tenant');
?>
<div class="auth-split">
  <aside class="auth-panel" aria-label="About <?= $v->e($school?->name ?? 'this school') ?>">
    <p class="auth-school"><?= $v->e($school?->name ?? 'DML Learning Platform') ?></p>
    <?php if ($school !== null && $school->tagline !== ''): ?><p class="auth-tagline"><?= $v->e($school->tagline) ?></p><?php endif; ?>
    <svg class="auth-trace" viewBox="0 0 320 170" aria-hidden="true" focusable="false">
      <path d="M0 140 H70 L100 110 H170 L200 80 H320"/>
      <path d="M0 160 H130 L150 140 H320"/>
      <path d="M40 0 V40 L70 70 H120 L140 50 H220"/>
      <path class="is-lit" d="M100 110 H170 L200 80 H260"/>
      <circle cx="100" cy="110" r="5"/>
      <circle cx="170" cy="110" r="5"/>
      <circle class="is-lit" cx="260" cy="80" r="7"/>
      <circle cx="150" cy="140" r="5"/>
      <circle cx="70" cy="70" r="5"/>
      <circle cx="220" cy="50" r="5"/>
    </svg>
    <ul class="auth-points">
      <li>Lessons you can follow on a phone</li>
      <li>Labs and quizzes marked straight away</li>
      <?php if ($school === null || $school->certificatesEnabled): ?><li>Certificates anyone can check</li><?php endif; ?>
    </ul>
  </aside>
  <section class="auth">
    <header class="page-head">
      <h1>Sign in</h1>
      <p class="lead">to <?= $v->e($school?->name) ?></p>
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
</div>
