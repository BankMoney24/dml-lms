<?php
/**
 * A class's report cards, and making a new one (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var list<\App\Modules\ReportCards\ReportCard> $cards
 * @var list<\App\Modules\Exams\Cbt\CbtExam> $exams
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
$base = '/groups/' . $group->id . '/report-cards';
$values = [
    'title' => (string) ($old['title'] ?? ''),
    'grading' => (string) ($old['grading'] ?? 'waec'),
    'next_term' => (string) ($old['next_term'] ?? ''),
    'rows' => [],
];
foreach ((array) ($old['exam'] ?? []) as $i => $exam) {
    $values['rows'][] = ['exam' => (string) $exam, 'label' => (string) (((array) ($old['label'] ?? []))[$i] ?? ''), 'weight' => (string) (((array) ($old['weight'] ?? []))[$i] ?? '')];
}
$action = $base;
$submitLabel = 'Make the report card';
?>
<?= $v->breadcrumbs([$group->name => '/groups/' . $group->id, 'Report cards' => null]) ?>
<header class="page-head">
  <h1>Report cards</h1>
  <p class="lead"><?= $v->e($group->name) ?>: a card for each learner each term, worked out from the class's CBT exams, with positions, grades and your remarks.</p>
</header>

<?php if ($cards !== []): ?>
<section class="section" aria-labelledby="cards-h">
  <h2 id="cards-h">This class's report cards</h2>
  <ul class="plain-list rc-list">
    <?php foreach ($cards as $c): ?>
    <li class="set-course"><span><a href="<?= $base ?>/<?= $c->id ?>"><?= $v->e($c->title) ?></a><span class="small muted"><?= $c->isPublished() ? 'Shared ' . $v->datetime((string) $c->publishedAt, 'j M Y') : 'Not shared yet' ?></span></span><?= $c->isPublished() ? $v->badge('Shared', 'ok') : $v->badge('Draft', 'draft') ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="section panel" aria-labelledby="new-card-h">
  <h2 id="new-card-h">New report card</h2>
  <?php if ($exams === []): ?>
    <?= $v->emptyState('First, an exam', 'A report card is worked out from CBT exams this class has sat. Set and publish an exam for this class (or for everyone), then come back.', '/teach/exams', 'Go to CBT exams') ?>
  <?php else: ?>
    <?php include __DIR__ . '/_form.php'; ?>
  <?php endif; ?>
</section>
