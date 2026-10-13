<?php
/**
 * A lab at the bench: the simulation, the task and the answer form; or,
 * once checked, the marked result. Also used for a teacher's practice run
 * ($preview set, nothing stored).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\VirtualLabs\LabDefinition $definition
 * @var ?\App\Modules\VirtualLabs\LabSession $session
 * @var ?\App\Modules\Courses\Course $course
 * @var ?\App\Modules\Courses\Lesson $lesson
 * @var array<string, mixed> $params
 * @var list<\App\Modules\VirtualLabs\LabField> $fields
 * @var ?\App\Modules\VirtualLabs\LabResult $result
 * @var int $secondsLeft
 * @var string $action
 * @var string $restart
 * @var string $back
 * @var ?\App\Modules\Courses\Lesson $next
 * @var ?array{seed: string, until: int, signature: string, pass: int} $preview
 */
$open = $preview !== null ? $result === null : ($session !== null && $session->isOpen());
$passMark = $definition->passMark;
$passed = $result !== null && $result->score >= $passMark;
$trail = $preview !== null
    ? ['Lab catalogue' => '/teach/labs', $definition->title => null]
    : [$course->title => '/courses/' . $course->slug, $lesson->title => $back, 'Lab' => null];
?>
<?= $v->breadcrumbs($trail) ?>

<header class="lab-head">
  <div>
    <p class="lab-kind"><?= $v->e($definition->subject) ?>, <?= $v->e($definition->level) ?><?= $preview !== null ? '. Practice run: nothing is saved' : '' ?></p>
    <h1><?= $v->e($definition->title) ?></h1>
  </div>
  <?php if ($open): ?>
  <div class="lab-clock">
    <p class="lab-timer" data-lab-timer data-seconds="<?= $secondsLeft ?>" role="timer" aria-live="off">
      <span class="lab-timer-value"><?= intdiv($secondsLeft + 59, 60) ?> min</span> left
    </p>
    <?php if ($preview === null && $session !== null): ?>
    <form method="post" action="/labs/<?= $session->id ?>/end" class="inline">
      <?= $v->csrfField() ?>
      <button type="submit" class="linklike">End without checking</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</header>

<?php if ($session !== null && $session->status === \App\Modules\VirtualLabs\LabSession::EXPIRED): ?>
  <div class="notice notice-err">
    <p><strong>Time ran out</strong> before this attempt was checked, so it was not marked.</p>
    <form method="post" action="<?= $v->e($restart) ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="button button-small">Start again with new numbers</button></form>
  </div>
<?php elseif ($session !== null && $session->status === \App\Modules\VirtualLabs\LabSession::ENDED): ?>
  <div class="notice">
    <p>This attempt was ended without being checked.</p>
    <form method="post" action="<?= $v->e($restart) ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="button button-small">Start the lab again</button></form>
  </div>
<?php endif; ?>

