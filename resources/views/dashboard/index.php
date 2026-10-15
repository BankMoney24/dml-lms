<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var ?array{card: array<string, mixed>, next: ?\App\Modules\Courses\Lesson} $continue */
/** @var list<array<string, mixed>> $myCards */
/** @var list<array<string, mixed>> $suggestedCards */
/** @var list<\App\Modules\Certificates\Certificate> $certificates */
/** @var bool $setupPending */
/** @var list<array{group: \App\Modules\Groups\LearnerGroup, teachers: list<string>, courses: list<array{course: \App\Modules\Courses\Course, due_on: ?string}>, announcements?: list<array{id: int, body: string, author: string, author_id: ?int, created_at: string}>, progress: array{lessons: array<int, int>, cells: array<int, array{status: string, done: int}>}}> $myGroups */
/** @var string $today */
/** @var list<\App\Modules\Guardians\GuardianLink> $children */
/** @var ?array{groups?: list<array{group: \App\Modules\Groups\LearnerGroup, learners: int, courses: int, teachers: list<string>}>, published: int, drafts: int, reviews: int, courses: list<array{course: \App\Modules\Courses\Course, lessons: int, enrolled: int, completed: int, active: int}>} $teaching */
$user = $v->shared('user');
$firstName = explode(' ', trim($user->displayName))[0];
$hasLearning = $continue !== null || $myCards !== [];
/** @var ?array{weeks: list<list<array{date: string, count: int, level: int, future: bool}>>, months: list<array{column: int, label: string}>, streak: int, today: bool, active_days: int, this_week: int, best_streak: int} $activity */
/** @var list<array{course_id: int, slug: string, title: string, due_on: string, group: string, started: bool}> $comingUp */
/** @var list<array{lesson_id: int, lesson_title: string, course_slug: string, course_title: string, saved: bool, body: string, updated_at: string}> $recentNotes */
$activity ??= null;
/** @var ?\App\Modules\Joining\LearnerProfile $profile */
$profile ??= null;
$cardReasons ??= [];
$showOnboarding ??= false;
$justOnboarded ??= false;
$confirmEmail ??= null;
$comingUp ??= [];
$recentNotes ??= [];
$zone = new DateTimeZone($v->shared('tenant')?->timezone ?? 'UTC');
$now = new DateTimeImmutable('now', $zone);
$hour = (int) $now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$myPaths ??= [];
$earned ??= [];
$hasSide = $activity !== null || $comingUp !== [] || $recentNotes !== [] || $earned !== [];
$hasMain = $continue !== null || $children !== [] || $myGroups !== [] || $myPaths !== [];
?>
<header class="home-head">
  <p class="home-date"><?= $v->e($now->format('l j F')) ?></p>
  <h1><?= $v->e($greeting) ?>, <?= $v->e($firstName) ?></h1>
  <?php if ($continue === null && $teaching === null): ?>
    <p class="lead"><?= $hasLearning ? 'You have finished everything you started. Pick something new below.' : ($children !== [] ? 'See how your ' . (count($children) === 1 ? 'child is' : 'children are') . ' getting on.' : 'Choose a course to begin.') ?></p>
  <?php endif; ?>
</header>

<?php if ($justOnboarded): ?>
<p class="notice notice-done" role="status">Thanks. <?= $cardReasons !== [] ? 'The courses below are ordered by what you told us.' : 'We will suggest courses as your school adds them.' ?><?= $profile?->weeklyGoal !== null ? ' Your weekly goal is ' . $v->e($v->plural((int) $profile->weeklyGoal, 'day')) . '.' : '' ?></p>
<?php endif; ?>
<?php if ($confirmEmail !== null): ?>
<div class="notice confirm-email">
  <p>Please confirm <strong><?= $v->e($confirmEmail) ?></strong> with the link we emailed you. It lets you get back in if you forget your password.</p>
  <form method="post" action="/account/confirm"><?= $v->csrfField() ?><button type="submit" class="linklike small">Send the link again</button></form>
