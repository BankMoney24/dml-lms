<?php
/**
 * Add or edit one bank question (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Bank\QuestionBank $bank
 * @var ?\App\Modules\Exams\Bank\BankQuestion $question
 * @var list<\App\Modules\Exams\Bank\Passage> $passages
 * @var list<string> $topics
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
use App\Modules\Exams\Bank\BankQuestion;

$testsTab = 'banks';
$base = '/teach/banks/' . $bank->id;
$o = static fn (string $k, string $fallback = ''): string => array_key_exists($k, $old) ? (string) $old[$k] : $fallback;
$options = $old !== [] ? array_values(array_map('strval', (array) ($old['options'] ?? []))) : ($question->options ?? []);
$options = array_pad($options, 5, '');
$correct = $old !== [] ? strtoupper((string) ($old['correct'] ?? '')) : ($question !== null ? $question->correctLetter() : '');
if (ctype_digit($correct)) {
    $correct = BankQuestion::LETTERS[(int) $correct] ?? '';
}
$passage = $o('passage_id', $question?->passageId !== null ? (string) $question->passageId : '');
$difficulty = $o('difficulty', $question?->difficulty !== null ? (string) $question->difficulty : '');
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Question banks' => '/teach/banks', $bank->name => $base, ($question === null ? 'New question' : 'Edit question') => null]) ?>
  <h1><?= $question === null ? 'New question' : 'Edit question' ?></h1>
  <?php if ($question !== null && $question->timesSeen > 0): ?><p class="lead">Seen in <?= $question->timesSeen ?> submitted <?= $question->timesSeen === 1 ? 'attempt' : 'attempts' ?><?= $question->facility() !== null ? ', ' . $question->facility() . '% answered it right' : '' ?>. Changing the right answer changes the marks of attempts submitted from now on, not earlier ones.</p><?php endif; ?>
</header>

<?= $v->errorSummary($errors, ['options' => 'opt-0', 'correct' => 'correct-A']) ?>
<form method="post" action="<?= $question === null ? $base . '/questions' : $base . '/questions/' . $question->id ?>" class="form form-wide question-form" novalidate>
  <?= $v->csrfField() ?>
  <?php if ($passages !== []): ?>
    <?= $v->field('passage_id', 'Passage it goes with', ['type' => 'select', 'options' => ['' => 'None'] + array_combine(array_map(static fn ($p): string => (string) $p->id, $passages), array_map(static fn ($p): string => $p->title, $passages)), 'value' => $passage, 'error' => $errors['passage_id'] ?? null, 'optional' => true]) ?>
  <?php endif; ?>
  <?= $v->field('prompt', 'Question', ['type' => 'textarea', 'rows' => 4, 'value' => $o('prompt', $question->prompt ?? ''), 'error' => $errors['prompt'] ?? null, 'hint' => 'Plain text. **Bold** a word with two stars each side (for "the word in bold" questions); `code` in backticks; a blank line starts a new paragraph.']) ?>

  <fieldset class="field options-field"<?= isset($errors['options']) || isset($errors['correct']) ? ' aria-describedby="options-error"' : '' ?>>
    <legend>Options, and the right one</legend>
    <p class="hint">Fill two to five options from A. Choose the right one with the button beside it.</p>
    <?php if (isset($errors['options']) || isset($errors['correct'])): ?><p class="field-error" id="options-error"><?= $v->e($errors['options'] ?? $errors['correct'] ?? '') ?></p><?php endif; ?>
    <?php foreach (BankQuestion::LETTERS as $i => $letter): ?>
    <div class="option-row">
      <span class="option-letter" aria-hidden="true"><?= $letter ?></span>
      <label class="visually-hidden" for="opt-<?= $i ?>">Option <?= $letter ?></label>
      <input id="opt-<?= $i ?>" name="options[]" value="<?= $v->e($options[$i]) ?>" maxlength="500" autocomplete="off"<?= $i >= 2 ? ' placeholder="Optional"' : '' ?>>
      <label class="option-key"><input type="radio" name="correct" value="<?= $letter ?>" id="correct-<?= $letter ?>"<?= $correct === $letter ? ' checked' : '' ?>><span>Right answer<span class="visually-hidden"> is <?= $letter ?></span></span></label>
    </div>
    <?php endforeach; ?>
  </fieldset>

  <?= $v->field('explanation', 'Why that answer is right', ['type' => 'textarea', 'rows' => 3, 'value' => $o('explanation', $question->explanation ?? ''), 'error' => $errors['explanation'] ?? null, 'optional' => true, 'hint' => 'Shown to candidates with their corrections, when the exam allows it.']) ?>
  <div class="field-row">
    <div class="field">
      <label for="topic">Topic <span class="optional">(optional)</span></label>
      <input id="topic" name="topic" value="<?= $v->e($o('topic', $question->topic ?? '')) ?>" maxlength="80" list="topic-list" autocomplete="off"<?= isset($errors['topic']) ? ' aria-invalid="true" aria-describedby="topic-error"' : '' ?>>
      <datalist id="topic-list"><?php foreach ($topics as $t): ?><option value="<?= $v->e($t) ?>"></option><?php endforeach; ?></datalist>
      <?php if (isset($errors['topic'])): ?><p class="field-error" id="topic-error"><?= $v->e($errors['topic']) ?></p><?php endif; ?>
    </div>
    <?= $v->field('difficulty', 'Difficulty', ['type' => 'select', 'options' => ['' => 'Not set', '1' => 'Easy', '2' => 'Medium', '3' => 'Hard'], 'value' => $difficulty, 'error' => $errors['difficulty'] ?? null, 'optional' => true]) ?>
  </div>
  <div class="row-actions">
    <button type="submit" class="button"><?= $question === null ? 'Add the question' : 'Save the question' ?></button>
    <?php if ($question === null): ?><button type="submit" name="another" value="1" class="button button-quiet">Add and write another</button><?php endif; ?>
    <a href="<?= $base ?>">Cancel</a>
  </div>
</form>
