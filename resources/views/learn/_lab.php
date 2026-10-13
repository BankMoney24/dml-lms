<?php
/**
 * A lab lesson: what the lab is, the learner's attempts, and the button to start.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Courses\Course $course
 * @var \App\Modules\Courses\Lesson $lesson
 * @var array{definition: ?\App\Modules\VirtualLabs\LabDefinition, open: ?\App\Modules\VirtualLabs\LabSession, history: list<\App\Modules\VirtualLabs\LabSession>, best: ?int} $lab
 * @var bool $completed
 */
$d = $lab['definition'];
$start = '/courses/' . $course->slug . '/lessons/' . $lesson->id . '/lab';
?>
<?php if ($d === null): ?>
  <p class="notice notice-err">This lab is not available in this version of the platform. Ask your teacher to check the lesson.</p>
<?php else: ?>
  <?php if ($lesson->body !== ''): ?><div class="prose"><?= $v->markdown($lesson->body) ?></div><?php endif; ?>

  <section class="lab-intro" aria-labelledby="lab-h">
    <h2 id="lab-h"><?= $v->e($d->title) ?></h2>
    <p><?= $v->e($d->objective) ?></p>
    <dl class="facts lab-facts">
      <dt>Subject</dt><dd><?= $v->e($d->subject) ?>, <?= $v->e($d->level) ?></dd>
      <dt>Time</dt><dd><?= $d->minutes ?> minutes for each attempt</dd>
      <dt>To pass</dt><dd><?= $d->passMark ?>% or more</dd>
      <?php if ($d->prerequisites !== ''): ?><dt>You need</dt><dd><?= $v->e($d->prerequisites) ?></dd><?php endif; ?>
      <?php if ($lab['best'] !== null): ?><dt>Your best</dt><dd><?= $lab['best'] ?>%</dd><?php endif; ?>
    </dl>
    <p class="small muted">It runs in your browser and uses very little data once loaded. Each attempt gives you new numbers.</p>

    <?php if ($lab['open'] !== null && $lab['open']->secondsLeft(time()) > 0): ?>
      <p><a class="button" href="/labs/<?= $lab['open']->id ?>">Continue the lab (<?= intdiv($lab['open']->secondsLeft(time()) + 59, 60) ?> min left)</a></p>
    <?php else: ?>
      <form method="post" action="<?= $v->e($start) ?>">
        <?= $v->csrfField() ?>
        <button type="submit" class="button"><?= $lab['history'] === [] ? 'Start the lab' : ($completed ? 'Practise again' : 'Try again') ?></button>
      </form>
    <?php endif; ?>
    <?php if ($completed): ?><p class="notice notice-done">You have passed this lab.</p><?php endif; ?>
  </section>

  <?php $finished = array_values(array_filter($lab['history'], static fn ($s): bool => !$s->isOpen())); ?>
  <?php if ($finished !== []): ?>
  <section class="lab-attempts" aria-labelledby="attempts-h">
    <h2 id="attempts-h">Your attempts</h2>
    <ol class="attempt-list" reversed>
      <?php foreach ($finished as $s): ?>
      <li>
        <a href="/labs/<?= $s->id ?>"><?= $v->datetime($s->startedAt) ?></a>
        <?= $s->score !== null ? $v->badge($s->score . '%', $s->passed ? 'ok' : 'draft') : '' ?>
        <span class="small muted"><?= $v->e($s->statusLabel()) ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>
<?php endif; ?>
