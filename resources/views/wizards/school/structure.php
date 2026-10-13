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

<?php $term = (string) ($values['group_term'] ?? 'class'); ?>
<fieldset class="field pick-list pick-row"<?= isset($errors['group_term']) ? ' aria-describedby="group_term-error"' : '' ?>>
  <legend>What do you call a group of learners who study together?</legend>
  <p class="hint">Used across the site, for example on the page where you set up groups and see their progress.</p>
  <?php if (isset($errors['group_term'])): ?><p class="field-error" id="group_term-error"><?= $v->e($errors['group_term']) ?></p><?php endif; ?>
  <?php $first = true; foreach (\App\Modules\Tenancy\Tenant::GROUP_TERMS as $key => [$one, $many]): ?>
  <label class="choice pick">
    <input type="radio" name="group_term" value="<?= $v->e($key) ?>"<?= $term === $key ? ' checked' : '' ?><?= $first ? ' id="group_term"' : '' ?>>
    <span><strong><?= $v->e(ucfirst($one)) ?></strong><span class="pick-hint"><?= $v->e(['class' => 'SS 2 Gold, Primary 4B', 'cohort' => 'January 2027 intake', 'group' => 'Group A, Tutorial 3', 'stream' => 'Science stream', 'set' => 'Top maths set'][$key]) ?></span></span>
  </label>
  <?php $first = false; endforeach; ?>
</fieldset>
