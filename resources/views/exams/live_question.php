<?php
/**
 * Edit one live quiz question (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Live\LiveQuiz $quiz
 * @var \App\Modules\Exams\Live\LiveQuestion $question
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
use App\Modules\Exams\Live\LiveQuestion;

$testsTab = 'live';
$base = '/teach/live/' . $quiz->id;
$letters = ['A', 'B', 'C', 'D'];
$opts = array_pad($old !== [] ? array_values(array_map('strval', (array) ($old['options'] ?? []))) : $question->options, 4, '');
$correct = $old !== [] ? strtoupper((string) ($old['correct'] ?? '')) : $letters[$question->correct];
$secondsOptions = array_combine(array_map('strval', LiveQuestion::SECONDS), array_map(static fn (int $s): string => $s < 60 ? $s . ' seconds' : ($s / 60) . ($s === 60 ? ' minute' : ' minutes'), LiveQuestion::SECONDS));
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Live quizzes' => '/teach/live', $quiz->title => $base, 'Edit question' => null]) ?>
  <h1>Edit question</h1>
  <p class="lead">Changes apply to games started from now on.</p>
</header>
<?= $v->errorSummary($errors, ['options' => 'lopt-0', 'correct' => 'lcorrect-A']) ?>
<form method="post" action="<?= $base ?>/questions/<?= $question->id ?>" class="form form-wide" novalidate>
  <?= $v->csrfField() ?>
  <?= $v->field('prompt', 'Question', ['type' => 'textarea', 'rows' => 2, 'value' => $old !== [] ? (string) ($old['prompt'] ?? '') : $question->prompt, 'error' => $errors['prompt'] ?? null, 'maxlength' => 500]) ?>
  <fieldset class="field options-field"<?= isset($errors['options']) || isset($errors['correct']) ? ' aria-describedby="loptions-error"' : '' ?>>
    <legend>Answers, and the right one</legend>
    <?php if (isset($errors['options']) || isset($errors['correct'])): ?><p class="field-error" id="loptions-error"><?= $v->e($errors['options'] ?? $errors['correct'] ?? '') ?></p><?php endif; ?>
    <?php foreach ($letters as $j => $l): ?>
    <div class="option-row">
      <span class="option-letter lq-<?= $j ?>" aria-hidden="true"><?= $l ?></span>
      <label class="visually-hidden" for="lopt-<?= $j ?>">Answer <?= $l ?></label>
      <input id="lopt-<?= $j ?>" name="options[]" value="<?= $v->e($opts[$j]) ?>" maxlength="120" autocomplete="off"<?= $j >= 2 ? ' placeholder="Optional"' : '' ?>>
      <label class="option-key"><input type="radio" name="correct" value="<?= $l ?>" id="lcorrect-<?= $l ?>"<?= $correct === $l ? ' checked' : '' ?>><span>Right<span class="visually-hidden"> answer is <?= $l ?></span></span></label>
    </div>
    <?php endforeach; ?>
  </fieldset>
  <div class="field-row">
    <?= $v->field('seconds', 'Time to answer', ['type' => 'select', 'options' => $secondsOptions, 'value' => $old !== [] ? (string) ($old['seconds'] ?? '20') : (string) $question->seconds, 'error' => $errors['seconds'] ?? null]) ?>
    <?= $v->field('points', 'Points', ['type' => 'select', 'options' => LiveQuestion::POINTS, 'value' => $old !== [] ? (string) ($old['points'] ?? 'standard') : $question->points, 'error' => $errors['points'] ?? null]) ?>
  </div>
  <div class="row-actions">
    <button type="submit" class="button">Save the question</button>
    <a href="<?= $base ?>">Cancel</a>
  </div>
</form>
