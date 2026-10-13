<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var ?array{card: array<string, mixed>, next: ?\App\Modules\Courses\Lesson} $continue */
/** @var list<array<string, mixed>> $myCards */
/** @var list<array<string, mixed>> $suggestedCards */
/** @var list<\App\Modules\Certificates\Certificate> $certificates */
/** @var bool $setupPending */
/** @var list<array{group: \App\Modules\Groups\LearnerGroup, teachers: list<string>, courses: list<array{course: \App\Modules\Courses\Course, due_on: ?string}>, announcements?: list<array{id: int, body: string, author: string, author_id: ?int, created_at: string}>, progress: array{lessons: array<int, int>, cells: array<int, array{status: string, done: int}>}}> $myGroups */
/** @var string $today */
/** @var ?array{groups?: list<array{group: \App\Modules\Groups\LearnerGroup, learners: int, courses: int, teachers: list<string>}>, published: int, drafts: int, reviews: int, courses: list<array{course: \App\Modules\Courses\Course, lessons: int, enrolled: int, completed: int, active: int}>} $teaching */
$user = $v->shared('user');
$firstName = explode(' ', trim($user->displayName))[0];
$hasLearning = $continue !== null || $myCards !== [];
?>
<header class="page-head">
  <h1>Hello, <?= $v->e($firstName) ?></h1>
  <?php if ($continue === null && $teaching === null): ?>
    <p class="lead"><?= $hasLearning ? 'You have finished everything you started. Pick something new below.' : 'Choose a course to begin.' ?></p>
  <?php endif; ?>
</header>

<?php if ($setupPending): ?>
<section class="setup-nudge" aria-labelledby="setup-nudge-h">
  <div>
    <h2 id="setup-nudge-h">Finish setting up your school</h2>
    <p>Choose your school's type, name, colour and features, and invite your team. It takes a few minutes, and you can stop at any point.</p>
  </div>
  <a class="button" href="/setup/school">Set up the school</a>
</section>
<?php endif; ?>

<?php if ($continue !== null): $card = $continue['card']; $c = $card['course']; $pct = $card['lessons'] === 0 ? 0 : intdiv($card['done'] * 100, $card['lessons']); ?>
<section class="continue" aria-labelledby="continue-h">
  <p class="continue-kicker" id="continue-h">Continue where you stopped</p>
  <h2 class="continue-title"><a href="/courses/<?= $v->e($c->slug) ?>"><?= $v->e($c->title) ?></a></h2>
  <?php include __DIR__ . '/../courses/_board.php'; ?>
  <p class="continue-progress"><?= $v->e($card['done'] . ' of ' . $v->plural($card['lessons'], 'lesson') . ' done') ?> (<?= $pct ?>%)</p>
  <?php if ($continue['next'] !== null): $next = $continue['next']; ?>
    <p class="continue-next">
      <a class="button" href="/courses/<?= $v->e($c->slug) ?>/lessons/<?= $next->id ?>">Continue: <?= $v->e($next->title) ?></a>
      <span class="muted small"><?= $v->e($next->typeLabel()) ?><?= $next->type === 'text' ? ', ' . $next->readingMinutes() . ' min read' : '' ?></span>
    </p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php $tenantNow = $v->shared('tenant'); foreach ($myGroups as $mg): $g = $mg['group']; ?>
<section class="section my-group" aria-labelledby="my-group-<?= $g->id ?>">
  <div class="my-group-head">
    <h2 id="my-group-<?= $g->id ?>">Your <?= $v->e($tenantNow?->groupWord() ?? 'class') ?>: <?= $v->e($g->name) ?></h2>
    <?php if ($mg['teachers'] !== []): ?><p class="small muted">Taught by <?= $v->e(implode(', ', $mg['teachers'])) ?></p><?php endif; ?>
  </div>
  <?php foreach (array_slice($mg['announcements'] ?? [], 0, 2) as $a): ?>
    <div class="ann ann-home">
      <div class="ann-meta"><strong><?= $v->e($a['author']) ?></strong> <span class="muted small"><?= $v->datetime($a['created_at']) ?></span></div>
      <?= $v->paragraphs($a['body']) ?>
    </div>
  <?php endforeach; ?>
  <?php if ($mg['courses'] === []): ?>
    <p class="muted">Your teacher has not set any courses yet.</p>
  <?php else: ?>
  <ol class="set-list">
    <?php foreach ($mg['courses'] as $sc):
        $course = $sc['course'];
        $cell = $mg['progress']['cells'][$course->id] ?? null;
        $total = $mg['progress']['lessons'][$course->id] ?? 0;
        $done = $cell !== null && $cell['status'] === 'completed';
        $late = !$done && $sc['due_on'] !== null && $sc['due_on'] < $today;
        $dueText = $sc['due_on'] === null ? null : (new DateTimeImmutable($sc['due_on']))->format('j M');
        ?>
    <li class="set-item<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?>">
      <a class="set-title" href="/courses/<?= $v->e($course->slug) ?>"><?= $v->e($course->title) ?></a>
      <span class="set-due"><?= $done ? 'Completed' : ($dueText === null ? 'No due date' : ($late ? 'Was due ' . $v->e($dueText) : 'Due ' . $v->e($dueText))) ?></span>
      <?php if (!$done): ?>
        <span class="set-progress small"><?= $cell === null ? 'Not started' : $v->e($cell['done'] . ' of ' . $v->plural($total, 'lesson')) ?></span>
        <progress class="meter meter-thin" max="<?= max(1, $total) ?>" value="<?= $cell['done'] ?? 0 ?>" aria-label="<?= $v->e($course->title) ?> progress"></progress>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($teaching !== null): ?>
