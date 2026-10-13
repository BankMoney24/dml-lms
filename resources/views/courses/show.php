<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var ?\App\Modules\Learning\Enrolment $enrolment */
/** @var ?\App\Modules\Certificates\Certificate $certificate */
/** @var bool $mustPay this learner has to pay before enrolling */
/** @var bool $payOnline the school takes payments through Paystack */
/** @var ?\App\Modules\Payments\Payment $waiting a checkout started recently and not yet confirmed */
$user = $v->shared('user');
$lessonIds = [];
$counts = [];
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        $lessonIds[] = $lesson->id;
        $counts[$lesson->typeLabel()] = ($counts[$lesson->typeLabel()] ?? 0) + 1;
    }
}
$firstOpen = null;
foreach ($outline as $module) {
    foreach ($module['lessons'] as $lesson) {
        if ($firstOpen === null && !in_array($lesson->id, $completedIds, true)) {
            $firstOpen = $lesson;
        }
    }
}
$lessonCount = count($lessonIds);
$done = count(array_intersect($lessonIds, $completedIds));
?>
<div class="course-layout">
  <section class="course-intro">
    <h1><?= $v->e($course->title) ?></h1>
    <p class="page-head-meta">
      <?php if ($course->levelLabel() !== null): ?><?= $v->badge($course->levelLabel(), 'level') ?><?php endif; ?>
      <?php if ($course->durationHours !== null): ?><?= $v->badge('About ' . $v->plural($course->durationHours, 'hour')) ?><?php endif; ?>
      <?= $v->badge($v->plural(count($outline), 'module')) ?>
      <?php if ($v->can('course.manage')): ?><a href="/teach/courses/<?= $course->id ?>">Edit this course</a><?php endif; ?>
    </p>
    <?= $v->paragraphs($course->summary) ?>
    <?php if ($counts !== []): ?>
    <?php
      $parts = array_map(static fn ($label, $n) => $n . ' ' . strtolower($label) . ($n === 1 ? '' : (str_ends_with(strtolower($label), 'z') ? 'zes' : 's')), array_keys($counts), $counts);
      $last = array_pop($parts);
    ?>
    <p class="small muted course-counts"><?= $v->e($parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last) ?></p>
    <?php endif; ?>

    <?php if ($certificate !== null): ?>
      <div class="notice notice-done cert-callout">
        <p><strong>You have completed this course.</strong> Your certificate is ready.</p>
        <p><a class="button button-small" href="/certificates/<?= $v->e($certificate->displayCode()) ?>">View and print your certificate</a></p>
      </div>
    <?php elseif ($enrolment !== null && $enrolment->isCompleted()): ?>
      <p class="notice notice-done">You have completed this course.</p>
    <?php elseif ($enrolment !== null && $firstOpen !== null): ?>
      <p class="small"><?= $done ?> of <?= $lessonCount ?> lessons done</p>
      <progress class="meter" max="<?= max(1, $lessonCount) ?>" value="<?= $done ?>" aria-label="Your progress"></progress>
      <p><a class="button" href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $firstOpen->id ?>"><?= $done === 0 ? 'Start' : 'Continue' ?>: <?= $v->e($firstOpen->title) ?></a></p>
    <?php elseif ($user === null): ?>
      <?php if ($course->isPaid()): ?><p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p><?php endif; ?>
      <p><a class="button" href="/login">Sign in to enrol</a></p>
    <?php elseif ($v->can('course.learn') && $mustPay): ?>
      <div class="pay-box" id="pay">
        <p class="course-price"><strong><?= $v->e((string) $course->priceLabel()) ?></strong> for the whole course</p>
        <?php if ($waiting !== null): ?>
          <p class="notice">You started a payment of <?= $v->e($waiting->amount()) ?> on <?= $v->e($v->datetime($waiting->createdAt)) ?>.
            If money left your account, the course opens as soon as Paystack confirms it.
            <a href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($waiting->reference)) ?>">Check now</a></p>
        <?php endif; ?>
        <?php if ($payOnline): ?>
          <form method="post" action="/courses/<?= $v->e($course->slug) ?>/pay">
            <?= $v->csrfField() ?>
            <button type="submit" class="button">Pay <?= $v->e((string) $course->priceLabel()) ?> and start</button>
          </form>
          <p class="small muted">You pay on Paystack's secure page by card, bank transfer or USSD, then come straight back here.</p>
        <?php else: ?>
          <p>This school does not take payments online yet. Ask the school how to pay. Once they record your payment, the course opens for you here.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($v->can('course.learn')): ?>
      <form method="post" action="/courses/<?= $v->e($course->slug) ?>/enrol">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Enrol and start</button>
      </form>
    <?php endif; ?>

    <?php if ($course->outcomes !== []): ?>
    <section class="outcomes" aria-labelledby="outcomes-h">
      <h2 id="outcomes-h">What you will be able to do</h2>
      <ul class="checklist">
        <?php foreach ($course->outcomes as $outcome): ?><li><?= $v->e($outcome) ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($course->prerequisites !== ''): ?>
    <section aria-labelledby="pre-h">
      <h2 id="pre-h">Before you start</h2>
      <div class="prose"><?= $v->markdown($course->prerequisites) ?></div>
    </section>
    <?php endif; ?>
  </section>

  <section class="course-outline" aria-labelledby="outline-title">
    <h2 id="outline-title">Course outline</h2>
    <?php $linkLessons = $enrolment !== null; include __DIR__ . '/_trace.php'; ?>
  </section>
</div>
