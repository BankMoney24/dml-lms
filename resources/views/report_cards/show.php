<?php
/**
 * A report card's broadsheet for the class teacher (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var array{subjects: list<string>, components: list<array{exam: ?\App\Modules\Exams\Cbt\CbtExam, label: string, weight: int}>, rows: array<int, array{subjects: array<string, array{parts: array<int, int|string>, score: ?int, grade: ?string, word: ?string, position: ?int}>, average: ?float, position: ?int, absent: list<string>, attendance: ?array{held: int, present: int, late: int, rate: ?int}}>, ranked: int, subjectCounts: array<string, int>} $sheet
 * @var array<int, array{teacher: string, head: string}> $remarks
 */
use App\Modules\ReportCards\Grading;

$base = '/groups/' . $group->id . '/report-cards/' . $card->id;
$written = count(array_filter($learners, static fn (array $l): bool => ($remarks[$l['user_id']]['teacher'] ?? '') !== ''));
?>
<?= $v->breadcrumbs([$group->name => '/groups/' . $group->id, 'Report cards' => '/groups/' . $group->id . '/report-cards', $card->title => null]) ?>
<header class="page-head page-head-row">
  <div>
    <h1><?= $v->e($card->title) ?></h1>
    <p class="page-head-meta"><?= $card->isPublished() ? $v->badge('Shared ' . $v->datetime((string) $card->publishedAt, 'j M'), 'ok') : $v->badge('Draft: not shared yet', 'draft') ?>
      <span><?= $v->e(implode(', ', array_map(static fn (array $c): string => $c['label'] . ' ' . $c['weight'] . '%', $sheet['components']))) ?></span></p>
  </div>
  <p class="page-actions">
    <a class="button button-quiet" href="<?= $base ?>/edit">Change</a>
    <?php if ($learners !== []): ?>
    <a class="button button-quiet" href="<?= $base ?>/print"><?= $v->icon('print') ?>Print all cards</a>
    <a class="button button-quiet" href="<?= $base ?>/broadsheet.csv" download><?= $v->icon('download') ?>Download broadsheet</a>
    <?php endif; ?>
  </p>
</header>

<?php if ($learners === []): ?>
  <?= $v->emptyState('No learners in this class', 'Add learners to the class to see their report cards.', '/groups/' . $group->id, 'Back to the class') ?>
<?php else: ?>
<div class="table-wrap">
  <table class="table progress-grid rc-broadsheet" data-sortable>
    <thead><tr>
      <th scope="col">Learner</th>
      <?php foreach ($sheet['subjects'] as $s): ?><th scope="col" class="num"><?= $v->e($s) ?></th><?php endforeach; ?>
      <th scope="col" class="num">Average</th><th scope="col" class="num">Position</th><th scope="col">Remark</th>
    </tr></thead>
    <tbody>
    <?php foreach ($learners as $l): $r = $sheet['rows'][$l['user_id']]; ?>
      <tr>
        <th scope="row"><a href="<?= $base ?>/learners/<?= $l['user_id'] ?>"><?= $v->e($l['name']) ?></a><?php if ($r['absent'] !== []): ?><span class="small rc-absent">Absent: <?= $v->e(implode(', ', $r['absent'])) ?></span><?php endif; ?></th>
        <?php foreach ($sheet['subjects'] as $s): $cell = $r['subjects'][$s] ?? null; ?>
          <td class="num" data-sort="<?= $cell['score'] ?? -1 ?>"><?php if ($cell === null || $cell['score'] === null): ?><span class="muted">–</span><?php else: ?><?= $cell['score'] ?><span class="small muted"><?= $v->e((string) $cell['grade']) ?></span><?php endif; ?></td>
        <?php endforeach; ?>
        <td class="num" data-sort="<?= $r['average'] ?? -1 ?>"><strong><?= $r['average'] === null ? '–' : $v->e(number_format($r['average'], 1)) ?></strong></td>
        <td class="num" data-sort="<?= $r['position'] ?? 9999 ?>"><?= $r['position'] === null ? '–' : $v->e(Grading::ordinal($r['position'])) ?></td>
        <td><a class="small" href="<?= $base ?>/learners/<?= $l['user_id'] ?>"><?= ($remarks[$l['user_id']]['teacher'] ?? '') !== '' ? 'Written' : 'Write' ?><span class="visually-hidden"> the remark for <?= $v->e($l['name']) ?></span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small muted">Scores out of 100, from each learner's latest attempt at each exam. A learner who missed an exam scores 0 for its subjects; a subject they did not choose is left out. Positions share a place on a tie. Remarks written: <?= $written ?> of <?= count($learners) ?>.</p>

<section class="section panel rc-share" aria-labelledby="share-h">
  <h2 id="share-h"><?= $card->isPublished() ? 'Shared with the class' : 'Share with the class' ?></h2>
  <?php if ($card->isPublished()): ?>
    <p>Each learner sees their own card under Report cards, and parents who may see results see their child's. Changes to scores and remarks show straight away.</p>
    <form method="post" action="<?= $base ?>/unpublish"><?= $v->csrfField() ?><button type="submit" class="button button-quiet">Take it back</button></form>
  <?php else: ?>
    <p>When you share it, each learner sees only their own card and gets a notification; so do parents who may see results. Write the remarks first: <?= $written ?> of <?= count($learners) ?> done.</p>
    <form method="post" action="<?= $base ?>/publish"><?= $v->csrfField() ?><button type="submit" class="button">Share with the class</button></form>
    <form method="post" action="<?= $base ?>/delete" class="inline-form"><?= $v->csrfField() ?><button type="submit" class="linklike small danger-link">Delete this report card</button></form>
  <?php endif; ?>
</section>
<?php endif; ?>
