<?php
/**
 * Before an exam (ADR 0042): what is in it, the rules, choosing subjects, the
 * access code, and Start.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Cbt\CbtExam $exam
 * @var list<\App\Modules\Exams\Cbt\CbtAttempt> $attempts
 * @var ?\App\Modules\Exams\Cbt\CbtAttempt $open
 * @var ?string $why
 * @var string $now
 * @var \DateTimeImmutable $clockNow
 * @var array<string, string> $errors
 */
$done = array_values(array_filter($attempts, static fn ($a): bool => $a->isSubmitted()));
$compulsory = $exam->compulsory();
$optional = $exam->optional();
$left = $exam->maxAttempts === null ? null : max(0, $exam->maxAttempts - count($done));
?>
<header class="page-head">
  <?= $v->breadcrumbs(['Exams' => '/exams', $exam->title => null]) ?>
  <h1><?= $v->e($exam->title) ?></h1>
  <p class="lead"><?= $v->e($exam->questionsLabel()) ?> questions, <?= $exam->durationMinutes ?> minutes<?= $exam->closesAt !== null ? ', closes ' . $v->datetime($exam->closesAt) : '' ?>.</p>
</header>

<div class="tests-layout">
<div>
  <?php if ($exam->instructions !== ''): ?>
  <section class="panel exam-instructions" aria-labelledby="ins-h">
    <h2 id="ins-h">Instructions</h2>
    <?= $v->paragraphs($exam->instructions) ?>
  </section>
  <?php endif; ?>

  <section aria-labelledby="paper-h" class="section">
    <h2 id="paper-h">The paper</h2>
    <ul class="paper-list">
      <?php foreach ($exam->sections as $i => $s): ?>
      <li><strong><?= $v->e($s['label']) ?></strong>: <?= $s['count'] ?> questions, scored out of 100<?= $exam->chooseCount > 0 ? (in_array($i, $compulsory, true) ? ' (everyone)' : ' (choose)') : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section aria-labelledby="how-h" class="section">
    <h2 id="how-h">How it works</h2>
    <ul class="how-list">
      <li>The clock starts when you press Start, and keeps running if you close the page or lose the connection. Come back to this page to carry on.</li>
      <li>Every answer is saved as you go. If the connection drops, answers are kept on this device and sent when it is back.</li>
      <li>Move between subjects with the tabs and between questions with the numbers. Flag a question to come back to it.</li>
      <li>On a keyboard: <kbd>A</kbd> to <kbd>E</kbd> choose an answer, <kbd>N</kbd> next, <kbd>P</kbd> previous, <kbd>F</kbd> flag, <kbd>S</kbd> submit.</li>
      <li>When the time is up, your saved answers are submitted for you.</li>
    </ul>
  </section>
</div>

<aside class="tests-side">
  <section class="panel exam-start" aria-labelledby="start-h">
    <h2 id="start-h"><?= $open !== null ? 'You have started' : 'Ready?' ?></h2>
    <?php if ($open !== null): ?>
      <p>You have <?= (int) ceil($open->secondsLeft($clockNow) / 60) ?> minutes left.</p>
      <a class="button" href="/exams/attempts/<?= $open->id ?>">Carry on</a>
    <?php elseif ($why !== null): ?>
      <p><?= $v->e($why) ?></p>
      <?php if ($done !== []): ?><a class="button button-quiet" href="/exams/attempts/<?= $done[0]->id ?>/result">See your result</a><?php endif; ?>
    <?php else: ?>
      <?= $v->errorSummary($errors, ['subjects' => 'subject-' . ($optional[0] ?? 0)]) ?>
      <form method="post" action="/exams/<?= $exam->id ?>/start" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?php if ($exam->chooseCount > 0): ?>
        <fieldset class="field"<?= isset($errors['subjects']) ? ' aria-describedby="subjects-error"' : '' ?>>
          <legend>Choose <?= $exam->chooseCount ?> <?= $exam->chooseCount === 1 ? 'subject' : 'subjects' ?></legend>
          <p class="hint">Everyone sits <?= $v->e(implode(', ', array_map(static fn (int $i): string => $exam->sections[$i]['label'], $compulsory))) ?: 'the compulsory subjects' ?>.</p>
          <?php if (isset($errors['subjects'])): ?><p class="field-error" id="subjects-error"><?= $v->e($errors['subjects']) ?></p><?php endif; ?>
          <?php foreach ($optional as $i): ?>
          <label class="choice choice-tight"><input type="checkbox" name="subjects[]" value="<?= $i ?>" id="subject-<?= $i ?>"><span><?= $v->e($exam->sections[$i]['label']) ?></span></label>
          <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <?php if ($exam->accessCode !== null): ?>
          <?= $v->field('code', 'Access code', ['error' => $errors['code'] ?? null, 'autocomplete' => 'off', 'maxlength' => 12, 'hint' => 'The invigilator gives it out in the exam room.']) ?>
        <?php endif; ?>
        <?php if (isset($errors['start'])): ?><p class="field-error"><?= $v->e($errors['start']) ?></p><?php endif; ?>
        <button type="submit" class="button button-wide">Start the exam</button>
        <p class="small muted"><?= $left === null ? 'You can sit it as many times as you like.' : 'You have ' . $v->e($v->plural($left, 'attempt')) . ' left.' ?></p>
      </form>
    <?php endif; ?>
  </section>
</aside>
</div>
