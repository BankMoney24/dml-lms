<?php
/**
 * The Live classes section of a group page (ADR 0028): what is planned,
 * recent classes with their registers, attendance per learner, and the form
 * to plan a class once or weekly.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var array{upcoming: list<\App\Modules\LiveClasses\ClassSession>, past: list<\App\Modules\LiveClasses\ClassSession>, attendance: array{held: int, rows: array<int, array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int}>}, mayTeach: bool, now: int, courses: list<\App\Modules\Courses\Course>} $liveClasses
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var string $today
 * @var bool $archived
 * @var string $base
 */
$lc = $liveClasses;
$now = $lc['now'];
$showGroup = false;
$co = static fn (string $k, string $d = ''): string => $old['class_' . $k] ?? $d;
$classErrors = [];
foreach ($errors as $k => $m) {
    if (str_starts_with($k, 'class_')) {
        $classErrors[substr($k, 6)] = $m;
    }
}
$courseOptions = ['' => 'No course'];
foreach ($lc['courses'] as $c) {
    $courseOptions[(string) $c->id] = $c->title;
}
$att = $lc['attendance'];
?>
<section class="section lc-group" id="classes" aria-labelledby="classes-h">
  <div class="section-head">
    <h2 id="classes-h">Live classes</h2>
    <?php if ($att['held'] > 0): ?><a href="<?= $base ?>/attendance.csv" download>Download attendance</a><?php endif; ?>
  </div>
  <div class="lc-columns">
    <div>
      <h3 class="small-heading">Coming up</h3>
      <?php if ($lc['upcoming'] === []): ?>
        <p class="muted small">Nothing planned yet.</p>
      <?php else: ?>
        <ul class="lc-list"><?php foreach ($lc['upcoming'] as $s): ?><?php include __DIR__ . '/../classes/_row.php'; ?><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($lc['past'] !== []): ?>
        <h3 class="small-heading">Recent</h3>
        <ul class="lc-list"><?php foreach ($lc['past'] as $s): ?><?php include __DIR__ . '/../classes/_row.php'; ?><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div>
      <h3 class="small-heading">Attendance<?= $att['held'] > 0 ? ', ' . $v->e($v->plural($att['held'], 'class', 'classes')) . ' held' : '' ?></h3>
      <?php if ($att['held'] === 0 || $learners === []): ?>
        <p class="muted small">Attendance shows here once classes have taken place.</p>
      <?php else: ?>
        <ul class="lc-att-list">
          <?php foreach ($learners as $l): $r = $att['rows'][$l['user_id']] ?? null; $rate = $r['rate'] ?? null; ?>
            <li>
              <span class="lc-att-name"><?= $v->e($l['name']) ?></span>
              <span class="lc-att-bar" aria-hidden="true"><span class="lc-att-fill<?= $rate !== null && $rate < 75 ? ' is-low' : '' ?>" data-width="<?= (int) ($rate ?? 0) ?>"></span></span>
              <span class="lc-att-rate"><?= $rate === null ? '–' : $rate . '%' ?></span>
              <span class="visually-hidden"><?= $r === null ? 'No classes since joining' : $v->e(sprintf('%d present, %d late, %d absent, %d excused, %d not marked', $r['present'], $r['late'], $r['absent'], $r['excused'], $r['unmarked'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="small muted">Present or late, out of the classes marked since each learner joined. Excused classes and classes with no register yet do not count against them. Below 75% is shown in red.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($lc['mayTeach'] && !$archived): ?>
  <details class="panel lc-plan"<?= $co('open') === '1' ? ' open' : '' ?>>
    <summary>Plan a live class</summary>
    <form method="post" action="<?= $base ?>/classes" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('class_title', 'Title', ['value' => $co('title'), 'maxlength' => 160, 'error' => $classErrors['title'] ?? null, 'hint' => 'For example: Week 3, subnetting practice.']) ?>
      <div class="field-row field-row-3">
        <?= $v->field('class_date', 'Day', ['type' => 'date', 'value' => $co('date', $today), 'error' => $classErrors['date'] ?? null]) ?>
        <?= $v->field('class_time', 'Starts', ['type' => 'time', 'value' => $co('time', '16:00'), 'error' => $classErrors['time'] ?? null, 'hint' => $school->timezone . ' time']) ?>
        <?= $v->field('class_minutes', 'Minutes', ['type' => 'select', 'options' => ['30' => '30', '45' => '45', '60' => '1 hour', '90' => '1½ hours', '120' => '2 hours', '180' => '3 hours'], 'value' => $co('minutes', '60'), 'error' => $classErrors['minutes'] ?? null]) ?>
      </div>
      <?php $modes = \App\Modules\LiveClasses\ClassSession::MODES;
      if (!($lc['classroomReady'] ?? false)) {
          unset($modes['classroom']);
      } ?>
      <?= $v->field('class_mode', 'Where', [
          'type' => 'select', 'options' => $modes, 'value' => $co('mode', ($lc['classroomReady'] ?? false) ? 'classroom' : 'online'), 'error' => $classErrors['mode'] ?? null,
          'hint' => ($lc['classroomReady'] ?? false) ? 'The online classroom runs inside this site: no meeting link needed, and learners can join with audio only to save data.' : null,
      ]) ?>
      <div class="field-row">
        <?= $v->field('class_join_url', 'Meeting link (online)', ['type' => 'url', 'value' => $co('join_url'), 'maxlength' => 500, 'error' => $classErrors['join_url'] ?? null, 'hint' => 'Google Meet, Zoom, Teams or Jitsi.']) ?>
        <?= $v->field('class_location', 'Room (in person)', ['value' => $co('location'), 'maxlength' => 200, 'error' => $classErrors['location'] ?? null, 'hint' => 'For example: Lab 2, first floor.']) ?>
      </div>
      <div class="field-row">
        <?= $v->field('class_course_id', 'Course (optional)', ['type' => 'select', 'options' => $courseOptions, 'value' => $co('course_id'), 'error' => $classErrors['course_id'] ?? null]) ?>
        <?= $v->field('class_weeks', 'Repeat', ['type' => 'select', 'options' => ['1' => 'Just once'] + array_combine(array_map('strval', range(2, 15)), array_map(static fn (int $n): string => 'Weekly, ' . $n . ' times', range(2, 15))), 'value' => $co('weeks', '1'), 'error' => $classErrors['weeks'] ?? null]) ?>
      </div>
      <?= $v->field('class_description', 'Notes for learners (optional)', ['type' => 'textarea', 'rows' => 3, 'value' => $co('description'), 'error' => $classErrors['description'] ?? null, 'hint' => 'What to read or bring. Learners see this on the class page.']) ?>
      <button type="submit" class="button button-small">Plan the class</button>
      <p class="small muted">Learners in <?= $v->e($group->name) ?> are told, and see it on their home page and calendar.</p>
    </form>
  </details>
  <?php endif; ?>
</section>
