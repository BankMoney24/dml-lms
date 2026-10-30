<?php
/**
 * The class's CBT exams on the class page (ADR 0045): each learner's latest score,
 * the class average, and how often a candidate left the exam page.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var array{list: list<\App\Modules\Exams\Cbt\CbtExam>, cells: array<int, array<int, array{percent: ?int, total: ?int, max: ?int, done: bool, away: int}>>} $exams
 */
?>
<section class="section" aria-labelledby="class-exams-h">
  <div class="section-head">
    <h2 id="class-exams-h">Exams</h2>
    <?php if ($exams['list'] !== [] && $learners !== []): ?><a class="button button-small button-quiet" href="/groups/<?= $group->id ?>/exams.csv" download><?= $v->icon('download') ?>Download exam results</a><?php endif; ?>
  </div>
  <?php if ($exams['list'] === []): ?>
    <p class="muted">No CBT exams are set for this class yet. Set one under <a href="/teach/exams">Quizzes and exams → CBT exams</a>, for everyone or for this class.</p>
  <?php elseif ($learners === []): ?>
    <p class="muted">Add learners to see their exam results here.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table progress-grid class-exams" data-sortable>
      <thead>
        <tr>
          <th scope="col">Learner</th>
          <?php foreach ($exams['list'] as $e): ?>
            <th scope="col"><a href="/teach/exams/<?= $e->id ?>/results"><?= $v->e($e->title) ?></a><span class="small muted"><?= $e->status === 'closed' ? 'Closed' : 'Open' ?></span></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($learners as $l): ?>
        <tr>
          <th scope="row"><?= $v->e($l['name']) ?><span class="small muted"><?= $v->e($l['email']) ?></span></th>
          <?php foreach ($exams['list'] as $e): $cell = $exams['cells'][$l['user_id']][$e->id] ?? null; ?>
            <td data-sort="<?= $cell !== null && $cell['done'] ? (int) $cell['percent'] : -1 ?>">
              <?php if ($cell === null): ?>
                <span class="muted small">Not sat</span>
              <?php elseif (!$cell['done']): ?>
                <?= $v->badge('Sitting now', 'level') ?>
              <?php else: ?>
                <span class="pg-count"><?= (int) $cell['total'] ?> of <?= (int) $cell['max'] ?></span>
                <span class="small"><?= (int) $cell['percent'] ?>% right</span>
                <?php if ($cell['away'] > 0): ?><span class="small away-flag">Left the page <?= $cell['away'] === 1 ? 'once' : $cell['away'] . ' times' ?></span><?php endif; ?>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th scope="row">Class average</th>
          <?php foreach ($exams['list'] as $e):
              $done = array_values(array_filter(array_map(static fn (array $l): ?array => $exams['cells'][$l['user_id']][$e->id] ?? null, $learners), static fn (?array $c): bool => $c !== null && $c['done']));
              ?>
            <td><?php if ($done === []): ?><span class="muted small">No results yet</span><?php else: ?><?= (int) round(array_sum(array_column($done, 'percent')) / count($done)) ?>% right<span class="small muted"><?= count($done) ?> of <?= count($learners) ?> sat</span><?php endif; ?></td>
          <?php endforeach; ?>
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="small muted">Each learner's latest attempt. Totals are out of 100 for each subject sat. Teachers see scores even when an exam holds them back from candidates.</p>
  <?php endif; ?>
</section>
