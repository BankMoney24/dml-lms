<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var array{packages: list<array{name: string, title: string, summary: string, level: string, hours: ?int, lessons: int}>, installed: list<string>} $extra
 */
$chosen = is_array($values['packages'] ?? null) ? $values['packages'] : [];
?>
<fieldset class="field pick-list">
  <legend>What your school will use</legend>
  <label class="choice pick">
    <input type="checkbox" name="certificates" value="1"<?= ($values['certificates'] ?? true) ? ' checked' : '' ?>>
    <span><strong>Certificates of completion</strong><span class="pick-hint">Issued automatically when a learner finishes a course, with a code anyone can check on your site. Turning this off later keeps certificates already issued.</span></span>
  </label>
  <label class="choice pick">
    <input type="checkbox" name="public_catalogue" value="1"<?= ($values['public_catalogue'] ?? true) ? ' checked' : '' ?>>
    <span><strong>Show your courses to visitors</strong><span class="pick-hint">Anyone can browse the course list and course pages before signing in. Turn off to show courses only to your members.</span></span>
  </label>
</fieldset>

<fieldset class="field pick-list"<?= isset($errors['packages']) ? ' aria-describedby="packages-error"' : '' ?>>
  <legend>Starter courses <span class="optional">(optional)</span></legend>
  <p class="hint">Complete courses written by DML, ready to use or adapt. They are added as drafts for your teachers to check and publish.</p>
  <?php if (isset($errors['packages'])): ?><p class="field-error" id="packages-error"><?= $v->e($errors['packages']) ?></p><?php endif; ?>
  <?php if ($extra['packages'] === []): ?>
    <p class="muted small"><?= $extra['installed'] === [] ? 'No starter courses come with this version.' : 'Your school already has every starter course.' ?></p>
  <?php endif; ?>
  <?php foreach ($extra['packages'] as $i => $p): ?>
  <label class="choice pick">
    <input type="checkbox" name="packages[]" value="<?= $v->e($p['name']) ?>"<?= in_array($p['name'], $chosen, true) ? ' checked' : '' ?><?= $i === 0 ? ' id="packages"' : '' ?>>
    <span>
      <strong><?= $v->e($p['title']) ?></strong>
      <span class="pick-hint"><?= $v->e($p['summary']) ?></span>
      <span class="pick-meta"><?= $v->e(implode(', ', array_filter([
          $p['level'] !== '' ? ucfirst($p['level']) : null,
          $p['hours'] !== null ? 'about ' . $p['hours'] . ' hours' : null,
          $v->plural($p['lessons'], 'lesson'),
      ]))) ?></span>
    </span>
  </label>
  <?php endforeach; ?>
  <?php if ($extra['installed'] !== []): ?>
    <p class="small muted">Already in your school: <?= $v->e(implode(', ', $extra['installed'])) ?>.</p>
  <?php endif; ?>
</fieldset>
