<?php
/**
 * Teach → Quizzes and exams → Question banks (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Exams\Bank\QuestionBank> $banks
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var int $samplesMissing how many of the sample banks this school does not have yet
 */
$testsTab = 'banks';
$current = array_values(array_filter($banks, static fn ($b): bool => $b->archivedAt === null));
$archived = array_values(array_filter($banks, static fn ($b): bool => $b->archivedAt !== null));
?>
<header class="page-head">
  <h1>Question banks</h1>
  <p class="lead">Your school’s own questions, one bank per subject. CBT exams draw their papers from banks, and live quizzes can borrow from them.</p>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<div class="tests-layout">
  <section aria-labelledby="banks-h">
    <h2 id="banks-h"><?= $v->e($v->plural(count($current), 'bank')) ?></h2>
    <?php if ($current === []): ?>
      <?= $v->emptyState('No question banks yet', 'Make one for each subject, then add questions one at a time or import a whole set. To try everything first, add the sample banks.') ?>
    <?php else: ?>
    <ul class="bank-grid">
      <?php foreach ($current as $b): ?>
      <li class="bank-card">
        <h3><a href="/teach/banks/<?= $b->id ?>"><?= $v->e($b->name) ?></a></h3>
        <p class="bank-count"><strong><?= number_format($b->activeCount) ?></strong> <?= $b->activeCount === 1 ? 'question' : 'questions' ?><?php if ($b->retiredCount > 0): ?><span class="muted">, <?= $b->retiredCount ?> retired</span><?php endif; ?></p>
        <?php if ($b->description !== ''): ?><p class="small muted"><?= $v->e($b->description) ?></p><?php endif; ?>
        <p class="bank-tags"><?= $b->practice ? $v->badge('Open for practice', 'ok') : $v->badge('Exams only', 'neutral') ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($archived !== []): ?>
    <details class="panel bank-archived">
      <summary><?= $v->e($v->plural(count($archived), 'archived bank')) ?></summary>
      <ul class="plain-list">
        <?php foreach ($archived as $b): ?><li><a href="/teach/banks/<?= $b->id ?>"><?= $v->e($b->name) ?></a> <span class="small muted"><?= $v->e($v->plural($b->activeCount, 'question')) ?></span></li><?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </section>

  <aside class="tests-side">
    <section class="panel" aria-labelledby="new-bank-h">
      <h2 id="new-bank-h">New bank</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/teach/banks" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Subject', ['value' => (string) ($old['name'] ?? ''), 'error' => $errors['name'] ?? null, 'maxlength' => 80, 'autocomplete' => 'off', 'hint' => 'For example Use of English, Mathematics or SS 2 Physics.']) ?>
        <?= $v->field('description', 'Notes for other teachers', ['type' => 'textarea', 'rows' => 2, 'value' => (string) ($old['description'] ?? ''), 'error' => $errors['description'] ?? null, 'optional' => true, 'maxlength' => 500]) ?>
        <label class="choice"><input type="checkbox" name="practice" value="1"<?= ($old['practice'] ?? '') === '1' ? ' checked' : '' ?>><span>Open for practice<span class="pick-hint">Learners can set themselves practice tests from it. Leave off for questions kept for real exams.</span></span></label>
        <button type="submit" class="button">Make the bank</button>
      </form>
    </section>
    <section class="panel panel-quiet" aria-labelledby="samples-h">
      <h2 id="samples-h">Sample banks</h2>
      <p class="small">Five starter banks (Use of English, Mathematics, Physics, Chemistry and Biology) with about 55 practice questions, so you can try a UTME-style exam and a live quiz straight away. They were written for this site; they are not past JAMB, WAEC or NECO questions.</p>
      <?php if ($samplesMissing > 0): ?>
      <form method="post" action="/teach/banks/samples">
        <?= $v->csrfField() ?>
        <button type="submit" class="button button-quiet button-small"><?= $samplesMissing === 5 ? 'Add the sample banks' : 'Add the ' . $v->plural($samplesMissing, 'missing sample bank') ?></button>
      </form>
      <?php else: ?>
      <p class="small muted">All five are in your list above.</p>
      <?php endif; ?>
    </section>
  </aside>
</div>
