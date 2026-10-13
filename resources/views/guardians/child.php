<?php
/**
 * A guardian's view of one learner. Only the parts the link allows are passed in.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\Guardians\GuardianLink $link
 * @var list<\App\Modules\Guardians\GuardianLink> $siblings
 * @var list<array{title: string, slug: string, status: string, done: int, total: int, last_at: ?string, due_on: ?string}> $courses
 * @var ?array{quizzes: list<array{course: string, lesson: string, best: int, passed: bool, attempts: int}>, assignments: list<array{course: string, lesson: string, status: string, at: string}>} $results
 * @var list<\App\Modules\Certificates\Certificate> $certificates
 * @var list<array{group: \App\Modules\Groups\LearnerGroup, teachers: list<string>, courses: list<array{course: \App\Modules\Courses\Course, due_on: ?string}>, announcements: list<array{id: int, body: string, author: string, author_id: ?int, created_at: string}>}> $groups
 * @var string $today
 */
$first = explode(' ', trim($link->learnerName))[0];
$assignmentLabels = ['submitted' => ['Waiting for the teacher', 'draft'], 'approved' => ['Approved', 'done'], 'changes_requested' => ['Changes asked for', 'neutral']];
$nothing = !$link->canProgress && !$link->canResults && !$link->canNotices;
?>
<?php if (count($siblings) > 1): ?>
<nav class="tabs" aria-label="Your children">
  <?php foreach ($siblings as $s): ?>
    <a href="/children/<?= $s->learnerId ?>"<?= $s->learnerId === $link->learnerId ? ' aria-current="page"' : '' ?>><?= $v->e($s->learnerName) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<header class="page-head">
  <h1><?= $v->e($link->learnerName) ?></h1>
  <p class="lead">You are <?= $v->e($first) ?>'s <?= $v->e(strtolower($link->relationshipLabel())) ?>. This page shows what <?= $v->e($school->name) ?> agreed you can see. You can look; nothing here can be changed.</p>
</header>

<?php if ($nothing): ?>
  <p class="notice">The school has not shared anything about <?= $v->e($first) ?> with you yet. Ask the school if you think this is a mistake.</p>
<?php endif; ?>

<?php foreach ($groups as $g): ?>
  <section class="section my-group" aria-labelledby="g-<?= $g['group']->id ?>">
    <div class="my-group-head">
      <h2 id="g-<?= $g['group']->id ?>"><?= $v->e($school->groupWord(false, true)) ?>: <?= $v->e($g['group']->name) ?></h2>
      <?php if ($g['teachers'] !== []): ?><p class="small muted">Taught by <?= $v->e(implode(', ', $g['teachers'])) ?></p><?php endif; ?>
    </div>
    <?php if ($link->canNotices): ?>
      <?php foreach (array_slice($g['announcements'], 0, 3) as $a): ?>
      <div class="ann ann-home">
        <div class="ann-meta"><strong><?= $v->e($a['author']) ?></strong> <span class="muted small"><?= $v->datetime($a['created_at']) ?></span></div>
        <?= $v->paragraphs($a['body']) ?>
      </div>
      <?php endforeach; ?>
      <?php if ($g['announcements'] === []): ?><p class="muted small">No announcements from the teachers yet.</p><?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php if ($link->canProgress): ?>
<section class="section" aria-labelledby="courses-h">
  <h2 id="courses-h">Courses</h2>
  <?php if ($courses === []): ?>
    <p class="muted"><?= $v->e($first) ?> has not started any courses yet.</p>
  <?php else: ?>
  <ol class="set-list child-courses">
    <?php foreach ($courses as $c):
        $done = $c['status'] === 'completed';
        $late = !$done && $c['due_on'] !== null && $c['due_on'] < $today;
        ?>
    <li class="set-item<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?>">
      <span class="set-title"><?= $v->e($c['title']) ?></span>
      <span class="set-due"><?= $done ? 'Completed' : ($c['due_on'] === null ? 'No due date' : ($late ? 'Was due ' : 'Due ') . $v->e((new DateTimeImmutable($c['due_on']))->format('j M'))) ?></span>
      <?php if (!$done): ?>
        <span class="set-progress small"><?= $v->e($c['done'] . ' of ' . $v->plural($c['total'], 'lesson') . ' done') ?><?= $c['last_at'] !== null ? ', last active ' . $v->datetime($c['last_at'], 'j M') : '' ?></span>
        <progress class="meter meter-thin" max="<?= max(1, $c['total']) ?>" value="<?= $c['done'] ?>" aria-label="<?= $v->e($c['title']) ?> progress"></progress>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($results !== null): ?>
