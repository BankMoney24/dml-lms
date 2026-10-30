<?php
/**
 * Teach → Quizzes and exams → Live quizzes (ADR 0042).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Exams\Live\LiveQuiz> $quizzes
 * @var array<string, string> $errors
 * @var string $title
 * @var array{banks: int, questions: int, quizzes: int, exams: int, missing: int} $kit
 */
$testsTab = 'live';
?>
<header class="page-head">
  <h1>Live quizzes</h1>
  <p class="lead">Put a quick quiz on the class screen. Everyone answers on their own phone with a PIN; quick right answers score most, and the top five show after every question.</p>
</header>
<?php include __DIR__ . '/_teach_tabs.php'; ?>

<div class="tests-layout">
  <section aria-labelledby="quizzes-h">
    <h2 id="quizzes-h"><?= $v->e($v->plural(count($quizzes), 'quiz', 'quizzes')) ?></h2>
    <?php if ($quizzes === []): ?>
      <?= $v->emptyState('No live quizzes yet', 'Give one a title, add a few questions (or borrow them from a question bank), then press Play live. Learners join at /play with the PIN on your screen. Or add the starter kit for six quizzes ready to play.') ?>
    <?php else: ?>
    <ul class="quiz-grid">
      <?php foreach ($quizzes as $q): ?>
      <li class="quiz-card">
        <h3><a href="/teach/live/<?= $q->id ?>"><?= $v->e($q->title) ?></a></h3>
        <p class="small"><?= $v->e($v->plural($q->questionCount, 'question')) ?><?= $q->runs > 0 ? ', played ' . $v->e($v->plural($q->runs, 'time')) : '' ?></p>
        <p class="small muted">By <?= $v->e($q->creatorName) ?>, changed <?= $v->datetime($q->updatedAt, 'j M') ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <aside class="tests-side">
    <section class="panel" aria-labelledby="new-quiz-h">
      <h2 id="new-quiz-h">New live quiz</h2>
      <?= $v->errorSummary($errors) ?>
      <form method="post" action="/teach/live" class="form" novalidate>
        <?= $v->csrfField() ?>
        <?= $v->field('title', 'Title', ['value' => $title, 'error' => $errors['title'] ?? null, 'maxlength' => 120, 'autocomplete' => 'off', 'hint' => 'For example “Cells: quick check” or “Week 3 warm-up”.']) ?>
        <button type="submit" class="button">Make the quiz</button>
      </form>
    </section>
    <?php $kitBack = '/teach/live'; include __DIR__ . '/_starter_kit.php'; ?>
    <section class="panel panel-quiet" aria-labelledby="how-live-h">
      <h2 id="how-live-h">How a game runs</h2>
      <ol class="small how-steps">
        <li>Press Play live and show your screen to the class.</li>
        <li>Learners open <strong>/play</strong> on their phones and type the PIN.</li>
        <li>You start each question; answers close when time is up or everyone has answered.</li>
        <li>The right answer and how the class answered show next, then the top five.</li>
        <li>At the end: a podium on screen, and full results for you to download.</li>
      </ol>
    </section>
  </aside>
</div>