</div>
<?php endif; ?>
<?php if ($showOnboarding): ?>
<section class="onboard-card" aria-labelledby="onboard-h">
  <div class="onboard-text">
    <h2 id="onboard-h">What do you want to learn?</h2>
    <p>Answer three quick questions and we will put the right courses first, from complete beginner to working programmer.</p>
  </div>
  <div class="onboard-actions">
    <a class="button" href="/setup/welcome"><?= $v->icon('sparkle') ?>Get started</a>
    <form method="post" action="/welcome/skip"><?= $v->csrfField() ?><button type="submit" class="linklike small">Not now</button></form>
  </div>
</section>
<?php endif; ?>
<?php if ($setupPending): ?>
<section class="setup-nudge" aria-labelledby="setup-nudge-h">
  <div>
    <h2 id="setup-nudge-h">Finish setting up your school</h2>
    <p>Choose your school's type, name, colour and features, and invite your team. It takes a few minutes, and you can stop at any point.</p>
  </div>
  <a class="button" href="/setup/school">Set up the school</a>
</section>
<?php endif; ?>

<?php if ($hasMain || $hasSide): ?>
<div class="home-grid<?= $hasSide ? '' : ' is-single' ?><?= $hasMain ? '' : ' is-side-only' ?>">
  <?php if ($hasMain): ?>
  <div class="home-main">
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

<?php if (($myPaths ?? []) !== []): ?>
<section class="section my-paths" aria-labelledby="paths-h">
  <h2 id="paths-h">Your learning paths</h2>
  <ul class="my-path-list">
    <?php foreach ($myPaths as ['path' => $p, 'progress' => $pr]): ?>
    <li class="my-path<?= $pr['complete'] ? ' is-done' : '' ?>">
      <div class="my-path-head">
        <a href="/paths/<?= $v->e($p->slug) ?>"><?= $v->e($p->title) ?></a>
        <span class="small muted"><?= $pr['completed'] ?> of <?= $pr['total'] ?> courses</span>
      </div>
      <span class="my-path-steps" aria-hidden="true"><?php foreach ($pr['steps'] as $st): ?><span class="is-<?= $v->e($st['state']) ?>"></span><?php endforeach; ?></span>
      <?php if ($pr['next'] !== null): ?><p class="small my-path-next">Next: <a href="/courses/<?= $v->e($pr['next']->slug) ?>"><?= $v->e($pr['next']->title) ?></a></p><?php else: ?><p class="small my-path-next">Path complete</p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($children !== []): ?>
