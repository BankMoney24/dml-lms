<?php
/**
 * "I have paid the school": the learner's note (ADR 0026), on the subscribe page
 * and, for a later part of a payment plan, in the plan panel (ADR 0037).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var string $here
 * @var array<string, string> $methods
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var bool $payOnline
 * @var ?array<string, mixed> $quote
 * @var ?array<string, string> $forChoice what the payment is for, when the learner may pay in parts
 * @var ?string $claimFoot replaces the closing line
 */
$forChoice ??= null;
$claimFoot ??= null;
?>
        <?= $v->errorSummary($errors) ?>
        <form method="post" action="<?= $v->e($here) ?>/transfer" class="form sub-claim" novalidate>
          <?= $v->csrfField() ?>
          <h3 class="sub-claim-title">I have paid</h3>
          <?php if ($forChoice !== null): ?>
            <?= $v->field('for', 'What it pays for', ['type' => 'select', 'options' => $forChoice, 'value' => $old['for'] ?? 'full', 'error' => $errors['for'] ?? null]) ?>
          <?php endif; ?>
          <div class="field-row">
            <?= $v->field('method', 'How you paid', ['type' => 'select', 'options' => $methods, 'value' => $old['method'], 'error' => $errors['method'] ?? null]) ?>
            <?= $v->field('amount', 'Amount', ['value' => $old['amount'], 'inputmode' => 'decimal', 'error' => $errors['amount'] ?? null, 'hint' => 'In ' . $v->e((string) $course->currency) . ', as on your receipt.']) ?>
          </div>
          <div class="field-row">
            <?= $v->field('paid_on', 'Date you paid', ['type' => 'date', 'value' => $old['paid_on'], 'error' => $errors['paid_on'] ?? null]) ?>
            <?= $v->field('reference', 'Reference', ['value' => $old['reference'], 'maxlength' => 80, 'error' => $errors['reference'] ?? null, 'hint' => 'Session ID, transaction or teller number.']) ?>
          </div>
          <?= $v->field('note', 'Anything else the school should know (optional)', ['type' => 'textarea', 'rows' => 2, 'value' => $old['note'], 'maxlength' => 500, 'error' => $errors['note'] ?? null, 'hint' => 'For example, if someone else paid for you, their name.']) ?>
          <button type="submit" class="button<?= $payOnline ? ' button-quiet' : '' ?>">Tell the school I have paid</button>
          <p class="small muted"><?= $claimFoot !== null ? $v->e($claimFoot) : 'The course opens when the school confirms the money arrived. Nothing is charged here.' . ($quote !== null ? ' A discount code is applied to online payment; to use it when paying the school directly, show it to the school.' : '') ?></p>
        </form>
