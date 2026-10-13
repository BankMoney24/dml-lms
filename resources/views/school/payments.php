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
 */
$schoolTab = 'payments';
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$tones = ['Paid' => 'done', 'Failed' => 'danger', 'Waiting' => 'draft', 'Not completed' => 'neutral'];
$connectErrors = array_intersect_key($errors, ['secret' => 1, 'currency' => 1]);
$manualErrors = array_intersect_key($errors, ['email' => 1, 'course_id' => 1, 'amount' => 1, 'note' => 1]);
?>
<header class="page-head">
  <h1>Payments</h1>
  <p class="lead">Take payment for paid courses through Paystack, or record money paid by bank transfer or cash.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>
<?= $v->errorSummary($errors) ?>

<div class="settings">
  <section class="settings-group" aria-labelledby="pay-online-h">
    <div class="settings-head"><h2 id="pay-online-h">Online payments</h2></div>
    <?php if ($account !== null): ?>
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
    <?php else: ?>
      <p>Connect your school's Paystack account so learners can pay by card, bank transfer or USSD. The money goes straight to your Paystack balance.</p>
      <ol class="steps-list small">
        <li>Sign in to <a href="https://dashboard.paystack.com" rel="noopener" target="_blank">dashboard.paystack.com</a> and open Settings, then API Keys &amp; Webhooks.</li>
        <li>Copy the secret key. Start with the test key to try a payment; switch to the live key when you are ready.</li>
        <li>Paste it below. After connecting, this page shows the webhook address to paste back into Paystack.</li>
      </ol>
      <?php include __DIR__ . '/_payments_connect.php'; ?>
    <?php endif; ?>
  </section>

  <section class="settings-group" aria-labelledby="pay-sum-h">
    <div class="settings-head"><h2 id="pay-sum-h">Last 30 days</h2></div>
    <?php if ($totals['paid_count'] > 0 && $totals['currency'] !== null): ?>
      <p class="pay-total"><?= $v->e(\App\Modules\Payments\Money::format($totals['paid_minor'], $totals['currency'])) ?></p>
      <p class="small muted">From <?= $v->e($v->plural($totals['paid_count'], 'payment')) ?>, including those recorded by hand. Test payments are not counted.</p>
    <?php else: ?>
      <p class="muted">No payments in the last 30 days.</p>
    <?php endif; ?>
    <dl class="facts">
      <dt>Paid courses</dt>
      <dd><?php if ($paidCourses === []): ?>None yet. Set a price on a course in <a href="/teach">Teach</a>, under Price.<?php else: ?><?= $v->e($v->plural(count($paidCourses), 'course')) ?><?php endif; ?></dd>
    </dl>
  </section>
</div>

<section class="pay-list" aria-labelledby="pay-list-h">
  <div class="settings-head">
    <h2 id="pay-list-h">Payment history</h2>
    <?php if ($rows !== [] || $filter !== ''): ?><a href="/school/payments.csv" download>Download as a spreadsheet</a><?php endif; ?>
  </div>
  <nav class="tabs tabs-quiet" aria-label="Show payments">
    <?php foreach (['' => 'All', 'paid' => 'Paid', 'waiting' => 'Waiting', 'failed' => 'Failed'] as $key => $label): ?>
      <a href="/school/payments<?= $key === '' ? '' : '?status=' . $key ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($rows === []): ?>
    <p class="muted"><?= $filter === '' ? 'No payments yet. When a learner pays for a course, or you record a payment below, it shows here.' : 'No payments in this list.' ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table pay-table">
      <thead><tr><th scope="col">Learner</th><th scope="col">Course</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Date</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $p = $row['payment']; $label = $p->statusLabel($now); ?>
        <tr>
          <td data-label="Learner"><?= $v->e($row['learner']) ?><span class="small muted"><?= $v->e($row['email']) ?></span></td>
          <td data-label="Course"><?= $v->e($row['course']) ?><span class="small muted"><?= $v->e($p->methodLabel()) ?></span></td>
          <td data-label="Amount" class="num"><?= $v->e($p->amount()) ?></td>
          <td data-label="Status"><?= $v->badge($label, $tones[$label] ?? 'neutral') ?><?php if ($p->note !== null && $p->status !== 'pending'): ?><span class="small muted"><?= $v->e($p->note) ?></span><?php endif; ?></td>
          <td data-label="Date"><?= $v->datetime($p->paidAt ?? $p->createdAt) ?><span class="small muted ref"><?= $v->e($p->reference) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

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
