<?php
/**
 * A printable learner report (ADR 0032). Only what the platform records; parts the
 * viewer may not see are left out, not shown empty.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var string $learnerName
 * @var array{from: string, to: string, error: ?string} $period
 * @var array{groups: list<string>, courses: ?list<array{title: string, status: string, done: int, total: int, last_at: ?string, average: ?int}>, lessonsInPeriod: ?int, attendance: ?array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int}, results: ?array{quizzes: list<array{course: string, lesson: string, best: int, passed: bool, attempts: int}>, assignments: list<array{course: string, lesson: string, status: string, at: string}>}, certificates: ?list<\App\Modules\Certificates\Certificate>} $data
 * @var string $back
 * @var bool $staff
 * @var string $here
 * @var string $issued
 */
$day = static fn (string $d): string => date('j M Y', (int) strtotime($d . ' 00:00:00 UTC'));
$assignmentLabels = ['submitted' => 'Waiting for the teacher', 'approved' => 'Approved', 'changes_requested' => 'Changes asked'];
?>
<div class="no-print">
  <?= $v->breadcrumbs($staff ? ['People' => '/people', $learnerName => null] : ['Your child' => $back, 'Report' => null]) ?>
  <form method="get" action="<?= $v->e($here) ?>" class="report-period">
    <?= $v->field('from', 'From', ['type' => 'date', 'value' => $period['from']]) ?>
    <?= $v->field('to', 'To', ['type' => 'date', 'value' => $period['to']]) ?>
    <button type="submit" class="button button-small button-quiet">Show this period</button>
    <button type="button" class="button button-small" data-print>Print or save as PDF</button>
  </form>
  <?php if ($period['error'] !== null): ?><p class="notice"><?= $v->e($period['error']) ?></p><?php endif; ?>
</div>

