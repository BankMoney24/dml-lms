<?php
/**
 * Results of a CBT exam for teachers (ADR 0042): who sat it, scores by subject,
 * who is still sitting it (with time to add), and how each question did.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Cbt\CbtExam $exam
 * @var list<\App\Modules\Exams\Cbt\CbtAttempt> $attempts
 * @var list<array{question: \App\Modules\Exams\Bank\BankQuestion, seen: int, right: int, picks: list<int>, blank: int, section: string}> $items
 * @var \DateTimeImmutable $now
 */
use App\Modules\Exams\Bank\BankQuestion;

$testsTab = 'exams';
$base = '/teach/exams/' . $exam->id;
$done = array_values(array_filter($attempts, static fn ($a): bool => $a->isSubmitted()));
$sitting = array_values(array_filter($attempts, static fn ($a): bool => !$a->isSubmitted()));
$percents = array_map(static fn ($a): int => (int) $a->percent, $done);
$labels = array_column($exam->sections, 'label');
$reasons = ['candidate' => 'Submitted', 'time' => 'Time ran out', 'closed' => 'Exam closed'];
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['CBT exams' => '/teach/exams', $exam->title => $base, 'Results' => null]) ?>
    <h1>Results</h1>
    <p class="lead"><?= $v->e($exam->title) ?>: <?= count($done) ?> submitted<?= $sitting !== [] ? ', ' . count($sitting) . ' sitting now' : '' ?>.</p>
  </div>
  <p class="page-actions">
    <a class="button button-quiet" href="<?= $base ?>/results"><?= $v->icon('activity') ?>Refresh</a>
    <?php if ($attempts !== []): ?><a class="button button-quiet" href="<?= $base ?>/results.csv" download><?= $v->icon('download') ?>Download CSV</a><?php endif; ?>
  </p>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<?php if ($attempts === []): ?>
  <?= $v->emptyState('Nobody has started yet', $exam->status === 'draft' ? 'Publish the exam so candidates can find it under Exams.' : 'Candidates start it from Exams. This page shows them as they sit it.') ?>
<?php else: ?>
<?php if ($done !== []): ?>
<dl class="report-figures">
  <div class="report-figure"><dt>Average</dt><dd><span class="report-value"><?= (int) round(array_sum($percents) / count($percents)) ?>%</span><span class="report-note">of questions right</span></dd></div>
  <div class="report-figure"><dt>Highest</dt><dd><span class="report-value"><?= max($percents) ?>%</span></dd></div>
  <div class="report-figure"><dt>Lowest</dt><dd><span class="report-value"><?= min($percents) ?>%</span></dd></div>
  <div class="report-figure"><dt>Submitted</dt><dd><span class="report-value"><?= count($done) ?></span><span class="report-note"><?= count(array_filter($done, static fn ($a): bool => $a->submitReason !== 'candidate')) ?> by the clock</span></dd></div>
</dl>
<?php endif; ?>

<?php if ($sitting !== []): ?>
<section aria-labelledby="sitting-h" class="section">
  <h2 id="sitting-h">Sitting now</h2>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th scope="col">Candidate</th><th scope="col">Answered</th><th scope="col">Time left</th><th scope="col">Last saved</th><th scope="col">Left the page</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($sitting as $a): $left = $a->secondsLeft($now); ?>
        <tr>
          <th scope="row"><?= $v->e($a->userName) ?><span class="small muted"><?= $v->e($a->userEmail) ?></span></th>
          <td data-label="Answered"><?= count($a->answers) ?> of <?= $a->questionCount ?></td>
          <td data-label="Time left"><?= $left > 0 ? (int) ceil($left / 60) . ' min' : 'Out of time' ?><?= $a->extraMinutes > 0 ? '<span class="small muted">+' . $a->extraMinutes . ' min added</span>' : '' ?></td>
          <td data-label="Last saved"><?= $a->lastSavedAt === null ? '<span class="muted">Nothing yet</span>' : $v->datetime($a->lastSavedAt, 'H:i:s') ?></td>
          <td data-label="Left the page"><?= $a->awayLabel() === null ? '<span class="muted">No</span>' : '<span class="away-flag">' . $v->e((string) $a->awayLabel()) . '</span>' ?></td>
          <td class="table-stack-action">
            <form method="post" action="<?= $base ?>/attempts/<?= $a->id ?>/extend" class="inline-form">
              <?= $v->csrfField() ?>
              <label class="visually-hidden" for="ext-<?= $a->id ?>">Minutes to add for <?= $v->e($a->userName) ?></label>
              <select id="ext-<?= $a->id ?>" name="minutes"><option value="5">+5 min</option><option value="10" selected>+10 min</option><option value="15">+15 min</option><option value="30">+30 min</option></select>
              <button type="submit" class="button button-small button-quiet">Add time</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($done !== []): ?>
