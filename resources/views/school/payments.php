<?php
/**
 * The school's Payments page.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var ?array{provider: string, mode: string, currency: string, connected_at: string, connected_by: string} $account
 * @var string $webhookUrl
 * @var string $callbackUrl
 * @var list<array{payment: \App\Modules\Payments\Payment, learner: string, email: string, course: string}> $rows
 * @var string $filter '' | paid | waiting | failed
 * @var array{paid_count: int, paid_minor: int, currency: ?string} $totals
 * @var list<\App\Modules\Courses\Course> $paidCourses
 * @var array<string, string> $currencies
 * @var int $now
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var list<\App\Modules\Payments\PaymentClaim> $claims learners who say they paid directly, oldest first
 * @var string $instructions
 * @var array<int, list<\App\Modules\Payments\Refunds\Refund>> $refundRows payment id => refunds
 * @var array<int, int> $refundable payment id => what can still be paid back
 * @var array{count: int, minor: int} $refundTotals
 * @var \App\Core\View\Pager $pager
 * @var string $q
 * @var ?int $courseFilter
 * @var array<int, string> $paymentCourses
 * @var array<string, int> $statusCounts
 * @var list<\App\Modules\Payments\Plans\PaymentPlan> $plans learners paying in parts (ADR 0037)
 * @var \DateTimeImmutable $planNow
 */
$plans ??= [];
$planNow ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
$owing = array_values(array_filter($plans, static fn ($p): bool => $p->nextPart() !== null));
$owedByCurrency = [];
$pausedCount = 0;
foreach ($owing as $p) {
    $owedByCurrency[$p->currency] = ($owedByCurrency[$p->currency] ?? 0) + $p->balance();
    $pausedCount += $p->isPaused($planNow) ? 1 : 0;
}
$refundRows ??= [];
$refundable ??= [];
$schoolTab = 'payments';
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$tones = ['Paid' => 'done', 'Failed' => 'danger', 'Waiting' => 'draft', 'Not completed' => 'neutral', 'Refunded' => 'neutral'];
$connectErrors = array_intersect_key($errors, ['secret' => 1, 'currency' => 1]);
$manualErrors = array_intersect_key($errors, ['email' => 1, 'course_id' => 1, 'amount' => 1, 'note' => 1]);
?>
<header class="page-head">
  <h1>Payments</h1>
  <p class="lead">Take payment for paid courses through Paystack, or record money paid by bank transfer or cash.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>
<?= $v->errorSummary($errors) ?>

<?php if ($account === null): ?>
<section class="settings-group pay-setup" aria-labelledby="pay-online-h">
  <div class="settings-head"><h2 id="pay-online-h">Take payments online</h2></div>

      <p>Connect your school's Paystack account so learners can pay by card, bank transfer or USSD. The money goes straight to your Paystack balance.</p>
      <ol class="steps-list small">
        <li>Sign in to <a href="https://dashboard.paystack.com" rel="noopener" target="_blank">dashboard.paystack.com</a> and open Settings, then API Keys &amp; Webhooks.</li>
        <li>Copy the secret key. Start with the test key to try a payment; switch to the live key when you are ready.</li>
        <li>Paste it below. After connecting, this page shows the webhook address to paste back into Paystack.</li>
      </ol>
      <?php include __DIR__ . '/_payments_connect.php'; ?>
</section>
<?php endif; ?>

