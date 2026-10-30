<?php
/**
 * Change a report card's setup (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var list<\App\Modules\Exams\Cbt\CbtExam> $exams
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
$base = '/groups/' . $group->id . '/report-cards/' . $card->id;
if ($old !== []) {
    $values = ['title' => (string) $old['title'], 'grading' => (string) $old['grading'], 'next_term' => (string) $old['next_term'], 'rows' => []];
    foreach ((array) $old['exam'] as $i => $exam) {
        $values['rows'][] = ['exam' => (string) $exam, 'label' => (string) (((array) $old['label'])[$i] ?? ''), 'weight' => (string) (((array) $old['weight'])[$i] ?? '')];
    }
} else {
    $values = ['title' => $card->title, 'grading' => $card->grading, 'next_term' => $card->nextTerm, 'rows' => array_map(
        static fn (array $c): array => ['exam' => (string) $c['exam'], 'label' => $c['label'], 'weight' => (string) $c['weight']],
        $card->components,
    )];
}
$action = $base;
$submitLabel = 'Save';
?>
<?= $v->breadcrumbs([$group->name => '/groups/' . $group->id, 'Report cards' => '/groups/' . $group->id . '/report-cards', $card->title => $base, 'Change' => null]) ?>
<header class="page-head">
  <h1>Change the report card</h1>
  <?php if ($card->isPublished()): ?><p class="lead">It is shared: learners and parents see your changes straight away.</p><?php endif; ?>
</header>
<?php include __DIR__ . '/_form.php'; ?>
