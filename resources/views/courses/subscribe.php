<?php
/**
 * Subscribe to a course (ADR 0026): account → pay (paid courses) → start.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var list<array{id: int, title: string, lessons: list<\App\Modules\Courses\Lesson>}> $outline
 * @var string $state account | subscribed | free | pay | cannot
 * @var ?\App\Modules\Courses\Lesson $nextLesson
 * @var bool $signUpOpen
 * @var bool $payOnline
 * @var string $instructions
 * @var ?\App\Modules\Payments\PaymentClaim $claim
 * @var ?\App\Modules\Payments\Payment $waiting
 * @var array<string, string> $methods
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $codesOn
 * @var string $code
 * @var ?array{coupon: \App\Modules\Payments\Coupons\Coupon, discount: int, price: int} $quote
 * @var ?string $codeError
 */
$codesOn ??= false;
$code ??= '';
$quote ??= null;
$codeError ??= null;
$slug = $v->e($course->slug);
$paid = $course->isPaid();
$counts = [];
$lessonCount = 0;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        $counts[$lesson->typeLabel()] = ($counts[$lesson->typeLabel()] ?? 0) + 1;
        $lessonCount++;
    }
}
$included = [];
foreach ($counts as $label => $n) {
    $included[] = $n . ' ' . strtolower($label) . ($n === 1 ? '' : (str_ends_with(strtolower($label), 'z') ? 'zes' : 's'));
}
$certificates = (bool) ($v->shared('tenant')?->certificatesEnabled ?? false);
$here = '/courses/' . $course->slug . '/subscribe';

// The steps, and where this person is on them.
$steps = ['account' => 'Your account'];
if ($paid) {
    $steps['pay'] = 'Payment';
}
$steps['start'] = 'Start learning';
$current = match ($state) {
    'account' => 'account',
    'pay' => 'pay',
    default => 'start',
};
$order = array_keys($steps);
$at = (int) array_search($current, $order, true);
$claimPending = $claim !== null && $claim->isPending();
$claimRejected = $claim !== null && $claim->status === 'rejected';
?>
<?= $v->breadcrumbs(['Courses' => '/courses', $course->title => '/courses/' . $course->slug, 'Subscribe' => null]) ?>

<header class="page-head sub-head">
  <h1>Subscribe to <?= $v->e($course->title) ?></h1>
  <p class="lead"><?= $paid
      ? 'Pay once for the whole course, then learn at your own pace.'
      : 'This course is free. Subscribe to save your progress' . ($certificates ? ' and earn the certificate.' : '.') ?></p>
</header>

<ol class="sub-steps" aria-label="Steps to subscribe">
  <?php foreach ($order as $i => $key): $status = $i < $at || $state === 'subscribed' ? 'done' : ($i === $at ? 'current' : 'next'); ?>
    <li class="sub-step is-<?= $status ?>"<?= $status === 'current' ? ' aria-current="step"' : '' ?>>
      <span class="sub-step-mark" aria-hidden="true"><?= $status === 'done' ? $v->icon('check') : $i + 1 ?></span>
      <span class="sub-step-name"><?= $v->e($steps[$key]) ?><span class="visually-hidden"><?= $status === 'done' ? ' (done)' : ($status === 'current' ? ' (you are here)' : '') ?></span></span>
    </li>
  <?php endforeach; ?>
</ol>

