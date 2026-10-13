<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Certificates\Certificate $certificate */
/** @var ?\App\Modules\Courses\Course $course */
/** @var string $verifyUrl */
$tenant = $v->shared('tenant');
?>
<div class="no-print">
  <?= $v->breadcrumbs(['Home' => '/', 'Certificate' => null]) ?>
  <p class="actions">
    <button type="button" class="button" data-print>Print or save as PDF</button>
    <?php if ($course !== null && $course->isPublished()): ?><a class="button button-quiet" href="/courses/<?= $v->e($course->slug) ?>">Back to the course</a><?php endif; ?>
  </p>
  <?php if (!$certificate->isValid()): ?>
    <p class="notice notice-err">This certificate was revoked on <?= $v->datetime($certificate->revokedAt, 'j M Y') ?>: <?= $v->e($certificate->revokedReason) ?></p>
  <?php endif; ?>
</div>

<article class="certificate<?= $certificate->isValid() ? '' : ' is-revoked' ?>" aria-label="Certificate of completion">
  <div class="certificate-inner">
    <p class="cert-school">
      <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><rect class="px-soft" x="1" y="2" width="4.5" height="4.5"/><rect x="6.5" y="2" width="4.5" height="4.5"/><rect x="12" y="2" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="2" width="4.5" height="4.5"/><rect x="6.5" y="7.5" width="4.5" height="4.5"/><rect class="px-soft" x="12" y="7.5" width="4.5" height="4.5"/><rect x="17.5" y="7.5" width="4.5" height="4.5"/><rect x="12" y="13" width="4.5" height="4.5"/><rect class="px-accent" x="17.5" y="13" width="4.5" height="4.5"/><rect x="17.5" y="18.5" width="4.5" height="4.5"/></svg>
      <?= $v->e($tenant?->name ?? '') ?>
    </p>
    <p class="cert-kicker">Certificate of completion</p>
    <p class="cert-line">This certifies that</p>
    <p class="cert-name"><?= $v->e($certificate->learnerName) ?></p>
    <p class="cert-line">has successfully completed</p>
    <p class="cert-course"><?= $v->e($certificate->courseTitle) ?></p>
    <?php if ($course !== null && ($course->levelLabel() !== null || $course->durationHours !== null)): ?>
    <p class="cert-detail">
      <?= $course->levelLabel() !== null ? $v->e($course->levelLabel() . ' level') : '' ?>
      <?= $course->levelLabel() !== null && $course->durationHours !== null ? ', ' : '' ?>
      <?= $course->durationHours !== null ? $v->e('about ' . $v->plural($course->durationHours, 'hour') . ' of study') : '' ?>
    </p>
    <?php endif; ?>
    <div class="cert-foot">
      <div>
        <p class="cert-label">Issued</p>
        <p><?= $v->datetime($certificate->issuedAt, 'j F Y') ?></p>
      </div>
      <div>
        <p class="cert-label">Certificate number</p>
        <p class="cert-code"><?= $v->e($certificate->displayCode()) ?></p>
      </div>
      <div>
        <p class="cert-label">Check it at</p>
        <p class="cert-url"><?= $v->e($verifyUrl) ?></p>
      </div>
    </div>
  </div>
</article>
