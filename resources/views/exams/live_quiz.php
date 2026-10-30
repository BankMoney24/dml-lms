<?php
/**
 * Writing a live quiz, and starting a game (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Exams\Live\LiveQuiz $quiz
 * @var list<\App\Modules\Exams\Live\LiveQuestion> $questions
 * @var list<\App\Modules\Exams\Live\LiveSession> $games
 * @var list<\App\Modules\Exams\Bank\QuestionBank> $banks
 * @var array<string, array<string, string>> $errors by form
 * @var array<string, array<string, mixed>> $old
 */
use App\Modules\Exams\Live\LiveQuestion;

$testsTab = 'live';
$base = '/teach/live/' . $quiz->id;
$qErr = $errors['question'] ?? [];
$qOld = $old['question'] ?? [];
$opts = array_pad(array_values(array_map('strval', (array) ($qOld['options'] ?? []))), 4, '');
$correct = strtoupper((string) ($qOld['correct'] ?? ''));
$bankErr = $errors['bank'] ?? [];
$bankOld = $old['bank'] ?? [];
$letters = ['A', 'B', 'C', 'D'];
$secondsOptions = array_combine(array_map('strval', LiveQuestion::SECONDS), array_map(static fn (int $s): string => $s < 60 ? $s . ' seconds' : ($s / 60) . ($s === 60 ? ' minute' : ' minutes'), LiveQuestion::SECONDS));
?>
<header class="page-head page-head-row">
  <div>
    <?= $v->breadcrumbs(['Live quizzes' => '/teach/live', $quiz->title => null]) ?>
    <h1><?= $v->e($quiz->title) ?></h1>
    <p class="lead"><?= $v->e($v->plural(count($questions), 'question')) ?>, about <?= max(1, (int) round(array_sum(array_map(static fn ($q): int => $q->seconds + 12, $questions)) / 60)) ?> minutes to play.</p>
  </div>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<div class="tests-layout">
<div>
  <section aria-labelledby="qlist-h" class="section">
    <h2 id="qlist-h">Questions</h2>
    <?php if ($questions === []): ?>
      <p class="muted">No questions yet. Write one below, or borrow some from a question bank.</p>
    <?php else: ?>
    <ol class="live-questions">
      <?php foreach ($questions as $i => $q): ?>
      <li id="q<?= $q->id ?>" class="lq">
        <p class="lq-prompt"><?= $v->e($q->prompt) ?></p>
        <ul class="lq-options">
          <?php foreach ($q->options as $j => $o): ?><li class="lq-opt lq-<?= $j ?><?= $j === $q->correct ? ' is-key' : '' ?>"><span class="lq-letter" aria-hidden="true"><?= $letters[$j] ?></span><?= $v->e($o) ?><?= $j === $q->correct ? '<span class="visually-hidden"> (right answer)</span>' : '' ?></li><?php endforeach; ?>
        </ul>
        <p class="lq-meta small"><?= $q->seconds ?> seconds<?= $q->points !== 'standard' ? ', ' . $v->e(strtolower(LiveQuestion::POINTS[$q->points])) : '' ?></p>
        <div class="lq-actions">
          <a class="button button-small button-quiet" href="<?= $base ?>/questions/<?= $q->id ?>">Edit<span class="visually-hidden"> question <?= $i + 1 ?></span></a>
          <?php if ($i > 0): ?><form method="post" action="<?= $base ?>/questions/<?= $q->id ?>/move" class="inline"><?= $v->csrfField() ?><input type="hidden" name="to" value="up"><button type="submit" class="linklike small">Move up<span class="visually-hidden"> question <?= $i + 1 ?></span></button></form><?php endif; ?>
          <?php if ($i < count($questions) - 1): ?><form method="post" action="<?= $base ?>/questions/<?= $q->id ?>/move" class="inline"><?= $v->csrfField() ?><input type="hidden" name="to" value="down"><button type="submit" class="linklike small">Move down<span class="visually-hidden"> question <?= $i + 1 ?></span></button></form><?php endif; ?>
          <form method="post" action="<?= $base ?>/questions/<?= $q->id ?>/delete" class="inline"><?= $v->csrfField() ?><button type="submit" class="linklike small danger-link">Delete<span class="visually-hidden"> question <?= $i + 1 ?></span></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>

  <section class="panel" id="add" aria-labelledby="add-h">
    <h2 id="add-h">Add a question</h2>
    <?= $v->errorSummary($qErr, ['options' => 'lopt-0', 'correct' => 'lcorrect-A']) ?>
    <form method="post" action="<?= $base ?>/questions" class="form form-wide" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('prompt', 'Question', ['type' => 'textarea', 'rows' => 2, 'value' => (string) ($qOld['prompt'] ?? ''), 'error' => $qErr['prompt'] ?? null, 'maxlength' => 500, 'hint' => 'Short and clear: it goes on the big screen.']) ?>
      <fieldset class="field options-field"<?= isset($qErr['options']) || isset($qErr['correct']) ? ' aria-describedby="loptions-error"' : '' ?>>
        <legend>Answers, and the right one</legend>
        <p class="hint">Two to four. For true or false, write True and False.</p>
        <?php if (isset($qErr['options']) || isset($qErr['correct'])): ?><p class="field-error" id="loptions-error"><?= $v->e($qErr['options'] ?? $qErr['correct'] ?? '') ?></p><?php endif; ?>
        <?php foreach ($letters as $j => $l): ?>
        <div class="option-row">
          <span class="option-letter lq-<?= $j ?>" aria-hidden="true"><?= $l ?></span>
          <label class="visually-hidden" for="lopt-<?= $j ?>">Answer <?= $l ?></label>
          <input id="lopt-<?= $j ?>" name="options[]" value="<?= $v->e($opts[$j]) ?>" maxlength="120" autocomplete="off"<?= $j >= 2 ? ' placeholder="Optional"' : '' ?>>
          <label class="option-key"><input type="radio" name="correct" value="<?= $l ?>" id="lcorrect-<?= $l ?>"<?= $correct === $l ? ' checked' : '' ?>><span>Right<span class="visually-hidden"> answer is <?= $l ?></span></span></label>
        </div>
        <?php endforeach; ?>
      </fieldset>
      <div class="field-row">
        <?= $v->field('seconds', 'Time to answer', ['type' => 'select', 'options' => $secondsOptions, 'value' => (string) ($qOld['seconds'] ?? '20'), 'error' => $qErr['seconds'] ?? null]) ?>
        <?= $v->field('points', 'Points', ['type' => 'select', 'options' => LiveQuestion::POINTS, 'value' => (string) ($qOld['points'] ?? 'standard'), 'error' => $qErr['points'] ?? null]) ?>
      </div>
      <button type="submit" class="button">Add the question</button>
    </form>
  </section>