<section aria-labelledby="done-h" class="section">
  <h2 id="done-h">Submitted</h2>
  <div class="table-wrap">
    <table class="table table-stack exam-scores">
      <thead><tr>
        <th scope="col">Candidate</th>
        <?php foreach ($labels as $l): ?><th scope="col" class="num"><?= $v->e($l) ?></th><?php endforeach; ?>
        <th scope="col" class="num">Total</th><th scope="col" class="num">Right</th><th scope="col">How</th><th scope="col">Left the page</th><th scope="col"><span class="visually-hidden">Actions</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($done as $a):
          $by = [];
          foreach ($a->result['sections'] ?? [] as $s) {
              $by[$s['label']] = $s;
          } ?>
        <tr>
          <th scope="row"><a href="<?= $base ?>/attempts/<?= $a->id ?>"><?= $v->e($a->userName) ?></a><span class="small muted"><?= $v->datetime($a->submittedAt, 'j M, H:i') ?>, <?= $a->minutesUsed() ?> min</span></th>
          <?php foreach ($labels as $l): ?><td data-label="<?= $v->e($l) ?>" class="num"><?= isset($by[$l]) ? $by[$l]['scaled'] . '<span class="small muted">' . $by[$l]['correct'] . '/' . $by[$l]['count'] . '</span>' : '<span class="muted">Not taken</span>' ?></td><?php endforeach; ?>
          <td data-label="Total" class="num"><strong><?= (int) ($a->result['total'] ?? 0) ?></strong><span class="small muted">of <?= (int) ($a->result['max'] ?? 0) ?></span></td>
          <td data-label="Right" class="num"><?= (int) $a->percent ?>%</td>
          <td data-label="How"><?= $v->e($reasons[(string) $a->submitReason] ?? '') ?></td>
          <td data-label="Left the page"><?= $a->awayLabel() === null ? '<span class="muted">No</span>' : '<span class="away-flag">' . $v->e((string) $a->awayLabel()) . '</span>' ?></td>
          <td class="table-stack-action">
            <form method="post" action="<?= $base ?>/attempts/<?= $a->id ?>/reset" class="inline">
              <?= $v->csrfField() ?>
              <button type="submit" class="linklike small danger-link">Let them sit again<span class="visually-hidden"> (<?= $v->e($a->userName) ?>)</span></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted">Each subject is scored out of 100 (right answers ÷ questions × 100), so the total is out of 100 for each subject sat. “Let them sit again” removes that attempt, for example after a power cut; it is recorded in Activity. “Left the page” counts the times the exam page was hidden or out of focus for 2 seconds or more (another tab or app, a minimised browser, a phone call); it can have innocent reasons, so ask before you act on it.</p>
</section>

<section aria-labelledby="items-h" class="section">
  <h2 id="items-h">How each question did</h2>
  <p class="small muted">Hardest first. A question most candidates get wrong, or where one wrong option is very popular, may be badly worded or have the wrong key: open it in its bank and check.</p>
  <div class="table-wrap">
    <table class="table table-stack items-table">
      <thead><tr><th scope="col">Question</th><th scope="col">Subject</th><th scope="col" class="num">Right</th><th scope="col">Answers chosen</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($items, 0, 200) as $it): $q = $it['question']; $share = $it['seen'] > 0 ? (int) round($it['right'] / $it['seen'] * 100) : 0; ?>
        <tr>
          <th scope="row"><?= $v->e(mb_strimwidth(trim((string) preg_replace(['/\*\*|`/', '/\s+/'], ['', ' '], $q->prompt)), 0, 140, '…')) ?></th>
          <td data-label="Subject"><?= $v->e($it['section']) ?></td>
          <td data-label="Right" class="num"><span class="item-share<?= $share < 30 ? ' is-low' : '' ?>"><?= $share ?>%</span><span class="small muted"><?= $it['right'] ?>/<?= $it['seen'] ?></span></td>
          <td data-label="Answers chosen">
            <span class="picks"><?php foreach ($it['picks'] as $i => $n): ?><span class="pick<?= $i === $q->correct ? ' is-key' : '' ?>"><?= BankQuestion::LETTERS[$i] ?> <?= $n ?><?= $i === $q->correct ? '<span class="visually-hidden"> (correct)</span>' : '' ?></span><?php endforeach; ?><?php if ($it['blank'] > 0): ?><span class="pick is-blank">blank <?= $it['blank'] ?></span><?php endif; ?></span>
          </td>
          <td class="table-stack-action"><a class="small" href="/teach/banks/<?= $q->bankId ?>/questions/<?= $q->id ?>">Open<span class="visually-hidden"> this question in its bank</span></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