<section class="pay-glance" aria-labelledby="pay-sum-h">
  <h2 id="pay-sum-h" class="visually-hidden">At a glance</h2>
  <div class="pay-figure">
    <p class="pay-figure-label">Last 30 days</p>
    <?php if ($totals['paid_count'] > 0 && $totals['currency'] !== null): ?>
      <p class="pay-total"><?= $v->e(\App\Modules\Payments\Money::format($totals['paid_minor'], $totals['currency'])) ?></p>
      <p class="small muted">From <?= $v->e($v->plural($totals['paid_count'], 'payment')) ?>, including those recorded by hand. Test payments are not counted.<?php if (($refundTotals['count'] ?? 0) > 0): ?> Paid back: <?= $v->e(\App\Modules\Payments\Money::format($refundTotals['minor'], $totals['currency'])) ?> (<?= $v->e($v->plural($refundTotals['count'], 'refund')) ?>); payments refunded in full are not in the total.<?php endif; ?></p>
    <?php else: ?>
      <p class="pay-total pay-total-none">Nothing yet</p>
      <p class="small muted">No payments in the last 30 days.</p>
    <?php endif; ?>
  </div>
  <dl class="pay-facts">
    <div><dt>To confirm</dt><dd><?php if ($claims === []): ?>None<?php else: ?><a href="#claims"><?= $v->e($v->plural(count($claims), 'payment')) ?></a><?php endif; ?></dd></div>
    <?php if ($owing !== []): ?>
    <div><dt>Paying in parts</dt><dd><a href="#plans"><?= $v->e($v->plural(count($owing), 'learner')) ?></a>, <?= $v->e(implode(' + ', array_map(static fn (string $c, int $m): string => \App\Modules\Payments\Money::format($m, $c), array_keys($owedByCurrency), $owedByCurrency))) ?> to come<?= $pausedCount > 0 ? '; ' . $pausedCount . ' paused' : '' ?></dd></div>
    <?php endif; ?>
    <div><dt>Paid courses</dt><dd><?php if ($paidCourses === []): ?>None. Set a price in <a href="/teach">Teach</a><?php else: ?><?= $v->e($v->plural(count($paidCourses), 'course')) ?><?php endif; ?></dd></div>
    <div><dt>Paystack</dt><dd><?php if ($account === null): ?>Not connected<?php else: ?><a href="#online"><?= $account['mode'] === 'live' ? 'Live' : 'Test mode' ?></a><?php endif; ?></dd></div>
  </dl>
</section>

<section class="pay-claims" id="claims" aria-labelledby="pay-claims-h">
  <div class="settings-head">
    <h2 id="pay-claims-h">Payments to confirm<?php if ($claims !== []): ?> <span class="count-pill"><?= count($claims) ?></span><?php endif; ?></h2>
  </div>
  <?php if ($claims === []): ?>
    <p class="muted small">Nothing to check. When a learner pays you by transfer or at the office and tells you on the course's subscribe page, it shows here.</p>
  <?php else: ?>
    <p class="small">Check each one against your bank statement. Confirming records the payment and enrols the learner; nothing opens until you do.</p>
    <ul class="claim-list">
    <?php foreach ($claims as $claim): ?>
      <li class="claim">
        <div class="claim-who">
          <p class="claim-name"><?= $v->e($claim->learnerName) ?> <span class="small muted"><?= $v->e($claim->learnerEmail) ?></span></p>
          <p class="claim-course"><?= $v->e($claim->courseTitle) ?></p>
        </div>
        <dl class="claim-facts">
          <dt>Amount</dt><dd><strong><?= $v->e($claim->amount()) ?></strong><?php if ($claim->partLabel() !== null): ?> <span class="muted">for <?= $v->e(strtolower((string) $claim->partLabel())) ?></span><?php endif; ?><?php if ($claim->isShort()): ?> <?= $v->badge($claim->partLabel() !== null ? 'Less than the part' : 'Less than the price', 'danger') ?><?php endif; ?></dd>
          <dt>How</dt><dd><?= $v->e($claim->methodLabel()) ?>, <?= $v->e($v->datetime($claim->paidOn . ' 00:00:00', 'j M Y')) ?></dd>
          <?php if ($claim->payerReference !== ''): ?><dt>Reference</dt><dd class="ref"><?= $v->e($claim->payerReference) ?></dd><?php endif; ?>
          <?php if ($claim->note !== ''): ?><dt>Note</dt><dd><?= $v->e($claim->note) ?></dd><?php endif; ?>
          <dt>Sent</dt><dd><?= $v->datetime($claim->createdAt) ?></dd>
        </dl>
        <div class="claim-actions">
          <form method="post" action="/school/payments/claims/<?= $claim->id ?>/confirm">
            <?= $v->csrfField() ?>
            <button type="submit" class="button button-small">Confirm and enrol</button>
          </form>
          <details class="claim-reject">
            <summary>Not found</summary>
            <form method="post" action="/school/payments/claims/<?= $claim->id ?>/reject" class="form">
              <?= $v->csrfField() ?>
              <?= $v->field('reason', 'Tell the learner why', ['id' => 'reason-' . $claim->id, 'maxlength' => 255, 'value' => 'No payment with that reference has arrived in our account.']) ?>
              <button type="submit" class="button button-small button-danger">Send and close</button>
            </form>
          </details>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>