<?php if ($result !== null): ?>
<section class="lab-report" aria-labelledby="report-h">
  <div class="lab-report-head">
    <h2 id="report-h"><?= $passed ? 'Passed' : 'Not passed yet' ?>: <?= $result->score ?>%</h2>
    <p class="muted">The pass mark is <?= $passMark ?>%.<?= $preview !== null ? ' This was a practice run; nothing was saved.' : '' ?></p>
  </div>
  <ul class="lab-marks">
    <?php foreach ($result->feedback as $line): ?>
    <li class="<?= $line['ok'] ? 'is-ok' : 'is-wrong' ?>">
      <span class="lab-mark" aria-hidden="true"><?= $line['ok'] ? '✓' : '✕' ?></span>
      <span><strong><?= $v->e($line['label']) ?></strong><span class="visually-hidden"><?= $line['ok'] ? ': correct.' : ': not correct.' ?></span> <?= $v->e($line['detail']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (is_string($result->evidence['code'] ?? null) && $result->evidence['code'] !== ''): ?>
    <details class="lab-evidence">
      <summary>The code you sent</summary>
      <pre><code><?= $v->e($result->evidence['code']) ?></code></pre>
    </details>
  <?php endif; ?>
  <div class="row-actions lab-report-actions">
    <?php if ($preview !== null): ?>
      <a class="button" href="<?= $v->e($restart) ?>">Try again with new numbers</a>
      <a href="<?= $v->e($back) ?>">Back to the catalogue</a>
    <?php else: ?>
      <?php if ($passed && $next !== null): ?>
        <a class="button" href="/courses/<?= $v->e($course->slug) ?>/lessons/<?= $next->id ?>">Next: <?= $v->e($next->title) ?></a>
      <?php endif; ?>
      <form method="post" action="<?= $v->e($restart) ?>" class="inline">
        <?= $v->csrfField() ?>
        <button type="submit" class="button<?= $passed ? ' button-quiet' : '' ?>"><?= $passed ? 'Practise again' : 'Try again with new numbers' ?></button>
      </form>
      <a href="<?= $v->e($back) ?>">Back to the lesson</a>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($open): ?>
<section class="lab-stage" id="lab-root" aria-label="Simulation"
  data-lab="<?= $v->e($definition->key) ?>"
  data-params="<?= $v->e((string) json_encode($params, JSON_UNESCAPED_UNICODE)) ?>"
  data-runner="/labs/runner">
  <p class="lab-loading">Loading the simulation…</p>
  <noscript><p class="notice notice-err">This lab runs in your browser and needs JavaScript. Turn JavaScript on, or use another browser.</p></noscript>
</section>

<div class="lab-bench">
  <section class="lab-task" aria-labelledby="task-h">
    <h2 id="task-h">Your task</h2>
    <p><?= $v->e($definition->objective) ?></p>
    <ol>
      <?php foreach ($definition->tasks as $task): ?><li><?= $v->e($task) ?></li><?php endforeach; ?>
    </ol>
    <p class="small muted">Pass mark <?= $passMark ?>%. Your numbers are different from everyone else’s.</p>
  </section>

  <section class="lab-answers" aria-labelledby="answers-h">
    <h2 id="answers-h">Your answers</h2>
    <form method="post" action="<?= $v->e($action) ?>" class="form" id="lab-answers" novalidate>
      <?= $v->csrfField() ?>
      <?php if ($preview !== null): ?>
        <input type="hidden" name="seed" value="<?= $v->e($preview['seed']) ?>">
        <input type="hidden" name="until" value="<?= (int) $preview['until'] ?>">
        <input type="hidden" name="signature" value="<?= $v->e($preview['signature']) ?>">
      <?php endif; ?>
      <?php foreach ($fields as $f):
        $id = 'answer-' . $f->name;
        $name = 'answers[' . $f->name . ']';
        $label = $f->label . ($f->unit !== '' ? ' (' . $f->unit . ')' : '');
      ?>
        <?php if ($f->type === 'hidden'): ?>
          <input type="hidden" id="<?= $v->e($id) ?>" name="<?= $v->e($name) ?>" value="">
        <?php elseif ($f->type === 'select'): ?>
          <?= $v->field($name, $label, ['type' => 'select', 'id' => $id, 'options' => $f->options, 'hint' => $f->hint !== '' ? $f->hint : null, 'class' => 'lab-field']) ?>
        <?php elseif ($f->type === 'code'): ?>
          <?= $v->field($name, $label, ['type' => 'textarea', 'id' => $id, 'rows' => $f->rows, 'hint' => $f->hint !== '' ? $f->hint : null, 'class' => 'field-code lab-code', 'value' => (string) ($params['starter'] ?? '')]) ?>
        <?php else: ?>
          <?= $v->field($name, $label, [
              'id' => $id, 'hint' => $f->hint !== '' ? $f->hint : null, 'class' => 'lab-field',
              'inputmode' => $f->type === 'number' ? 'decimal' : null, 'autocomplete' => 'off', 'maxlength' => 40,
          ]) ?>
        <?php endif; ?>
      <?php endforeach; ?>
      <button type="submit" class="button" data-lab-submit>Check my answers</button>
      <p class="small muted">Checking ends this attempt. You can start again with new numbers as often as you like.</p>
    </form>
  </section>
</div>

<script src="<?= $v->asset('labs/lab.js') ?>" defer></script>
<script src="<?= $v->asset($definition->script()) ?>" defer></script>
<?php endif; ?>