<section class="section" aria-labelledby="children-h">
  <h2 id="children-h"><?= count($children) === 1 ? 'Your child' : 'Your children' ?></h2>
  <?php include __DIR__ . '/../guardians/_child_list.php'; ?>
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

  </div>
  <?php endif; ?>
  <?php if ($hasSide): ?>
  <aside class="home-side" aria-label="Your study">
    <?php if ($activity !== null): ?>
    <section class="panel study-panel" aria-labelledby="streak-h">
      <h2 id="streak-h">Your learning</h2>
      <p class="streak-line">
      <?php if ($activity['streak'] > 0 && $activity['today']): ?>
        <strong><?= $v->e($v->plural($activity['streak'], 'day')) ?> in a row</strong>, today included. Keep going tomorrow.
      <?php elseif ($activity['streak'] > 0): ?>
        <strong><?= $v->e($v->plural($activity['streak'], 'day')) ?> in a row.</strong> Open a lesson today to make it <?= $activity['streak'] + 1 ?>.
      <?php elseif ($activity['active_days'] > 0): ?>
        Open a lesson today to start a new run of days.
      <?php else: ?>
        Each day you learn lights up a square here.
      <?php endif; ?>
      </p>
      <?php $weeksCount = count($activity['weeks']); $monthAt = array_column($activity['months'], 'label', 'column'); ?>
      <figure class="pixel-cal" aria-label="<?= $v->e('Active on ' . $v->plural($activity['active_days'], 'day') . ' in the last ' . $weeksCount . ' weeks') ?>">
        <div class="pc-months" aria-hidden="true"><?php for ($w = 0; $w < $weeksCount; $w++): ?><span><?= isset($monthAt[$w]) ? $v->e($monthAt[$w]) : '' ?></span><?php endfor; ?></div>
        <div class="pc-body" aria-hidden="true">
          <div class="pc-days"><span>Mon</span><span></span><span>Wed</span><span></span><span>Fri</span><span></span><span></span></div>
          <div class="pc-grid">
          <?php foreach ($activity['weeks'] as $week): ?><div class="pc-week"><?php foreach ($week as $cell): ?><span class="pc-day lvl-<?= $cell['level'] ?><?= $cell['future'] ? ' is-future' : '' ?><?= $cell['date'] === $now->format('Y-m-d') ? ' is-today' : '' ?>"<?php if (!$cell['future']): ?> title="<?= $v->e((new DateTimeImmutable($cell['date']))->format('D j M') . ': ' . ($cell['count'] === 0 ? 'no activity' : $v->plural($cell['count'], 'thing') . ' done')) ?>"<?php endif; ?>></span><?php endforeach; ?></div><?php endforeach; ?>
          </div>
        </div>
      </figure>
      <div class="pc-foot">
        <?php if ($profile?->weeklyGoal !== null): $goal = (int) $profile->weeklyGoal; $met = min($goal, $activity['this_week']); ?>
        <div class="week-goal">
          <p class="small"><strong><?= $met ?> of <?= $goal ?></strong> days this week<?= $activity['this_week'] >= $goal ? ': goal reached' : '' ?></p>
          <span class="week-pips" aria-hidden="true"><?php for ($i = 1; $i <= $goal; $i++): ?><span class="<?= $i <= $met ? 'is-on' : '' ?>"></span><?php endfor; ?></span>
        </div>
        <?php else: ?>
        <p class="small muted">This week: <?= $v->e($v->plural($activity['this_week'], 'day')) ?>.</p>
        <?php endif; ?>
        <p class="small muted">Longest run: <?= $v->e($v->plural($activity['best_streak'], 'day')) ?>.</p>
        <p class="pc-legend" aria-hidden="true"><span>Less</span><span class="pc-day lvl-0"></span><span class="pc-day lvl-1"></span><span class="pc-day lvl-2"></span><span class="pc-day lvl-3"></span><span class="pc-day lvl-4"></span><span>More</span></p>
      </div>
    </section>
    <?php endif; ?>
    <?php if ($earned !== []): $badges = \App\Modules\Achievements\Achievements::BADGES; ?>
    <section class="panel badge-panel" aria-labelledby="badges-h">
      <h2 id="badges-h">Achievements <span class="muted small"><?= count($earned) ?> of <?= count($badges) ?></span></h2>
      <ul class="badge-row">
        <?php foreach (array_slice(array_reverse($earned, true), 0, 4, true) as $code => $at): ?>
        <li class="badge-mini" title="<?= $v->e($badges[$code][0]) ?>"><?= $v->icon($badges[$code][2]) ?><span class="visually-hidden"><?= $v->e($badges[$code][0]) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="small"><a href="/achievements">See all achievements</a></p>
    </section>
    <?php endif; ?>

    <?php if ($comingUp !== []): ?>
    <section class="panel" aria-labelledby="due-h">
      <h2 id="due-h">Coming up</h2>
      <ul class="due-list">
        <?php foreach ($comingUp as $d):
            $date = new DateTimeImmutable($d['due_on'], $zone);
            $days = (int) (new DateTimeImmutable($now->format('Y-m-d'), $zone))->diff($date)->format('%r%a');
            $when = match (true) {
                $days === 0 => 'Due today',
                $days === 1 => 'Due tomorrow',
                $days > 1 => 'Due in ' . $days . ' days',
                $days === -1 => 'Was due yesterday',
                default => 'Was due ' . (-$days) . ' days ago',
            }; ?>
        <li class="<?= $days < 0 ? 'is-late' : ($days <= 2 ? 'is-soon' : '') ?>">
          <span class="due-date" aria-hidden="true"><span class="due-day"><?= $date->format('j') ?></span><span class="due-month"><?= $date->format('M') ?></span></span>
          <span class="due-text"><a href="/courses/<?= $v->e($d['slug']) ?>"><?= $v->e($d['title']) ?></a><span class="small due-when"><?= $v->e($when) ?><?= $d['started'] ? '' : ', not started' ?></span></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="small panel-link"><a href="/account/calendar"><?= $v->icon('calendar') ?>Put due dates in your calendar</a></p>
    </section>
    <?php endif; ?>

    <?php if ($recentNotes !== []): ?>
    <section class="panel" aria-labelledby="notes-h">
      <h2 id="notes-h">Saved and notes</h2>
      <ul class="mini-notes">
        <?php foreach ($recentNotes as $n): ?>
        <li><a href="/courses/<?= $v->e($n['course_slug']) ?>/lessons/<?= $n['lesson_id'] ?>"><?= $v->icon($n['saved'] ? 'saved-on' : 'note') ?><span><span class="mini-note-title"><?= $v->e($n['lesson_title']) ?></span><span class="small muted"><?= $n['body'] !== '' ? $v->e(mb_strimwidth($n['body'], 0, 70, '…')) : $v->e($n['course_title']) ?></span></span></a></li>
        <?php endforeach; ?>
      </ul>
      <p class="small panel-link"><a href="/saved">All saved lessons and notes</a></p>
    </section>
    <?php endif; ?>
  </aside>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($teaching !== null): ?>
