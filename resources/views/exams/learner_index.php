<?php
/**
 * Exams and quizzes for learners (ADR 0042): exams set by the school, practice
 * tests from open banks, and joining a live quiz.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{exam: \App\Modules\Exams\Cbt\CbtExam, attempts: list<\App\Modules\Exams\Cbt\CbtAttempt>, open: ?\App\Modules\Exams\Cbt\CbtAttempt, why: ?string}> $rows
 * @var string $now
 * @var list<\App\Modules\Exams\Bank\QuestionBank> $practiceBanks
 * @var list<\App\Modules\Exams\Cbt\CbtAttempt> $practice
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
use App\Modules\Exams\Cbt\CbtExams;

$openNow = [];
$later = [];
$past = [];
foreach ($rows as $row) {
    $e = $row['exam'];
    if ($row['open'] !== null || $e->isOpen($now)) {
        $openNow[] = $row;
    } elseif ($e->status === 'published' && $e->opensAt !== null && $e->opensAt > $now) {
        $later[] = $row;
    } else {
        $past[] = $row;
    }
}
$chosenBanks = array_map('intval', (array) ($old['banks'] ?? []));
$learns = $v->can('course.learn');
?>
<header class="page-head page-head-row">
  <div>
    <h1>Exams and quizzes</h1>
    <p class="lead">Computer-based tests your school sets, practice tests you set yourself, and live quizzes in class.</p>
  </div>
  <p class="page-actions"><a class="button" href="/play"><?= $v->icon('play') ?>Join a live quiz</a></p>
</header>

<div class="tests-layout">
<div>
  <section aria-labelledby="open-h" class="section">
    <h2 id="open-h">Open now</h2>
    <?php if ($openNow === []): ?>
      <p class="muted">No exams are open for you right now.</p>
    <?php else: ?>
    <ul class="exam-cards">
      <?php foreach ($openNow as $row): $e = $row['exam']; $doneOnes = array_values(array_filter($row['attempts'], static fn ($a): bool => $a->isSubmitted())); ?>
      <li class="exam-card is-open">
        <div>
          <h3><a href="/exams/<?= $e->id ?>"><?= $v->e($e->title) ?></a></h3>
          <p class="small"><?= $v->e($e->questionsLabel()) ?> questions in <?= $e->durationMinutes ?> minutes: <?= $v->e(implode(', ', array_column($e->sections, 'label'))) ?><?= $e->chooseCount > 0 ? ' (you choose ' . $e->chooseCount . ')' : '' ?></p>
          <?php if ($e->closesAt !== null): ?><p class="small muted">Closes <?= $v->datetime($e->closesAt) ?></p><?php endif; ?>
        </div>
        <div class="exam-card-act">
          <?php if ($row['open'] !== null): ?>
            <a class="button" href="/exams/attempts/<?= $row['open']->id ?>">Carry on<span class="visually-hidden"> with <?= $v->e($e->title) ?></span></a>
          <?php elseif ($row['why'] === null): ?>
            <a class="button" href="/exams/<?= $e->id ?>">Start<span class="visually-hidden"> <?= $v->e($e->title) ?></span></a>
          <?php else: ?>
            <span class="small muted"><?= $v->e($row['why']) ?></span>
          <?php endif; ?>
          <?php if ($doneOnes !== []): ?><a class="small" href="/exams/attempts/<?= $doneOnes[0]->id ?>/result">Your last result</a><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <?php if ($later !== []): ?>
  <section aria-labelledby="later-h" class="section">
    <h2 id="later-h">Coming up</h2>
    <ul class="exam-cards">
      <?php foreach ($later as $row): $e = $row['exam']; ?>
      <li class="exam-card"><div><h3><a href="/exams/<?= $e->id ?>"><?= $v->e($e->title) ?></a></h3><p class="small">Opens <?= $v->datetime($e->opensAt) ?>, <?= $e->durationMinutes ?> minutes</p></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($past !== []): ?>
  <section aria-labelledby="past-h" class="section">
    <h2 id="past-h">Earlier exams</h2>
    <ul class="exam-cards">
      <?php foreach ($past as $row): $e = $row['exam']; $last = null; foreach ($row['attempts'] as $a) { if ($a->isSubmitted()) { $last = $a; break; } } ?>
      <li class="exam-card">
        <div><h3><?= $v->e($e->title) ?></h3><p class="small muted"><?= $last === null ? 'You did not sit it.' : 'Sat ' . $v->datetime($last->submittedAt, 'j M Y') ?></p></div>
        <?php if ($last !== null): ?><div class="exam-card-act"><a class="button button-small button-quiet" href="/exams/attempts/<?= $last->id ?>/result">Result<span class="visually-hidden"> for <?= $v->e($e->title) ?></span></a></div><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>

<aside class="tests-side">
  <section class="panel" id="practice" aria-labelledby="practice-h">
    <h2 id="practice-h">Practise</h2>
    <?php if ($practiceBanks === []): ?>
      <p class="small muted">Your school has not opened any question banks for practice yet.</p>
    <?php elseif (!$learns): ?>
      <p class="small muted">Learners can set themselves practice tests here from <?= $v->e($v->plural(count($practiceBanks), 'open bank')) ?>.</p>
    <?php else: ?>
    <?= $v->errorSummary($errors, ['banks' => 'bank-' . ($practiceBanks[0]->id)]) ?>
    <form method="post" action="/exams/practice" class="form" novalidate>
      <?= $v->csrfField() ?>
      <fieldset class="field"<?= isset($errors['banks']) ? ' aria-describedby="banks-error"' : '' ?>>
        <legend>Subjects (up to four)</legend>
        <?php if (isset($errors['banks'])): ?><p class="field-error" id="banks-error"><?= $v->e($errors['banks']) ?></p><?php endif; ?>
        <?php foreach ($practiceBanks as $b): ?>
        <label class="choice choice-tight"><input type="checkbox" name="banks[]" value="<?= $b->id ?>" id="bank-<?= $b->id ?>"<?= in_array($b->id, $chosenBanks, true) ? ' checked' : '' ?>><span><?= $v->e($b->name) ?> <span class="small muted"><?= $b->activeCount ?></span></span></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="field-row">
        <?= $v->field('count', 'Questions in each', ['type' => 'select', 'options' => array_combine(array_map('strval', CbtExams::PRACTICE_COUNTS), array_map('strval', CbtExams::PRACTICE_COUNTS)), 'value' => (string) ($old['count'] ?? '20'), 'error' => $errors['count'] ?? null]) ?>
        <?= $v->field('minutes', 'Time', ['type' => 'select', 'options' => ['0' => 'No limit', '10' => '10 minutes', '20' => '20 minutes', '30' => '30 minutes', '60' => '1 hour', '120' => '2 hours'], 'value' => (string) ($old['minutes'] ?? '30'), 'error' => $errors['minutes'] ?? null]) ?>
      </div>
      <button type="submit" class="button">Start practising</button>
      <p class="small muted">Practice shows your score and corrections straight away. It is just for you; teachers see only exams they set.</p>
    </form>
    <?php endif; ?>
    <?php if ($practice !== []): ?>
    <h3 class="side-sub">Your recent practice</h3>
    <ul class="plain-list practice-list">
      <?php foreach ($practice as $a): ?>
      <li>
        <?php if ($a->isSubmitted()): ?>
          <a href="/exams/attempts/<?= $a->id ?>/result"><?= $v->e(preg_replace('/^Practice: /', '', $a->title)) ?></a> <span class="small muted"><?= (int) $a->percent ?>%, <?= $v->datetime($a->submittedAt, 'j M') ?></span>
        <?php else: ?>
          <a href="/exams/attempts/<?= $a->id ?>"><?= $v->e(preg_replace('/^Practice: /', '', $a->title)) ?></a> <?= $v->badge('In progress', 'level') ?>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</aside>
</div>