<article class="receipt report-sheet" aria-labelledby="report-h">
  <header class="receipt-head">
    <div>
      <?php if ($school->hasLogo()): ?><img class="receipt-logo" src="/logo?v=<?= $v->e($school->logoVersion()) ?>" alt="" height="48"><?php endif; ?>
      <p class="receipt-school"><?= $v->e($school->name) ?></p>
      <?php if ($school->contactEmail !== ''): ?><p class="small muted"><?= $v->e($school->contactEmail) ?><?= $school->contactPhone !== '' ? ', ' . $v->e($school->contactPhone) : '' ?></p><?php endif; ?>
    </div>
    <div class="receipt-title">
      <h1 id="report-h">Learner report</h1>
      <p class="small muted"><?= $v->e($day($period['from'])) ?> to <?= $v->e($day($period['to'])) ?></p>
    </div>
  </header>

  <dl class="receipt-facts">
    <dt>Learner</dt><dd><strong><?= $v->e($learnerName) ?></strong></dd>
    <?php if ($data['groups'] !== []): ?><dt><?= $v->e($school->groupWord(count($data['groups']) > 1, true)) ?></dt><dd><?= $v->e(implode(', ', $data['groups'])) ?></dd><?php endif; ?>
    <dt>Issued</dt><dd><?= $v->e($issued) ?></dd>
    <?php if ($data['lessonsInPeriod'] !== null): ?><dt>Lessons finished in this period</dt><dd><?= (int) $data['lessonsInPeriod'] ?></dd><?php endif; ?>
  </dl>

  <?php if ($data['courses'] !== null): ?>
  <section class="report-part" aria-labelledby="r-courses">
    <h2 id="r-courses">Courses</h2>
    <?php if ($data['courses'] === []): ?>
      <p class="muted">Not taking any courses.</p>
    <?php else: ?>
    <div class="report-scroll"><table class="table receipt-lines">
      <thead><tr><th scope="col">Course</th><th scope="col">Progress</th><?php if ($data['results'] !== null): ?><th scope="col" class="num">Quiz average</th><?php endif; ?><th scope="col">Last active</th></tr></thead>
      <tbody>
      <?php foreach ($data['courses'] as $c): ?>
        <tr>
          <th scope="row"><?= $v->e($c['title']) ?></th>
          <td><?= $c['status'] === 'completed' ? 'Completed' : $v->e($c['done'] . ' of ' . $c['total'] . ' lessons') ?></td>
          <?php if ($data['results'] !== null): ?><td class="num"><?= $c['average'] === null ? '–' : (int) $c['average'] . '%' ?></td><?php endif; ?>
          <td><?= $c['last_at'] === null ? 'Not started' : $v->datetime($c['last_at'], 'j M Y') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($data['results'] !== null): ?><p class="small muted">Quiz average: the best score on each required quiz in the course; a quiz not yet taken counts as 0.</p><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($data['attendance'] !== null): $a = $data['attendance']; ?>
  <section class="report-part" aria-labelledby="r-att">
    <h2 id="r-att">Attendance at live classes</h2>
    <?php if ($a['held'] === 0): ?>
      <p class="muted">No live classes were held for this learner in this period.</p>
    <?php else: ?>
    <dl class="report-stats">
      <div><dt>Classes held</dt><dd><?= $a['held'] ?></dd></div>
      <div><dt>Present</dt><dd><?= $a['present'] ?></dd></div>
      <div><dt>Late</dt><dd><?= $a['late'] ?></dd></div>
      <div><dt>Absent</dt><dd><?= $a['absent'] ?></dd></div>
      <div><dt>Excused</dt><dd><?= $a['excused'] ?></dd></div>
      <div><dt>Attendance</dt><dd><?= $a['rate'] === null ? '–' : $a['rate'] . '%' ?></dd></div>
    </dl>
    <?php if ($a['unmarked'] > 0): ?><p class="small muted"><?= $v->e($v->plural($a['unmarked'], 'class has', 'classes have')) ?> no register yet and <?= $a['unmarked'] === 1 ? 'is' : 'are' ?> not counted.</p><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($data['results'] !== null): ?>
  <section class="report-part" aria-labelledby="r-quiz">
    <h2 id="r-quiz">Quiz results</h2>
    <?php if ($data['results']['quizzes'] === []): ?>
      <p class="muted">No quizzes taken yet.</p>
    <?php else: ?>
    <div class="report-scroll"><table class="table receipt-lines">
      <thead><tr><th scope="col">Quiz</th><th scope="col" class="num">Best score</th><th scope="col">Result</th><th scope="col" class="num">Tries</th></tr></thead>
      <tbody>
      <?php foreach ($data['results']['quizzes'] as $q): ?>
        <tr><th scope="row"><?= $v->e($q['lesson']) ?><span class="small muted"><?= $v->e($q['course']) ?></span></th><td class="num"><?= $q['best'] ?>%</td><td><?= $q['passed'] ? 'Passed' : 'Not passed yet' ?></td><td class="num"><?= $q['attempts'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($data['results']['assignments'] !== []): ?>
    <h2 id="r-assign">Assignments</h2>
    <div class="report-scroll"><table class="table receipt-lines" aria-labelledby="r-assign">
      <thead><tr><th scope="col">Assignment</th><th scope="col">Status</th><th scope="col">Sent</th></tr></thead>
      <tbody>
      <?php foreach ($data['results']['assignments'] as $s): ?>
        <tr><th scope="row"><?= $v->e($s['lesson']) ?><span class="small muted"><?= $v->e($s['course']) ?></span></th><td><?= $v->e($assignmentLabels[$s['status']] ?? $s['status']) ?></td><td><?= $v->datetime($s['at'], 'j M Y') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($data['certificates'] !== null && $data['certificates'] !== []): ?>
  <section class="report-part" aria-labelledby="r-cert">
    <h2 id="r-cert">Certificates</h2>
    <ul class="report-certs">
      <?php foreach ($data['certificates'] as $cert): ?>
        <li><?= $v->e($cert->courseTitle) ?>, <?= $v->datetime($cert->issuedAt, 'j M Y') ?>. Number <span class="ref"><?= $v->e($cert->displayCode()) ?></span><?= $cert->isValid() ? '' : ' (revoked)' ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="small muted">Anyone can check a certificate number at <?= $v->e($v->shared('base_url') ?? '') ?>/verify.</p>
  </section>
  <?php endif; ?>

  <div class="report-sign" aria-hidden="true">
    <p><span class="report-line"></span>Class teacher</p>
    <p><span class="report-line"></span>Head of school</p>
  </div>
  <p class="small muted receipt-note">Made from the records in <?= $v->e($school->name) ?>’s learning platform on <?= $v->e($issued) ?>.</p>
</article>
