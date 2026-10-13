<?php
/**
 * Classes, cohorts or groups (the school's word).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var list<array{group: \App\Modules\Groups\LearnerGroup, learners: int, courses: int, teachers: list<string>}> $rows
 * @var bool $managesAll
 * @var bool $showingArchived
 * @var int $archivedCount
 * @var int $unplaced learners of the school not in any group
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$one = $school->groupWord();
$many = $school->groupWord(true);
$o = static fn (string $k): string => $old[$k] ?? '';
?>
<header class="page-head">
  <h1><?= $v->e($showingArchived ? 'Archived ' . $many : $school->groupWord(true, true)) ?></h1>
  <p class="lead"><?= $managesAll
      ? $v->e(sprintf('Put learners who study together in a %s, set the courses they take, and follow their progress side by side.', $one))
      : $v->e(sprintf('The %s you teach. Set their courses and follow their progress.', $many)) ?></p>
</header>
<?= $v->errorSummary($errors) ?>

<?php if ($managesAll && $unplaced > 0 && !$showingArchived): ?>
  <p class="notice"><?= $v->e(sprintf('%s not in any %s yet.', $v->plural($unplaced, 'learner is', 'learners are'), $one)) ?> Open a <?= $v->e($one) ?> to add them.</p>
<?php endif; ?>

<div class="groups-layout">
  <section aria-labelledby="list-h">
    <h2 id="list-h" class="visually-hidden"><?= $v->e($school->groupWord(true, true)) ?></h2>
    <?php if ($rows === []): ?>
      <?= $managesAll
          ? $v->emptyState($showingArchived ? 'Nothing archived' : sprintf('No %s yet', $many), $showingArchived ? sprintf('Archived %s show up here.', $many) : sprintf('Create your first %s with the form beside this, for example one for each arm of SS 2.', $one))
          : $v->emptyState(sprintf('You are not teaching a %s yet', $one), sprintf('An administrator adds teachers to each %s. Once you are added, it shows up here.', $one)) ?>
    <?php else: ?>
    <ul class="group-list">
      <?php foreach ($rows as $row): $g = $row['group']; ?>
      <li class="group-card">
        <h3><a href="/groups/<?= $g->id ?>"><?= $v->e($g->name) ?></a></h3>
        <?php if ($g->detailLine() !== null): ?><p class="group-card-detail"><?= $v->e($g->detailLine()) ?></p><?php endif; ?>
        <p class="group-card-counts"><span><?= $v->e($v->plural($row['learners'], 'learner')) ?></span><span><?= $v->e($v->plural($row['courses'], 'course')) ?></span></p>
        <p class="group-card-teachers small muted"><?= $row['teachers'] === [] ? 'No teacher yet' : $v->e('Taught by ' . implode(', ', $row['teachers'])) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($managesAll): ?>
      <p class="small"><?= $showingArchived
          ? '<a href="/groups">Back to current ' . $v->e($many) . '</a>'
          : ($archivedCount > 0 ? '<a href="/groups?archived=1">' . $v->e(sprintf('Archived %s (%d)', $many, $archivedCount)) . '</a>' : '') ?></p>
    <?php endif; ?>
  </section>

  <?php if ($managesAll && !$showingArchived): ?>
  <aside>
    <section class="panel" aria-labelledby="new-h">
      <h2 id="new-h">New <?= $v->e($one) ?></h2>
      <form method="post" action="/groups" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('name', 'Name', ['value' => $o('name'), 'error' => $errors['name'] ?? null, 'maxlength' => 80, 'hint' => 'For example SS 2 Gold, Primary 4B or January 2027 intake.']) ?>
        <?php if ($school->levels !== []): ?>
          <?= $v->field('level', 'Class or level', ['type' => 'select', 'options' => ['' => 'Not set'] + array_combine($school->levels, $school->levels), 'value' => $o('level'), 'error' => $errors['level'] ?? null]) ?>
        <?php else: ?>
          <?= $v->field('level', 'Class or level', ['value' => $o('level'), 'error' => $errors['level'] ?? null, 'optional' => true, 'maxlength' => 60]) ?>
        <?php endif; ?>
        <?= $v->field('period', 'Year or term', ['value' => $o('period'), 'error' => $errors['period'] ?? null, 'optional' => true, 'maxlength' => 60, 'hint' => 'For example 2026/2027.']) ?>
        <button type="submit" class="button button-small">Create <?= $v->e($one) ?></button>
      </form>
    </section>
  </aside>
  <?php endif; ?>
</div>