</div>

<aside class="tests-side">
  <section class="panel play-panel" aria-labelledby="play-h">
    <h2 id="play-h">Play live</h2>
    <?php if (isset($errors['host'])): ?><p class="field-error"><?= $v->e(implode(' ', $errors['host'])) ?></p><?php endif; ?>
    <form method="post" action="<?= $base ?>/host" class="form">
      <?= $v->csrfField() ?>
      <label class="choice"><input type="checkbox" name="guests" value="1" checked><span>Let people join without signing in<span class="pick-hint">They type a nickname. Members always join under their own name.</span></span></label>
      <label class="choice"><input type="checkbox" name="fun_names" value="1"><span>Give everyone a made-up name<span class="pick-hint">Such as “Swift Hornbill”, so nobody types a rude one and quiet learners feel safer.</span></span></label>
      <button type="submit" class="button button-wide"<?= $questions === [] ? ' disabled' : '' ?>><?= $v->icon('play') ?>Play live</button>
    </form>
    <p class="small muted">Opens the class screen with a PIN. Show it on the projector or TV.</p>
  </section>

  <?php if ($banks !== []): ?>
  <section class="panel" aria-labelledby="borrow-h">
    <h2 id="borrow-h">Borrow from a bank</h2>
    <?= $v->errorSummary($bankErr) ?>
    <form method="post" action="<?= $base ?>/from-bank" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('bank', 'Question bank', ['type' => 'select', 'options' => ['' => 'Choose'] + array_combine(array_map(static fn ($b): string => (string) $b->id, $banks), array_map(static fn ($b): string => $b->name, $banks)), 'value' => (string) ($bankOld['bank'] ?? ''), 'error' => $bankErr['bank'] ?? null]) ?>
      <div class="field-row">
        <?= $v->field('count', 'How many', ['type' => 'select', 'options' => ['5' => '5', '10' => '10', '15' => '15', '20' => '20'], 'value' => (string) ($bankOld['count'] ?? '10'), 'error' => $bankErr['count'] ?? null]) ?>
        <?= $v->field('seconds', 'Time each', ['id' => 'bank-seconds', 'type' => 'select', 'options' => $secondsOptions, 'value' => (string) ($bankOld['seconds'] ?? '20')]) ?>
      </div>
      <button type="submit" class="button button-quiet">Add at random</button>
      <p class="small muted">Only questions with up to four short answers fit a live quiz.</p>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($games !== []): ?>
  <section class="panel" aria-labelledby="games-h">
    <h2 id="games-h">Games played</h2>
    <ul class="plain-list">
      <?php foreach ($games as $g): ?>
      <li><a href="/teach/live/games/<?= $g->id ?><?= $g->status === 'finished' ? '/results' : '' ?>"><?= $v->datetime($g->createdAt, 'j M, H:i') ?></a> <?= $g->status === 'finished' ? '' : $v->badge('Open', 'ok') ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <details class="panel">
    <summary>Quiz settings</summary>
    <form method="post" action="<?= $base ?>" class="form" novalidate>
      <?= $v->csrfField() ?>
      <?= $v->field('title', 'Title', ['id' => 'quiz-title', 'value' => $quiz->title, 'error' => isset($errors['title']) ? implode(' ', $errors['title']) : null, 'maxlength' => 120]) ?>
      <button type="submit" class="button button-small">Rename</button>
    </form>
    <form method="post" action="<?= $base ?>/archive" class="bank-archive"><?= $v->csrfField() ?><button type="submit" class="linklike small"><?= $quiz->archivedAt === null ? 'Archive this quiz' : 'Put it back in the list' ?></button></form>
  </details>
</aside>
</div>
