<?php
/**
 * Where the learner lands back from Paystack when the payment is not (yet) confirmed.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var string $outcome pending | failed | unknown | paid (paid only when the course is closed)
 * @var string $amount
 * @var string $reference
 */
$courseUrl = '/courses/' . $course->slug;
?>
<section class="pay-result">
  <?= $v->breadcrumbs(['Courses' => '/courses', $course->title => $courseUrl, 'Payment' => null]) ?>
  <?php if ($outcome === 'failed'): ?>
    <h1>The payment did not go through</h1>
    <p class="lead">Paystack did not take the <?= $v->e($amount) ?> for <?= $v->e($course->title) ?>. If your bank shows a charge, it is usually reversed within a day.</p>
    <form method="post" action="<?= $v->e($courseUrl) ?>/pay">
      <?= $v->csrfField() ?>
      <button type="submit" class="button">Try again</button>
    </form>
  <?php elseif ($outcome === 'paid'): ?>
    <h1>Payment received</h1>
    <p class="lead">We have your <?= $v->e($amount) ?> for <?= $v->e($course->title) ?>. The course is closed for changes right now; it opens for you as soon as the school reopens it.</p>
  <?php else: ?>
    <h1>Confirming your payment</h1>
    <p class="lead">Paystack has not confirmed the <?= $v->e($amount) ?> for <?= $v->e($course->title) ?> yet. This usually takes under a minute, and longer for bank transfers.</p>
    <p><a class="button" href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($reference)) ?>">Check again</a></p>
    <p class="small muted">If you closed the payment page without paying, nothing was charged. You can go back to the <a href="<?= $v->e($courseUrl) ?>">course page</a> and pay from there.</p>
  <?php endif; ?>
  <p class="small muted">Payment reference <span class="ref"><?= $v->e($reference) ?></span>. Quote it if you contact the school.</p>
</section>
