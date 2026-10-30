<?php
/**
 * A published report card, for the learner or a parent (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var array{user_id: int, name: string} $learner
 * @var array<string, mixed> $sheet
 * @var array{teacher: string, head: string} $remarks
 * @var string $back
 * @var string $backLabel
 */
$draftNote = false;
?>
<div class="no-print">
  <?= $v->breadcrumbs([$backLabel => $back, $card->title => null]) ?>
  <p class="row-actions"><button type="button" class="button button-small" data-print>Print or save as PDF</button></p>
</div>
<?php include __DIR__ . '/_card.php'; ?>
