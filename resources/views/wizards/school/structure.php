<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
$calendar = (string) ($values['calendar'] ?? '');
$hints = [
    'terms' => 'Usually three terms a year.',
    'semesters' => 'Two semesters a year.',
    'flexible' => 'Courses start whenever learners join.',
];
?>
<fieldset class="field pick-list pick-row"<?= isset($errors['calendar']) ? ' aria-describedby="calendar-error"' : '' ?>>
  <legend>How is your year organised?</legend>
  <?php if (isset($errors['calendar'])): ?><p class="field-error" id="calendar-error"><?= $v->e($errors['calendar']) ?></p><?php endif; ?>
  <?php foreach (\App\Modules\Tenancy\Tenant::CALENDARS as $key => $label): ?>
  <label class="choice pick">
    <input type="radio" name="calendar" value="<?= $v->e($key) ?>"<?= $calendar === $key ? ' checked' : '' ?><?= $key === 'terms' ? ' id="calendar"' : '' ?>>
    <span><strong><?= $v->e($label) ?></strong><span class="pick-hint"><?= $v->e($hints[$key]) ?></span></span>
  </label>
  <?php endforeach; ?>
</fieldset>

<?= $v->field('levels', 'Classes, years or levels', [
    'type' => 'textarea',
    'rows' => 7,
    'value' => (string) ($values['levels'] ?? ''),
    'error' => $errors['levels'] ?? null,
    'optional' => true,
    'hint' => 'One per line, in order. We filled in a suggestion from the type you chose; change it to match your school.',
    'class' => 'field-narrow',
]) ?>