<section class="section teaching-strip" aria-labelledby="teaching">
  <h2 id="teaching">Teaching</h2>
  <?php $tgroups = $teaching['groups'] ?? []; $groupWord = $v->shared('tenant')?->groupWord(true) ?? 'groups'; ?>
  <div class="staff-grid<?= $tgroups === [] ? ' is-single' : '' ?>">
    <div>
      <h3 class="staff-sub">To do</h3>
      <ul class="todo-list">
        <?php if ($teaching['reviews'] > 0): ?>
          <li class="todo-urgent"><a href="/teach/reviews"><?= $v->icon('review') ?><?= $v->e($v->plural($teaching['reviews'], 'assignment')) ?> waiting for your review</a></li>
        <?php endif; ?>
        <?php if ($teaching['drafts'] > 0): ?>
          <li><a href="/teach"><?= $v->icon('teach') ?><?= $v->e($v->plural($teaching['drafts'], 'draft course')) ?> to finish and publish</a></li>
        <?php endif; ?>
        <li><a href="/teach/courses/new"><?= $v->icon('courses') ?>Start a new course</a></li>
        <?php if ($v->can('members.manage')): ?><li><a href="/people"><?= $v->icon('people') ?>Add people</a></li><?php endif; ?>
        <?php $openHelp = (int) ($v->shared('nav_counts', [])['support'] ?? 0); if ($openHelp > 0): ?><li class="todo-urgent"><a href="/school/support"><?= $v->icon('inbox') ?><?= $v->e($v->plural($openHelp, 'help request')) ?> waiting for an answer</a></li><?php endif; ?>
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
  <h2 id="join"><?= $cardReasons !== [] ? 'Suggested for you' : ($hasLearning ? 'More courses' : 'Courses you can join') ?></h2>
  <?php $cards = $suggestedCards; include __DIR__ . '/../courses/_list.php'; ?>
</section>
<?php elseif (!$hasLearning && $teaching === null && $v->can('course.learn')): ?>
  <?= $v->emptyState('No courses yet', 'Your school has not published any courses. Check back soon, or ask your teacher which course to join.') ?>
<?php endif; ?>
