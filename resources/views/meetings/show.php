<?php
/**
 * One meeting (ADR 0030): when, joining, and for the host who is invited,
 * who came, inviting more, ending and cancelling.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Meetings\Meeting $meeting
 * @var bool $isHost
 * @var bool $runs
 * @var list<array{user_id: int, name: string, email: string, role: string}> $invitees
 * @var array<int, int> $minutesIn
 * @var array<int, string> $groups
 * @var int $now
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$m = $meeting;
$phase = $m->phase($now);
$school = $v->shared('tenant');
$o = static fn (string $k): string => (string) ($old[$k] ?? '');
$picked = static fn (string $k, int $id): bool => false;
$opensAt = $m->startTs() - ($runs ? \App\Modules\Meetings\Meeting::HOST_EARLY_MINUTES : \App\Modules\Meetings\Meeting::JOIN_EARLY_MINUTES) * 60;
$canJoin = in_array($phase, ['open', 'live'], true) || ($runs && $phase === 'before' && $now >= $opensAt);
$came = count(array_filter($invitees, static fn (array $i): bool => isset($minutesIn[$i['user_id']])));
$roleLabels = ['owner' => 'Staff', 'admin' => 'Staff', 'educator' => 'Teacher', 'learner' => 'Learner', 'guardian' => 'Parent'];
?>
<?= $v->breadcrumbs(['Meetings' => '/meetings', $m->title => null]) ?>
<header class="page-head lc-head">
  <p class="lc-kicker small"><?= $isHost ? 'You host this meeting' : 'Hosted by ' . $v->e($m->hostName) ?></p>
  <h1><?= $v->e($m->title) ?></h1>
  <p class="lc-when"><strong><?= $v->datetime($m->startsAt, 'l j F') ?></strong>, <?= $v->datetime($m->startsAt, 'H:i') ?>–<?= $v->datetime($m->endsAt, 'H:i') ?> <span class="small muted">(<?= $v->e($school?->timezone ?? 'UTC') ?> time)</span></p>
</header>
<?= $v->errorSummary($errors) ?>

<div class="lc-layout">
  <div class="lc-main">
    <section class="lc-card lc-card-<?= $v->e($phase) ?>" aria-labelledby="join-h">
      <h2 id="join-h" class="visually-hidden">Joining</h2>
      <?php if ($phase === 'cancelled'): ?>
        <p class="lc-status"><?= $v->badge('Cancelled', 'danger') ?></p>
        <p><?= $v->e((string) $m->cancelledReason) ?></p>
      <?php elseif ($canJoin): ?>
        <?php if ($phase === 'live'): ?><p class="lc-status"><span class="lc-live">Live now</span></p><?php else: ?><p class="lc-status"><span class="lc-live lc-soon">Starting soon</span></p><?php endif; ?>
        <div class="lc-join join-bar">
          <a class="button lc-join-button" href="/meetings/<?= $m->id ?>/room"><?= $v->icon('live') ?><?= $isHost ? 'Open the meeting' : 'Join the meeting' ?></a>
          <span class="small muted">Works in a phone’s browser. Choose audio only to save data.</span>
        </div>
      <?php elseif ($phase === 'before'): ?>
        <p class="lc-where"><?= $v->icon('live') ?><span>In the online classroom. You can join from <?= $v->datetime(gmdate('Y-m-d H:i:s', $opensAt), 'H:i') ?><?= $runs ? ' (30 minutes early, to set up)' : '' ?>.</span></p>
      <?php else: ?>
        <p class="lc-where"><?= $v->icon('live') ?><span>This meeting has ended.</span></p>
      <?php endif; ?>
    </section>

    <?php if ($m->agenda !== ''): ?>
    <section class="lc-notes" aria-labelledby="agenda-h">
      <h2 id="agenda-h">Agenda</h2>
      <div class="prose"><?= $v->markdown($m->agenda) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($isHost): ?>
    <section class="lc-section" id="people" aria-labelledby="people-h">
      <div class="settings-head"><h2 id="people-h">People</h2><span class="small muted"><?= $v->e($v->plural(count($invitees), 'person', 'people')) ?> invited<?= $phase !== 'before' && !$m->isCancelled() ? ', ' . $came . ' joined' : '' ?></span></div>
      <ul class="plain-list meet-people">
        <?php foreach ($invitees as $i): $mins = $minutesIn[$i['user_id']] ?? null; ?>
        <li>
          <span class="meet-person"><?= $v->e($i['name']) ?> <span class="small muted"><?= $v->e($roleLabels[$i['role']] ?? '') ?></span></span>
          <span class="small <?= $mins === null ? 'muted' : 'meet-came' ?>"><?= $mins === null ? ($phase === 'before' ? 'Invited' : 'Not joined') : 'About ' . $mins . ' min' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$m->isCancelled() && $phase !== 'ended'): ?>
      <details class="panel"<?= $o('invite_open') === '1' ? ' open' : '' ?>>
        <summary>Invite more people</summary>
        <form method="post" action="/meetings/<?= $m->id ?>/invite" class="form" novalidate>
          <?= $v->csrfField() ?>
          <?php if (isset($errors['invite'])): ?><p class="field-error"><?= $v->e($errors['invite']) ?></p><?php endif; ?>
          <?php include __DIR__ . '/_audience.php'; ?>
          <button type="submit" class="button button-small">Invite and tell them</button>
        </form>
      </details>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="lc-side" aria-label="About this meeting">
    <dl class="facts small">
      <dt>Host</dt><dd><?= $v->e($m->hostName) ?></dd>
      <dt>Where</dt><dd>Online classroom on this site</dd>
      <dt>Length</dt><dd><?= $m->minutes() ?> minutes</dd>
    </dl>
    <?php if ($isHost && !$m->isCancelled() && $phase !== 'ended'): ?>
      <?php if ($phase === 'live'): ?>
      <details class="panel">
        <summary>End the meeting now</summary>
        <form method="post" action="/meetings/<?= $m->id ?>/end">
          <?= $v->csrfField() ?>
          <p class="small">No one can join after this. Who came stays listed here.</p>
          <button type="submit" class="button button-small button-danger">End the meeting</button>
        </form>
      </details>
      <?php endif; ?>
      <details class="panel lc-cancel"<?= $o('cancel_open') === '1' ? ' open' : '' ?>>
        <summary>Cancel this meeting</summary>
        <form method="post" action="/meetings/<?= $m->id ?>/cancel" class="form">
          <?= $v->csrfField() ?>
          <?= $v->field('reason', 'Tell people why', ['value' => $o('reason'), 'maxlength' => 255, 'error' => $errors['reason'] ?? null]) ?>
          <button type="submit" class="button button-small button-danger">Cancel the meeting</button>
        </form>
      </details>
    <?php endif; ?>
  </aside>
</div>
