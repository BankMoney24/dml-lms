<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
use App\Modules\Joining\LearnerProfile;

$chosen = (array) ($values['goals'] ?? []);
$icons = ['code' => 'code', 'networks' => 'network', 'security' => 'shield', 'data' => 'chart', 'school' => 'school', 'career' => 'briefcase', 'everyday' => 'people'];
$error = $errors['goals'] ?? null;
?>
<fieldset class="field"<?= $error !== null ? ' aria-describedby="goals-error"' : '' ?>>
  <legend>I want to learn</legend>
  <?php if ($error !== null): ?><p class="field-error" id="goals-error"><?= $v->e($error) ?></p><?php endif; ?>
  <div class="tile-grid">
    <?php foreach (LearnerProfile::GOALS as $key => [$label, $hint]): ?>
    <label class="tile">
      <input type="checkbox" name="goals[]" value="<?= $v->e($key) ?>"<?= in_array($key, $chosen, true) ? ' checked' : '' ?><?= $key === 'code' ? ' id="goals"' : '' ?>>
      <span class="tile-icon"><?= $v->icon($icons[$key]) ?></span>
      <span class="tile-text"><strong><?= $v->e($label) ?></strong><span><?= $v->e($hint) ?></span></span>
    </label>
    <?php endforeach; ?>
  </div>
</fieldset>
<?php if (!$v->can('course.manage')): ?>
<label class="choice pick tile-extra">
  <input type="checkbox" name="teach" value="1"<?= ($values['teach'] ?? false) === true ? ' checked' : '' ?>>
  <span><strong>I would also like to teach here</strong><span class="pick-hint">After this, you can tell the school what you would teach. The school decides.</span></span>
</label>
<?php endif; ?>
