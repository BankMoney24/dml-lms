<?php
/**
 * Live classes (ADR 0028): what is coming up for a learner, and for teachers
 * today's classes and registers still to take.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\LiveClasses\ClassSession> $mine
 * @var array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int, recent: list<array{session: \App\Modules\LiveClasses\ClassSession, status: ?string}>}|array{} $past
 * @var bool $teaches
 * @var list<\App\Modules\LiveClasses\ClassSession> $today
 * @var list<\App\Modules\LiveClasses\ClassSession> $upcoming
 * @var list<\App\Modules\LiveClasses\ClassSession> $registers
 * @var int $now
 * @var list<array{id: int, name: string}> $myGroups
 * @var bool $classroomReady
 * @var list<\App\Modules\Meetings\Meeting> $meetings
 * @var bool $mayHost
 */
$school = $v->shared('tenant');
$myGroups ??= [];
$classroomReady ??= false;
$meetings ??= [];
$mayHost ??= false;
$userId = (int) ($v->shared('user')?->id ?? 0);
?>
<header class="page-head page-head-row">
  <div>
    <h1>Live classes</h1>
    <p class="lead"><?= $teaches ? 'Classes you teach, and registers still to take.' : 'Classes your teachers have planned for you. Join online classes from here when they start.' ?></p>
  </div>
  <?php if ($mayHost && $classroomReady): ?><a class="button button-quiet" href="/meetings/new"><?= $v->icon('people') ?>New meeting</a><?php endif; ?>
</header>

<?php if ($teaches && $classroomReady && $myGroups !== []): ?>
<section class="quick-class" aria-labelledby="quick-h">
  <div class="quick-class-intro">
    <h2 id="quick-h"><?= $v->icon('live') ?>Start a class now</h2>
    <p class="small">No planning: learners are told it is live and join from their phones. You go straight into the classroom.</p>
  </div>
  <form method="post" action="/classes/now" class="quick-class-form">
    <?= $v->csrfField() ?>
    <?php if (count($myGroups) === 1): ?>
      <input type="hidden" name="group_id" value="<?= (int) $myGroups[0]['id'] ?>">
      <p class="quick-for small">For <strong><?= $v->e($myGroups[0]['name']) ?></strong></p>
    <?php else: ?>
      <div class="field"><label for="quick-group">For</label>
        <select id="quick-group" name="group_id"><?php foreach ($myGroups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= $v->e($g['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field"><label for="quick-title">Title <span class="optional">(optional)</span></label><input id="quick-title" name="title" maxlength="160" placeholder="Live class"></div>
    <fieldset class="opts"><legend>How long</legend>
      <?php foreach ([30 => '30 min', 60 => '1 hour', 90 => '1½ hours'] as $mins => $label): ?>
        <label class="opt"><input type="radio" name="minutes" value="<?= $mins ?>"<?= $mins === 60 ? ' checked' : '' ?>><span><?= $label ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset class="opts"><legend>Style</legend>
      <label class="opt"><input type="radio" name="style" value="lecture" checked><span>Lecture</span></label>
      <label class="opt"><input type="radio" name="style" value="discussion"><span>Discussion</span></label>
    </fieldset>
    <button type="submit" class="button quick-go"><?= $v->icon('play') ?>Start now</button>
  </form>
</section>
<?php endif; ?>

<?php if ($meetings !== []): ?>
<section class="lc-section" aria-labelledby="meet-h">
  <h2 id="meet-h">Meetings this week</h2>
  <ul class="lc-list"><?php foreach ($meetings as $m): ?><?php include __DIR__ . '/../meetings/_row.php'; ?><?php endforeach; ?></ul>
  <p class="small"><a href="/meetings">All meetings</a></p>
</section>
<?php endif; ?>

<?php if ($teaches): ?>
<div class="lc-columns">
  <section class="lc-section" aria-labelledby="today-h">
    <h2 id="today-h">Today</h2>
    <?php if ($today === []): ?><p class="muted">No classes today.</p><?php else: ?>
      <ul class="lc-list"><?php foreach ($today as $s): ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <section class="lc-section" aria-labelledby="reg-h">
    <h2 id="reg-h">Registers to take<?php if ($registers !== []): ?> <span class="count-pill"><?= count($registers) ?></span><?php endif; ?></h2>
    <?php if ($registers === []): ?><p class="muted">All caught up.</p><?php else: ?>
      <ul class="lc-list"><?php foreach ($registers as $s): ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
<section class="lc-section" aria-labelledby="next-h">
  <h2 id="next-h">The next three weeks</h2>
  <?php if ($upcoming === []): ?>
    <p class="muted">Nothing planned. Plan classes from a <?= $v->e($school?->groupWord() ?? 'class') ?>’s page, under Live classes.</p>
  <?php else: ?>
    <ul class="lc-list"><?php foreach ($upcoming as $s): ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
  <?php endif; ?>
  <p class="small"><a href="/groups">Go to your <?= $v->e($school?->groupWord(true) ?? 'classes') ?></a></p>
</section>
<?php endif; ?>

<?php if (!$teaches || $mine !== []): ?>
<section class="lc-section" aria-labelledby="mine-h">
  <h2 id="mine-h"><?= $teaches ? 'Classes you attend' : 'Coming up' ?></h2>
  <?php if ($mine === []): ?>
    <?= $v->emptyState('No live classes planned', 'When a teacher plans a class for your ' . ($school?->groupWord() ?? 'class') . ', it shows here, on your home page and in your calendar.') ?>
  <?php else: ?>
    <ul class="lc-list"><?php foreach ($mine as $s): $showGroup = true; ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (($past['held'] ?? 0) > 0): ?>
<section class="lc-section" aria-labelledby="att-h">
  <h2 id="att-h">Your attendance</h2>
  <div class="lc-attend">
    <p class="lc-rate"><strong><?= $past['rate'] === null ? '–' : $past['rate'] . '%' ?></strong><span class="small muted">attendance, from <?= $v->e($v->plural($past['held'] - $past['unmarked'], 'marked class', 'marked classes')) ?></span></p>
    <dl class="lc-counts small">
      <dt>Present</dt><dd><?= $past['present'] ?></dd><dt>Late</dt><dd><?= $past['late'] ?></dd><dt>Absent</dt><dd><?= $past['absent'] ?></dd><dt>Excused</dt><dd><?= $past['excused'] ?></dd>
    </dl>
  </div>
  <ul class="lc-list"><?php foreach ($past['recent'] as $r): $s = $r['session']; $mark = $r['status'] ?? 'unmarked'; ?><?php include __DIR__ . '/_row.php'; ?><?php endforeach; $mark = null; ?></ul>
</section>
<?php endif; ?>
