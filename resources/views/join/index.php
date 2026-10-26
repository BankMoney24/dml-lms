<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var bool $open
 * @var bool $emailing
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var ?\App\Modules\Courses\Course $joiningFor
 */
$joiningFor ??= null;
$pages = (array) $v->shared('site_pages', []);
$link = static fn (string $path, string $text): string => isset($pages[$path]) ? '<a href="' . $path . '" target="_blank" rel="noopener">' . $text . '</a>' : $text;
$formError = $errors['form'] ?? null;
unset($errors['form']);
?>
<div class="join">
  <?php include __DIR__ . '/_panel.php'; ?>
  <section class="join-main">
    <?php if (!$open): ?>
      <header class="page-head">
        <h1>Joining <?= $v->e($school->name) ?></h1>
        <p class="lead">This school adds its learners itself, so there is no sign-up form here.</p>
      </header>
      <div class="join-card">
        <h2>To get an account</h2>
        <p>Ask the school office<?= $school->contactEmail !== '' ? ' at <a href="mailto:' . $v->e($school->contactEmail) . '">' . $v->e($school->contactEmail) . '</a>' : '' ?><?= $school->contactPhone !== '' ? ' or on ' . $v->e($school->contactPhone) : '' ?>. They will give you a way to sign in.</p>
        <p><a class="button" href="/login">I already have an account</a></p>
      </div>
    <?php else: ?>
      <header class="page-head">
        <h1>Create your account</h1>
        <p class="lead"><?= $joiningFor === null ? 'It takes a minute. Courses with a price show it before you join them.' : 'It takes a minute, then you go straight back to your course.' ?></p>
      </header>
      <?php if ($joiningFor !== null): ?>
      <div class="join-for">
        <?= $v->icon('courses') ?>
        <p><span class="small muted">You are joining to take</span><strong><?= $v->e($joiningFor->title) ?></strong><span class="small"><?= $joiningFor->isPaid()
            ? $v->e((string) $joiningFor->priceLabel()) . ', paid on the next step on Paystack\'s secure page'
            : 'Free: you start as soon as your account is ready' ?></span></p>
      </div>
      <?php endif; ?>
      <?php if ($formError !== null): ?><p class="notice notice-err" role="alert"><?= $v->e($formError) ?></p><?php endif; ?>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/join" class="form join-form" novalidate>
        <?= $v->csrfField() ?>
        <?php if ($joiningFor !== null): ?><input type="hidden" name="course" value="<?= $v->e($joiningFor->slug) ?>"><?php endif; ?>
        <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <?= $v->field('name', 'Full name', ['value' => $old['name'], 'error' => $errors['name'] ?? null, 'autocomplete' => 'name', 'maxlength' => 120, 'hint' => 'As you would like it on your certificates.']) ?>
        <?= $v->field('email', 'Email address', ['type' => 'email', 'value' => $old['email'], 'error' => $errors['email'] ?? null, 'autocomplete' => 'email', 'maxlength' => 254,
            'hint' => $emailing ? 'We send a link to confirm it. Already have a DML account? Use the same email and its password.' : 'Already have a DML account at another school? Use the same email and its password.']) ?>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
          <label for="password">Password</label>
          <p class="hint" id="password-hint">At least 12 characters. A short sentence is easy to remember and hard to guess.</p>
          <div class="reveal-row">
            <input type="password" id="password" name="password" autocomplete="new-password" maxlength="200" aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
            <?= \App\Core\View\ViewHelpers::revealButton('password') ?>
          </div>
          <?php if (isset($errors['password'])): ?><p class="field-error" id="password-error"><?= $v->e($errors['password']) ?></p><?php endif; ?>
        </div>
        <?php if ($school->registration === 'code'): ?>
          <?= $v->field('code', 'Join code', ['value' => $old['code'], 'error' => $errors['code'] ?? null, 'autocomplete' => 'off', 'maxlength' => 20, 'class' => 'join-code-field', 'hint' => 'Your school or teacher gives you this code. Capitals and dashes do not matter.']) ?>
        <?php endif; ?>
        <fieldset class="field consent">
          <legend class="visually-hidden">Agreements</legend>
          <label class="choice choice-tight<?= isset($errors['terms']) ? ' has-error' : '' ?>" id="terms"><input type="checkbox" name="terms" value="1"><span>I agree to <?= $link('/terms', 'the school\'s rules') ?> and to it keeping my learning records as its <?= $link('/privacy', 'privacy policy') ?> explains.</span></label>
          <?php if (isset($errors['terms'])): ?><p class="field-error"><?= $v->e($errors['terms']) ?></p><?php endif; ?>
          <label class="choice choice-tight<?= isset($errors['age']) ? ' has-error' : '' ?>" id="age"><input type="checkbox" name="age" value="1"><span>I am 18 or older, or my parent or guardian agrees to me joining.</span></label>
          <?php if (isset($errors['age'])): ?><p class="field-error"><?= $v->e($errors['age']) ?></p><?php endif; ?>
        </fieldset>
        <button type="submit" class="button join-submit"><?= $v->icon('user-plus') ?>Create my account</button>
      </form>
      <p class="join-signin">Already have an account here? <a href="/login<?= $joiningFor !== null ? '?next=' . $v->e(rawurlencode('/courses/' . $joiningFor->slug)) : '' ?>">Sign in</a></p>
    <?php endif; ?>

    <div class="join-others">
      <?php if ($school->parentRequests): ?>
      <a class="join-other" href="/join/parent"><?= $v->icon('child') ?><span><strong>Parent or guardian?</strong>Ask to follow your child's progress.</span></a>
      <?php endif; ?>
      <a class="join-other" href="<?= $open ? '#name' : '/login' ?>"><?= $v->icon('teach') ?><span><strong>Want to teach here?</strong><?= $open ? 'Create an account first, then tell the school what you would teach.' : 'Sign in first, then ask the school from your account.' ?></span></a>
    </div>
  </section>
</div>
