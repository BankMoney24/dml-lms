<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
$kind = (string) ($values['kind'] ?? '');
$error = $errors['kind'] ?? null;
?>
<fieldset class="field pick-list"<?= $error !== null ? ' aria-describedby="kind-error"' : '' ?>>
  <legend class="visually-hidden">Type of institution</legend>
  <?php if ($error !== null): ?><p class="field-error" id="kind-error"><?= $v->e($error) ?></p><?php endif; ?>
  <?php foreach (\App\Modules\Tenancy\Tenant::KINDS as $key => $label): ?>
  <label class="choice pick">
    <input type="radio" name="kind" value="<?= $v->e($key) ?>"<?= $kind === $key ? ' checked' : '' ?><?= $key === array_key_first(\App\Modules\Tenancy\Tenant::KINDS) ? ' id="kind"' : '' ?>>
    <span><strong><?= $v->e($label) ?></strong><span class="pick-hint"><?= $v->e(\App\Modules\Tenancy\Setup\TypeStep::HINTS[$key]) ?></span></span>
  </label>
  <?php endforeach; ?>
</fieldset>
