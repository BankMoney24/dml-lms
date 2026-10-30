<?php
/**
 * The class screen for a live quiz (ADR 0042), inside layouts/focus. live.js
 * asks the server for the game's state every second and draws it.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Live\LiveSession $game
 * @var string $joinUrl
 * @var string $host
 */
$base = '/teach/live/games/' . $game->id;
$pin = substr($game->pin, 0, 3) . ' ' . substr($game->pin, 3);
$canRun = $v->shared('user')?->id === $game->hostId;
?>
<div class="stage" data-stage data-state="<?= $base ?>/state" data-advance="<?= $base ?>/advance" data-remove="<?= $base ?>/remove" data-results="<?= $base ?>/results" data-csrf="<?= $v->e((string) $v->shared('csrf_token', '')) ?>" data-host="<?= $canRun ? '1' : '0' ?>">
  <section class="stage-view stage-lobby" data-view="lobby" aria-labelledby="lobby-h">
    <h1 id="lobby-h" class="stage-title"><?= $v->e($game->title) ?></h1>
    <div class="stage-joinbox">
      <p class="stage-join">On your phone, go to <strong><?= $v->e($host) ?>/play</strong></p>
      <p class="stage-pin-label">and type the game PIN</p>
      <p class="stage-pin" aria-label="Game PIN <?= $v->e(implode(' ', str_split($game->pin))) ?>"><?= $v->e($pin) ?></p>
    </div>
    <p class="stage-count" data-stage-count aria-live="polite">Waiting for players…</p>
    <ul class="stage-names" data-stage-names></ul>
    <?php if ($canRun): ?><p class="stage-actions"><button type="button" class="button stage-go" data-act="start" disabled>Start the quiz</button></p><?php endif; ?>
  </section>

  <section class="stage-view stage-question" data-view="question" hidden aria-labelledby="stage-prompt">
    <div class="stage-qhead">
      <p class="stage-qnum" data-stage-qnum></p>
      <p class="stage-timer" data-stage-timer aria-hidden="true"></p>
      <p class="stage-answered" data-stage-answered aria-live="polite"></p>
    </div>
    <h1 class="stage-prompt" id="stage-prompt" data-stage-prompt></h1>
    <ol class="stage-options" data-stage-options></ol>
    <?php if ($canRun): ?><p class="stage-actions"><button type="button" class="button button-quiet" data-act="reveal">Stop and show the answer</button></p><?php endif; ?>
  </section>

  <section class="stage-view stage-reveal" data-view="reveal" hidden aria-labelledby="stage-reveal-prompt">
    <p class="stage-qnum" data-stage-qnum></p>
    <h1 class="stage-prompt" id="stage-reveal-prompt" data-stage-prompt></h1>
    <ol class="stage-options is-revealed" data-stage-options></ol>
    <?php if ($canRun): ?><p class="stage-actions"><button type="button" class="button stage-go" data-act="next" data-next-label>Next</button></p><?php endif; ?>
  </section>

  <section class="stage-view stage-scores" data-view="scores" hidden aria-labelledby="scores-h">
    <h1 id="scores-h" class="stage-title">Top five</h1>
    <ol class="stage-leaders" data-stage-leaders></ol>
    <?php if ($canRun): ?><p class="stage-actions"><button type="button" class="button stage-go" data-act="next">Next question</button></p><?php endif; ?>
  </section>

  <section class="stage-view stage-finished" data-view="finished" hidden aria-labelledby="podium-h">
    <h1 id="podium-h" class="stage-title">Well played</h1>
    <ol class="stage-podium" data-stage-podium></ol>
    <ol class="stage-leaders stage-rest" data-stage-rest start="4"></ol>
    <p class="stage-actions"><a class="button" href="<?= $base ?>/results">Full results</a></p>
  </section>

  <?php if ($canRun): ?><p class="stage-end"><button type="button" class="linklike" data-act="end">End the game</button></p><?php endif; ?>
  <p class="stage-net" data-stage-net role="status" hidden>Reconnecting…</p>
  <noscript><p class="notice notice-err">The class screen needs JavaScript.</p></noscript>
</div>
