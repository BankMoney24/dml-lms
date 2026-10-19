<?php
/**
 * The Code studio (ADR 0020, 0027): JavaScript, Python and web pages, run in
 * sealed runners in the browser.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<array{id: int, title: string, language: string, updated_at: string, lines: int}> $snippets
 * @var ?array{id: int, title: string, language: string, code: string} $open
 * @var string $language javascript | python | web
 * @var string $code
 * @var array<string, array<string, string>> $examples
 * @var int $solvedCount
 * @var int $challengeCount
 */
$languages = \App\Modules\Playground\Http\PlaygroundController::LANGUAGES;
$web = $language === 'web';
$fileName = ['javascript' => 'main.js', 'python' => 'main.py', 'web' => 'index.html'][$language];
?>
<header class="page-head page-head-row studio-head">
  <div>
    <h1>Code studio</h1>
    <p class="lead">Write JavaScript, Python or a web page and run it right here. It runs sealed off inside your browser: it cannot reach the internet, this site or your account.</p>
  </div>
  <a class="studio-challenges-link" href="/playground/challenges"><?= $v->icon('sparkle') ?><span><strong>Coding challenges</strong><span class="small"><?= $solvedCount ?> of <?= $challengeCount ?> solved</span></span></a>
</header>

<div class="studio" data-studio data-language="<?= $v->e($language) ?>">
  <form method="post" action="/playground" class="studio-form" data-studio-form>
    <?= $v->csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) ($open['id'] ?? 0) ?>">
    <input type="hidden" name="language" value="<?= $v->e($language) ?>">

    <div class="studio-bar">
      <nav class="studio-langs" aria-label="Language">
        <?php foreach ($languages as $key => $label): ?>
          <a href="/playground?lang=<?= $key ?>"<?= $key === $language ? ' aria-current="page"' : '' ?> data-studio-leave><?= $v->e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <label class="visually-hidden" for="pg-title">Title</label>
      <input id="pg-title" name="title" class="studio-title" maxlength="120" placeholder="Untitled <?= $v->e($languages[$language]) ?> snippet" value="<?= $v->e($open['title'] ?? '') ?>">
      <label class="visually-hidden" for="pg-example">Start from an example</label>
      <select id="pg-example" class="studio-example" data-pg-example hidden>
        <option value="">Examples…</option>
        <?php foreach ($examples[$language] as $name => $exampleCode): ?><option value="<?= $v->e($exampleCode) ?>"><?= $v->e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <p class="studio-leave-note small" data-studio-leave-note hidden>You have changes that are not saved. Save first, or choose the language again to leave without saving.</p>

    <div class="studio-work">
      <section class="studio-editor-pane" aria-labelledby="pg-code-h">
        <div class="studio-tab"><span class="studio-file" id="pg-code-h"><?= $v->e($fileName) ?></span><span class="studio-pos small" data-studio-pos aria-live="off"></span></div>
        <div class="pg-editor studio-editor">
          <div class="pg-gutter" aria-hidden="true" data-pg-gutter></div>
          <div class="studio-code">
            <pre class="studio-hl" aria-hidden="true" data-studio-hl></pre>
            <label class="visually-hidden" for="pg-code"><?= $v->e($languages[$language]) ?> code</label>
            <textarea id="pg-code" name="code" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" data-pg-code><?= $v->e($code) ?></textarea>
          </div>
        </div>
        <div class="studio-actions">
          <button type="button" class="button pg-run" data-pg-run hidden><?= $v->icon('play') ?>Run<kbd>Ctrl</kbd><kbd>Enter</kbd></button>
          <button type="submit" class="button button-quiet"><?= $open !== null ? 'Save changes' : 'Save snippet' ?></button>
          <details class="studio-keys">
            <summary class="small">Keyboard</summary>
            <ul class="small">
              <li><kbd>Ctrl</kbd> <kbd>Enter</kbd> run</li>
              <li><kbd>Ctrl</kbd> <kbd>S</kbd> save</li>
              <li><kbd>Tab</kbd> / <kbd>Shift</kbd> <kbd>Tab</kbd> indent or outdent the selected lines</li>
              <li><kbd>Ctrl</kbd> <kbd>/</kbd> comment the selected lines on or off</li>
              <li><kbd>Esc</kbd> then <kbd>Tab</kbd> move out of the editor</li>
            </ul>
          </details>
          <noscript><p class="small muted">Running code needs JavaScript switched on. You can still save it.</p></noscript>
        </div>
      </section>

      <section class="pg-out studio-out" aria-labelledby="pg-out-h">
        <div class="pg-out-head">
          <h2 id="pg-out-h"><?= $web ? 'Preview' : 'Output' ?></h2>
          <span class="small" data-pg-status></span>
          <button type="button" class="linklike small" data-pg-clear hidden>Clear</button>
        </div>
        <?php if ($web): ?>
          <iframe class="studio-preview" sandbox="allow-scripts" title="Preview of your page" data-studio-preview></iframe>
          <div class="studio-console-head small">Console</div>
          <pre class="pg-console studio-console-small" data-pg-output aria-live="polite"><span class="pg-hint">Messages from console.log in your page appear here.</span></pre>
          <p class="studio-note small">Pictures and scripts from other websites are blocked here. Use your own HTML, CSS and JavaScript.</p>
        <?php else: ?>
          <pre class="pg-console" data-pg-output aria-live="polite"><span class="pg-hint">Press Run to see what your code prints with <?= $language === 'python' ? 'print()' : 'console.log' ?>.</span></pre>
          <div class="studio-input">
            <label for="pg-stdin" class="small"><?= $language === 'python' ? 'Input: one line for each input()' : 'Input: one line for each prompt()' ?></label>
            <textarea id="pg-stdin" rows="3" spellcheck="false" data-pg-stdin placeholder="Ada&#10;16"></textarea>
          </div>
        <?php endif; ?>
        <iframe src="/labs/runner" sandbox="allow-scripts" title="Code runner" class="sim-runner" tabindex="-1" aria-hidden="true" data-pg-runner></iframe>
      </section>
    </div>
  </form>
