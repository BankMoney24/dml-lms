<?php
/**
 * Joining a live quiz on a phone (ADR 0042), inside layouts/focus.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $pin
 * @var string $nickname
 * @var bool $signedIn
 * @var bool $needsName
 * @var array<string, string> $errors
 */
?>
<div class="play play-join">
  <h1 class="play-h">Join a live quiz</h1>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="/play" class="form play-form" novalidate>
    <?= $v->csrfField() ?>
    <?php if ($needsName): ?>
      <input type="hidden" name="pin" value="<?= $v->e($pin) ?>">
      <input type="hidden" name="step" value="name">
      <p class="play-pin-shown">Game <?= $v->e(substr($pin, 0, 3) . ' ' . substr($pin, 3)) ?></p>
      <?= $v->field('nickname', 'Your name on the screen', ['value' => $nickname, 'error' => $errors['nickname'] ?? null, 'maxlength' => 20, 'autocomplete' => 'nickname', 'autofocus' => true, 'hint' => 'Your first name is best. Everyone in the class will see it.']) ?>
      <button type="submit" class="button button-wide play-go">Join</button>
    <?php else: ?>
      <?= $v->field('pin', 'Game PIN', ['value' => $pin, 'error' => $errors['pin'] ?? null, 'inputmode' => 'numeric', 'maxlength' => 7, 'autocomplete' => 'off', 'autofocus' => $pin === '', 'class' => 'play-pin-field', 'hint' => 'The six numbers on the class screen.']) ?>
      <button type="submit" class="button button-wide play-go">Enter</button>
      <?php if (!$signedIn): ?><p class="small muted">Signed in? Your own name is used. <a href="/login">Sign in</a></p><?php endif; ?>
    <?php endif; ?>
  </form>
</div>
