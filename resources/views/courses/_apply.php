<?php
/**
 * Apply for a place and wait (ADR 0025), inside the course page's start box.
 * Expects $applyState ('form' | 'waiting'), $admission, $course, $user, $signUpOpen.
 *
 * @var \App\Core\View\ViewHelpers $v
 */
$app = $admission['application'];
$form = $admission['form'];
$errors = $form['errors'];
$old = $form['old'] + ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
$full = !$course->takesApplications() && $admission['placesLeft'] === 0;
$pagesShared = (array) $v->shared('site_pages', []);
?>
<div id="apply" class="apply">
<?php if ($applyState === 'received'): ?>
  <p class="start-title"><?= $v->icon('review') ?>Application received</p>
  <p>Thank you. The school will contact you at <strong><?= $v->e((string) $admission['received']) ?></strong> when a place is ready for you, in the order people applied.</p>
  <p class="small muted">Already have an account here? <a href="/login?next=<?= $v->e(rawurlencode('/courses/' . $course->slug)) ?>">Sign in</a> to see your place in line.</p>
<?php elseif ($applyState === 'waiting' && $app !== null): ?>
  <p class="start-title"><?= $v->icon('inbox') ?>You are on the waiting list</p>
  <div class="apply-status">
    <span class="apply-position"><span class="small">Your place in line</span><strong><?= (int) $admission['position'] ?></strong></span>
    <p>You applied on <?= $v->datetime($app->createdAt, 'j M Y') ?>. The school offers places in the order people applied; you will get a notification<?= $course->isPaid() ? ' and can pay' : ' and can start' ?> as soon as yours is ready.</p>
  </div>
  <form method="post" action="/courses/<?= $v->e($course->slug) ?>/apply/withdraw" class="start-alt"><?= $v->csrfField() ?><input type="hidden" name="application" value="<?= $app->id ?>"><button type="submit" class="linklike small">Leave the waiting list</button></form>
<?php else: ?>
  <p class="start-title"><?= $full ? 'This course is full' : 'Places are given by the school' ?></p>
  <p class="small"><?= $full
      ? 'Join the waiting list. When a place opens, the school offers it to the next person in line.'
      : 'Tell the school you want to join. You will be told here (and by email if you give it) when your place is ready.' ?>
    <?php if ($course->isPaid()): ?> The price is <strong><?= $v->e((string) $course->priceLabel()) ?></strong>, paid once you are offered a place.<?php endif; ?></p>
  <?php if ($app !== null && $app->status === 'declined'): ?><p class="notice small">Your last application was not offered a place<?= $app->decidedAt !== null ? ' (' . $v->datetime($app->decidedAt, 'j M') . ')' : '' ?>. You can apply again for a later intake.</p><?php endif; ?>
  <?php if (isset($errors['form'])): ?><p class="notice notice-err small" role="alert"><?= $v->e($errors['form']) ?></p><?php endif; ?>
  <form method="post" action="/courses/<?= $v->e($course->slug) ?>/apply" class="form apply-form" novalidate>
    <?= $v->csrfField() ?>
    <div class="hp" aria-hidden="true"><label for="apply-website">Leave this empty</label><input id="apply-website" name="website" tabindex="-1" autocomplete="off"></div>
    <?php if ($user === null): ?>
      <?= $v->field('name', 'Full name', ['value' => $old['name'], 'error' => $errors['name'] ?? null, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
      <?= $v->field('email', 'Email address', ['type' => 'email', 'value' => $old['email'], 'error' => $errors['email'] ?? null, 'autocomplete' => 'email', 'maxlength' => 254]) ?>
    <?php endif; ?>
    <?= $v->field('phone', 'Phone number', ['type' => 'tel', 'value' => $old['phone'], 'error' => $errors['phone'] ?? null, 'autocomplete' => 'tel', 'maxlength' => 40, 'optional' => true]) ?>
    <?= $v->field('message', 'Anything the school should know', ['type' => 'textarea', 'rows' => 3, 'value' => $old['message'], 'error' => $errors['message'] ?? null, 'optional' => true, 'hint' => 'For example what you do now, or when you can start.']) ?>
    <?php if ($user === null): ?>
    <label class="choice choice-tight<?= isset($errors['agree']) ? ' has-error' : '' ?>" id="agree"><input type="checkbox" name="agree" value="1"><span class="small">The school may contact me about a place<?= isset($pagesShared['/privacy']) ? ', as its <a href="/privacy" target="_blank" rel="noopener">privacy policy</a> explains' : '' ?>.</span></label>
    <?php if (isset($errors['agree'])): ?><p class="field-error"><?= $v->e($errors['agree']) ?></p><?php endif; ?>
    <?php endif; ?>
    <button type="submit" class="button apply-submit"><?= $full ? 'Join the waiting list' : 'Apply for a place' ?></button>
  </form>
  <?php if ($user === null): ?><p class="start-alt">Have an account here? <a href="/login?next=<?= $v->e(rawurlencode('/courses/' . $course->slug . '#apply')) ?>">Sign in</a> to apply with it<?= $signUpOpen ? ', or <a href="/join?course=' . $v->e($course->slug) . '">create one</a>' : '' ?>.</p><?php endif; ?>
<?php endif; ?>
</div>
