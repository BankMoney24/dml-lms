<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
use App\Modules\Joining\LearnerProfile;

$level = (string) ($values['experience'] ?? '');
$error = $errors['experience'] ?? null;
$bars = ['new' => 1, 'some' => 2, 'pro' => 3];
?>
<fieldset class="field"<?= $error !== null ? ' aria-describedby="experience-error"' : '' ?>>
  <legend>In the things I chose, I am</legend>
  <?php if ($error !== null): ?><p class="field-error" id="experience-error"><?= $v->e($error) ?></p><?php endif; ?>
  <div class="tile-grid tile-grid-3">
    <?php foreach (LearnerProfile::EXPERIENCE as $key => [$label, $hint]): ?>
    <label class="tile">
      <input type="radio" name="experience" value="<?= $v->e($key) ?>"<?= $level === $key ? ' checked' : '' ?><?= $key === 'new' ? ' id="experience"' : '' ?>>
      <span class="tile-level" aria-hidden="true"><?php for ($i = 1; $i <= 3; $i++): ?><span class="<?= $i <= $bars[$key] ? 'is-on' : '' ?>"></span><?php endfor; ?></span>
      <span class="tile-text"><strong><?= $v->e($label) ?></strong><span><?= $v->e($hint) ?></span></span>
    </label>
    <?php endforeach; ?>
  </div>
</fieldset>

<fieldset class="field dev-links">
  <legend>If you write code <span class="optional">(optional)</span></legend>
  <p class="hint">Your teachers see these when they review your work. Leave them empty if you prefer.</p>
  <?= $v->field('github', 'GitHub username', ['value' => (string) ($values['github'] ?? ''), 'error' => $errors['github'] ?? null, 'maxlength' => 80, 'autocomplete' => 'off', 'optional' => true]) ?>
  <?= $v->field('portfolio', 'Portfolio or website', ['value' => (string) ($values['portfolio'] ?? ''), 'error' => $errors['portfolio'] ?? null, 'maxlength' => 250, 'inputmode' => 'url', 'optional' => true]) ?>
</fieldset>
