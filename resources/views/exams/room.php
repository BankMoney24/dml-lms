<?php
/**
 * The CBT exam room (ADR 0042), inside layouts/focus. The page is a frame;
 * cbt.js loads the paper as JSON, shows one question at a time, saves every
 * answer as it is chosen and keeps the time the server gave it.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Cbt\CbtAttempt $attempt
 * @var ?\App\Modules\Exams\Cbt\CbtExam $exam
 * @var string $candidate
 */
$base = '/exams/attempts/' . $attempt->id;
?>
<div class="cbt" data-cbt data-attempt="<?= $attempt->id ?>" data-paper="<?= $base ?>/paper" data-save="<?= $base ?>/save" data-submit="<?= $base ?>/submit" data-result="<?= $base ?>/result" data-csrf="<?= $v->e((string) $v->shared('csrf_token', '')) ?>">
  <header class="cbt-top">
    <div class="cbt-who">
      <h1 class="cbt-title"><?= $v->e($attempt->title) ?></h1>
      <p class="cbt-candidate"><span class="visually-hidden">Candidate: </span><?= $v->e($candidate) ?></p>
    </div>
    <div class="cbt-clock">
      <p class="cbt-timer" data-cbt-timer><span class="visually-hidden">Time left: </span><span data-cbt-time>--:--</span></p>
      <p class="cbt-save" role="status" data-cbt-save>Loading the paper…</p>
    </div>
    <button type="button" class="button cbt-submit-open" data-cbt-submit-open disabled>Submit</button>
  </header>

  <nav class="cbt-tabs" data-cbt-tabs aria-label="Subjects"></nav>

  <div class="cbt-body">
    <section class="cbt-stage" aria-labelledby="cbt-qnum">
      <p class="cbt-qnum" id="cbt-qnum" data-cbt-qnum></p>
      <div class="cbt-passage" data-cbt-passage hidden></div>
      <div class="cbt-question" data-cbt-question>
        <p class="muted">Loading…</p>
      </div>
      <div class="cbt-nav">
        <button type="button" class="button button-quiet" data-cbt-prev><?= $v->icon('arrow', 'flip') ?>Previous <kbd aria-hidden="true">P</kbd></button>
        <button type="button" class="button button-quiet cbt-flag" data-cbt-flag aria-pressed="false"><?= $v->icon('saved') ?><span data-cbt-flag-text>Flag</span> <kbd aria-hidden="true">F</kbd></button>
        <button type="button" class="button" data-cbt-next>Next <kbd aria-hidden="true">N</kbd><?= $v->icon('arrow') ?></button>
      </div>
    </section>

    <aside class="cbt-side" aria-label="Questions and tools">
      <button type="button" class="button button-quiet cbt-palette-toggle" data-cbt-palette-toggle aria-expanded="false" aria-controls="cbt-palette-box"><span data-cbt-progress>Questions</span></button>
      <div class="cbt-palette-box" id="cbt-palette-box" data-cbt-palette-box>
        <h2 class="cbt-side-h" data-cbt-palette-title>Questions</h2>
        <div class="cbt-palette" data-cbt-palette></div>
        <ul class="cbt-legend small">
          <li><span class="cbt-dot is-answered" aria-hidden="true"></span>Answered</li>
          <li><span class="cbt-dot" aria-hidden="true"></span>Not answered</li>
          <li><span class="cbt-dot is-flagged" aria-hidden="true"></span>Flagged</li>
        </ul>
      </div>
      <button type="button" class="button button-quiet cbt-calc-toggle" data-cbt-calc-toggle aria-expanded="false" aria-controls="cbt-calc">Calculator</button>
      <div class="cbt-calc" id="cbt-calc" data-cbt-calc hidden>
        <output class="cbt-calc-screen" data-cbt-calc-screen aria-live="polite">0</output>
        <div class="cbt-calc-keys" role="group" aria-label="Calculator keys">
          <?php foreach ([['C', 'clear', 'Clear'], ['±', 'neg', 'Change sign'], ['%', 'pct', 'Percent'], ['÷', '/', 'Divide'], ['7', '7', ''], ['8', '8', ''], ['9', '9', ''], ['×', '*', 'Multiply'], ['4', '4', ''], ['5', '5', ''], ['6', '6', ''], ['−', '-', 'Minus'], ['1', '1', ''], ['2', '2', ''], ['3', '3', ''], ['+', '+', 'Plus'], ['√', 'sqrt', 'Square root'], ['0', '0', ''], ['.', '.', 'Point'], ['=', '=', 'Equals']] as [$face, $key, $name]): ?>
          <button type="button" data-calc="<?= $v->e($key) ?>"<?= $name !== '' ? ' aria-label="' . $v->e($name) . '"' : '' ?><?= in_array($key, ['/', '*', '-', '+', '='], true) ? ' class="is-op"' : '' ?>><?= $v->e($face) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>
  </div>

  <dialog class="cbt-dialog" data-cbt-dialog aria-labelledby="cbt-dialog-h">
    <h2 id="cbt-dialog-h">Submit your answers?</h2>
    <div data-cbt-summary></div>
    <p class="small">Once you submit, you cannot change your answers.</p>
    <div class="row-actions">
      <button type="button" class="button" data-cbt-submit>Submit and finish</button>
      <button type="button" class="button button-quiet" data-cbt-cancel>Go back to the questions</button>
    </div>
  </dialog>
  <p class="visually-hidden" aria-live="assertive" data-cbt-alert></p>
  <noscript><p class="notice notice-err">The exam room needs JavaScript. Turn it on in your browser’s settings, then reload this page; your time is already running.</p></noscript>
</div>
