<?php
/**
 * One class, cohort or group: progress side by side, its courses, learners and teachers.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var list<array{course: \App\Modules\Courses\Course, due_on: ?string}> $courses
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $teachers
 * @var array{lessons: array<int, int>, cells: array<int, array<int, array{status: string, done: int}>>, last_active: array<int, string>} $matrix
 * @var list<\App\Modules\Courses\Course> $available published courses not set yet
 * @var list<array{user_id: int, name: string, email: string}> $unplaced learners in no group
 * @var list<array{user_id: int, name: string, role: string}> $staff
 * @var bool $managesAll
 * @var list<array{id: int, body: string, author: string, author_id: ?int, created_at: string}> $announcements
 * @var string $today Y-m-d in the school's time zone
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$one = $school->groupWord();
$base = '/groups/' . $group->id;
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$archived = $group->isArchived();
$due = static fn (?string $d): ?string => $d === null ? null : (new DateTimeImmutable($d))->format('j M');
$teacherIds = array_column($teachers, 'user_id');
$behind = [];
?>
<?= $v->breadcrumbs([$school->groupWord(true, true) => '/groups', $group->name => null]) ?>
<header class="page-head page-head-row">
  <div>
    <h1><?= $v->e($group->name) ?></h1>
    <p class="page-head-meta">
      <?php if ($group->level !== null): ?><?= $v->badge($group->level, 'level') ?><?php endif; ?>
      <?php if ($group->periodLabel !== null): ?><?= $v->badge($group->periodLabel) ?><?php endif; ?>
      <?php if ($archived): ?><?= $v->badge('Archived', 'draft') ?><?php endif; ?>
      <span><?= $teachers === [] ? 'No teacher yet' : $v->e('Taught by ' . implode(', ', array_column($teachers, 'name'))) ?></span>
    </p>
  </div>
  <?php if ($learners !== [] && $courses !== []): ?><p class="page-head-actions"><a href="<?= $base ?>/progress.csv" download>Download progress</a></p><?php endif; ?>
</header>
<?= $v->errorSummary($errors) ?>

<?php if ($archived): ?>
<div class="notice">
  <p>This <?= $v->e($one) ?> is archived: it is hidden from learners' home pages and cannot be changed. Learners keep their courses and progress.</p>
  <?php if ($managesAll): ?>
  <form method="post" action="<?= $base ?>/archive">
    <?= $v->csrfField() ?><input type="hidden" name="restore" value="1">
    <button type="submit" class="button button-small">Restore this <?= $v->e($one) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="section" aria-labelledby="progress-h">
  <h2 id="progress-h">Progress</h2>
  <?php if ($learners === [] || $courses === []): ?>
    <p class="muted"><?= $v->e(match (true) {
        $learners === [] && $courses === [] => sprintf('Add learners and set a course, and each learner\'s progress shows here, course by course.'),
        $learners === [] => sprintf('Add learners to this %s to see their progress here.', $one),
        default => 'Set a course for these learners to see their progress here.',
    }) ?></p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table progress-grid">
      <thead>
        <tr>
          <th scope="col">Learner</th>
          <?php foreach ($courses as $c): ?>
            <th scope="col"><a href="/teach/courses/<?= $c['course']->id ?>/progress"><?= $v->e($c['course']->title) ?></a><?php if ($c['due_on'] !== null): ?><span class="small muted">Due <?= $v->e((string) $due($c['due_on'])) ?></span><?php endif; ?></th>
          <?php endforeach; ?>
          <th scope="col">Last active</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($learners as $l): ?>
        <tr>
          <th scope="row"><?= $v->e($l['name']) ?><span class="small muted"><?= $v->e($l['email']) ?></span></th>
          <?php foreach ($courses as $c):
              $cid = $c['course']->id;
              $cell = $matrix['cells'][$l['user_id']][$cid] ?? null;
              $total = $matrix['lessons'][$cid] ?? 0;
              $late = $c['due_on'] !== null && $c['due_on'] < $today && ($cell === null || $cell['status'] !== 'completed');
              if ($late) { $behind[$l['user_id']] = true; }
              ?>
            <td class="pg-cell<?= $late ? ' is-late' : '' ?>">
              <?php if ($cell === null): ?>
                <span class="muted small">Not enrolled</span>
              <?php elseif ($cell['status'] === 'completed'): ?>
                <?= $v->badge('Completed', 'done') ?>
              <?php else: ?>
                <span class="pg-count"><?= $cell['done'] ?> of <?= $total ?></span>
                <progress class="meter meter-thin" max="<?= max(1, $total) ?>" value="<?= $cell['done'] ?>" aria-label="<?= $v->e($l['name'] . ', ' . $c['course']->title) ?>"></progress>
              <?php endif; ?>
              <?php if ($late): ?><span class="pg-late">Past due</span><?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td><?= isset($matrix['last_active'][$l['user_id']]) ? $v->datetime($matrix['last_active'][$l['user_id']], 'j M') : '<span class="muted small">Not yet</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted"><?= $v->e($v->plural(count($learners), 'learner')) ?>. Lessons done out of each course's lessons.<?= $behind !== [] ? ' ' . $v->e(sprintf('%s a course past its due date.', $v->plural(count($behind), 'learner has', 'learners have'))) : '' ?></p>
  <?php endif; ?>
</section>

<section class="section announcements" aria-labelledby="ann-h">
  <h2 id="ann-h">Announcements</h2>
  <?php if (!$archived): ?>
  <form method="post" action="<?= $base ?>/announcements" class="form announce-form" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('announcement', 'Message to the learners in ' . $group->name, [
        'type' => 'textarea', 'rows' => 3, 'value' => $o('announcement'), 'error' => $errors['announcement'] ?? null,
        'hint' => 'They see it on their home page and get a notification. Keep marks and private matters out of it.',
    ]) ?>
    <button type="submit" class="button button-small">Post announcement</button>
  </form>
  <?php endif; ?>
  <?php if ($announcements === []): ?>
    <p class="muted small">Nothing posted yet.</p>
  <?php else: ?>
  <ol class="ann-list">
    <?php foreach ($announcements as $a): ?>
    <li class="ann">
      <div class="ann-meta"><strong><?= $v->e($a['author']) ?></strong> <span class="muted small"><?= $v->datetime($a['created_at']) ?></span></div>
      <?= $v->paragraphs($a['body']) ?>
      <?php if (!$archived): ?>
      <form method="post" action="<?= $base ?>/announcements/<?= $a['id'] ?>/delete" class="inline">
        <?= $v->csrfField() ?>
        <button type="submit" class="linklike small">Delete<span class="visually-hidden"> this announcement</span></button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>

<div class="settings">
  <section class="settings-group" aria-labelledby="courses-h">
    <div class="settings-head"><h2 id="courses-h">Courses</h2></div>
    <?php if ($courses === []): ?>
      <p class="muted">No courses set yet. Learners in this <?= $v->e($one) ?> are enrolled in every course you set here, including those who join later.</p>
    <?php else: ?>
    <ul class="plain-list">
      <?php foreach ($courses as $c): ?>
      <li class="set-course">
        <span><a href="/courses/<?= $v->e($c['course']->slug) ?>"><?= $v->e($c['course']->title) ?></a>
          <span class="small muted"><?= $c['due_on'] !== null ? 'Due ' . $v->e((string) $due($c['due_on'])) : 'No due date' ?><?= $c['course']->isPaid() ? ', paid by the school' : '' ?></span></span>
        <?php if (!$archived): ?>
        <form method="post" action="<?= $base ?>/courses/<?= $c['course']->id ?>/remove" class="inline">
          <?= $v->csrfField() ?>
          <button type="submit" class="linklike small">Remove<span class="visually-hidden"> <?= $v->e($c['course']->title) ?></span></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!$archived): ?>
    <details class="disclosure"<?= isset($errors['course_id']) || isset($errors['due_on']) ? ' open' : '' ?>>
      <summary>Set a course or change a due date</summary>
      <form method="post" action="<?= $base ?>/courses" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?php
          $options = ['' => 'Choose a course'];
          foreach ($courses as $c) {
              $options[(string) $c['course']->id] = $c['course']->title . ' (already set)';
          }
          foreach ($available as $c) {
              $options[(string) $c->id] = $c->title . ($c->isPaid() ? ' (' . $c->priceLabel() . ', the school covers it)' : '');
          }
        ?>
        <?= $v->field('course_id', 'Course', ['type' => 'select', 'options' => $options, 'value' => $o('course_id'), 'error' => $errors['course_id'] ?? null]) ?>
        <?= $v->field('due_on', 'Due date', ['type' => 'date', 'value' => $o('due_on'), 'error' => $errors['due_on'] ?? null, 'optional' => true]) ?>
        <button type="submit" class="button button-small">Set course</button>
      </form>
      <?php if (!$managesAll): ?><p class="small muted">Paid courses are set by an administrator.</p><?php endif; ?>
    </details>
    <?php endif; ?>
  </section>

  <section class="settings-group" aria-labelledby="learners-h">
    <div class="settings-head"><h2 id="learners-h">Learners</h2><span class="muted small"><?= count($learners) ?></span></div>
    <?php if (!$archived): ?>
    <details class="disclosure"<?= isset($errors['emails']) || $learners === [] ? ' open' : '' ?>>
      <summary>Add learners</summary>
      <form method="post" action="<?= $base ?>/learners" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?php if ($unplaced !== []): ?>
        <fieldset class="field tick-list" id="unplaced-list">
          <legend>Learners not in any <?= $v->e($one) ?> yet</legend>
          <?php if (count($unplaced) > 8): ?>
          <p class="filter-row">
            <label for="unplaced-find" hidden>Find a learner</label>
            <input type="search" id="unplaced-find" data-filter="unplaced-list" placeholder="Name or email" autocomplete="off" hidden>
            <span id="unplaced-list-count" class="small muted" role="status"></span>
          </p>
          <?php endif; ?>
          <?php foreach ($unplaced as $u): ?>
            <label class="choice choice-tight" data-filter-row><input type="checkbox" name="learners[]" value="<?= $u['user_id'] ?>"><span><?= $v->e($u['name']) ?> <span class="small muted"><?= $v->e($u['email']) ?></span></span></label>
          <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <?= $v->field('emails', $unplaced === [] ? 'Learners\' emails' : 'Or type their emails', [
            'type' => 'textarea', 'rows' => 3, 'value' => $o('emails'), 'error' => $errors['emails'] ?? null,
            'hint' => 'One per line, or separated by commas. They must already have an account here (People page).',
        ]) ?>
        <button type="submit" class="button button-small">Add to <?= $v->e($group->name) ?></button>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($learners !== []): ?>
    <details class="disclosure">
      <summary>Remove a learner</summary>
      <ul class="plain-list">
        <?php foreach ($learners as $l): ?>
        <li class="set-course"><span><?= $v->e($l['name']) ?></span>
          <?php if (!$archived): ?>
          <form method="post" action="<?= $base ?>/learners/<?= $l['user_id'] ?>/remove" class="inline">
            <?= $v->csrfField() ?>
            <button type="submit" class="linklike small">Remove<span class="visually-hidden"> <?= $v->e($l['name']) ?></span></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="small muted">Removed learners keep their courses and progress.</p>
    </details>
    <?php endif; ?>
  </section>

  <?php if ($managesAll): ?>
  <section class="settings-group" aria-labelledby="teachers-h">
    <div class="settings-head"><h2 id="teachers-h">Teachers</h2></div>
    <p class="small">Teachers see this <?= $v->e($one) ?>'s progress, add learners and set free courses.</p>
    <form method="post" action="<?= $base ?>/teachers" class="form" novalidate>
      <?= $v->csrfField() ?>
      <fieldset class="field tick-list"<?= isset($errors['teachers']) ? ' aria-describedby="teachers-error"' : '' ?>>
        <legend class="visually-hidden">Teachers</legend>
        <?php if (isset($errors['teachers'])): ?><p class="field-error" id="teachers-error"><?= $v->e($errors['teachers']) ?></p><?php endif; ?>
        <?php foreach ($staff as $s): ?>
          <label class="choice choice-tight"><input type="checkbox" name="teachers[]" value="<?= $s['user_id'] ?>"<?= in_array($s['user_id'], $teacherIds, true) ? ' checked' : '' ?><?= $archived ? ' disabled' : '' ?>><span><?= $v->e($s['name']) ?> <span class="small muted"><?= $v->e(ucfirst($s['role'])) ?></span></span></label>
        <?php endforeach; ?>
      </fieldset>
      <?php if (!$archived): ?><button type="submit" class="button button-small">Save teachers</button><?php endif; ?>
    </form>
  </section>

  <section class="settings-group" aria-labelledby="details-h">
    <div class="settings-head"><h2 id="details-h">Details</h2></div>
    <form method="post" action="<?= $base ?>/details" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('name', 'Name', ['value' => $o('name', $group->name), 'error' => $errors['name'] ?? null, 'maxlength' => 80]) ?>
      <?php $levels = $school->levels; if ($group->level !== null && !in_array($group->level, $levels, true)) { $levels[] = $group->level; } ?>
      <?php if ($levels !== []): ?>
        <?= $v->field('level', 'Class or level', ['type' => 'select', 'options' => ['' => 'Not set'] + array_combine($levels, $levels), 'value' => $o('level', $group->level ?? ''), 'error' => $errors['level'] ?? null]) ?>
      <?php else: ?>
        <?= $v->field('level', 'Class or level', ['value' => $o('level', $group->level ?? ''), 'error' => $errors['level'] ?? null, 'optional' => true, 'maxlength' => 60]) ?>
      <?php endif; ?>
      <?= $v->field('period', 'Year or term', ['value' => $o('period', $group->periodLabel ?? ''), 'error' => $errors['period'] ?? null, 'optional' => true, 'maxlength' => 60]) ?>
      <button type="submit" class="button button-small">Save details</button>
    </form>
    <?php if (!$archived): ?>
    <form method="post" action="<?= $base ?>/archive" class="form-inline-end">
      <?= $v->csrfField() ?>
      <p class="small muted">At the end of the year, archive the <?= $v->e($one) ?>. Learners keep their courses and progress.</p>
      <button type="submit" class="button button-small button-quiet">Archive <?= $v->e($group->name) ?></button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
