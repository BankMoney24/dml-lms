<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var list<array{id: int, title: string, lessons: list<\App\Modules\Courses\Lesson>}> $outline */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
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
          <div class="t-lesson-main">
            <a class="t-lesson-title" href="<?= $base ?>/lessons/<?= $lesson->id ?>"><?= $v->e($lesson->title) ?></a>
            <?= $v->badge($lesson->isQuiz() ? 'Quiz' : 'Reading') ?>
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
          <label class="choice"><input type="radio" name="type" value="text"<?= $o('type', 'text') === 'text' ? ' checked' : '' ?>> <span><strong>Reading</strong><br><span class="small muted">Text learners read, then mark complete</span></span></label>
          <label class="choice"><input type="radio" name="type" value="quiz"<?= $o('type') === 'quiz' ? ' checked' : '' ?>> <span><strong>Quiz</strong><br><span class="small muted">Multiple choice, marked on the server</span></span></label>
        </fieldset>
        <?= $v->field('lesson_title', 'Lesson title', ['value' => $o('lesson_title'), 'error' => $errors['lesson_title'] ?? null, 'maxlength' => 190]) ?>
        <?= $v->field('body', 'Lesson text', [
            'type' => 'textarea', 'rows' => 8, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
            'hint' => 'Leave a blank line between paragraphs. For a quiz, this is the instruction shown above the questions.',
        ]) ?>
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

    <details class="panel"<?= isset($errors['title']) || isset($errors['summary']) || $o('details_open') === '1' ? ' open' : '' ?>>
      <summary>Course title and summary</summary>
      <form method="post" action="<?= $base ?>/details" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('title', 'Course title', ['value' => $o('title', $course->title), 'error' => $errors['title'] ?? null, 'maxlength' => 190]) ?>
        <?= $v->field('summary', 'Summary', ['type' => 'textarea', 'rows' => 4, 'value' => $o('summary', $course->summary), 'error' => $errors['summary'] ?? null, 'optional' => true]) ?>
        <button type="submit" class="button button-small">Save details</button>
      </form>
    </details>
  </aside>
</div>
