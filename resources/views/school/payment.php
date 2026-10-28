<?php
/**
 * One payment: who paid, for what, everything recorded about it, refunds, and the refund form (ADR 0032).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Payments\Payment $payment
 * @var string $learner
 * @var string $email
 * @var string $course
 * @var list<\App\Modules\Payments\Refunds\Refund> $refunds
 * @var int $refundable
 * @var list<array{type: string, outcome: string, source: string, created_at: string}> $events
 * @var int $now
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use App\Modules\Payments\Money;

$schoolTab = 'payments';
$label = $payment->statusLabel($now);
$tones = ['Paid' => 'done', 'Failed' => 'danger', 'Waiting' => 'draft', 'Not completed' => 'neutral', 'Refunded' => 'neutral'];
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Payments' => '/school/payments', $payment->reference => null]) ?>
  <h1><?= $v->e($payment->amount()) ?> from <?= $v->e($learner) ?></h1>
  <p class="page-head-meta"><?= $v->badge($label, $tones[$label] ?? 'neutral') ?> <span><?= $v->e($course) ?></span></p>
</header>

<div class="teach-layout">
  <section aria-labelledby="pay-facts-h">
    <h2 id="pay-facts-h">The payment</h2>
    <dl class="facts">
      <dt>Learner</dt><dd><?= $v->e($learner) ?> <span class="muted small"><?= $v->e($email) ?></span></dd>
      <dt>Course</dt><dd><?= $v->e($course) ?></dd>
      <?php if ($payment->planId !== null): ?><dt>Pays</dt><dd>Part <?= (int) $payment->planPart ?> of a <a href="/school/payments/plans/<?= $payment->planId ?>">payment plan</a></dd><?php endif; ?>
      <dt>Amount</dt><dd><?= $v->e($payment->amount()) ?><?php if ($payment->discountMinor() > 0): ?> <span class="muted small">(price <?= $v->e(Money::format((int) $payment->listPriceMinor, $payment->currency)) ?>, discount code)</span><?php endif; ?></dd>
      <dt>How</dt><dd><?= $v->e($payment->methodLabel()) ?><?php if ($payment->note !== null): ?><br><span class="small muted"><?= $v->e($payment->note) ?></span><?php endif; ?></dd>
      <dt>Reference</dt><dd class="ref"><?= $v->e($payment->reference) ?></dd>
      <dt><?= $payment->paidAt !== null ? 'Paid' : 'Started' ?></dt><dd><?= $v->datetime($payment->paidAt ?? $payment->createdAt) ?></dd>
    </dl>
    <?php if (in_array($payment->status, ['paid', 'refunded'], true)): ?><p><a href="/account/receipts/<?= $v->e(rawurlencode($payment->reference)) ?>">Open the receipt</a></p><?php endif; ?>

    <?php if ($refunds !== []): ?>
    <h2 id="refunds-h">Refunds</h2>
    <ul class="meet-people" aria-labelledby="refunds-h">
      <?php foreach ($refunds as $r): ?>
        <li><span><strong><?= $v->e($r->amount()) ?></strong>, <?= $v->e($r->reason) ?><span class="small muted"> by <?= $v->e($r->createdByName) ?>, <?= $v->datetime($r->createdAt) ?><?= $r->withdrewLearner ? '; taken off the course' : '' ?></span></span>
          <?= $v->badge($r->statusLabel(), $r->status === 'processed' ? 'done' : ($r->status === 'failed' ? 'danger' : 'draft')) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($events !== []): ?>
    <details class="pay-events">
      <summary>Everything recorded about this payment (<?= count($events) ?>)</summary>
      <ul class="small">
        <?php foreach ($events as $e): ?><li><?= $v->datetime($e['created_at']) ?>: <?= $v->e($e['type']) ?>, <?= $v->e($e['outcome']) ?> <span class="muted">(<?= $v->e($e['source']) ?>)</span></li><?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </section>

  <aside>
    <section class="panel" aria-labelledby="refund-h">
      <h2 id="refund-h">Pay money back</h2>
      <?php if ($refundable === 0): ?>
        <p class="muted"><?= $payment->provider === 'coupon' ? 'Nothing was paid: this place came from a discount code.' : ($payment->isPaid() || $payment->status === 'refunded' ? 'Everything paid has been paid back or is on its way back.' : 'Only confirmed payments can be refunded.') ?></p>
      <?php else: ?>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/school/payments/<?= $payment->id ?>/refund" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('refund_amount', 'Amount to pay back', ['value' => $old['refund_amount'], 'inputmode' => 'decimal', 'maxlength' => 14, 'optional' => true,
            'error' => $errors['refund_amount'] ?? null, 'hint' => 'Up to ' . Money::format($refundable, $payment->currency) . '. Leave empty for all of it.']) ?>
        <?= $v->field('refund_reason', 'Why', ['value' => $old['refund_reason'], 'maxlength' => 200, 'error' => $errors['refund_reason'] ?? null,
            'hint' => 'The learner sees this, for example “Course cancelled” or “Paid twice”.']) ?>
        <label class="choice choice-tight"><input type="checkbox" name="withdraw" value="1"<?= ($old['withdraw'] ?? '1') === '1' ? ' checked' : '' ?>><span>Also take <?= $v->e($learner) ?> off the course. Their progress is kept and comes back if they join again.</span></label>
        <p class="small muted"><?= $payment->provider === 'paystack'
            ? 'Paystack pays it back to the card or account they paid with, usually within a few working days.'
            : 'This was paid outside the platform, so pay it back the same way; this records that you did.' ?></p>
        <button type="submit" class="button button-danger"><?= $payment->provider === 'paystack' ? 'Ask Paystack to pay it back' : 'Record the refund' ?></button>
      </form>
      <?php endif; ?>
    </section>
  </aside>
</div>