</div>

<section class="section pg-saved" aria-labelledby="pg-saved-h">
  <div class="section-head">
    <h2 id="pg-saved-h">Your snippets</h2>
    <?php if ($open !== null): ?><a href="/playground?lang=<?= $v->e($language) ?>"><?= $v->icon('code') ?> New <?= $v->e($languages[$language]) ?> snippet</a><?php endif; ?>
  </div>
  <?php if ($snippets === []): ?>
    <p class="muted">Nothing saved yet. Snippets you save stay private to you, on any device you sign in from.</p>
  <?php else: ?>
  <ul class="snippet-list">
    <?php foreach ($snippets as $s): ?>
    <li<?= ($open['id'] ?? 0) === $s['id'] ? ' class="is-open"' : '' ?>>
      <a href="/playground?snippet=<?= $s['id'] ?>"><span class="snippet-lang snippet-lang-<?= $v->e($s['language']) ?>" aria-hidden="true"><?= ['javascript' => 'JS', 'python' => 'PY', 'web' => '&lt;/&gt;'][$s['language']] ?? 'JS' ?></span><span><span class="snippet-title"><?= $v->e($s['title']) ?></span><span class="small muted"><?= $v->e($languages[$s['language']] ?? 'JavaScript') ?>, <?= $v->e($v->plural($s['lines'], 'line')) ?>, saved <?= $v->datetime($s['updated_at'], 'j M, H:i') ?></span></span></a>
      <form method="post" action="/playground/<?= $s['id'] ?>/delete"><?= $v->csrfField() ?><button type="submit" class="linklike small">Delete<span class="visually-hidden"> <?= $v->e($s['title']) ?></span></button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<script src="<?= $v->asset('studio.js') ?>" defer></script>
