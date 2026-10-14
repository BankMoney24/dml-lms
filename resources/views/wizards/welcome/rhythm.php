<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
use App\Modules\Joining\LearnerProfile;

$goal = (int) ($values['weekly_goal'] ?? 3);
$error = $errors['weekly_goal'] ?? null;
$names = [1 => 'Once a week', 2 => 'Twice a week', 3 => '3 days a week', 5 => 'Weekdays', 7 => 'Every day'];
?>
<fieldset class="field"<?= $error !== null ? ' aria-describedby="weekly-error"' : '' ?>>
  <legend>How often I want to learn</legend>
  <?php if ($error !== null): ?><p class="field-error" id="weekly-error"><?= $v->e($error) ?></p><?php endif; ?>
  <div class="rhythm-list">
    <?php foreach (LearnerProfile::WEEKLY_GOALS as $days): ?>
    <label class="rhythm">
      <input type="radio" name="weekly_goal" value="<?= $days ?>"<?= $goal === $days ? ' checked' : '' ?><?= $days === 1 ? ' id="weekly_goal"' : '' ?>>
      <span class="rhythm-days" aria-hidden="true"><?php for ($d = 1; $d <= 7; $d++): ?><span class="<?= $d <= $days ? 'is-on' : '' ?>"></span><?php endfor; ?></span>
      <span class="rhythm-label"><?= $v->e($names[$days]) ?></span>
    </label>
    <?php endforeach; ?>
  </div>
  <p class="hint">Even ten minutes counts: opening a lesson, trying a quiz or running a lab lights up the day.</p>
</fieldset>