<div class="sub-layout">
  <div class="sub-main">
  <?php if ($state === 'subscribed'): ?>
    <section class="sub-panel sub-panel-done" aria-labelledby="sub-done-h">
      <h2 id="sub-done-h">You are subscribed</h2>
      <p><?= $v->e($course->title) ?> is in your courses. Your progress is saved as you go.</p>
      <p class="sub-actions">
        <?php if ($nextLesson !== null): ?><a class="button" href="/courses/<?= $slug ?>/lessons/<?= $nextLesson->id ?>">Continue: <?= $v->e($nextLesson->title) ?></a><?php endif; ?>
        <a class="button button-quiet" href="/account/subscriptions">My subscriptions</a>
      </p>
    </section>

  <?php elseif ($state === 'account'): ?>
    <section class="sub-panel" aria-labelledby="sub-acc-h">
      <h2 id="sub-acc-h"><?= $signUpOpen ? 'Create your account' : 'Sign in to subscribe' ?></h2>
      <?php if ($signUpOpen): ?>
        <p>An account keeps your progress<?= $paid ? ', your payment and your receipt' : '' ?> in one place. It takes a minute, and you come straight back here<?= $paid ? ' to pay' : ' and start' ?>.</p>
        <p class="sub-actions">
          <a class="button" href="/join?course=<?= $slug ?>"><?= $v->icon('user-plus') ?>Create an account</a>
          <a class="button button-quiet" href="/login?next=<?= $v->e(rawurlencode($here)) ?>">I already have one: sign in</a>
        </p>
      <?php else: ?>
        <p>This school adds its learners itself. If you have an account, sign in and you come straight back here. If not, ask the school office to add you.</p>
        <p class="sub-actions"><a class="button" href="/login?next=<?= $v->e(rawurlencode($here)) ?>">Sign in</a></p>
      <?php endif; ?>
    </section>

  <?php elseif ($state === 'free'): ?>
    <section class="sub-panel" aria-labelledby="sub-free-h">
      <h2 id="sub-free-h">Free to take</h2>
      <p>Subscribe and the first lesson opens straight away. You can leave and come back; the course remembers where you stopped.</p>
      <form method="post" action="<?= $v->e($here) ?>" class="sub-actions">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Subscribe and start</button>
      </form>
    </section>

  <?php elseif ($state === 'pay'): ?>
    <?php if ($waiting !== null): ?>
      <p class="notice">You started an online payment of <?= $v->e($waiting->amount()) ?> on <?= $v->e($v->datetime($waiting->createdAt)) ?>.
        If money left your account, the course opens as soon as Paystack confirms it.
        <a href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($waiting->reference)) ?>">Check now</a></p>
    <?php endif; ?>

    <?php if ($claimPending): ?>
      <section class="sub-panel sub-panel-wait" aria-labelledby="sub-wait-h">
        <h2 id="sub-wait-h">The school is checking your payment</h2>
        <p>When they find it in their account, the course opens and you get a notification. This is usually within a working day.</p>
        <dl class="facts">
          <dt>Amount</dt><dd><?= $v->e($claim->amount()) ?></dd>
          <dt>How</dt><dd><?= $v->e($claim->methodLabel()) ?> on <?= $v->e($v->datetime($claim->paidOn . ' 00:00:00', 'j M Y')) ?></dd>
          <?php if ($claim->payerReference !== ''): ?><dt>Reference</dt><dd><?= $v->e($claim->payerReference) ?></dd><?php endif; ?>
          <dt>Sent</dt><dd><?= $v->e($v->datetime($claim->createdAt)) ?></dd>
        </dl>
        <form method="post" action="<?= $v->e($here) ?>/transfer/withdraw" class="form-inline-end">
          <?= $v->csrfField() ?>
          <input type="hidden" name="claim" value="<?= $claim->id ?>">
          <button type="submit" class="button button-small button-quiet">I made a mistake: withdraw this note</button>
        </form>
      </section>
    <?php else: ?>
      <?php if ($claimRejected): ?>
        <div class="notice notice-err" role="status">
          <p><strong>The school could not confirm your <?= $v->e($claim->amount()) ?> payment.</strong> <?= $v->e((string) $claim->reason) ?></p>
          <p class="small">Check the details with your bank, then pay again or send a corrected note below.</p>
        </div>
      <?php endif; ?>

      <?php if ($codesOn): ?>
      <form method="get" action="<?= $v->e($here) ?>" class="code-box" id="discount">
        <?= $v->field('code', 'Discount code', ['value' => $code, 'maxlength' => 40, 'autocomplete' => 'off', 'optional' => true, 'error' => $codeError,
            'hint' => $quote === null ? 'If the school gave you one.' : null]) ?>
        <button type="submit" class="button button-small button-quiet"><?= $quote === null ? 'Use code' : 'Change code' ?></button>
      </form>
      <?php if ($quote !== null): $price = \App\Modules\Payments\Money::format($quote['price'], (string) $course->currency); ?>
        <p class="notice notice-done code-quote" role="status">Code <strong><?= $v->e($quote['coupon']->code) ?></strong>: <?= $v->e($quote['coupon']->label()) ?>.
          <?= $quote['price'] === 0 ? 'The course is free for you.' : 'You pay <strong>' . $v->e($price) . '</strong> instead of <span class="price-was">' . $v->e((string) $course->priceLabel()) . '</span>.' ?></p>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($quote !== null && $quote['price'] === 0): ?>
      <section class="sub-panel sub-option" aria-labelledby="sub-free-h">
        <h2 id="sub-free-h">Start with your code</h2>
        <form method="post" action="<?= $v->e($here) ?>" class="sub-actions">
          <?= $v->csrfField() ?>
          <input type="hidden" name="code" value="<?= $v->e($quote['coupon']->code) ?>">
          <button type="submit" class="button">Subscribe and start</button>
        </form>
      </section>
      <?php else: ?>
      <?php if ($payOnline): ?>
      <section class="sub-panel sub-option" aria-labelledby="sub-online-h">
        <h2 id="sub-online-h">Pay online</h2>
        <p>Card, bank transfer or USSD on Paystack's secure page. You come straight back here and the course opens as soon as the payment is confirmed.</p>
        <form method="post" action="<?= $v->e($here) ?>" class="sub-actions">
          <?= $v->csrfField() ?>
          <?php if ($quote !== null): ?><input type="hidden" name="code" value="<?= $v->e($quote['coupon']->code) ?>"><?php endif; ?>
          <button type="submit" class="button">Pay <?= $v->e($quote !== null ? \App\Modules\Payments\Money::format($quote['price'], (string) $course->currency) : (string) $course->priceLabel()) ?> and subscribe</button>
        </form>
      </section>
      <?php endif; ?>

      <section class="sub-panel sub-option" id="transfer" aria-labelledby="sub-direct-h">
        <h2 id="sub-direct-h"><?= $payOnline ? 'Or pay the school directly' : 'Pay the school' ?></h2>
        <?php if ($instructions !== ''): ?>
          <div class="sub-bank"><?= $v->paragraphs($instructions) ?></div>
          <p class="small muted">Pay <strong><?= $v->e((string) $course->priceLabel()) ?></strong>, then tell the school below so they can find your payment.</p>
        <?php else: ?>
          <p>Ask the school office for their bank details, pay <strong><?= $v->e((string) $course->priceLabel()) ?></strong>, then tell the school below so they can find your payment.</p>
        <?php endif; ?>

        <?= $v->errorSummary($errors) ?>
        <form method="post" action="<?= $v->e($here) ?>/transfer" class="form sub-claim" novalidate>
          <?= $v->csrfField() ?>
          <h3 class="sub-claim-title">I have paid</h3>
          <div class="field-row">
            <?= $v->field('method', 'How you paid', ['type' => 'select', 'options' => $methods, 'value' => $old['method'], 'error' => $errors['method'] ?? null]) ?>
            <?= $v->field('amount', 'Amount', ['value' => $old['amount'], 'inputmode' => 'decimal', 'error' => $errors['amount'] ?? null, 'hint' => 'In ' . $v->e((string) $course->currency) . ', as on your receipt.']) ?>
          </div>
          <div class="field-row">
            <?= $v->field('paid_on', 'Date you paid', ['type' => 'date', 'value' => $old['paid_on'], 'error' => $errors['paid_on'] ?? null]) ?>
            <?= $v->field('reference', 'Reference', ['value' => $old['reference'], 'maxlength' => 80, 'error' => $errors['reference'] ?? null, 'hint' => 'Session ID, transaction or teller number.']) ?>
          </div>
          <?= $v->field('note', 'Anything else the school should know (optional)', ['type' => 'textarea', 'rows' => 2, 'value' => $old['note'], 'maxlength' => 500, 'error' => $errors['note'] ?? null, 'hint' => 'For example, if someone else paid for you, their name.']) ?>
          <button type="submit" class="button<?= $payOnline ? ' button-quiet' : '' ?>">Tell the school I have paid</button>
          <p class="small muted">The course opens when the school confirms the money arrived. Nothing is charged here.<?= $quote !== null ? ' A discount code is applied to online payment; to use it when paying the school directly, show it to the school.' : '' ?></p>
        </form>
      </section>
      <?php endif; ?>
    <?php endif; ?>

  <?php else: ?>
    <section class="sub-panel" aria-labelledby="sub-no-h">
      <h2 id="sub-no-h">Your account does not take courses here</h2>
      <p>Accounts for parents and some staff can look at courses but not join them. Ask the school office if you need a learner account.</p>
      <p><a href="/courses/<?= $slug ?>">Back to the course</a></p>
    </section>
  <?php endif; ?>
  </div>

  <aside class="sub-ticket" aria-label="What you are subscribing to">
    <div class="sub-ticket-art"><?php $c = $course; include __DIR__ . '/_cover.php'; ?></div>
    <div class="sub-ticket-body">
      <p class="sub-ticket-title"><?= $v->e($course->title) ?></p>
      <p class="sub-ticket-price"><?php if ($paid): ?><strong><?= $v->e((string) $course->priceLabel()) ?></strong> once, for the whole course<?php else: ?><strong>Free</strong><?php endif; ?></p>
    </div>
    <ul class="sub-ticket-list">
      <?php if ($included !== []): ?><li><?= $v->e(implode(', ', $included)) ?></li><?php else: ?><li><?= $v->e($v->plural($lessonCount, 'lesson')) ?></li><?php endif; ?>
      <?php if ($course->durationHours !== null): ?><li>About <?= $v->e($v->plural($course->durationHours, 'hour')) ?>, at your own pace</li><?php endif; ?>
      <?php if ($course->levelLabel() !== null): ?><li><?= $v->e($course->levelLabel()) ?> level</li><?php endif; ?>
      <?php if ($certificates): ?><li>A certificate anyone can check online</li><?php endif; ?>
      <li>Questions answered by teachers under every lesson</li>
    </ul>
    <p class="sub-ticket-foot"><a href="/courses/<?= $slug ?>">See the full outline</a></p>
  </aside>
</div>
