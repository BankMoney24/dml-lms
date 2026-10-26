<?php
/**
 * School → Payments → Discount codes (ADR 0032).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Payments\Coupons\Coupon> $coupons
 * @var list<\App\Modules\Courses\Course> $paidCourses
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$schoolTab = 'payments';
$today = (new \DateTimeImmutable('now', new \DateTimeZone((string) ($v->shared('tenant')?->timezone ?: 'UTC'))))->format('Y-m-d');
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Payments' => '/school/payments', 'Discount codes' => null]) ?>
  <h1>Discount codes</h1>
  <p class="lead">Codes learners type before they pay, for a scholarship, an early-bird offer or staff children.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

    <details class="panel coupon-new"<?= $errors !== [] || $coupons === [] ? ' open' : '' ?>>
      <summary id="new-code-h">Make a code</summary>
      <?php if ($paidCourses === []): ?>
        <p class="muted">Set a price on a course first; codes take money off course prices.</p>
      <?php else: ?>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/school/payments/codes" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('code', 'Code', ['value' => $old['code'], 'error' => $errors['code'] ?? null, 'maxlength' => 32, 'autocomplete' => 'off',
            'hint' => 'Letters, numbers and dashes. Learners can type it in small or capital letters.']) ?>
        <fieldset class="field<?= isset($errors['kind']) ? ' has-error' : '' ?>">
          <legend>Discount</legend>
          <div class="coupon-kind">
            <label class="choice choice-tight"><input type="radio" name="kind" value="percent"<?= $old['kind'] !== 'amount' ? ' checked' : '' ?>><span>Percentage</span></label>
            <label class="choice choice-tight"><input type="radio" name="kind" value="amount"<?= $old['kind'] === 'amount' ? ' checked' : '' ?>><span>Amount</span></label>
          </div>
          <?php if (isset($errors['kind'])): ?><p class="field-error" id="kind-error"><?= $v->e($errors['kind']) ?></p><?php endif; ?>
        </fieldset>
        <?= $v->field('value', 'How much off', ['value' => $old['value'], 'error' => $errors['value'] ?? null, 'inputmode' => 'decimal', 'maxlength' => 12,
            'hint' => 'For a percentage, 1 to 100 (100 means free). For an amount, in the course’s currency, for example 5000.']) ?>
        <?php $courseOptions = ['' => 'Every paid course']; foreach ($paidCourses as $pc) { $courseOptions[(string) $pc->id] = $pc->title . ' (' . $pc->priceLabel() . ')'; } ?>
        <?= $v->field('course_id', 'For', ['type' => 'select', 'options' => $courseOptions, 'value' => $old['course_id'], 'error' => $errors['course_id'] ?? null]) ?>
        <?= $v->field('max_uses', 'How many times it can be used', ['value' => $old['max_uses'], 'error' => $errors['max_uses'] ?? null, 'inputmode' => 'numeric', 'optional' => true, 'maxlength' => 6]) ?>
        <?= $v->field('expires_on', 'Last day it works', ['type' => 'date', 'value' => $old['expires_on'], 'error' => $errors['expires_on'] ?? null, 'optional' => true]) ?>
        <?= $v->field('note', 'Note for staff', ['value' => $old['note'], 'error' => $errors['note'] ?? null, 'optional' => true, 'maxlength' => 160,
            'hint' => 'Who it is for. Learners do not see this.']) ?>
        <button type="submit" class="button button-small">Make the code</button>
      </form>
      <?php endif; ?>
    </details>
  <section aria-labelledby="codes-h">
    <h2 id="codes-h"><?= $v->e($v->plural(count($coupons), 'code')) ?></h2>
    <?php if ($coupons === []): ?>
      <p class="muted">No codes yet. Make one with the form; it works as soon as it is saved.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table coupon-table table-stack" data-sortable>
        <thead><tr><th scope="col">Code</th><th scope="col">Discount</th><th scope="col">Course</th><th scope="col" class="num">Used</th><th scope="col">Last day</th><th scope="col"><span class="visually-hidden">Status and action</span></th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c):
            $state = !$c->isActive() ? ['Off', 'neutral'] : ($c->hasExpired($today) ? ['Expired', 'neutral'] : ($c->usedUp() ? ['Used up', 'neutral'] : ['Works', 'done'])); ?>
          <tr>
            <th scope="row"><span class="coupon-code"><?= $v->e($c->code) ?></span><?php if ($c->note !== ''): ?><span class="small muted"><?= $v->e($c->note) ?></span><?php endif; ?></th>
            <td data-label="Discount"><?= $v->e($c->label()) ?></td>
            <td data-label="Course"><?= $c->courseId === null ? 'Every paid course' : $v->e((string) $c->courseTitle) ?></td>
            <td class="num" data-label="Used" data-sort="<?= $c->uses ?>"><?= $c->uses ?><?= $c->maxUses !== null ? ' of ' . $c->maxUses : '' ?></td>
            <td data-label="Last day" data-sort="<?= $v->e((string) $c->expiresOn) ?>"><?= $c->expiresOn === null ? '<span class="muted">No end</span>' : $v->e(date('j M Y', (int) strtotime($c->expiresOn . ' 00:00:00 UTC'))) ?></td>
            <td class="table-stack-action">
              <?= $v->badge($state[0], $state[1]) ?>
              <form method="post" action="/school/payments/codes/<?= $c->id ?>/status" class="inline">
                <?= $v->csrfField() ?>
                <input type="hidden" name="active" value="<?= $c->isActive() ? '0' : '1' ?>">
                <button type="submit" class="linklike small"><?= $c->isActive() ? 'Switch off' : 'Switch on' ?><span class="visually-hidden"> <?= $v->e($c->code) ?></span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted">A code is used when a payment with it is confirmed. A refund in full gives the use back. Codes work for online payment and, when they cover the whole price, without paying at all.</p>
    <?php endif; ?>
  </section>

