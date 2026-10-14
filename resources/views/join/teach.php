<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var bool $alreadyTeaches
 * @var ?\App\Modules\Joining\JoinRequest $latest
 * @var bool $needsConfirmation
 * @var bool $fromWelcome
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$formError = $errors['form'] ?? null;
unset($errors['form']);
?>
<header class="page-head">
  <h1>Teach at <?= $v->e($school->name) ?></h1>
  <p class="lead">Tell the school what you would teach. If they agree, you can build courses, labs and quizzes here.</p>
</header>

<?php if ($alreadyTeaches): ?>
  <p class="notice notice-done">You can already build courses here. <a href="/teach">Go to your courses</a>.</p>
<?php elseif ($latest !== null && $latest->isPending()): ?>
  <div class="panel teach-status">
    <h2>Request sent <?= $v->datetime($latest->createdAt, 'j M Y') ?></h2>
    <p>The school will decide and you will see the answer in your notifications.</p>
  </div>
<?php else: ?>
  <?php if ($fromWelcome): ?><p class="notice notice-ok">Your suggestions are ready on your home page. First, tell the school what you would like to teach.</p><?php endif; ?>
  <?php if ($latest !== null && $latest->status === 'declined'): ?><p class="notice">Your last request was not approved. You can ask again with more detail.</p><?php endif; ?>
  <?php if ($needsConfirmation): ?>
    <div class="notice notice-err">
      <p>Confirm your email address first, so the school knows how to reach you.</p>
      <form method="post" action="/account/confirm"><?= $v->csrfField() ?><button type="submit" class="button button-small button-quiet">Send me a new link</button></form>
    </div>
  <?php endif; ?>
  <?php if ($formError !== null): ?><p class="notice notice-err" role="alert"><?= $v->e($formError) ?></p><?php endif; ?>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="/join/teach" class="form narrow" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('subjects', 'What would you like to teach?', ['value' => $old['subjects'], 'error' => $errors['subjects'] ?? null, 'maxlength' => 200, 'hint' => 'For example "Python for beginners", "Network+ preparation" or "SS 2 Chemistry".']) ?>
    <?= $v->field('experience', 'Your experience', ['type' => 'textarea', 'rows' => 6, 'value' => $old['experience'], 'error' => $errors['experience'] ?? null, 'maxlength' => 3000, 'hint' => 'Where you have taught or worked, and anything the school should know.']) ?>
    <?= $v->field('link', 'A link that shows your work', ['value' => $old['link'], 'error' => $errors['link'] ?? null, 'inputmode' => 'url', 'maxlength' => 250, 'optional' => true, 'hint' => 'GitHub, LinkedIn, a portfolio or a course you made.']) ?>
    <button type="submit" class="button">Send to the school</button>
  </form>
<?php endif; ?>
