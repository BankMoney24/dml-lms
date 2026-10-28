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
      <form method="post" action="<?= $base ?>/lessons" class="form" novalidate data-type-switch>
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
        <div data-lesson-type="video">
        <?= $v->field('media_url', 'Video link (video lessons only)', [
            'value' => $o('media_url'), 'error' => $errors['media_url'] ?? null, 'optional' => true, 'inputmode' => 'url', 'maxlength' => 500,
            'hint' => 'A YouTube or Vimeo address. To upload your own video instead, leave this empty: the next page has the upload.',
        ]) ?>
        </div>
        <p class="small muted" data-lesson-type="audio">You upload the recording on the next page.</p>
        <div data-lesson-type="lab">
        <?= $v->field('lab_key', 'Lab (lab lessons only)', [
            'type' => 'select', 'options' => ['' => 'Choose a lab'] + $labOptions, 'value' => $o('lab_key'), 'error' => $errors['lab_key'] ?? null,
            'hint' => 'Leave the title empty to use the lab’s own title.',
        ]) ?>
        <p class="small"><a href="/teach/labs">Browse and try the labs</a></p>
        </div>
        <?= $v->field('body', 'Lesson text', [
            'type' => 'textarea', 'rows' => 8, 'value' => $o('body'), 'error' => $errors['body'] ?? null,
            'hint' => 'Reading: the lesson itself. Video or audio: notes or a transcript. Quiz: instructions. Assignment: the task and how it is judged. Lab: an optional introduction.',
        ]) ?>
        <?php include __DIR__ . '/_format_help.php'; ?>
        <details class="advanced" data-lesson-type="quiz"<?= isset($errors['pass_threshold']) || isset($errors['max_attempts']) ? ' open' : '' ?>>
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

    <?php
    $allLessons = array_merge([], ...array_map(static fn (array $m): array => $m['lessons'], $outline));
    $requiredCount = count(array_filter($allLessons, static fn ($l): bool => $l->mandatory));
    ?>
    <section class="panel" aria-labelledby="completion-h">
      <h2 id="completion-h">Finishing the course</h2>
      <p class="small">Learners finish when every required lesson is done: <strong><?= $requiredCount ?> of <?= count($allLessons) ?></strong> lessons are required. Quizzes count once passed, assignments once approved. Make a lesson optional in its editor.</p>
      <form method="post" action="<?= $base ?>/completion" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('completion_min_average', 'Average needed across the required quizzes (%)', [
            'value' => $o('completion_min_average', $course->completionMinAverage === null ? '' : (string) $course->completionMinAverage),
            'inputmode' => 'numeric', 'optional' => true, 'maxlength' => 4, 'error' => $errors['completion_min_average'] ?? null,
            'hint' => ($requiredQuizzes ?? 0) === 0
                ? 'This course has no required quizzes yet, so an average has no effect until you add one.'
                : 'Each quiz’s best score counts (' . $v->plural((int) $requiredQuizzes, 'required quiz', 'required quizzes') . '). Leave empty to rely on each quiz’s own pass mark.',
        ]) ?>
        <button type="submit" class="button button-small">Save</button>
      </form>
    </section>

    <section class="panel" aria-labelledby="enrol-h">
      <h2 id="enrol-h">How people join</h2>
      <?php $enrolNow = $o('enrolment', $course->enrolment); ?>
      <form method="post" action="<?= $base ?>/enrolment" class="form" novalidate>
        <?= $v->csrfField() ?>
        <fieldset class="field pick-list<?= isset($errors['enrolment']) ? ' has-error' : '' ?>">
          <legend class="visually-hidden">How people join this course</legend>
          <label class="choice pick">
            <input type="radio" name="enrolment" value="open"<?= $enrolNow !== 'apply' ? ' checked' : '' ?>>
            <span><strong>They join directly</strong><span class="pick-hint">Learners enrol themselves<?= $course->isPaid() ? ' after paying' : '' ?>.</span></span>
          </label>
          <label class="choice pick">
            <input type="radio" name="enrolment" value="apply"<?= $enrolNow === 'apply' ? ' checked' : '' ?>>
            <span><strong>They apply and wait</strong><span class="pick-hint">People fill a short form; you offer places under Applications, in the order they applied<?= $course->isPaid() ? ', and they pay once offered' : '' ?>.</span></span>
          </label>
        </fieldset>
        <?= $v->field('capacity', 'Number of places', [
            'value' => $o('capacity', $course->capacity === null ? '' : (string) $course->capacity), 'inputmode' => 'numeric', 'optional' => true,
            'error' => $errors['capacity'] ?? null, 'hint' => 'When this many people have joined, new people go on the waiting list. Leave empty for no limit.',
        ]) ?>
        <button type="submit" class="button button-small">Save</button>
      </form>
    </section>

    <section class="panel" aria-labelledby="access-h">
      <h2 id="access-h">Who can read it</h2>
      <?php $accessNow = $o('access', $course->access); ?>
      <form method="post" action="<?= $base ?>/access" class="form" novalidate>
        <?= $v->csrfField() ?>
        <fieldset class="field pick-list<?= isset($errors['access']) ? ' has-error' : '' ?>">
          <legend class="visually-hidden">Who can read this course</legend>
          <?php if (isset($errors['access'])): ?><p class="field-error" id="access"><?= $v->e($errors['access']) ?></p><?php endif; ?>
          <label class="choice pick">
            <input type="radio" name="access" value="members"<?= $accessNow !== 'open' ? ' checked' : '' ?>>
            <span><strong>People who join</strong><span class="pick-hint">Learners join the course to read it<?= $course->isPaid() ? ' after paying' : '' ?>. Lessons you mark as a free preview stay open to everyone.</span></span>
          </label>
          <label class="choice pick<?= $course->isPaid() ? ' is-disabled' : '' ?>">
            <input type="radio" name="access" value="open"<?= $accessNow === 'open' ? ' checked' : '' ?><?= $course->isPaid() ? ' disabled' : '' ?>>
            <span><strong>Everyone <?= $v->badge('Open', 'open') ?></strong><span class="pick-hint"><?= $course->isPaid()
                ? 'Only free courses can be open. Remove the price first.'
                : 'Anyone can read the readings and videos, even without an account, and it shows in the course list for visitors. Quizzes, labs and assignments still need joining, so progress and certificates work as usual.' ?></span></span>
          </label>
        </fieldset>
        <button type="submit" class="button button-small">Save</button>
      </form>
    </section>

    <section class="panel" aria-labelledby="price-h">
      <h2 id="price-h">Price</h2>
      <?php if ($v->can('school.manage')): ?>
        <form method="post" action="<?= $base ?>/price" class="form price-form" novalidate>
          <?= $v->csrfField() ?>
          <div class="price-fields">
            <?= $v->field('currency', 'Currency', [
                'type' => 'select', 'error' => $errors['currency'] ?? null,
                'options' => array_map(static fn (array $c): string => $c[0] . ' ' . $c[1], \App\Modules\Payments\Money::CURRENCIES),
                'value' => $o('currency', $defaultCurrency),
            ]) ?>
            <?= $v->field('price', 'Price', [
                'value' => $o('price', $course->priceMinor === null ? '' : rtrim(rtrim(number_format($course->priceMinor / 100, 2, '.', ''), '0'), '.')),
                'inputmode' => 'decimal', 'optional' => true, 'error' => $errors['price'] ?? null,
            ]) ?>
          </div>
          <?= $v->field('instalments', 'Paying in parts', [
              'type' => 'select', 'error' => $errors['instalments'] ?? null,
              'options' => ['' => 'In full only', '2' => 'In full, or 2 monthly parts', '3' => 'In full, or 3 monthly parts', '4' => 'In full, or 4 monthly parts'],
              'value' => $o('instalments', $course->instalments === null ? '' : (string) $course->instalments),
              'hint' => 'The first part opens the course; the next is due every 30 days. If a part is more than 7 days late the course pauses until it is paid.',
          ]) ?>
          <p class="hint">Leave the price blank for a free course. Learners pay before they can start; take payments on the <a href="/school/payments">Payments</a> page.</p>
          <button type="submit" class="button button-small">Save price</button>
        </form>
      <?php else: ?>
        <p><?= $course->isPaid() ? '<strong>' . $v->e((string) $course->priceLabel()) . '</strong>' . ($course->instalments !== null ? ', or ' . $course->instalments . ' monthly parts' : '') . '. Learners pay before they can start.' : 'Free.' ?></p>
        <p class="small muted">A school administrator sets prices.</p>
      <?php endif; ?>
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
