<?php
/**
 * A marked attempt (ADR 0042): scores by subject, then each question with the
 * candidate's answer, the right one and why. For the candidate (when the exam
 * allows it) and for teachers.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Cbt\CbtAttempt $attempt
 * @var ?\App\Modules\Exams\Cbt\CbtExam $exam
 * @var list<array{label: string, items: list<array{n: int, question: \App\Modules\Exams\Bank\BankQuestion, choice: ?int}>}> $sections
 * @var array<int, \App\Modules\Exams\Bank\Passage> $passages
 * @var bool $forTeacher
 * @var bool $showScore
 * @var bool $showReview
 */
use App\Modules\Exams\Bank\BankQuestion;

$result = $attempt->result;
$reasons = ['candidate' => 'You submitted it', 'time' => 'The time ran out, so it was submitted with your saved answers', 'closed' => 'The exam was closed, so it was submitted with your saved answers'];
if ($forTeacher) {
    $reasons = ['candidate' => 'Submitted by the candidate', 'time' => 'Submitted when the time ran out', 'closed' => 'Submitted when the exam was closed'];
}
$shownPassages = [];
?>
<header class="page-head">
  <?php if ($forTeacher && $exam !== null): ?>
    <?= $v->breadcrumbs(['CBT exams' => '/teach/exams', $exam->title => '/teach/exams/' . $exam->id, 'Results' => '/teach/exams/' . $exam->id . '/results', $attempt->userName => null]) ?>
  <?php else: ?>
    <?= $v->breadcrumbs(['Exams' => '/exams', 'Result' => null]) ?>
  <?php endif; ?>
  <h1><?= $forTeacher ? $v->e($attempt->userName) : $v->e($attempt->title) ?></h1>
  <p class="lead"><?= $forTeacher ? $v->e($attempt->title) . '. ' : '' ?><?= $v->e($reasons[(string) $attempt->submitReason] ?? 'Submitted') ?> on <?= $v->datetime($attempt->submittedAt) ?>, after <?= $v->e($v->plural($attempt->minutesUsed(), 'minute')) ?>.</p>
</header>

<?php if (!$showScore): ?>
  <section class="panel result-hidden" aria-labelledby="sent-h">
    <h2 id="sent-h">Your answers are in</h2>
    <p><?= $exam !== null && $exam->results === 'after_close' ? 'Your result appears here when the exam closes' . ($exam->closesAt !== null ? ' (' . $v->datetime($exam->closesAt) . ')' : '') . '.' : 'Your school will tell you your result.' ?></p>
    <p><a class="button button-quiet" href="/exams">Back to exams</a></p>
  </section>
<?php else: ?>
  <section class="result-board" aria-labelledby="score-h">
    <h2 id="score-h" class="visually-hidden">Score</h2>
    <div class="result-total">
      <p class="result-big"><?= (int) ($result['total'] ?? 0) ?><span>/ <?= (int) ($result['max'] ?? 0) ?></span></p>
      <p class="result-sub"><?= (int) $attempt->correctCount ?> of <?= $attempt->questionCount ?> right (<?= (int) $attempt->percent ?>%)</p>
    </div>
    <ul class="result-subjects">
      <?php foreach ($result['sections'] ?? [] as $s): ?>
      <li>
        <span class="result-subject"><?= $v->e($s['label']) ?></span>
        <span class="result-meter" aria-hidden="true"><span class="result-fill" data-width="<?= (int) $s['scaled'] ?>"></span></span>
        <span class="result-score"><strong><?= (int) $s['scaled'] ?></strong>/100<span class="small muted"> (<?= (int) $s['correct'] ?> of <?= (int) $s['count'] ?>)</span></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$forTeacher): ?><p class="row-actions"><a class="button button-quiet" href="/exams">Back to exams</a><?php if ($attempt->isPractice()): ?> <a class="button" href="/exams#practice">Practise again</a><?php endif; ?></p><?php endif; ?>
  </section>

  <?php if ($showReview): ?>
  <section aria-labelledby="review-h" class="section review">
    <h2 id="review-h">Corrections</h2>
    <p class="small muted">Questions are shown in the order you had them; options are in the bank’s order, A to E.</p>
    <?php foreach ($sections as $s): ?>
      <h3 class="review-section"><?= $v->e($s['label']) ?></h3>
      <ol class="review-list">
        <?php foreach ($s['items'] as $it): $q = $it['question']; $choice = $it['choice']; $right = $choice === $q->correct; ?>
        <li class="review-item <?= $choice === null ? 'is-blank' : ($right ? 'is-right' : 'is-wrong') ?>" value="<?= $it['n'] ?>">
          <?php if ($q->passageId !== null && isset($passages[$q->passageId]) && !isset($shownPassages[$q->passageId])): $shownPassages[$q->passageId] = true; $p = $passages[$q->passageId]; ?>
            <details class="review-passage"><summary><?= $v->e($p->title) ?></summary><div class="prose prose-small"><?= $v->markdown($p->body) ?></div></details>
          <?php endif; ?>
          <p class="review-verdict">Question <?= $it['n'] ?>: <?= $choice === null ? 'not answered' : ($right ? 'right' : 'wrong') ?></p>
          <div class="prose prose-small"><?= $v->markdown($q->prompt) ?></div>
          <?php if ($q->imageFileId !== null): ?><figure class="qpic"><img src="/files/<?= $q->imageFileId ?>" alt="<?= $v->e($q->imageAlt) ?>" loading="lazy"></figure><?php endif; ?>
          <ul class="review-options">
            <?php foreach ($q->options as $i => $o): ?>
            <li class="<?= $i === $q->correct ? 'is-key' : '' ?><?= $i === $choice && !$right ? ' is-chosen' : '' ?>">
              <span class="bq-letter" aria-hidden="true"><?= BankQuestion::LETTERS[$i] ?></span><span><?= $v->e($o) ?></span>
              <?php if ($i === $q->correct): ?><span class="review-tag">Right answer</span><?php endif; ?>
              <?php if ($i === $choice): ?><span class="review-tag review-tag-you"><?= $forTeacher ? 'Their answer' : 'Your answer' ?></span><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($q->explanation !== ''): ?><div class="review-why small"><strong>Why:</strong> <?= $v->markdown($q->explanation) ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    <?php endforeach; ?>
  </section>
  <?php elseif (!$forTeacher): ?>
    <p class="small muted">This exam does not show corrections.</p>
  <?php endif; ?>
<?php endif; ?>
