<?php
/**
 * Paystack key form. The key field is never prefilled (PAY-005).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var ?array{currency: string} $account
 * @var array<string, string> $currencies
 * @var array<string, string> $errors
 * @var callable(string, string=): string $o
 */
?>
<form method="post" action="/school/payments/connect" class="form" novalidate autocomplete="off">
  <?= $v->csrfField() ?>
  <?= $v->field('secret', 'Paystack secret key', [
      'type' => 'password', 'autocomplete' => 'off', 'error' => $errors['secret'] ?? null,
      'hint' => 'Starts with sk_test_ or sk_live_. It is stored encrypted and never shown again, not even to you.',
  ]) ?>
  <?= $v->field('currency', 'Currency your courses are priced in', [
      'type' => 'select', 'options' => $currencies, 'value' => $o('currency', $account['currency'] ?? 'NGN'), 'error' => $errors['currency'] ?? null,
  ]) ?>
  <button type="submit" class="button button-small"><?= $account === null ? 'Connect Paystack' : 'Save the new key' ?></button>
</form>
