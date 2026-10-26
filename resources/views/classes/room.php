<?php
/**
 * The online classroom for one class (ADR 0029): the school's Jitsi Meet,
 * embedded, with a low-data choice, the class materials beside it, and a
 * calm way back in when the connection drops.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\LiveClasses\ClassSession $session
 * @var bool $teaches
 * @var array{ok: bool, reason: string} $access
 * @var ?array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool} $pass
 * @var list<\App\Modules\Files\StoredFile> $materials
 * @var int $now
 */
$s = $session;
$opensAt = $s->startTs() - ($teaches ? \App\Modules\LiveClasses\Classroom\Classroom::TEACHER_EARLY_MINUTES : \App\Modules\LiveClasses\ClassSession::JOIN_EARLY_MINUTES) * 60;
?>
<?php if ($pass === null): ?><?= $v->breadcrumbs(['Live classes' => '/classes', $s->title => '/classes/' . $s->id, 'Classroom' => null]) ?><?php endif; ?>

<?php if ($pass === null): ?>
<section class="room-closed">
  <h1><?= $v->e($s->title) ?></h1>
  <?php if ($access['reason'] === 'before'): ?>
    <p class="lead">The classroom opens at <strong><?= $v->datetime(gmdate('Y-m-d H:i:s', $opensAt), 'H:i') ?></strong>, <?= $teaches ? '30' : '15' ?> minutes before the class starts.</p>
    <p class="muted" data-reload-at="<?= $opensAt ?>" data-now="<?= $now ?>">This page opens the classroom by itself when it is time, if you leave it open.</p>
  <?php elseif ($access['reason'] === 'not-set-up'): ?>
    <p class="lead">The school’s online classroom is not connected at the moment.</p>
    <p><?= $teaches ? 'Ask an administrator to check School, Classroom, or change this class to use a meeting link.' : 'Your teacher has been told on their class page. Check back shortly.' ?></p>
  <?php elseif ($access['reason'] === 'cancelled'): ?>
    <p class="lead">This class was cancelled.</p>
    <p><?= $v->e((string) $s->cancelledReason) ?></p>
  <?php else: ?>
    <p class="lead">This class has ended.</p>
    <p>Materials, and the recording if your teacher shares one, stay on the class page.</p>
  <?php endif; ?>
  <p><a class="button button-quiet" href="/classes/<?= $s->id ?>">Back to the class page</a></p>
</section>
<?php else:
    $room = [
        'title' => $s->title,
        'kicker' => $s->groupName . ', ' . $v->datetime($s->startsAt, 'H:i') . '–' . $v->datetime($s->endsAt, 'H:i'),
        'live' => $s->phase($now) === 'live',
        'backUrl' => '/classes/' . $s->id,
        'backLabel' => 'Class page',
        'signalUrl' => '/classes/' . $s->id . '/signal',
        'extendUrl' => $teaches ? '/classes/' . $s->id . '/extend' : null,
        'endUrl' => $teaches ? '/classes/' . $s->id . '/end' : null,
        'endLabel' => 'End the class for everyone',
        'lecture' => $s->isLecture(),
        'endsTs' => $s->endTs(),
        'now' => $now,
        'pass' => $pass,
        'materials' => $materials,
        'tip' => $teaches ? 'Learners start muted. Share your screen from the toolbar; your camera can stay off to save everyone data.' : 'Your microphone starts muted. Raise your hand to speak.',
    ];
    include __DIR__ . '/../live/_room.php';
endif; ?>
<?php if ($pass === null && $access['reason'] === 'before'): ?>
<script src="<?= $v->asset('classroom.js') ?>" defer></script>
<?php endif; ?>