</section>

<?php if ($plans !== []): ?>
<section class="pay-plans" id="plans" aria-labelledby="pay-plans-h">
  <h2 id="pay-plans-h">Payment plans</h2>
  <p class="small muted">Learners paying a course in monthly parts. A course pauses for the learner when a part is more than <?= \App\Modules\Payments\Plans\PaymentPlan::GRACE_DAYS ?> days late, and opens again when it is paid. Reminders go out by themselves.</p>
  <div class="table-wrap">
    <table class="table table-stack plan-table">
      <thead><tr><th scope="col">Learner</th><th scope="col">Course</th><th scope="col">Paid</th><th scope="col">Next part</th><th scope="col">Still to pay</th><th scope="col">Standing</th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): $next = $p->nextPart(); [$label, $tone] = \App\Modules\Payments\Plans\PaymentPlan::standingBadge($p->standing($planNow)); ?>
        <tr>
          <th scope="row"><a href="/school/payments/plans/<?= $p->id ?>"><?= $v->e($p->learnerName) ?></a><span class="small muted"><?= $v->e($p->learnerEmail) ?></span></th>
          <td data-label="Course"><?= $v->e($p->courseTitle) ?></td>
          <td data-label="Paid"><?= count($p->paidParts) ?> of <?= $p->parts ?>
            <span class="plan-progress" aria-hidden="true"><?php for ($i = 1; $i <= $p->parts; $i++): ?><span<?= $p->isPartPaid($i) ? ' class="is-paid"' : '' ?>></span><?php endfor; ?></span></td>
          <td data-label="Next part"><?php if ($next === null): ?><span class="muted">None</span><?php else: ?><?= $v->e($p->partLabel($next)) ?><span class="small muted">due <?= $v->e($v->datetime($p->dueAt($next)?->format('Y-m-d H:i:s') ?? '', 'j M Y')) ?></span><?php endif; ?></td>
          <td data-label="Still to pay" class="plan-owed"><?= $p->balance() > 0 ? $v->e(\App\Modules\Payments\Money::format($p->balance(), $p->currency)) : '<span class="muted">Nothing</span>' ?></td>
          <td data-label="Standing"><?= $v->badge($label, $tone) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="pay-list" aria-labelledby="pay-list-h">
  <div class="settings-head">
    <h2 id="pay-list-h">Payment history</h2>
    <p class="settings-head-links"><a href="/school/payments/codes">Discount codes</a><?php if ($rows !== []): ?> <a href="/school/payments.csv<?= ($csvq = http_build_query(array_filter(['status' => $filter, 'course' => $courseFilter === null ? '' : (string) $courseFilter, 'q' => $q]))) === '' ? '' : '?' . $v->e($csvq) ?>" download>Download <?= $filter !== '' || $q !== '' || $courseFilter !== null ? 'these' : 'all' ?> as a spreadsheet</a><?php endif; ?></p>
  </div>
