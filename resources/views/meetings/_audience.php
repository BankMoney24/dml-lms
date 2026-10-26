<?php
/**
 * Who to invite: all staff, whole classes, their parents, or named people.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<int, string> $groups
 * @var array<string, string> $errors
 * @var callable(string): string $o
 * @var callable(string, int): bool $picked
 */
?>
<div class="audience">
  <label class="choice choice-tight audience-all"><input type="checkbox" name="staff" value="1"<?= $o('staff') === '1' ? ' checked' : '' ?>>
    <span><strong>All staff</strong> <span class="small muted">teachers and administrators</span></span></label>

  <?php if ($groups !== []): ?>
  <fieldset class="audience-groups">
    <legend class="small-heading">Whole <?= $v->e($v->shared('tenant')?->groupWord(true) ?? 'classes') ?></legend>
    <ul class="plain-list audience-list">
      <?php foreach ($groups as $id => $name): ?>
      <li>
        <span class="audience-name"><?= $v->e($name) ?></span>
        <label class="mini-check"><input type="checkbox" name="groups[]" value="<?= (int) $id ?>"<?= $picked('groups', (int) $id) ? ' checked' : '' ?>><span>Learners and teachers</span></label>
        <label class="mini-check"><input type="checkbox" name="parents[]" value="<?= (int) $id ?>"<?= $picked('parents', (int) $id) ? ' checked' : '' ?>><span>Parents</span></label>
      </li>
      <?php endforeach; ?>
    </ul>
  </fieldset>
  <?php endif; ?>

  <?= $v->field('emails', 'People by email', [
      'type' => 'textarea', 'rows' => 2, 'value' => $o('emails'), 'optional' => true, 'error' => $errors['emails'] ?? null,
      'hint' => 'Anyone in the school: separate addresses with spaces, commas or new lines.',
  ]) ?>
</div>