<section class="section" aria-labelledby="results-h">
  <h2 id="results-h">Results</h2>
  <?php if ($results['quizzes'] === [] && $results['assignments'] === []): ?>
    <p class="muted">No quizzes or assignments handed in yet.</p>
  <?php endif; ?>
  <?php if ($results['quizzes'] !== []): ?>
  <div class="table-wrap">
    <table class="table table-stack">
      <caption class="visually-hidden">Quizzes: best score</caption>
      <thead><tr><th scope="col">Quiz</th><th scope="col" class="num">Best score</th><th scope="col">Result</th><th scope="col" class="num">Tries</th></tr></thead>
      <tbody>
      <?php foreach ($results['quizzes'] as $q): ?>
        <tr>
          <td><?= $v->e($q['lesson']) ?><span class="small muted"><?= $v->e($q['course']) ?></span></td>
          <td class="num" data-label="Best score"><?= $q['best'] ?>%</td>
          <td data-label="Result"><?= $q['passed'] ? $v->badge('Passed', 'done') : $v->badge('Not passed yet') ?></td>
          <td class="num" data-label="Tries"><?= $q['attempts'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($results['assignments'] !== []): ?>
  <h3 class="staff-sub staff-sub-gap">Assignments</h3>
  <ul class="plain-list">
    <?php foreach ($results['assignments'] as $a): [$label, $tone] = $assignmentLabels[$a['status']] ?? ['Handed in', 'neutral']; ?>
    <li class="set-course"><span><?= $v->e($a['lesson']) ?><span class="small muted"><?= $v->e($a['course']) ?>, handed in <?= $v->datetime($a['at'], 'j M') ?></span></span><?= $v->badge($label, $tone) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($certificates !== []): ?>
<section class="section" aria-labelledby="certs-h">
  <h2 id="certs-h">Certificates</h2>
  <ul class="cert-list">
    <?php foreach ($certificates as $cert): ?>
    <li><a href="/certificates/<?= $v->e($cert->displayCode()) ?>"><?= $v->e($cert->courseTitle) ?></a><span class="small muted">Issued <?= $v->datetime($cert->issuedAt, 'j M Y') ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<aside class="section guardian-terms" aria-labelledby="terms-h">
  <h2 id="terms-h">What you can see</h2>
  <ul class="checklist-plain">
    <li class="<?= $link->canProgress ? 'is-on' : 'is-off' ?>">Courses and progress: <?= $link->canProgress ? 'yes' : 'no' ?></li>
    <li class="<?= $link->canResults ? 'is-on' : 'is-off' ?>">Quiz scores and assignment results: <?= $link->canResults ? 'yes' : 'no' ?></li>
    <li class="<?= $link->canNotices ? 'is-on' : 'is-off' ?>">Announcements from <?= $v->e($first) ?>'s teachers: <?= $link->canNotices ? 'yes' : 'no' ?></li>
  </ul>
  <p class="small">The school linked your account to <?= $v->e($first) ?> on <?= $v->datetime($link->verifiedAt, 'j M Y') ?>. To change what you can see, to stop seeing it, or for any question about <?= $v->e($first) ?>'s information, contact <?= $v->e($school->name) ?><?= $school->contactEmail !== '' ? ' at ' . $v->e($school->contactEmail) : '' ?><?= $school->contactPhone !== '' ? ' or ' . $v->e($school->contactPhone) : '' ?>.</p>
</aside>
