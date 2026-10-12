<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var ?\App\Modules\Certificates\Certificate $certificate */
$tenant = $v->shared('tenant');
?>
<section class="narrow">
  <header class="page-head">
    <h1>Check a certificate</h1>
    <p class="lead">Enter the certificate number printed on a <?= $v->e($tenant?->name ?? '') ?> certificate to confirm it is genuine.</p>
  </header>

  <?php if ($searched && $certificate === null): ?>
    <div class="notice notice-err" role="alert">
      <p><strong>No certificate found with that number.</strong></p>
      <p>Check the number and try again. Certificates from other schools are checked on that school’s own site.</p>
    </div>
  <?php elseif ($certificate !== null && !$certificate->isValid()): ?>
    <div class="notice notice-err verify-result" role="status">
      <p class="verify-status">Revoked</p>
      <p>Certificate <?= $v->e($certificate->displayCode()) ?> for <strong><?= $v->e($certificate->learnerName) ?></strong>, <?= $v->e($certificate->courseTitle) ?>, was revoked on <?= $v->datetime($certificate->revokedAt, 'j F Y') ?>.</p>
      <p>Reason: <?= $v->e($certificate->revokedReason) ?></p>
    </div>
  <?php elseif ($certificate !== null): ?>
    <div class="notice notice-done verify-result" role="status">
      <p class="verify-status">✓ Genuine certificate</p>
      <dl class="facts">
        <dt>Awarded to</dt><dd><?= $v->e($certificate->learnerName) ?></dd>
        <dt>Course</dt><dd><?= $v->e($certificate->courseTitle) ?></dd>
        <dt>Issued</dt><dd><?= $v->datetime($certificate->issuedAt, 'j F Y') ?></dd>
        <dt>Issued by</dt><dd><?= $v->e($tenant?->name ?? '') ?></dd>
        <dt>Number</dt><dd><?= $v->e($certificate->displayCode()) ?></dd>
      </dl>
    </div>
  <?php endif; ?>

  <form method="get" action="/verify" class="form verify-form">
    <?= $v->field('code', 'Certificate number', [
        'value' => $code, 'hint' => '12 letters and numbers, for example ABCD-EFGH-JKLM.', 'autocomplete' => 'off', 'maxlength' => 20,
    ]) ?>
    <button type="submit" class="button">Check</button>
  </form>
</section>
