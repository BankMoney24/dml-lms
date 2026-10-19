<?php
/**
 * One coding challenge: the brief, the editor, and the tests (run in the browser's sealed runner).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $key
 * @var array{title: string, topic: string, level: string, prompt: string, arg: string, js: string, py: string, cases: list<array{0: list<mixed>, 1: mixed}>} $challenge
 * @var string $language javascript | python
 * @var string $code
 * @var bool $solved
 * @var list<array{args: list<mixed>, py_args: string, expected: mixed, py_expected: string}> $cases
 * @var string $fn
 * @var ?string $next
 */
$langs = \App\Modules\Playground\Challenges::LANGUAGES;
$show = static fn (mixed $value): string => $language === 'python'
    ? \App\Modules\Playground\Challenges::pyLiteral($value)
    : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
?>
<?= $v->breadcrumbs(['Code studio' => '/playground', 'Coding challenges' => '/playground/challenges', $challenge['title'] => null]) ?>
<header class="page-head">
  <p class="ch-kicker small"><?= $v->e($challenge['level']) ?>, <?= $v->e($challenge['topic']) ?><?= $solved ? ' ' . $v->badge('Solved', 'done') : '' ?></p>
  <h1><?= $v->e($challenge['title']) ?></h1>
</header>

<div class="ch-layout" data-studio data-language="<?= $v->e($language) ?>" data-challenge="<?= $v->e($key) ?>" data-fn="<?= $v->e($fn) ?>"
  data-cases="<?= $v->e((string) json_encode($cases, JSON_UNESCAPED_UNICODE)) ?>">
  <section class="ch-brief" aria-labelledby="brief-h">
    <h2 id="brief-h" class="visually-hidden">The task</h2>
    <div class="prose"><?= $v->markdown($challenge['prompt']) ?></div>
    <p class="small">Write a function called <code><?= $v->e($fn) ?></code>.</p>
    <nav class="studio-langs studio-langs-light" aria-label="Language">
      <?php foreach ($langs as $k => $label): ?>
        <a href="/playground/challenges/<?= $v->e($key) ?>?lang=<?= $k ?>"<?= $k === $language ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <h3 class="small-heading">Tests</h3>
    <div class="table-wrap">
      <table class="table ch-tests" data-ch-table>
        <thead><tr><th scope="col">Call</th><th scope="col">Expected</th><th scope="col">Yours</th></tr></thead>
        <tbody>
          <?php foreach ($cases as $i => $c): ?>
            <tr data-ch-row="<?= $i ?>">
              <td><code><?= $v->e($fn) ?>(<?= $v->e($language === 'python' ? $c['py_args'] : implode(', ', array_map(static fn ($a): string => (string) json_encode($a, JSON_UNESCAPED_UNICODE), $c['args']))) ?>)</code></td>
              <td><code><?= $v->e($show($c['expected'])) ?></code></td>
              <td data-ch-got><span class="muted">not run</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($next !== null): ?><p class="small"><a href="/playground/challenges/<?= $v->e($next) ?>?lang=<?= $v->e($language) ?>">Next challenge</a> · <a href="/playground/challenges">All challenges</a></p><?php endif; ?>
  </section>

  <form method="post" action="/playground/challenges/<?= $v->e($key) ?>" class="studio-form ch-work" data-studio-form>
    <?= $v->csrfField() ?>
    <input type="hidden" name="language" value="<?= $v->e($language) ?>">
    <div class="studio-tab"><span class="studio-file"><?= $language === 'python' ? 'solution.py' : 'solution.js' ?></span><span class="studio-pos small" data-studio-pos></span></div>
    <div class="pg-editor studio-editor">
      <div class="pg-gutter" aria-hidden="true" data-pg-gutter></div>
      <div class="studio-code">
        <pre class="studio-hl" aria-hidden="true" data-studio-hl></pre>
        <label class="visually-hidden" for="pg-code">Your <?= $v->e($langs[$language]) ?> code</label>
        <textarea id="pg-code" name="code" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" data-pg-code><?= $v->e($code) ?></textarea>
      </div>
    </div>
    <div class="studio-actions">
      <button type="button" class="button pg-run" data-pg-run hidden><?= $v->icon('play') ?>Run<kbd>Ctrl</kbd><kbd>Enter</kbd></button>
      <button type="button" class="button" data-ch-test hidden>Run the tests</button>
      <button type="submit" class="button button-quiet" data-ch-submit hidden>Save as solved</button>
      <span class="small" data-ch-summary aria-live="polite"></span>
    </div>
    <section class="pg-out studio-out" aria-labelledby="pg-out-h">
      <div class="pg-out-head">
        <h2 id="pg-out-h">Output</h2>
        <span class="small" data-pg-status></span>
        <button type="button" class="linklike small" data-pg-clear hidden>Clear</button>
      </div>
      <pre class="pg-console studio-console-small" data-pg-output aria-live="polite"><span class="pg-hint">Run prints what your code prints. Run the tests to check your function.</span></pre>
      <iframe src="/labs/runner" sandbox="allow-scripts" title="Code runner" class="sim-runner" tabindex="-1" aria-hidden="true" data-pg-runner></iframe>
    </section>
  </form>
</div>
<script src="<?= $v->asset('studio.js') ?>" defer></script>