<?php if (array_sum($statusCounts) > 0 || $q !== '' || $courseFilter !== null): ?>
  <form method="get" action="/school/payments" class="list-tools" role="search" aria-label="Find payments" data-autosubmit>
    <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= $v->e($filter) ?>"><?php endif; ?>
    <div class="list-tool list-tool-find">
      <label for="pay-q">Find</label>
      <input id="pay-q" name="q" type="search" value="<?= $v->e($q) ?>" maxlength="80" placeholder="Name, email or reference" autocomplete="off">
    </div>
    <?php if (count($paymentCourses) > 1): ?>
    <div class="list-tool">
      <label for="pay-course">Course</label>
      <select id="pay-course" name="course">
        <option value="">Every course</option>
        <?php foreach ($paymentCourses as $cid => $ctitle): ?><option value="<?= $cid ?>"<?= $courseFilter === $cid ? ' selected' : '' ?>><?= $v->e($ctitle) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="list-tool-actions">
      <button type="submit" class="button button-small button-quiet">Show</button>
      <?php if ($q !== '' || $courseFilter !== null): ?><a class="small" href="/school/payments<?= $filter === '' ? '' : '?status=' . $filter ?>">Clear</a><?php endif; ?>
    </div>
  </form>
  <?php $keep = array_filter(['q' => $q, 'course' => $courseFilter === null ? '' : (string) $courseFilter]); $allCount = array_sum($statusCounts); ?>
  <nav class="tabs tabs-quiet" aria-label="Show payments">
    <?php foreach (['' => ['All', null], 'paid' => ['Paid', 'paid'], 'waiting' => ['Waiting', 'pending'], 'failed' => ['Failed', 'failed'], 'refunded' => ['Refunded', 'refunded']] as $key => [$label, $st]): $n = $st === null ? $allCount : ($statusCounts[$st] ?? 0); ?>
      <a href="/school/payments<?= ($qs = http_build_query($keep + ($key === '' ? [] : ['status' => $key]))) === '' ? '' : '?' . $v->e($qs) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $label ?> <span class="tab-count">(<?= $n ?>)</span></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>
  <?php if ($rows === []): ?>
    <p class="muted"><?= $filter === '' && $q === '' && $courseFilter === null ? 'No payments yet. When a learner pays for a course, or you record a payment below, it shows here.' : 'No payments match. Try fewer words, or another status.' ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table pay-table" data-sortable>
      <thead><tr><th scope="col">Learner</th><th scope="col">Course</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col"><span class="visually-hidden">More</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $p = $row['payment']; $label = $p->statusLabel($now); ?>
        <?php $refundsHere = $refundRows[$p->id] ?? []; $canRefund = ($refundable[$p->id] ?? 0) > 0; ?>
        <tr id="payment-<?= $p->id ?>">
          <td data-label="Learner"><?= $v->e($row['learner']) ?><span class="small muted"><?= $v->e($row['email']) ?></span></td>
          <td data-label="Course"><?= $v->e($row['course']) ?><span class="small muted"><?= $v->e($p->methodLabel()) ?><?= $p->planPart !== null ? ', part ' . (int) $p->planPart : '' ?></span></td>
          <td data-label="Amount" class="num" data-sort="<?= $p->amountMinor ?>"><?= $v->e($p->amount()) ?><?php if ($p->discountMinor() > 0): ?><span class="small muted"><?= $v->e(\App\Modules\Payments\Money::format($p->discountMinor(), $p->currency)) ?> off with a code</span><?php endif; ?></td>
          <td data-label="Status"><?= $v->badge($label, $tones[$label] ?? 'neutral') ?><?php if ($p->note !== null && $p->status !== 'pending'): ?><span class="small muted"<?= mb_strlen($p->note) > 70 ? ' title="' . $v->e($p->note) . '"' : '' ?>><?= $v->e(mb_strimwidth($p->note, 0, 70, '…')) ?></span><?php endif; ?>
            <?php foreach ($refundsHere as $rf): ?><span class="small refund-line<?= $rf->status === 'failed' ? ' is-failed' : '' ?>"><?= $v->e($rf->statusLabel()) ?>: <?= $v->e($rf->amount()) ?>, <?= $v->e($rf->reason) ?></span><?php endforeach; ?></td>
          <td data-label="Date" data-sort="<?= $v->e((string) ($p->paidAt ?? $p->createdAt)) ?>"><?= $v->datetime($p->paidAt ?? $p->createdAt) ?><span class="small muted ref"><?= $v->e($p->reference) ?></span></td>
          <td class="table-stack-action">
            <a class="small pay-detail-link" href="/school/payments/<?= $p->id ?>">Details<?= $canRefund ? '<span class="visually-hidden"> or refund</span>' : '' ?><span class="visually-hidden">: <?= $v->e($row['learner']) ?>, <?= $v->e($row['course']) ?></span></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted pay-count">Showing <?= $pager->from() ?> to <?= $pager->to() ?> of <?= $v->e($v->plural($pager->total, 'payment')) ?>.</p>
  <?php $pagerLabel = 'Payment pages'; include __DIR__ . '/../partials/pager.php'; ?>
  <?php endif; ?>
</section>

<h2 class="pay-more-h">Settings and tools</h2>

