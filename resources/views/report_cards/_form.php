<?php
/**
 * Set up a report card: title, the exams that count and their weights, grading (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Exams\Cbt\CbtExam> $exams
 * @var array<string, string> $errors
 * @var array{title: string, grading: string, next_term: string, rows: list<array{exam: string, label: string, weight: string}>} $values
 * @var string $action
 * @var string $submitLabel
 */
use App\Modules\ReportCards\Grading;

$rows = array_pad($values['rows'], 4, ['exam' => '', 'label' => '', 'weight' => '']);
$examOptions = ['' => 'Choose an exam'];
foreach ($exams as $e) {
    $examOptions[(string) $e->id] = $e->title . ($e->status === 'closed' ? ' (closed)' : '');
}
?>
<?= $v->errorSummary($errors, ['exam' => 'exam-0', 'weight' => 'weight-0']) ?>
<form method="post" action="<?= $v->e($action) ?>" class="form form-wide" novalidate>
  <?= $v->csrfField() ?>
  <?= $v->field('title', 'Title', ['value' => $values['title'], 'error' => $errors['title'] ?? null, 'maxlength' => 120, 'hint' => 'For example “First term 2026/2027”. Learners and parents see it.']) ?>
  <fieldset class="field rc-components"<?= isset($errors['exam']) || isset($errors['weight']) ? ' aria-describedby="components-error"' : '' ?>>
    <legend>Exams that count</legend>
    <p class="hint">Choose the CBT exams this class sat this term and how much each counts, for example a mid-term test at 30 and the end-of-term exam at 70. Each subject is worked out of 100 from them.</p>
    <?php if (isset($errors['exam']) || isset($errors['weight'])): ?><p class="field-error" id="components-error"><?= $v->e($errors['exam'] ?? $errors['weight'] ?? '') ?></p><?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
    <div class="rc-row">
      <div class="field">
        <label for="exam-<?= $i ?>">Exam <?= $i + 1 ?><?= $i > 0 ? ' <span class="optional">(optional)</span>' : '' ?></label>
        <select id="exam-<?= $i ?>" name="exam[]">
          <?php foreach ($examOptions as $val => $text): ?><option value="<?= $v->e((string) $val) ?>"<?= (string) $val === $r['exam'] ? ' selected' : '' ?>><?= $v->e($text) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="label-<?= $i ?>">Name on the card</label>
        <input id="label-<?= $i ?>" name="label[]" value="<?= $v->e($r['label']) ?>" maxlength="40" placeholder="<?= $i === 0 ? 'Mid-term test' : ($i === 1 ? 'Exam' : '') ?>" autocomplete="off">
      </div>
      <div class="field rc-weight">
        <label for="weight-<?= $i ?>">Weight</label>
        <input id="weight-<?= $i ?>" name="weight[]" value="<?= $v->e($r['weight']) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" placeholder="<?= $i === 0 ? '30' : ($i === 1 ? '70' : '') ?>">
      </div>
    </div>
    <?php endforeach; ?>
  </fieldset>
  <?= $v->field('grading', 'Grades', ['type' => 'select', 'options' => Grading::SCALES, 'value' => $values['grading'], 'error' => $errors['grading'] ?? null]) ?>
  <?= $v->field('next_term', 'A line at the bottom of the card', ['value' => $values['next_term'], 'error' => $errors['next_term'] ?? null, 'maxlength' => 160, 'optional' => true, 'hint' => 'For example “Next term begins on Monday 12 January 2027.”']) ?>
  <button type="submit" class="button"><?= $v->e($submitLabel) ?></button>
</form>
