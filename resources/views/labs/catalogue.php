<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\VirtualLabs\LabDefinition> $labs
 */
$groups = [];
foreach ($labs as $lab) {
    $groups[$lab->track][] = $lab;
}
$headings = ['school' => 'School subjects', 'skills' => 'Skills'];
?>
<?= $v->breadcrumbs(['Your courses' => '/teach', 'Lab catalogue' => null]) ?>
<header class="page-head">
  <h1>Lab catalogue</h1>
  <p class="lead">Hands-on simulations you can put in any course. Learners work in their browser, get their own numbers, and are marked automatically.</p>
</header>

<p class="notice">To use one, open a course, choose <strong>Add a lesson</strong>, pick <strong>Lab</strong> and choose it from the list. Try each lab here first: practice runs are not saved.</p>

<?php foreach ($headings as $track => $heading): if (($groups[$track] ?? []) === []) { continue; } ?>
<section class="section" aria-labelledby="track-<?= $v->e($track) ?>">
  <h2 id="track-<?= $v->e($track) ?>"><?= $v->e($heading) ?></h2>
  <ul class="lab-list">
    <?php foreach ($groups[$track] as $lab): ?>
    <li class="lab-item">
      <div class="lab-item-main">
        <p class="lab-kind"><?= $v->e($lab->subject) ?>, <?= $v->e($lab->level) ?></p>
        <h3><?= $v->e($lab->title) ?></h3>
        <p><?= $v->e($lab->summary) ?></p>
        <p class="small muted"><?= $lab->minutes ?> minutes, pass mark <?= $lab->passMark ?>%<?= $lab->runsLearnerCode ? '. Learners write code; it runs only in their browser, in a sandbox with no internet access' : '' ?>.</p>
      </div>
      <a class="button button-small button-quiet" href="/teach/labs/<?= $v->e($lab->key) ?>">Try it<span class="visually-hidden">: <?= $v->e($lab->title) ?></span></a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
