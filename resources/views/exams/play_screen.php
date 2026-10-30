<?php
/**
 * A player's phone during a live quiz (ADR 0042), inside layouts/focus.
 * live.js asks for the game's state and draws it.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Live\LiveSession $game
 * @var \App\Modules\Exams\Live\LivePlayer $player
 */
?>
<div class="play" data-play data-state="/play/state" data-answer="/play/answer" data-csrf="<?= $v->e((string) $v->shared('csrf_token', '')) ?>">
  <p class="play-me"><span class="play-name"><?= $v->e($player->nickname) ?></span><span class="play-score" data-play-score></span></p>
  <section class="play-view" data-view="lobby">
    <h1 class="play-h">You’re in!</h1>
    <p>Look for your name on the class screen. The quiz starts soon.</p>
    <p class="play-wait" aria-hidden="true"><span></span><span></span><span></span></p>
  </section>
  <section class="play-view" data-view="question" hidden>
    <p class="play-qnum" data-play-qnum></p>
    <div class="play-timebar" aria-hidden="true"><span data-play-timebar></span></div>
    <h1 class="play-prompt" data-play-prompt></h1>
    <div class="play-answers" data-play-answers role="group" aria-label="Answers"></div>
  </section>
  <section class="play-view play-sent" data-view="sent" hidden>
    <h1 class="play-h">Answer in</h1>
    <p data-play-sent-text>Waiting for the others…</p>
  </section>
  <section class="play-view play-result" data-view="reveal" hidden>
    <h1 class="play-h" data-play-verdict></h1>
    <p class="play-points" data-play-points></p>
    <p data-play-correct></p>
    <p class="play-streak" data-play-streak></p>
  </section>
  <section class="play-view" data-view="scores" hidden>
    <h1 class="play-h" data-play-rank></h1>
    <p data-play-behind></p>
  </section>
  <section class="play-view" data-view="finished" hidden>
    <h1 class="play-h" data-play-final></h1>
    <p data-play-final-detail></p>
    <p><a class="button button-quiet" href="/play/leave">Join another game</a></p>
  </section>
  <section class="play-view" data-view="removed" hidden>
    <h1 class="play-h">You have left this game</h1>
    <p>The host took you out of it.</p>
    <p><a class="button button-quiet" href="/play/leave">Join a game</a></p>
  </section>
  <p class="play-net" data-play-net role="status" hidden>Reconnecting…</p>
  <p class="visually-hidden" aria-live="assertive" data-play-alert></p>
  <noscript><p class="notice notice-err">Playing needs JavaScript.</p></noscript>
</div>
