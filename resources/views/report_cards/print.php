<?php
/**
 * Every learner's card for printing, one per page (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var list<array{user_id: int, name: string, email: string, added_at: string}> $learners
 * @var array<string, mixed> $sheet
 * @var array<int, array{teacher: string, head: string}> $remarks
 */
$draftNote = true;
$all = $remarks;
?>
<div class="no-print">
  <?= $v->breadcrumbs([$group->name => '/groups/' . $group->id, 'Report cards' => '/groups/' . $group->id . '/report-cards', $card->title => '/groups/' . $group->id . '/report-cards/' . $card->id, 'Print' => null]) ?>
  <p class="row-actions"><button type="button" class="button" data-print>Print or save as PDF</button> <span class="small muted"><?= $v->e($v->plural(count($learners), 'card')) ?>, one per page.</span></p>
</div>
<?php foreach ($learners as $learner): $remarks = $all[$learner['user_id']] ?? ['teacher' => '', 'head' => '']; ?>
<div class="rc-page"><?php include __DIR__ . '/_card.php'; ?></div>
<?php endforeach; ?>
