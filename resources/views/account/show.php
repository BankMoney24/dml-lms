<?php
/** @var list<array{payment: \App\Modules\Payments\Payment, course: string}> $payments */
/** @var int $now */
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Identity\CurrentUser $me */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var ?\App\Modules\Joining\LearnerProfile $profile */
$profile ??= null;
$emailing ??= false;
$mustChange = $me->user->mustChangePassword;
$passwordErrors = array_intersect_key($errors, array_flip(['current_password', 'new_password', 'confirm_password']));
?>
<section class="narrow">
  <header class="page-head">
    <h1><?= $mustChange ? 'Choose your password' : 'Your account' ?></h1>
    <p class="lead"><?= $v->e($me->user->email) ?>, <?= $v->e($me->membership?->role->label() ?? '') ?></p>
  </header>
  <?php if ($emailing && !$me->user->emailVerified && !$mustChange): ?>
  <div class="notice confirm-email">
    <p>Your email address is not confirmed yet. Use the link we sent you, or get a new one.</p>
    <form method="post" action="/account/confirm"><?= $v->csrfField() ?><button type="submit" class="button button-small button-quiet">Send a new link</button></form>
  </div>
  <?php endif; ?>

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
  <section class="section" aria-labelledby="two-step-h">
    <h2 id="two-step-h">Two-step sign-in</h2>
    <p><?= $me->user->twoFactorEnabled ? $v->badge('On', 'ok') . ' Your account asks for a code from your phone after your password.' : $v->badge('Off') . ' Add a code from your phone to protect your account.' ?></p>
    <p><a class="button button-quiet" href="/account/two-step"><?= $me->user->twoFactorEnabled ? 'Manage two-step sign-in' : 'Set up two-step sign-in' ?></a></p>
  </section>

  <section class="section" aria-labelledby="name-h">
    <h2 id="name-h">Your name</h2>
    <form method="post" action="/account" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('display_name', 'Name shown to teachers and classmates', ['value' => $old['display_name'], 'error' => $errors['display_name'] ?? null, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
      <button type="submit" class="button button-quiet">Save name</button>
    </form>
  </section>

  <?php if ($v->can('course.learn')): ?>
  <section class="section" aria-labelledby="profile-h">
    <h2 id="profile-h">Your learning profile</h2>
    <?php if ($profile === null || $profile->goals === []): ?>
      <p>Tell us what you want to learn and we will put the right courses first.</p>
    <?php else: ?>
      <dl class="facts">
        <dt>Learning</dt><dd><?= $v->e(implode(', ', array_map(static fn (string $g): string => \App\Modules\Joining\LearnerProfile::GOALS[$g][0], $profile->goals))) ?></dd>
        <?php if ($profile->experience !== null): ?><dt>Experience</dt><dd><?= $v->e(\App\Modules\Joining\LearnerProfile::EXPERIENCE[$profile->experience][0]) ?></dd><?php endif; ?>
        <?php if ($profile->weeklyGoal !== null): ?><dt>Weekly goal</dt><dd><?= $v->e($v->plural($profile->weeklyGoal, 'day')) ?></dd><?php endif; ?>
        <?php if ($profile->github !== null): ?><dt>GitHub</dt><dd><a href="<?= $v->e((string) $profile->githubUrl()) ?>" rel="noopener">github.com/<?= $v->e($profile->github) ?></a></dd><?php endif; ?>
        <?php if ($profile->portfolio !== null): ?><dt>Portfolio</dt><dd><a href="<?= $v->e($profile->portfolio) ?>" rel="noopener nofollow"><?= $v->e($profile->portfolio) ?></a></dd><?php endif; ?>
      </dl>
    <?php endif; ?>
    <p class="actions"><a class="button button-quiet button-small" href="/setup/welcome"><?= $v->icon('sparkle') ?><?= $profile === null || $profile->goals === [] ? 'Get started' : 'Change' ?></a>
      <?php if (!$v->can('course.manage')): ?><a class="button button-quiet button-small" href="/join/teach"><?= $v->icon('teach') ?>Ask to teach here</a><?php endif; ?></p>
  </section>
  <?php endif; ?>

  <section class="section" aria-labelledby="look-h">
    <h2 id="look-h">Appearance</h2>
    <form method="post" action="/account/appearance" class="look-form">
      <?= $v->csrfField() ?>
      <input type="hidden" name="back" value="/account">
      <p class="hint" id="look-hint">Dark is easier on the eyes at night and saves battery on many phones.</p>
      <div class="segmented segmented-wide" role="group" aria-labelledby="look-h" aria-describedby="look-hint">
        <?php foreach (\App\Modules\Identity\User::APPEARANCES as $look => $label): ?>
        <button type="submit" name="appearance" value="<?= $look ?>" aria-pressed="<?= $me->user->appearance === $look ? 'true' : 'false' ?>"><?= $v->icon(['system' => 'device', 'light' => 'sun', 'dark' => 'moon'][$look]) ?><span><?= $v->e($label) ?></span></button>
        <?php endforeach; ?>
      </div>
    </form>
  </section>

  <section class="section" aria-labelledby="cal-h">
    <h2 id="cal-h">Due dates in your calendar</h2>
    <p>Get a private link that puts the due dates your teachers set into Google Calendar, your phone or Outlook.</p>
    <p><a class="button button-quiet" href="/account/calendar"><?= $v->icon('calendar') ?>Set up a calendar link</a></p>
  </section>

  <?php if ($payments !== []): ?>
  <section class="section" aria-labelledby="payments-h">
    <h2 id="payments-h">Your payments</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Course</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $row): $p = $row['payment']; ?>
          <tr>
            <td><?= $v->e($row['course']) ?><span class="small muted"><?= $v->datetime($p->paidAt ?? $p->createdAt) ?>, <span class="ref"><?= $v->e($p->reference) ?></span></span></td>
            <td class="num"><?= $v->e($p->amount()) ?></td>
            <td><?= $v->e($p->statusLabel($now)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted">Quote the reference if you ask the school about a payment.</p>
  </section>
  <?php endif; ?>
  <?php endif; ?>
</section>
