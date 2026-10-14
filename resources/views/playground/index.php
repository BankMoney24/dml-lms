<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{id: int, title: string, updated_at: string, lines: int}> $snippets
 * @var ?array{id: int, title: string, code: string} $open
 * @var string $code
 * @var array<string, string> $examples
 */
?>
<header class="page-head page-head-row">
  <div>
    <h1>Code playground</h1>
    <p class="lead">Write JavaScript and run it right here. It runs sealed off inside your browser: it cannot reach the internet, this page or your account.</p>
  </div>
</header>

<div class="pg" data-playground>
  <section class="pg-main" aria-labelledby="pg-code-h">
    <h2 id="pg-code-h" class="visually-hidden">Your code</h2>
    <form method="post" action="/playground" class="pg-form">
      <?= $v->csrfField() ?>
      <input type="hidden" name="id" value="<?= (int) ($open['id'] ?? 0) ?>">
      <div class="pg-bar">
        <label class="visually-hidden" for="pg-title">Title</label>
        <input id="pg-title" name="title" class="pg-title" maxlength="120" placeholder="Untitled snippet" value="<?= $v->e($open['title'] ?? '') ?>">
        <label class="visually-hidden" for="pg-example">Start from an example</label>
        <select id="pg-example" class="pg-example" data-pg-example hidden>
          <option value="">Examples…</option>
          <?php foreach ($examples as $name => $exampleCode): ?><option value="<?= $v->e($exampleCode) ?>"><?= $v->e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="pg-editor">
        <label class="visually-hidden" for="pg-code">JavaScript code</label>
        <div class="pg-gutter" aria-hidden="true" data-pg-gutter></div>
        <textarea id="pg-code" name="code" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" data-pg-code><?= $v->e($code) ?></textarea>
      </div>
      <div class="pg-actions">
        <button type="button" class="button pg-run" data-pg-run hidden><?= $v->icon('play') ?>Run<kbd>Ctrl</kbd><kbd>Enter</kbd></button>
        <button type="submit" class="button button-quiet"><?= $open !== null ? 'Save changes' : 'Save snippet' ?></button>
        <noscript><p class="small muted">Running code needs JavaScript switched on. You can still save it.</p></noscript>
      </div>
    </form>
  </section>

  <section class="pg-out" aria-labelledby="pg-out-h">
    <div class="pg-out-head">
      <h2 id="pg-out-h">Output</h2>
      <span class="small" data-pg-status></span>
      <button type="button" class="linklike small" data-pg-clear hidden>Clear</button>
    </div>
    <pre class="pg-console" data-pg-output aria-live="polite"><span class="pg-hint">Press Run to see what your code prints with console.log.</span></pre>
    <iframe src="/labs/runner" sandbox="allow-scripts" title="Code runner" class="sim-runner" tabindex="-1" aria-hidden="true" data-pg-runner></iframe>
  </section>
</div>

<section class="section pg-saved" aria-labelledby="pg-saved-h">
  <div class="section-head">
    <h2 id="pg-saved-h">Your snippets</h2>
    <?php if ($open !== null): ?><a href="/playground"><?= $v->icon('code') ?> New snippet</a><?php endif; ?>
  </div>
  <?php if ($snippets === []): ?>
    <p class="muted">Nothing saved yet. Snippets you save stay private to you, on any device you sign in from.</p>
  <?php else: ?>
  <ul class="snippet-list">
    <?php foreach ($snippets as $s): ?>
    <li<?= ($open['id'] ?? 0) === $s['id'] ? ' class="is-open"' : '' ?>>
      <a href="/playground?snippet=<?= $s['id'] ?>"><?= $v->icon('code') ?><span><span class="snippet-title"><?= $v->e($s['title']) ?></span><span class="small muted"><?= $v->e($v->plural($s['lines'], 'line')) ?>, saved <?= $v->datetime($s['updated_at'], 'j M, H:i') ?></span></span></a>
      <form method="post" action="/playground/<?= $s['id'] ?>/delete"><?= $v->csrfField() ?><button type="submit" class="linklike small">Delete<span class="visually-hidden"> <?= $v->e($s['title']) ?></span></button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
