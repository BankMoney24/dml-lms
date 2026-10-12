<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<section class="narrow">
  <?= $v->breadcrumbs(['Your courses' => '/teach', 'New course' => null]) ?>
  <header class="page-head">
    <h1>New course</h1>
    <p class="lead">Start with a title and a short summary. You will add modules and lessons next.</p>
  </header>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="/teach/courses" class="form" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('title', 'Course title', ['value' => $old['title'], 'error' => $errors['title'] ?? null, 'required' => true, 'maxlength' => 190]) ?>
    <?= $v->field('summary', 'Summary', [
        'type' => 'textarea', 'rows' => 4, 'value' => $old['summary'], 'error' => $errors['summary'] ?? null, 'optional' => true,
        'hint' => 'What will learners be able to do afterwards? Two or three sentences is enough.', 'maxlength' => 2000,
    ]) ?>
    <button type="submit" class="button">Create course</button>
  </form>
</section>
