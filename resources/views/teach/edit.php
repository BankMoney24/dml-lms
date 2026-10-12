<?php
/** @var \App\Core\View\ViewHelpers $v */
/** @var \App\Modules\Courses\Course $course */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
$action = '/teach/courses/' . $course->id;
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="err-' . $f . '"' : '';
?>
<nav class="crumbs" aria-label="Breadcrumb"><a href="/teach">Your courses</a></nav>
<section class="page-head page-head-row">
  <div>
    <h1><?= $v->e($course->title) ?></h1>
    <p><span class="status status-<?= $v->e($course->status) ?>"><?= $course->isPublished() ? 'Published' : 'Draft' ?></span>
      <?php if ($course->isPublished()): ?><a href="/courses/<?= $v->e($course->slug) ?>">See it as a learner</a><?php endif; ?></p>
  </div>
  <?php if (!$course->isPublished()): ?>
  <form method="post" action="<?= $action ?>/publish">
    <?= $v->csrfField() ?>
    <button type="submit" class="button"<?= $publishProblems !== [] ? ' aria-describedby="publish-problems"' : '' ?>>Publish course</button>
  </form>
  <?php endif; ?>
</section>

<?php if (isset($errors['publish'])): ?><p class="notice notice-err" role="alert"><?= $v->e($errors['publish']) ?></p><?php endif; ?>
<?php if ($publishProblems !== [] && !isset($errors['publish'])): ?>
<div class="notice" id="publish-problems">
  <p>Before you publish:</p>
  <ul><?php foreach ($publishProblems as $p): ?><li><?= $v->e($p) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="teach-layout">
  <section aria-labelledby="outline-h">
    <h2 id="outline-h">Outline</h2>
    <?php if ($outline === []): ?>
      <p class="empty">Add a module to start the outline. Modules group lessons, for example “Week 1” or “Electricity”.</p>
    <?php endif; ?>
    <?php foreach ($outline as $mi => $module): ?>
    <div class="t-module">
      <h3><?= $v->e($module['title']) ?></h3>
      <?php if ($module['lessons'] === []): ?><p class="muted small">No lessons yet.</p><?php endif; ?>
      <ol class="t-lessons">
        <?php foreach ($module['lessons'] as $lesson): ?>
        <li id="lesson-<?= $lesson->id ?>">
          <span class="t-lesson-title"><?= $v->e($lesson->title) ?></span>
          <span class="tag"><?= $lesson->isQuiz() ? 'Quiz' : 'Reading' ?></span>
          <?php if ($lesson->isQuiz()): $count = $questionCounts[$lesson->id] ?? 0; $open = $o('question_lesson') === (string) $lesson->id; ?>
            <span class="small muted"><?= $count ?> <?= $count === 1 ? 'question' : 'questions' ?></span>
            <details class="q-builder"<?= $open || $count === 0 ? ' open' : '' ?>>
              <summary>Add a question</summary>
              <form method="post" action="<?= $action ?>/lessons/<?= $lesson->id ?>/questions" class="form" novalidate>
                <?= $v->csrfField() ?>
                <div class="field">
                  <label for="prompt-<?= $lesson->id ?>">Question</label>
                  <textarea id="prompt-<?= $lesson->id ?>" name="prompt" rows="2" maxlength="1000"<?= $open ? $invalid('prompt') : '' ?>><?= $open ? $v->e($o('prompt')) : '' ?></textarea>
                  <?php if ($open) { $field = 'prompt'; include __DIR__ . '/_field_error.php'; } ?>
                </div>
                <fieldset class="field">
                  <legend>Answer options (select the correct one)</legend>
                  <?php for ($i = 0; $i < 4; $i++): ?>
                  <div class="option-row">
                    <input type="radio" name="correct" value="<?= $i ?>" id="c-<?= $lesson->id ?>-<?= $i ?>" <?= $open && $o('correct') === (string) $i ? 'checked' : '' ?>>
                    <label class="visually-hidden" for="c-<?= $lesson->id ?>-<?= $i ?>">Option <?= $i + 1 ?> is correct</label>
                    <label class="visually-hidden" for="o-<?= $lesson->id ?>-<?= $i ?>">Option <?= $i + 1 ?></label>
                    <input id="o-<?= $lesson->id ?>-<?= $i ?>" name="options[<?= $i ?>]" maxlength="500" placeholder="Option <?= $i + 1 ?><?= $i >= 2 ? ' (optional)' : '' ?>" value="<?= $open ? $v->e($o('option_' . $i)) : '' ?>">
                  </div>
                  <?php endfor; ?>
                  <?php if ($open) { foreach (['options', 'correct'] as $field) { include __DIR__ . '/_field_error.php'; } } ?>
                </fieldset>
                <button type="submit" class="button button-small">Add question</button>
              </form>
            </details>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endforeach; ?>
  </section>

  <aside class="teach-forms">
    <section class="panel" aria-labelledby="add-module-h">
      <h2 id="add-module-h">Add a module</h2>
      <form method="post" action="<?= $action ?>/modules" class="form" novalidate>
        <?= $v->csrfField() ?>
        <div class="field">
          <label for="module_title">Module title</label>
          <input id="module_title" name="module_title" maxlength="190" value="<?= $v->e($o('module_title')) ?>"<?= $invalid('module_title') ?>>
          <?php $field = 'module_title'; include __DIR__ . '/_field_error.php'; ?>
        </div>
        <button type="submit" class="button button-small">Add module</button>
      </form>
    </section>

    <?php if ($outline !== []): ?>
    <section class="panel" aria-labelledby="add-lesson-h">
      <h2 id="add-lesson-h">Add a lesson</h2>
      <form method="post" action="<?= $action ?>/lessons" class="form" novalidate>
        <?= $v->csrfField() ?>
        <div class="field">
          <label for="module_id">Module</label>
          <select id="module_id" name="module_id"<?= $invalid('module_id') ?>>
            <?php foreach ($outline as $module): ?>
            <option value="<?= $module['id'] ?>"<?= $o('module_id') === (string) $module['id'] ? ' selected' : '' ?>><?= $v->e($module['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php $field = 'module_id'; include __DIR__ . '/_field_error.php'; ?>
        </div>
        <fieldset class="field">
          <legend>Lesson type</legend>
          <label class="choice"><input type="radio" name="type" value="text"<?= $o('type', 'text') === 'text' ? ' checked' : '' ?>> <span>Reading: text learners read, then mark complete</span></label>
          <label class="choice"><input type="radio" name="type" value="quiz"<?= $o('type') === 'quiz' ? ' checked' : '' ?>> <span>Quiz: multiple choice, marked on the server</span></label>
          <?php $field = 'type'; include __DIR__ . '/_field_error.php'; ?>
        </fieldset>
        <div class="field">
          <label for="lesson_title">Lesson title</label>
          <input id="lesson_title" name="lesson_title" maxlength="190" value="<?= $v->e($o('lesson_title')) ?>"<?= $invalid('lesson_title') ?>>
          <?php $field = 'lesson_title'; include __DIR__ . '/_field_error.php'; ?>
        </div>
        <div class="field">
          <label for="body">Lesson text</label>
          <p class="hint" id="body-hint">Leave a blank line between paragraphs. For a quiz, this is the instruction shown above the questions.</p>
          <textarea id="body" name="body" rows="8" aria-describedby="body-hint"<?= isset($errors['body']) ? ' aria-invalid="true"' : '' ?>><?= $v->e($o('body')) ?></textarea>
          <?php $field = 'body'; include __DIR__ . '/_field_error.php'; ?>
        </div>
        <details class="advanced"<?= isset($errors['pass_threshold']) || isset($errors['max_attempts']) ? ' open' : '' ?>>
          <summary>Quiz settings</summary>
          <div class="field">
            <label for="pass_threshold">Pass mark (%)</label>
            <input id="pass_threshold" name="pass_threshold" inputmode="numeric" value="<?= $v->e($o('pass_threshold', '70')) ?>"<?= $invalid('pass_threshold') ?>>
            <?php $field = 'pass_threshold'; include __DIR__ . '/_field_error.php'; ?>
          </div>
          <div class="field">
            <label for="max_attempts">Attempts allowed <span class="muted">(blank for unlimited)</span></label>
            <input id="max_attempts" name="max_attempts" inputmode="numeric" value="<?= $v->e($o('max_attempts')) ?>"<?= $invalid('max_attempts') ?>>
            <?php $field = 'max_attempts'; include __DIR__ . '/_field_error.php'; ?>
          </div>
        </details>
        <button type="submit" class="button button-small">Add lesson</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
