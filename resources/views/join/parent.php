<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var bool $sent
 * @var array<string, string> $relationships
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$formError = $errors['form'] ?? null;
unset($errors['form']);
?>
<div class="join">
  <aside class="join-panel" aria-label="For parents and guardians">
    <?php $pixelClass = 'pixels-hero'; include __DIR__ . '/../partials/pixels.php'; ?>
    <p class="join-school">For parents and guardians</p>
    <p class="join-tagline"><?= $v->e($school->name) ?></p>
    <ol class="join-steps">
      <li><span class="join-step-node" aria-hidden="true"></span>You send this short form</li>
      <li><span class="join-step-node" aria-hidden="true"></span>The school checks who you are, usually by phone or at the office</li>
      <li><span class="join-step-node" aria-hidden="true"></span>You get a sign-in and see your child's courses, results and class news, as the school allows</li>
    </ol>
    <p class="join-note">You can only ever see your own child, and you cannot change anything in their account.</p>
  </aside>
  <section class="join-main">
    <?php if ($sent): ?>
      <header class="page-head">
        <h1>Request sent</h1>
        <p class="lead">Thank you. The school will contact you to check who you are before giving you access.</p>
      </header>
      <p><a class="button button-quiet" href="/">Back to the home page</a></p>
    <?php else: ?>
      <header class="page-head">
        <h1>Follow your child's learning</h1>
        <p class="lead">Ask <?= $v->e($school->name) ?> to give you access. Nothing is shared until the school has checked who you are.</p>
      </header>
      <?php if ($formError !== null): ?><p class="notice notice-err" role="alert"><?= $v->e($formError) ?></p><?php endif; ?>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/join/parent" class="form join-form" novalidate>
        <?= $v->csrfField() ?>
        <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <?= $v->field('name', 'Your full name', ['value' => $old['name'], 'error' => $errors['name'] ?? null, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
        <?= $v->field('email', 'Your email address', ['type' => 'email', 'value' => $old['email'], 'error' => $errors['email'] ?? null, 'autocomplete' => 'email', 'maxlength' => 254]) ?>
        <?= $v->field('phone', 'Your phone number', ['value' => $old['phone'], 'error' => $errors['phone'] ?? null, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 40, 'optional' => true, 'hint' => 'The quickest way for the school to check who you are.']) ?>
        <?= $v->field('child', 'Your child\'s full name', ['value' => $old['child'], 'error' => $errors['child'] ?? null, 'maxlength' => 120, 'autocomplete' => 'off', 'hint' => 'As the school knows them. Add their class in the message if it helps.']) ?>
        <?= $v->field('relationship', 'You are their', ['type' => 'select', 'options' => ['' => 'Choose'] + $relationships, 'value' => $old['relationship'], 'error' => $errors['relationship'] ?? null]) ?>
        <?= $v->field('message', 'Message', ['type' => 'textarea', 'rows' => 3, 'value' => $old['message'], 'error' => $errors['message'] ?? null, 'optional' => true, 'maxlength' => 2000]) ?>
        <label class="choice choice-tight<?= isset($errors['agree']) ? ' has-error' : '' ?>" id="agree"><input type="checkbox" name="agree" value="1"><span>The school may contact me to check who I am.</span></label>
        <?php if (isset($errors['agree'])): ?><p class="field-error"><?= $v->e($errors['agree']) ?></p><?php endif; ?>
        <button type="submit" class="button join-submit">Send request</button>
      </form>
    <?php endif; ?>
  </section>
</div>
