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
 */
$school = $v->shared('tenant');
?>
<header class="page-head">
  <h1>Live classes</h1>
  <p class="lead"><?= $teaches ? 'Classes you teach, and registers still to take.' : 'Classes your teachers have planned for you. Join online classes from here when they start.' ?></p>
</header>

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
