<?php
/**
 * The online classroom stage, shared by classes and meetings (ADR 0029, 0030).
 * Phone first: the video takes the screen, the data choice sits under the
 * title, and materials and help fold away under the video.
 *
 * Expects $room = [
 *   'title' => string, 'kicker' => string, 'live' => bool,
 *   'backUrl' => string, 'backLabel' => string,
 *   'signalUrl' => string, 'extendUrl' => ?string, 'endUrl' => ?string,
 *   'endLabel' => string, 'lecture' => bool, 'endsTs' => int, 'now' => int,
 *   'pass' => array{domain: string, room: string, token: string, moderator: bool, lowData: bool},
 *   'materials' => list<StoredFile>, 'tip' => string,
 * ]
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $room
 */
$pass = $room['pass'];
$runs = (bool) $pass['moderator'];
$materials = $room['materials'];
?>
<div class="room" data-room
     data-domain="<?= $v->e($pass['domain']) ?>"
     data-room-name="<?= $v->e($pass['room']) ?>"
     data-jwt="<?= $v->e($pass['token']) ?>"
     data-moderator="<?= $runs ? '1' : '0' ?>"
     data-low-data="<?= $pass['lowData'] ? '1' : '0' ?>"
     data-lecture="<?= $room['lecture'] ? '1' : '0' ?>"
     data-subject="<?= $v->e($room['title']) ?>"
     data-signal="<?= $v->e($room['signalUrl']) ?>"
     data-extend="<?= $v->e((string) ($room['extendUrl'] ?? '')) ?>"
     data-csrf="<?= $v->e((string) ($v->shared('csrf_token') ?? '')) ?>"
     data-ends="<?= (int) $room['endsTs'] ?>"
     data-ends-label="<?= $v->e($v->datetime(gmdate('Y-m-d H:i:s', (int) $room['endsTs']), 'H:i')) ?>"
     data-now="<?= (int) $room['now'] ?>">
  <header class="room-head">
    <a class="room-back" href="<?= $v->e($room['backUrl']) ?>"><?= $v->icon('chevron', 'room-back-icon') ?><span><?= $v->e($room['backLabel']) ?></span></a>
    <div class="room-title">
      <p class="room-kicker small"><span class="lc-live<?= $room['live'] ? '' : ' lc-soon' ?>"><?= $room['live'] ? 'Live' : 'Starting soon' ?></span> <span><?= $v->e($room['kicker']) ?></span></p>
      <h1><?= $v->e($room['title']) ?></h1>
    </div>
    <fieldset class="room-modes" data-room-modes>
      <legend class="visually-hidden">How much data to use</legend>
      <label><input type="radio" name="room-mode" value="audio"><span><?= $v->icon('headphones') ?><b class="lbl-long">Audio only</b><b class="lbl-short">Audio</b></span></label>
      <label><input type="radio" name="room-mode" value="saver"><span><?= $v->icon('video') ?><b class="lbl-long">Data saver</b><b class="lbl-short">Saver</b></span></label>
      <label><input type="radio" name="room-mode" value="video"><span><?= $v->icon('video') ?><b class="lbl-long">Full video</b><b class="lbl-short">Video</b></span></label>
    </fieldset>
  </header>

  <p class="room-status" data-room-status role="status" aria-live="polite">Connecting…</p>

  <div class="room-layout">
    <div class="room-stage" id="room-stage" data-room-stage>
      <noscript><p class="room-stage-note">The online classroom needs JavaScript turned on in your browser.</p></noscript>
    </div>
    <aside class="room-side" aria-label="During the session">
      <div class="room-bar">
        <p class="room-clock small" data-room-clock>Ends <?= $v->datetime(gmdate('Y-m-d H:i:s', (int) $room['endsTs']), 'H:i') ?></p>
        <?php if ($runs && ($room['extendUrl'] ?? null) !== null): ?>
          <div class="room-actions">
            <button type="button" class="button button-small button-quiet" data-room-extend="15">+15 min</button>
            <button type="button" class="button button-small button-quiet" data-room-extend="30">+30 min</button>
            <?php if (($room['endUrl'] ?? null) !== null): ?>
            <details class="room-end">
              <summary class="button button-small button-quiet">End</summary>
              <form method="post" action="<?= $v->e((string) $room['endUrl']) ?>" data-room-end>
                <?= $v->csrfField() ?>
                <p class="small">Everyone leaves and no one can rejoin.</p>
                <button type="submit" class="button button-small button-danger"><?= $v->e((string) $room['endLabel']) ?></button>
              </form>
            </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($room['lecture']): ?>
        <p class="room-note small"><?= $runs
            ? 'Lecture mode: learners join muted with cameras off and raise a hand to speak. Let someone speak from the participants list.'
            : 'Lecture mode: you can watch and listen. Raise your hand to ask to speak.' ?></p>
      <?php endif; ?>
      <?php if ($materials !== []): ?>
      <details class="room-panel" data-room-panel open>
        <summary><?= $v->icon('note') ?>Materials <span class="count-pill"><?= count($materials) ?></span></summary>
        <?php $fileList = $materials; $removeUrl = null; include __DIR__ . '/../files/_list.php'; ?>
      </details>
      <?php endif; ?>
      <details class="room-panel" data-room-panel open>
        <summary><?= $v->icon('help') ?>If something goes wrong</summary>
        <ul class="plain-list small room-tips">
          <li><strong>Choppy or stuck?</strong> Choose Saver or Audio above.</li>
          <li><strong>Can’t hear?</strong> Check the phone is not on silent and the browser may use the speaker.</li>
          <li><strong>Dropped out?</strong> <button type="button" class="linklike" data-room-rejoin>Rejoin</button>. Your attendance is kept.</li>
        </ul>
        <?php if ($room['tip'] !== ''): ?><p class="small muted"><?= $v->e($room['tip']) ?></p><?php endif; ?>
      </details>
    </aside>
  </div>
</div>
<script src="https://<?= $v->e($pass['domain']) ?>/external_api.js" defer></script>
<script src="<?= $v->asset('classroom.js') ?>" defer></script>
