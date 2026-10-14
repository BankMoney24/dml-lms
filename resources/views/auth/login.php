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
      <rect x="95" y="105" width="10" height="10"/>
      <rect x="165" y="105" width="10" height="10"/>
      <rect class="is-lit" x="253" y="73" width="14" height="14"/>
      <rect x="145" y="135" width="10" height="10"/>
      <rect x="65" y="65" width="10" height="10"/>
      <rect x="215" y="45" width="10" height="10"/>
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
    <?php if ($school !== null && $school->signUpOpen()): ?>
      <p class="join-cta"><span>New here?</span> <a class="button button-quiet button-small" href="/join"><?= $v->icon('user-plus') ?>Create an account</a></p>
    <?php else: ?>
      <p class="muted small">No account yet? Ask your school administrator. Younger learners: ask a parent or teacher to help you sign in.</p>
    <?php endif; ?>
    <?php if ($school !== null && $school->parentRequests): ?>
      <p class="small"><a href="/join/parent">Parent or guardian? Ask to follow your child</a></p>
    <?php endif; ?>
  </section>
</div>
