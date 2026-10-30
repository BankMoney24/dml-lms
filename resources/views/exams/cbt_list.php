<?php
/**
 * Teach → Quizzes and exams → CBT exams (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{exam: \App\Modules\Exams\Cbt\CbtExam, attempts: int, submitted: int, in_progress: int}> $rows
 * @var string $now
 * @var bool $hasBanks
 */
$testsTab = 'exams';
$statusBadge = static function (\App\Modules\Exams\Cbt\CbtExam $e, string $now) use ($v): string {
    if ($e->status === 'draft') {
        return $v->badge('Draft', 'draft');
    }
    if ($e->status === 'closed' || ($e->closesAt !== null && $e->closesAt <= $now)) {
        return $v->badge('Closed', 'neutral');
    }
    if ($e->opensAt !== null && $e->opensAt > $now) {
        return $v->badge('Opens ' . $v->datetime($e->opensAt, 'j M, H:i'), 'level');
    }

    return $v->badge('Open', 'ok');
};
?>
<header class="page-head page-head-row">
  <div>
    <h1>CBT exams</h1>
    <p class="lead">Computer-based tests on the school’s own question banks: timed, marked on the server, with each subject scored out of 100 as in the UTME.</p>
  </div>
  <?php if ($hasBanks): ?>
  <p class="page-actions">
    <a class="button" href="/teach/exams/new">New exam</a>
    <a class="button button-quiet" href="/teach/exams/new?layout=utme">Start from the UTME layout</a>
  </p>
  <?php endif; ?>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<?php if (!$hasBanks): ?>
  <?= $v->emptyState('First, a question bank', 'An exam draws its questions from banks, one per subject. Make a bank and add questions, or add the sample banks to try it out.', '/teach/banks', 'Go to question banks') ?>
<?php elseif ($rows === []): ?>
  <?= $v->emptyState('No exams yet', 'Set a mock exam, a test at the end of a topic, or a full UTME-style practice: English plus three subjects, two hours.', '/teach/exams/new?layout=utme', 'Start from the UTME layout') ?>
<?php else: ?>
<div class="table-wrap">
  <table class="table table-stack">
    <thead><tr><th scope="col">Exam</th><th scope="col">Status</th><th scope="col">Paper</th><th scope="col">Candidates</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): $e = $row['exam']; ?>
      <tr>
        <th scope="row"><a href="/teach/exams/<?= $e->id ?>"><?= $v->e($e->title) ?></a></th>
        <td data-label="Status"><?= $statusBadge($e, $now) ?></td>
        <td data-label="Paper"><?= $v->e($e->questionsLabel()) ?> questions, <?= $e->durationMinutes ?> min<span class="small muted"><?= $v->e(implode(', ', array_column($e->sections, 'label'))) ?><?= $e->chooseCount > 0 ? '; choose ' . $e->chooseCount : '' ?></span></td>
        <td data-label="Candidates"><?= $row['submitted'] ?> submitted<?php if ($row['in_progress'] > 0): ?><span class="small"><?= $row['in_progress'] ?> sitting now</span><?php endif; ?></td>
        <td class="table-stack-action"><?php if ($row['attempts'] > 0): ?><a class="button button-small button-quiet" href="/teach/exams/<?= $e->id ?>/results">Results<span class="visually-hidden"> for <?= $v->e($e->title) ?></span></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