<section class="section teaching-strip" aria-labelledby="teaching">
  <h2 id="teaching">Teaching</h2>
  <?php $tgroups = $teaching['groups'] ?? []; $groupWord = $v->shared('tenant')?->groupWord(true) ?? 'groups'; ?>
  <div class="staff-grid<?= $tgroups === [] ? ' is-single' : '' ?>">
    <div>
      <h3 class="staff-sub">To do</h3>
      <ul class="todo-list">
        <?php if ($teaching['reviews'] > 0): ?>
          <li class="todo-urgent"><a href="/teach/reviews"><?= $v->e($v->plural($teaching['reviews'], 'assignment')) ?> waiting for your review</a></li>
        <?php endif; ?>
        <?php if ($teaching['drafts'] > 0): ?>
          <li><a href="/teach"><?= $v->e($v->plural($teaching['drafts'], 'draft course')) ?> to finish and publish</a></li>
        <?php endif; ?>
        <li><a href="/teach/courses/new">Start a new course</a></li>
        <?php if ($v->can('members.manage')): ?><li><a href="/people">Add people</a></li><?php endif; ?>
      </ul>
    </div>
    <?php if ($tgroups !== []): ?>
    <div>
      <h3 class="staff-sub">Your <?= $v->e($groupWord) ?></h3>
      <ul class="mini-groups">
        <?php foreach (array_slice($tgroups, 0, 6) as $tg): ?>
        <li><a href="/groups/<?= $tg['group']->id ?>"><span class="mini-group-name"><?= $v->e($tg['group']->name) ?></span><span class="small muted"><?= $v->e($v->plural($tg['learners'], 'learner') . ', ' . $v->plural($tg['courses'], 'course')) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($tgroups) > 6): ?><p class="small"><a href="/groups">All <?= $v->e($groupWord) ?></a></p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($teaching['courses'] !== []): ?>
  <h3 class="staff-sub staff-sub-gap">Courses <span class="muted small"><?= $v->e($v->plural($teaching['published'], 'published course')) ?><?= $teaching['drafts'] > 0 ? ', ' . $v->e($v->plural($teaching['drafts'], 'draft')) : '' ?></span></h3>
  <div class="table-wrap teaching-table">
  <table class="table table-stack">
    <caption class="visually-hidden">Learners in each course</caption>
    <thead><tr>
      <th scope="col">Course</th>
      <th scope="col" class="num">Learners</th>
      <th scope="col" class="num">Finished</th>
      <th scope="col" class="num">Active this week</th>
      <th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($teaching['courses'] as $row): $tc = $row['course']; ?>
      <tr>
        <td>
          <a href="/teach/courses/<?= $tc->id ?>"><?= $v->e($tc->title) ?></a>
          <?php if (!$tc->isPublished()): ?> <?= $v->badge('Draft', 'draft') ?><?php endif; ?>
          <span class="small muted teaching-lessons"><?= $v->e($v->plural($row['lessons'], 'lesson')) ?></span>
        </td>
        <td class="num" data-label="Learners"><?= $row['enrolled'] ?></td>
        <td class="num" data-label="Finished"><?= $row['completed'] ?></td>
        <td class="num" data-label="This week"><?= $row['active'] ?></td>
        <td class="num table-stack-action"><?php if ($row['enrolled'] > 0 && $v->can('reports.view')): ?><a href="/teach/courses/<?= $tc->id ?>/progress">Progress</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($myCards !== []): ?>
<section class="section" aria-labelledby="mine">
  <h2 id="mine"><?= $continue !== null ? 'Your other courses' : 'Your courses' ?></h2>
  <?php $cards = $myCards; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php endif; ?>

<?php if ($certificates !== []): ?>
<section class="section" aria-labelledby="certs">
  <h2 id="certs">Your certificates</h2>
  <ul class="cert-list">
    <?php foreach ($certificates as $cert): ?>
    <li>
      <a href="/certificates/<?= $v->e($cert->displayCode()) ?>"><?= $v->e($cert->courseTitle) ?></a>
      <span class="small muted">Issued <?= $v->datetime($cert->issuedAt, 'j M Y') ?><?= $cert->isValid() ? '' : ', revoked' ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($suggestedCards !== []): ?>
<section class="section" aria-labelledby="join">
  <h2 id="join"><?= $hasLearning ? 'More courses' : 'Courses you can join' ?></h2>
  <?php $cards = $suggestedCards; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php elseif (!$hasLearning && $teaching === null && $v->can('course.learn')): ?>
  <?= $v->emptyState('No courses yet', 'Your school has not published any courses. Check back soon, or ask your teacher which course to join.') ?>
<?php endif; ?>
