<?php
/**
 * The learner's payment plan on the subscribe page (ADR 0037): every part, what
 * is paid and when the rest is due, and how to pay the next part.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var \App\Modules\Payments\Plans\PaymentPlan $plan
 * @var ?int $planNext
 * @var ?string $planStanding
 * @var list<\App\Modules\Payments\Payment> $planPayments
 * @var ?\App\Modules\Payments\PaymentClaim $claim
 * @var bool $onlineOn
 * @var string $instructions
 * @var string $here
 */
$paidOn = [];
foreach ($planPayments as $p) {
    if ($p->isPaid() && $p->planPart !== null && $p->paidAt !== null) {
        $paidOn[$p->planPart] = $p->paidAt;
    }
}
$dueText = static fn (int $part): string => $v->datetime($plan->dueAt($part)?->format('Y-m-d H:i:s') ?? '', 'j M Y');
$next = $planNext;
$paused = $planStanding === 'paused';
$late = $planStanding === 'late';
$claimForPart = $claim !== null && $claim->isPending() && $claim->planId === $plan->id && $claim->planPart === $next;
$title = match (true) {
    $paused => 'The course is paused',
    $planStanding === 'completed' => 'Paid in full',
    $planStanding === 'written_off' => 'Your payment plan is closed',
    default => 'Your payment plan',
};
?>
<section class="sub-panel plan-panel<?= $paused ? ' is-paused' : '' ?>" id="plan" aria-labelledby="plan-h">
  <h2 id="plan-h"><?= $v->e($title) ?></h2>
  <p class="plan-lead"><?php
    if ($next !== null && $paused) {
        echo 'Part ' . $next . ' (' . $v->e($plan->partLabel($next)) . ') was due on ' . $v->e($dueText($next)) . '. The lessons open again as soon as it is paid. Your progress is kept.';
    } elseif ($next !== null && $late) {
        echo 'Part ' . $next . ' (' . $v->e($plan->partLabel($next)) . ') was due on ' . $v->e($dueText($next)) . '. Pay it by '
            . $v->e($v->datetime($plan->pausesAt()?->format('Y-m-d H:i:s') ?? '', 'j M Y')) . ' to keep the course open.';
    } elseif ($next !== null) {
        echo 'You are paying ' . $v->e($course->title) . ' in ' . $plan->parts . ' parts. Part ' . $next . ' (' . $v->e($plan->partLabel($next)) . ') is due on ' . $v->e($dueText($next)) . '.';
    } elseif ($planStanding === 'written_off') {
        echo 'The school has closed this plan. There is nothing more to pay, and the course stays open.';
    } else {
        echo 'Every part is paid. Thank you.';
    }
?></p>

  <ol class="plan-parts" aria-label="The parts of your plan">
    <?php for ($i = 1; $i <= $plan->parts; $i++):
        $isPaid = isset($paidOn[$i]) || $plan->isPartPaid($i);
        $state = $isPaid ? 'paid' : ($i === $next ? ($paused || $late ? 'late' : 'next') : 'later');
        ?>
      <li class="plan-part is-<?= $state ?>">
        <span class="plan-part-mark" aria-hidden="true"><?= $isPaid ? $v->icon('check') : $i ?></span>
        <span class="plan-part-name">Part <?= $i ?></span>
        <span class="plan-part-amount"><?= $v->e($plan->partLabel($i)) ?></span>
        <span class="plan-part-when"><?php
          if ($isPaid) {
              echo 'Paid' . (isset($paidOn[$i]) ? ' ' . $v->e($v->datetime($paidOn[$i], 'j M Y')) : '');
          } elseif ($planStanding === 'written_off') {
              echo 'Not needed';
          } else {
              echo ($state === 'late' ? 'Was due ' : 'Due ') . $v->e($dueText($i));
          }
        ?></span>
      </li>
    <?php endfor; ?>
  </ol>
  <p class="small muted plan-total">Paid <?= $v->e(\App\Modules\Payments\Money::format($plan->paidMinor(), $plan->currency)) ?> of <?= $v->e(\App\Modules\Payments\Money::format($plan->totalMinor, $plan->currency)) ?><?= $plan->balance() > 0 ? '; ' . $v->e(\App\Modules\Payments\Money::format($plan->balance(), $plan->currency)) . ' to go' : '' ?>.</p>

  <?php if ($next !== null): ?>
    <?php if ($claimForPart): ?>
      <p class="notice">The school is checking the <?= $v->e($claim->amount()) ?> you told them you paid for part <?= $next ?>. You get a notification when they confirm it.</p>
    <?php else: ?>
      <?php if ($onlineOn): ?>
      <form method="post" action="<?= $v->e($here) ?>/part" class="sub-actions">
        <?= $v->csrfField() ?>
        <button type="submit" class="button">Pay part <?= $next ?>: <?= $v->e($plan->partLabel($next)) ?></button>
      </form>
      <?php endif; ?>
      <details class="plan-direct"<?= $errors !== [] || !$onlineOn ? ' open' : '' ?>>
        <summary><?= $onlineOn ? 'Paid the school directly? Tell them' : 'Pay the school, then tell them' ?></summary>
        <?php if ($instructions !== ''): ?><div class="sub-bank"><?= $v->paragraphs($instructions) ?></div><?php endif; ?>
        <?php
        $forChoice = null;
        $claimAmounts = ['' => $plan->partAmount($next)];
        $claimFoot = 'The school confirms it once the money arrives. Nothing is charged here.';
        $payOnline = $onlineOn;
        include __DIR__ . '/_claim_form.php';
        ?>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</section>
