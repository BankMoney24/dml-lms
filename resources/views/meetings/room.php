<?php
/**
 * A meeting's room (ADR 0030), or why it is not open.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Meetings\Meeting $meeting
 * @var bool $isHost
 * @var bool $runs
 * @var array{ok: bool, reason: string} $access
 * @var ?array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool} $pass
 * @var int $now
 */
$m = $meeting;
$opensAt = $m->startTs() - ($runs ? \App\Modules\Meetings\Meeting::HOST_EARLY_MINUTES : \App\Modules\Meetings\Meeting::JOIN_EARLY_MINUTES) * 60;
?>
<?php if ($pass === null): ?>
<?= $v->breadcrumbs(['Meetings' => '/meetings', $m->title => '/meetings/' . $m->id, 'Room' => null]) ?>
<section class="room-closed">
  <h1><?= $v->e($m->title) ?></h1>
  <?php if ($access['reason'] === 'before'): ?>
    <p class="lead">The meeting opens at <strong><?= $v->datetime(gmdate('Y-m-d H:i:s', $opensAt), 'H:i') ?></strong>.</p>
    <p class="muted" data-reload-at="<?= $opensAt ?>" data-now="<?= $now ?>">Leave this page open and it lets you in when it is time.</p>
  <?php elseif ($access['reason'] === 'not-set-up'): ?>
    <p class="lead">The school’s online classroom is not connected at the moment.</p>
  <?php elseif ($access['reason'] === 'cancelled'): ?>
    <p class="lead">This meeting was cancelled.</p>
    <p><?= $v->e((string) $m->cancelledReason) ?></p>
  <?php else: ?>
    <p class="lead">This meeting has ended.</p>
  <?php endif; ?>
  <p><a class="button button-quiet" href="/meetings/<?= $m->id ?>">Back to the meeting page</a></p>
</section>
<?php if ($access['reason'] === 'before'): ?><script src="<?= $v->asset('classroom.js') ?>" defer></script><?php endif; ?>
<?php else:
    $room = [
        'title' => $m->title,
        'kicker' => ($isHost ? 'You host' : 'Hosted by ' . $m->hostName) . ', ' . $v->datetime($m->startsAt, 'H:i') . '–' . $v->datetime($m->endsAt, 'H:i'),
        'live' => $m->phase($now) === 'live',
        'backUrl' => '/meetings/' . $m->id,
        'backLabel' => 'Meeting page',
        'signalUrl' => '/meetings/' . $m->id . '/signal',
        'extendUrl' => $isHost ? '/meetings/' . $m->id . '/extend' : null,
        'endUrl' => $isHost ? '/meetings/' . $m->id . '/end' : null,
        'endLabel' => 'End the meeting for everyone',
        'lecture' => false,
        'endsTs' => $m->endTs(),
        'now' => $now,
        'pass' => $pass,
        'materials' => [],
        'tip' => $runs ? 'You can mute people and let them speak from the participants list.' : 'Your microphone starts muted. Unmute when you want to speak.',
    ];
    include __DIR__ . '/../live/_room.php';
endif; ?>
