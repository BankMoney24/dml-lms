<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var list<array{id: int, title: string, lessons: list<\App\Modules\Courses\Lesson>}> $outline */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var array<string, string> $labOptions */
$base = '/teach/courses/' . $course->id;
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$tab = 'outline';
$n = 0;
?>
<?php include __DIR__ . '/_course_head.php'; ?>

<?= $v->errorSummary(array_diff_key($errors, ['publish' => true])) ?>

<?php if ($course->isPublished()): ?>
<div class="notice notice-ok">
  <p>Learners can find and enrol in this course. Edits to text appear immediately.</p>
  <form method="post" action="<?= $base ?>/unpublish" class="inline">
    <?= $v->csrfField() ?>
    <button type="submit" class="linklike">Unpublish (hide from learners, keep their progress)</button>
  </form>
</div>
<?php else: ?>
<div class="notice<?= isset($errors['publish']) ? ' notice-err' : '' ?>" id="publish-problems"<?= isset($errors['publish']) ? ' role="alert"' : '' ?>>
  <?php if ($publishProblems === []): ?>
    <p>This draft is ready. Publish it when you want learners to see it.</p>
  <?php else: ?>
    <p><strong>Before you publish:</strong></p>
    <ul><?php foreach ($publishProblems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" action="<?= $base ?>/publish">
    <?= $v->csrfField() ?>
    <p><button type="submit" class="button button-small">Publish course</button></p>
  </form>
</div>
<?php endif; ?>

<div class="teach-layout section">
  <section aria-labelledby="outline-h">
    <h2 id="outline-h">Outline</h2>
    <?php if ($outline === []): ?>
      <?= $v->emptyState('Start with a module', 'Modules group lessons, for example “Week 1” or “Electricity”. Add one using the form beside this.') ?>
    <?php endif; ?>
    <?php foreach ($outline as $module): $count = count($module['lessons']); ?>
    <div class="t-module" id="module-<?= $module['id'] ?>">
      <div class="t-module-head">
        <h3><?= $v->e($module['title']) ?></h3>
        <details class="inline-edit">
          <summary>Rename</summary>
          <form method="post" action="<?= $base ?>/modules/<?= $module['id'] ?>" class="row-actions">
            <?= $v->csrfField() ?>
            <label class="visually-hidden" for="rename-<?= $module['id'] ?>">New module title</label>
            <input id="rename-<?= $module['id'] ?>" name="module_title" value="<?= $v->e($module['title']) ?>" maxlength="190" required>
            <button type="submit" class="button button-small">Save</button>
          </form>
          <?php if ($count === 0): ?>
          <form method="post" action="<?= $base ?>/modules/<?= $module['id'] ?>/delete">
            <?= $v->csrfField() ?>
            <button type="submit" class="linklike">Delete this empty module</button>
          </form>
          <?php endif; ?>
        </details>
      </div>
      <?php if ($count === 0): ?><p class="muted small">No lessons yet. Add one with the form beside this.</p><?php endif; ?>
      <ol class="t-lessons">
        <?php foreach ($module['lessons'] as $i => $lesson): $n++;
          $qCount = $questionCounts[$lesson->id] ?? 0; ?>
        <li id="lesson-<?= $lesson->id ?>" value="<?= $n ?>">
         <div class="t-lesson-row">
          <div class="t-lesson-main">
            <a class="t-lesson-title" href="<?= $base ?>/lessons/<?= $lesson->id ?>"><?= $v->e($lesson->title) ?></a>
            <?= $v->badge($lesson->typeLabel(), 'type-' . $lesson->type) ?>
            <?php if ($lesson->isQuiz()): ?>
              <?= $qCount === 0 ? $v->badge('No questions yet', 'danger') : $v->badge($v->plural($qCount, 'question')) ?>
            <?php endif; ?>
          </div>
          <div class="row-actions">
            <form method="post" action="<?= $base ?>/lessons/<?= $lesson->id ?>/move" class="row-actions">
              <?= $v->csrfField() ?>
              <button type="submit" name="direction" value="up" class="icon-button" aria-label="Move “<?= $v->e($lesson->title) ?>” up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
              <button type="submit" name="direction" value="down" class="icon-button" aria-label="Move “<?= $v->e($lesson->title) ?>” down"<?= $i === $count - 1 ? ' disabled' : '' ?>>↓</button>
            </form>
            <a href="<?= $base ?>/lessons/<?= $lesson->id ?>">Edit<span class="visually-hidden"> <?= $v->e($lesson->title) ?></span></a>
            <a href="<?= $base ?>/lessons/<?= $lesson->id ?>/preview">Preview<span class="visually-hidden"> <?= $v->e($lesson->title) ?></span></a>
          </div>
         </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endforeach; ?>
  </section>

  <aside class="teach-forms">
    <?php if ($outline !== []): ?>
    <section class="panel" aria-labelledby="add-lesson-h">
      <h2 id="add-lesson-h">Add a lesson</h2>
      <form method="post" action="<?= $base ?>/lessons" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?php
          $moduleOptions = [];
          foreach ($outline as $module) {
              $moduleOptions[(string) $module['id']] = $module['title'];
          }
          $lastModule = (string) $outline[count($outline) - 1]['id'];
        ?>
        <?= $v->field('module_id', 'Module', ['type' => 'select', 'options' => $moduleOptions, 'value' => $o('module_id', $lastModule), 'error' => $errors['module_id'] ?? null]) ?>
        <fieldset class="field">
          <legend>Lesson type</legend>
          <?php foreach (\App\Modules\Courses\Lesson::TYPES as $typeKey => [$typeLabel, $typeHint]): ?>
          <label class="choice"><input type="radio" name="type" value="<?= $v->e($typeKey) ?>"<?= $o('type', 'text') === $typeKey ? ' checked' : '' ?>> <span><strong><?= $v->e($typeLabel) ?></strong><br><span class="small muted"><?= $v->e($typeHint) ?></span></span></label>
          <?php endforeach; ?>
        </fieldset>
        <?= $v->field('lesson_title', 'Lesson title', ['value' => $o('lesson_title'), 'error' => $errors['lesson_title'] ?? null, 'maxlength' => 190]) ?>
        <?= $v->field('media_url', 'Video link (video lessons only)', [
            'value' => $o('media_url'), 'error' => $errors['media_url'] ?? null, 'optional' => true, 'inputmode' => 'url', 'maxlength' => 500,
            'hint' => 'A YouTube or Vimeo address.',
        ]) ?>
        <?= $v->field('lab_key', 'Lab (lab lessons only)', [
            'type' => 'select', 'options' => ['' => 'Choose a lab'] + $labOptions, 'value' => $o('lab_key'), 'error' => $errors['lab_key'] ?? null,
            'hint' => 'Leave the title empty to use the lab’s own title.',
        ]) ?>
        <p class="small"><a href="/teach/labs">Browse and try the labs</a></p>
        <?= $v->field('body', 'Lesson text', [
            'type' => 'textarea', 'rows' => 8, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
            'hint' => 'Reading: the lesson itself. Video: notes under the video. Quiz: instructions. Assignment: the task and how it is judged. Lab: an optional introduction.',
        ]) ?>
        <?php include __DIR__ . '/_format_help.php'; ?>
        <details class="advanced"<?= isset($errors['pass_threshold']) || isset($errors['max_attempts']) ? ' open' : '' ?>>
          <summary>Quiz settings</summary>
          <?= $v->field('pass_threshold', 'Pass mark (%)', ['value' => $o('pass_threshold', '70'), 'inputmode' => 'numeric', 'error' => $errors['pass_threshold'] ?? null]) ?>
          <?= $v->field('max_attempts', 'Attempts allowed', ['value' => $o('max_attempts'), 'inputmode' => 'numeric', 'hint' => 'Leave blank for unlimited.', 'error' => $errors['max_attempts'] ?? null]) ?>
        </details>
        <button type="submit" class="button button-small">Add lesson</button>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel" aria-labelledby="add-module-h">
      <h2 id="add-module-h">Add a module</h2>
      <form method="post" action="<?= $base ?>/modules" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('module_title', 'Module title', ['value' => $o('module_title'), 'error' => $errors['module_title'] ?? null, 'maxlength' => 190]) ?>
        <button type="submit" class="button button-small">Add module</button>
      </form>
    </section>

    <?php $detailErrors = array_intersect_key($errors, array_flip(['title', 'summary', 'level', 'duration_hours', 'outcomes', 'prerequisites', 'track'])); ?>
    <details class="panel"<?= $detailErrors !== [] || $o('details_open') === '1' ? ' open' : '' ?>>
      <summary>Course details</summary>
      <form method="post" action="<?= $base ?>/details" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('title', 'Course title', ['value' => $o('title', $course->title), 'error' => $errors['title'] ?? null, 'maxlength' => 190]) ?>
        <?= $v->field('summary', 'Summary', ['type' => 'textarea', 'rows' => 4, 'value' => $o('summary', $course->summary), 'error' => $errors['summary'] ?? null, 'optional' => true]) ?>
        <?= $v->field('track', 'Kind of course', [
            'type' => 'select', 'options' => ['' => 'Not set'] + \App\Modules\Courses\Course::TRACKS,
            'value' => $o('track', $course->track ?? ''), 'error' => $errors['track'] ?? null,
            'hint' => 'School subject: part of a school curriculum (e.g. SS2 Physics). Skills: vocational or professional training. Learners can filter the course list by this.',
        ]) ?>
        <?= $v->field('level', 'Level', [
            'type' => 'select', 'options' => ['' => 'Not set'] + \App\Modules\Courses\Course::LEVELS,
            'value' => $o('level', $course->level ?? ''), 'error' => $errors['level'] ?? null,
        ]) ?>
        <?= $v->field('duration_hours', 'Hours to complete', [
            'value' => $o('duration_hours', $course->durationHours === null ? '' : (string) $course->durationHours),
            'inputmode' => 'numeric', 'optional' => true, 'error' => $errors['duration_hours'] ?? null,
        ]) ?>
        <?= $v->field('outcomes', 'What learners will be able to do', [
            'type' => 'textarea', 'rows' => 5, 'value' => $o('outcomes', implode("\n", $course->outcomes)), 'optional' => true,
            'hint' => 'One outcome per line, starting with a verb: “Configure…”, “Explain…”.', 'error' => $errors['outcomes'] ?? null,
        ]) ?>
        <?= $v->field('prerequisites', 'Before you start', [
            'type' => 'textarea', 'rows' => 3, 'value' => $o('prerequisites', $course->prerequisites), 'optional' => true,
            'hint' => 'What learners should already know or have.', 'error' => $errors['prerequisites'] ?? null,
        ]) ?>
        <button type="submit" class="button button-small">Save details</button>
      </form>
    </details>
  </aside>
</div>
