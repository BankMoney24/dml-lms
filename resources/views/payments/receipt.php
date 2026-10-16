<?php
/**
 * A payment receipt, ready to print or save as PDF (ADR 0024).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Payments\Payment $payment
 * @var ?\App\Modules\Courses\Course $course
 * @var string $payerName
 * @var string $payerEmail
 */
$school = $v->shared('tenant');
?>
<div class="no-print">
  <?= $v->breadcrumbs(['Your account' => '/account', 'Receipt' => null]) ?>
  <p class="actions">
    <button type="button" class="button" data-print>Print or save as PDF</button>
    <?php if ($course !== null && $course->isPublished()): ?><a class="button button-quiet" href="/courses/<?= $v->e($course->slug) ?>">Go to the course</a><?php endif; ?>
  </p>
</div>
<article class="receipt" aria-labelledby="receipt-h">
  <header class="receipt-head">
    <div>
      <?php if ($school?->hasLogo()): ?><img class="receipt-logo" src="/logo?v=<?= $v->e($school->logoVersion()) ?>" alt="" height="48"><?php endif; ?>
      <p class="receipt-school"><?= $v->e($school?->name) ?></p>
      <?php if (($school?->contactEmail ?? '') !== ''): ?><p class="small muted"><?= $v->e($school->contactEmail) ?><?= $school->contactPhone !== '' ? ', ' . $v->e($school->contactPhone) : '' ?></p><?php endif; ?>
    </div>
    <div class="receipt-title">
      <h1 id="receipt-h">Receipt</h1>
      <p class="badge badge-ok">Paid</p>
    </div>
  </header>
  <dl class="receipt-facts">
    <dt>Reference</dt><dd class="ref"><?= $v->e($payment->reference) ?></dd>
    <dt>Date paid</dt><dd><?= $v->datetime($payment->paidAt ?? $payment->createdAt, 'j F Y, H:i') ?></dd>
    <dt>Received from</dt><dd><?= $v->e($payerName) ?><br><span class="muted"><?= $v->e($payerEmail) ?></span></dd>
    <dt>Method</dt><dd><?= $v->e($payment->methodLabel()) ?></dd>
    <?php if ($payment->providerTransactionId !== null): ?><dt>Paystack transaction</dt><dd class="ref"><?= $v->e($payment->providerTransactionId) ?></dd><?php endif; ?>
  </dl>
  <table class="table receipt-lines">
    <thead><tr><th scope="col">For</th><th scope="col" class="num">Amount</th></tr></thead>
    <tbody><tr><td>Course: <?= $v->e($course?->title ?? 'Course') ?></td><td class="num"><?= $v->e($payment->amount()) ?></td></tr></tbody>
    <tfoot><tr><th scope="row">Total paid</th><td class="num"><strong><?= $v->e($payment->amount()) ?></strong></td></tr></tfoot>
  </table>
  <p class="small muted receipt-note">Keep this receipt. Quote the reference if you contact <?= $v->e($school?->name) ?> about this payment.</p>
</article>