<details class="panel"<?= $manualErrors !== [] || $o('manual_open') === '1' ? ' open' : '' ?>>
  <summary>Record a payment made by bank transfer or cash</summary>
  <?php if ($paidCourses === []): ?>
    <p class="muted">Set a price on a course first.</p>
  <?php else: ?>
  <p class="small">The learner is enrolled straight away. Check the money has arrived before you record it.</p>
  <form method="post" action="/school/payments/manual" class="form" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('email', 'Learner\'s email', ['type' => 'email', 'value' => $o('email'), 'error' => $errors['email'] ?? null, 'autocomplete' => 'off']) ?>
    <?php $courseOptions = ['' => 'Choose a course'];
    foreach ($paidCourses as $c) {
        $courseOptions[(string) $c->id] = $c->title . ' (' . $c->priceLabel() . ')';
    } ?>
    <?= $v->field('course_id', 'Course', ['type' => 'select', 'options' => $courseOptions, 'value' => $o('course_id'), 'error' => $errors['course_id'] ?? null]) ?>
    <?= $v->field('amount', 'Amount received', ['value' => $o('amount'), 'inputmode' => 'decimal', 'error' => $errors['amount'] ?? null, 'hint' => 'In the course\'s currency, for example 15000.']) ?>
    <?= $v->field('note', 'How it was paid', ['value' => $o('note'), 'maxlength' => 200, 'error' => $errors['note'] ?? null, 'hint' => 'For example: Bank transfer, teller 004512.']) ?>
    <button type="submit" class="button button-small">Record payment and enrol</button>
  </form>
  <?php endif; ?>
</details>

<details class="panel"<?= isset($errors['instructions']) ? ' open' : '' ?>>
    <summary><?= $instructions === '' ? 'Write how learners can pay you directly' : 'How learners pay you directly' ?></summary>
    <p class="small">Learners see this on the subscribe page of every paid course<?= $account !== null ? ', under the Paystack button' : '' ?>. Write your bank, account name and account number, and what to put as the narration. Never put a Paystack key here.</p>
    <form method="post" action="/school/payments/instructions" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('instructions', 'Payment details', ['type' => 'textarea', 'rows' => 5, 'maxlength' => 2000, 'value' => $instructions, 'error' => $errors['instructions'] ?? null, 'hint' => 'For example: Bank, account name, account number. Use your full name and the course name as the narration.']) ?>
      <button type="submit" class="button button-small">Save payment details</button>
    </form>
</details>

<?php if ($account !== null): ?>
<details class="panel" id="online"<?= $connectErrors !== [] ? ' open' : '' ?>>
  <summary>Online payments: Paystack, <?= $account['mode'] === 'live' ? 'live' : 'test mode' ?></summary>

      <dl class="facts">
        <dt>Paystack</dt>
        <dd><?= $account['mode'] === 'live' ? $v->badge('Live', 'done') . ' Learners are charged real money' : $v->badge('Test mode', 'draft') . ' No real money moves' ?></dd>
        <dt>Currency</dt><dd><?= $v->e($currencies[$account['currency']] ?? $account['currency']) ?></dd>
        <dt>Connected</dt><dd><?= $v->datetime($account['connected_at']) ?> by <?= $v->e($account['connected_by']) ?></dd>
      </dl>
      <p class="small pay-webhook-intro">Paystack must tell this site about each payment. In Paystack, open Settings, then API Keys &amp; Webhooks, and paste this as the <?= $account['mode'] === 'live' ? 'Live' : 'Test' ?> Webhook URL:</p>
      <div class="code-block code-wrap"><pre><code><?= $v->e($webhookUrl) ?></code></pre></div>
      <?php if (str_starts_with($webhookUrl, 'http://')): ?>
        <p class="notice small">This site is not on HTTPS yet. Paystack only sends live payment notices to an <strong>https://</strong> address, so set up HTTPS for your domain before taking real payments. Until then, learners' payments are still confirmed when they come back from Paystack.</p>
      <?php endif; ?>
      <details class="disclosure"<?= $connectErrors !== [] ? ' open' : '' ?>>
        <summary>Change the key or disconnect</summary>
        <?php include __DIR__ . '/_payments_connect.php'; ?>
        <form method="post" action="/school/payments/disconnect" class="form-inline-end">
          <?= $v->csrfField() ?>
          <button type="submit" class="button button-small button-danger">Disconnect Paystack</button>
        </form>
      </details>
</details>
<?php endif; ?>
