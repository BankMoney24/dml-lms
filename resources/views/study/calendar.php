<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var ?string $newLink shown once, straight after it is made
 * @var bool $hasLink
 * @var list<array{course_id: int, slug: string, title: string, due_on: string, group: string, started: bool}> $upcoming
 */
?>
<?= $v->breadcrumbs(['Your account' => '/account', 'Calendar link' => null]) ?>
<header class="page-head">
  <h1>Due dates in your calendar</h1>
  <p class="lead">Add the dates your teachers set to Google Calendar, your phone or Outlook. They update on their own when a date changes.</p>
</header>

<div class="teach-layout">
  <section aria-labelledby="link-h">
    <?php if ($newLink !== null): ?>
    <div class="panel calendar-new" role="status">
      <h2 id="link-h">Your calendar link</h2>
      <p>Copy it now. For your safety we only show it once; if you lose it, make a new one.</p>
      <div class="copy-row">
        <label for="cal-link" class="visually-hidden">Calendar link</label>
        <input id="cal-link" type="text" readonly value="<?= $v->e($newLink) ?>">
        <button type="button" class="button button-small" data-copy="cal-link" hidden>Copy link</button>
      </div>
      <ol class="steps steps-tight">
        <li><strong>Google Calendar</strong> on a computer: Other calendars, then the plus sign, then From URL. Paste the link.</li>
        <li><strong>iPhone or iPad</strong>: Settings, Calendar, Accounts, Add Account, Other, Add Subscribed Calendar. Paste the link.</li>
        <li><strong>Outlook</strong>: Add calendar, then Subscribe from web. Paste the link.</li>
      </ol>
      <p class="small muted">Anyone with this link can see your course titles and due dates, and nothing else. Do not post it in a group chat.</p>
    </div>
    <?php else: ?>
    <div class="panel">
      <h2 id="link-h"><?= $hasLink ? 'Your calendar link is on' : 'Make a calendar link' ?></h2>
      <p><?= $hasLink
          ? 'A calendar link exists for your account. If you lost it, or shared it by mistake, make a new one: the old one stops working straight away.'
          : 'You get a private web address to paste into your calendar app once. It shows course titles and due dates only.' ?></p>
      <form method="post" action="/account/calendar" class="actions">
        <?= $v->csrfField() ?>
        <button type="submit" class="button"><?= $hasLink ? 'Make a new link' : 'Make my calendar link' ?></button>
      </form>
    </div>
    <?php endif; ?>
    <?php if ($hasLink): ?>
    <form method="post" action="/account/calendar/revoke" class="calendar-off">
      <?= $v->csrfField() ?>
      <button type="submit" class="linklike small">Turn the calendar link off</button>
    </form>
    <?php endif; ?>
  </section>

  <aside aria-labelledby="dates-h">
    <section class="panel">
      <h2 id="dates-h">What it will show</h2>
      <?php if ($upcoming === []): ?>
        <p class="muted">No due dates yet. When a teacher sets a date for a course in your <?= $v->e($v->shared('tenant')?->groupWord() ?? 'class') ?>, it appears here and in your calendar.</p>
      <?php else: ?>
        <ul class="due-list">
          <?php foreach ($upcoming as $d): $date = new DateTimeImmutable($d['due_on']); ?>
          <li><span class="due-date" aria-hidden="true"><span class="due-day"><?= $date->format('j') ?></span><span class="due-month"><?= $date->format('M') ?></span></span>
            <span class="due-text"><a href="/courses/<?= $v->e($d['slug']) ?>"><?= $v->e($d['title']) ?></a><span class="small muted">Due <?= $v->e($date->format('l j F')) ?></span></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </aside>
</div>
