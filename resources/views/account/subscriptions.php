<?php
/**
 * My subscriptions (ADR 0026): the courses this person has, what they are
 * waiting on, and what they paid, with receipts.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{course_id: int, slug: string, title: string, status: string, done: int, total: int}> $courses
 * @var list<array{payment: \App\Modules\Payments\Payment, course: string}> $paid
 * @var list<array{payment: \App\Modules\Payments\Payment, course: string}> $checkouts
 * @var array<int, string> $checkoutSlugs
 * @var list<\App\Modules\Payments\PaymentClaim> $pendingClaims
 * @var list<\App\Modules\Payments\PaymentClaim> $rejectedClaims
 * @var list<\App\Modules\Applications\Application> $applications
 */
$waitingCount = count($pendingClaims) + count($checkouts) + count($applications) + count($rejectedClaims);
?>
<header class="page-head">
  <h1>My subscriptions</h1>
  <p class="lead">Courses you have subscribed to, anything still being confirmed, and your receipts.</p>
</header>

<?php if ($waitingCount > 0): ?>
<section class="subs-section" aria-labelledby="subs-wait-h">
  <h2 id="subs-wait-h">Waiting on the school</h2>
  <ul class="subs-list">
    <?php foreach ($pendingClaims as $claim): ?>
      <li class="subs-row">
        <div><a href="/courses/<?= $v->e($claim->courseSlug) ?>/subscribe"><?= $v->e($claim->courseTitle) ?></a>
          <span class="small muted"><?= $v->e($claim->methodLabel()) ?> of <?= $v->e($claim->amount()) ?> on <?= $v->e($v->datetime($claim->paidOn . ' 00:00:00', 'j M Y')) ?></span></div>
        <?= $v->badge('Payment being checked', 'draft') ?>
      </li>
    <?php endforeach; ?>
    <?php foreach ($checkouts as $row): $p = $row['payment']; ?>
      <li class="subs-row">
        <div><?= isset($checkoutSlugs[$p->courseId]) ? '<a href="/courses/' . $v->e($checkoutSlugs[$p->courseId]) . '/subscribe">' . $v->e($row['course']) . '</a>' : $v->e($row['course']) ?>
          <span class="small muted">Online payment of <?= $v->e($p->amount()) ?> started <?= $v->e($v->datetime($p->createdAt)) ?></span></div>
        <a class="button button-small button-quiet" href="/payments/paystack/callback?reference=<?= $v->e(rawurlencode($p->reference)) ?>">Check now</a>
      </li>
    <?php endforeach; ?>
    <?php foreach ($applications as $a): ?>
      <li class="subs-row">
        <div><a href="/courses/<?= $v->e($a->courseSlug) ?>#apply"><?= $v->e($a->courseTitle) ?></a>
          <span class="small muted">Applied <?= $v->e($v->datetime($a->createdAt, 'j M Y')) ?></span></div>
        <?= $a->status === 'offered' ? $v->badge('Place offered: pay to start', 'done') : $v->badge('On the waiting list', 'draft') ?>
      </li>
    <?php endforeach; ?>
    <?php foreach ($rejectedClaims as $claim): ?>
      <li class="subs-row">
        <div><a href="/courses/<?= $v->e($claim->courseSlug) ?>/subscribe"><?= $v->e($claim->courseTitle) ?></a>
          <span class="small muted">Not confirmed: <?= $v->e((string) $claim->reason) ?></span></div>
        <?= $v->badge('Payment not found', 'danger') ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="subs-section" aria-labelledby="subs-courses-h">
  <h2 id="subs-courses-h">Your courses</h2>
  <?php if ($courses === []): ?>
    <?= $v->emptyState('No subscriptions yet', 'Find a course and subscribe. Free courses start at once; paid ones start after payment.', '/courses', 'Browse courses') ?>
  <?php else: ?>
  <ul class="subs-list">
    <?php foreach ($courses as $c): $pct = $c['total'] === 0 ? 0 : (int) round($c['done'] * 100 / $c['total']); ?>
      <li class="subs-row">
        <div><a href="/courses/<?= $v->e($c['slug']) ?>"><?= $v->e($c['title']) ?></a>
          <span class="small muted"><?= $c['status'] === 'completed' ? 'Completed' : $v->e($c['done'] . ' of ' . $c['total'] . ' lessons done') ?></span></div>
        <?php if ($c['status'] === 'completed'): ?><?= $v->badge('Completed', 'done') ?><?php else: ?>
          <progress class="meter meter-thin subs-meter" max="100" value="<?= $pct ?>" aria-label="<?= $v->e($c['title']) ?>: <?= $pct ?>% done"></progress>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="subs-section" aria-labelledby="subs-pay-h">
  <h2 id="subs-pay-h">Payments and receipts</h2>
  <?php if ($paid === []): ?>
    <p class="muted">No payments yet.</p>
  <?php else: ?>
  <ul class="subs-list">
    <?php foreach ($paid as $row): $p = $row['payment']; ?>
      <li class="subs-row">
        <div><?= $v->e($row['course']) ?>
          <span class="small muted"><?= $v->e($p->amount()) ?>, <?= $v->e($p->provider === 'manual' ? 'paid to the school' : $p->methodLabel()) ?>, <?= $v->e($v->datetime($p->paidAt ?? $p->createdAt, 'j M Y')) ?></span></div>
        <a class="button button-small button-quiet" href="/account/receipts/<?= $v->e($p->reference) ?>"><?= $v->icon('receipt') ?>Receipt</a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
