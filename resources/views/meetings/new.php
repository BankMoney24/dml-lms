<?php
/**
 * Start or plan a meeting (ADR 0030). One page, top to bottom: what, when,
 * who. "Now" takes the host straight into the room.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<int, string> $groups
 * @var bool $ready
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var list<int> $minutes
 */
$o = static fn (string $k): string => is_string($old[$k] ?? null) ? $old[$k] : '';
$picked = static fn (string $k, int $id): bool => in_array((string) $id, array_map('strval', (array) ($old[$k] ?? [])), true);
$later = $o('when') === 'later';
$lengths = [];
foreach ($minutes as $m) {
    $lengths[(string) $m] = $m < 60 ? $m . ' minutes' : ($m % 60 === 0 ? ($m / 60) . ($m === 60 ? ' hour' : ' hours') : intdiv($m, 60) . '½ hours');
}
?>
<?= $v->breadcrumbs(['Meetings' => '/meetings', 'New meeting' => null]) ?>
<header class="page-head">
  <h1>New meeting</h1>
  <p class="lead">Meet in the online classroom. Only the people you invite can get in, and they are told straight away.</p>
</header>
<?php if (!$ready): ?>
  <p class="notice">The online classroom is not connected yet. <?= $v->can('school.manage') ? 'Connect it under <a href="/school/classroom">School, Classroom</a>.' : 'Ask an administrator to connect it.' ?></p>
<?php endif; ?>
<?= $v->errorSummary($errors) ?>

<form method="post" action="/meetings" class="form meet-form" novalidate data-meet-form>
  <?= $v->csrfField() ?>

  <section class="meet-step" aria-labelledby="what-h">
    <h2 id="what-h" class="meet-step-title"><span class="meet-step-n" aria-hidden="true">1</span>What</h2>
    <?= $v->field('title', 'Title', ['value' => $o('title'), 'maxlength' => 160, 'error' => $errors['title'] ?? null, 'hint' => 'For example: Staff meeting, SS 2 parents’ evening, Call with Mrs Bello.']) ?>
    <?= $v->field('agenda', 'Agenda', ['type' => 'textarea', 'rows' => 3, 'value' => $o('agenda'), 'optional' => true, 'error' => $errors['agenda'] ?? null]) ?>
  </section>

  <section class="meet-step" aria-labelledby="when-h">
    <h2 id="when-h" class="meet-step-title"><span class="meet-step-n" aria-hidden="true">2</span>When</h2>
    <fieldset class="opts" role="radiogroup">
      <legend class="visually-hidden">When</legend>
      <label class="opt"><input type="radio" name="when" value="now"<?= $later ? '' : ' checked' ?> data-when><span>Now</span></label>
      <label class="opt"><input type="radio" name="when" value="later"<?= $later ? ' checked' : '' ?> data-when><span>Later</span></label>
    </fieldset>
    <div class="field-row" data-when-later<?= $later ? '' : ' hidden' ?>>
      <?= $v->field('date', 'Day', ['type' => 'date', 'value' => $o('date'), 'error' => $errors['date'] ?? null]) ?>
      <?= $v->field('time', 'Starts', ['type' => 'time', 'value' => $o('time'), 'hint' => ($v->shared('tenant')?->timezone ?? 'UTC') . ' time']) ?>
    </div>
    <?php if (isset($errors['date']) && !$later): ?><p class="field-error"><?= $v->e($errors['date']) ?></p><?php endif; ?>
    <?= $v->field('minutes', 'How long', ['type' => 'select', 'options' => $lengths, 'value' => $o('minutes'), 'error' => $errors['minutes'] ?? null]) ?>
  </section>

  <section class="meet-step" aria-labelledby="who-h" id="invite">
    <h2 id="who-h" class="meet-step-title"><span class="meet-step-n" aria-hidden="true">3</span>Who</h2>
    <?php if (isset($errors['invite'])): ?><p class="field-error"><?= $v->e($errors['invite']) ?></p><?php endif; ?>
    <?php include __DIR__ . '/_audience.php'; ?>
  </section>

  <label class="choice choice-tight"><input type="checkbox" name="staff_moderate" value="1"<?= $o('staff_moderate') === '1' ? ' checked' : '' ?>>
    <span><strong>Other staff can run it too.</strong> Teachers and administrators you invite can mute people and let them speak.</span></label>

  <div class="meet-submit">
    <button type="submit" class="button" data-when-label-now="Start the meeting now" data-when-label-later="Plan the meeting and invite"><?= $later ? 'Plan the meeting and invite' : 'Start the meeting now' ?></button>
    <a class="button button-quiet" href="/meetings">Cancel</a>
  </div>
</form>
