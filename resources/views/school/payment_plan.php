<?php
/**
 * One learner's payment plan (ADR 0037): the parts, what is paid, and the
 * school's two actions: record the next part, or stop asking for the rest.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Payments\Plans\PaymentPlan $plan
 * @var string $standing
 * @var list<\App\Modules\Payments\Payment> $payments
 * @var int $now
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use App\Modules\Payments\Money;
use App\Modules\Payments\Plans\PaymentPlan;

$schoolTab = 'payments';
[$label, $tone] = PaymentPlan::standingBadge($standing);
$next = $plan->nextPart();
$byPart = [];
foreach ($payments as $p) {
    if ($p->isPaid() && $p->planPart !== null) {
        $byPart[$p->planPart] = $p;
    }
}
$date = static fn (?\DateTimeImmutable $d): string => $d === null ? '' : $v->datetime($d->format('Y-m-d H:i:s'), 'j M Y');
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Payments' => '/school/payments', 'Payment plans' => '/school/payments#plans', $plan->learnerName => null]) ?>
  <h1><?= $v->e($plan->learnerName) ?>: <?= $v->e($plan->courseTitle) ?></h1>
  <p class="page-head-meta"><?= $v->badge($label, $tone) ?> <span><?= $v->e(Money::format($plan->totalMinor, $plan->currency)) ?> in <?= $plan->parts ?> monthly parts<?= $plan->startedAt !== null ? ', from ' . $v->e($v->datetime($plan->startedAt, 'j M Y')) : '' ?></span></p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>
<?= $v->errorSummary($errors) ?>

<div class="plan-admin-grid">
  <section aria-labelledby="parts-h">
    <h2 id="parts-h">The parts</h2>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th scope="col">Part</th><th scope="col">Amount</th><th scope="col">Due</th><th scope="col">Paid</th></tr></thead>
        <tbody>
        <?php for ($i = 1; $i <= $plan->parts; $i++): $paid = $byPart[$i] ?? null; ?>
          <tr<?= $i === $next && ($standing === 'paused' || $standing === 'late') ? ' class="is-late"' : '' ?>>
            <th scope="row">Part <?= $i ?></th>
            <td data-label="Amount" class="plan-owed"><?= $v->e($plan->partLabel($i)) ?></td>
            <td data-label="Due"><?= $i === 1 ? 'To start' : $v->e($date($plan->dueAt($i))) ?></td>
            <td data-label="Paid"><?php if ($paid !== null): ?>
              <a href="/school/payments/<?= $paid->id ?>"><?= $v->datetime((string) $paid->paidAt, 'j M Y') ?></a><span class="small muted"><?= $v->e($paid->methodLabel()) ?></span>
            <?php elseif ($plan->isPartPaid($i)): ?>Paid<?php elseif ($standing === 'written_off'): ?><span class="muted">Not asked for</span><?php elseif ($i === $next): ?><?= $v->badge($standing === 'paused' ? 'Overdue' : ($standing === 'late' ? 'Late' : 'Next'), $standing === 'paused' ? 'danger' : ($standing === 'late' ? 'draft' : 'neutral')) ?><?php else: ?><span class="muted">Not yet</span><?php endif; ?></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <dl class="facts">
      <dt>Paid so far</dt><dd><?= $v->e(Money::format($plan->paidMinor(), $plan->currency)) ?></dd>
      <dt>Still to pay</dt><dd><?= $v->e(Money::format($plan->balance(), $plan->currency)) ?></dd>
      <?php if ($next !== null && $next > 1): ?><dt>Course pauses</dt><dd><?= $v->e($date($plan->pausesAt())) ?>, if part <?= $next ?> is still unpaid</dd><?php endif; ?>
      <?php if ($plan->endedNote !== null): ?><dt>Closed because</dt><dd><?= $v->e($plan->endedNote) ?></dd><?php endif; ?>
      <dt>Email</dt><dd><?= $v->e($plan->learnerEmail) ?></dd>
    </dl>
    <?php $other = array_filter($payments, static fn ($p): bool => !$p->isPaid()); if ($other !== []): ?>
    <h3>Attempts that did not go through</h3>
    <ul class="small">
      <?php foreach ($other as $p): ?><li><a href="/school/payments/<?= $p->id ?>"><?= $v->e($p->reference) ?></a>: <?= $v->e($p->amount()) ?>, <?= $v->e(strtolower($p->statusLabel($now))) ?>, <?= $v->datetime($p->createdAt, 'j M Y') ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <?php if ($next !== null): ?>
  <aside class="panel" aria-labelledby="record-h">
    <h2 id="record-h">Record part <?= $next ?></h2>
    <p class="small">When <?= $v->e($plan->learnerName) ?> pays <?= $v->e($plan->partLabel($next)) ?> at the office or by transfer. <?= $standing === 'paused' ? 'The course opens for them again at once.' : '' ?></p>
    <form method="post" action="/school/payments/plans/<?= $plan->id ?>/record" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('note', 'How it was paid', ['value' => $old['note'], 'maxlength' => 200, 'error' => $errors['note'] ?? null, 'hint' => 'For example “Bank transfer, teller 004512”.']) ?>
      <button type="submit" class="button button-small">Record <?= $v->e($plan->partLabel($next)) ?></button>
    </form>
    <details class="plan-close"<?= ($old['writeoff_open'] ?? '') === '1' ? ' open' : '' ?>>
      <summary>Stop asking for the rest</summary>
      <p class="small">For a scholarship or an agreed discount. The course stays open, what was paid stays paid, and no more reminders go out. This cannot be undone.</p>
      <form method="post" action="/school/payments/plans/<?= $plan->id ?>/write-off" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('reason', 'Why', ['value' => $old['reason'], 'maxlength' => 200, 'error' => $errors['reason'] ?? null, 'hint' => 'Only staff see this.']) ?>
        <button type="submit" class="button button-small button-danger">Close the plan: <?= $v->e(Money::format($plan->balance(), $plan->currency)) ?> not collected</button>
      </form>
    </details>
  </aside>
  <?php endif; ?>
</div>
