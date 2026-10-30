<?php
/**
 * One learner's report card with the remarks to write (ADR 0046).
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var \App\Modules\Groups\LearnerGroup $group
 * @var \App\Modules\ReportCards\ReportCard $card
 * @var array{user_id: int, name: string, email: string, added_at: string} $learner
 * @var array<string, mixed> $sheet
 * @var array{teacher: string, head: string} $remarks
 * @var bool $mayWriteHead
 * @var ?array{user_id: int, name: string, email: string, added_at: string} $previous
 * @var ?array{user_id: int, name: string, email: string, added_at: string} $next
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$base = '/groups/' . $group->id . '/report-cards/' . $card->id;
$draftNote = true;
$teacherText = $old['teacher'] ?? $remarks['teacher'];
$headText = $old['head'] ?? $remarks['head'];
?>
<div class="no-print">
  <?= $v->breadcrumbs([$group->name => '/groups/' . $group->id, 'Report cards' => '/groups/' . $group->id . '/report-cards', $card->title => $base, $learner['name'] => null]) ?>
  <nav class="rc-step" aria-label="Learners">
    <?php if ($previous !== null): ?><a href="<?= $base ?>/learners/<?= $previous['user_id'] ?>"><?= $v->icon('arrow', 'flip') ?><?= $v->e($previous['name']) ?></a><?php else: ?><span></span><?php endif; ?>
    <button type="button" class="button button-small button-quiet" data-print>Print this card</button>
    <?php if ($next !== null): ?><a href="<?= $base ?>/learners/<?= $next['user_id'] ?>"><?= $v->e($next['name']) ?><?= $v->icon('arrow') ?></a><?php else: ?><span></span><?php endif; ?>
  </nav>
</div>

<?php include __DIR__ . '/_card.php'; ?>

<section class="panel no-print rc-remark-form" aria-labelledby="remarks-h">
  <h2 id="remarks-h">Remarks for <?= $v->e($learner['name']) ?></h2>
  <?= $v->errorSummary($errors) ?>
  <form method="post" action="<?= $base ?>/learners/<?= $learner['user_id'] ?>" class="form" novalidate>
    <?= $v->csrfField() ?>
    <?= $v->field('teacher_remark', "Class teacher's remark", ['type' => 'textarea', 'rows' => 3, 'value' => $teacherText, 'error' => $errors['teacher_remark'] ?? null, 'maxlength' => 600, 'hint' => 'What went well and one thing to work on. Learners and parents read it.']) ?>
    <?php if ($mayWriteHead): ?>
      <?= $v->field('head_remark', "Head's remark", ['type' => 'textarea', 'rows' => 2, 'value' => $headText, 'error' => $errors['head_remark'] ?? null, 'maxlength' => 600, 'optional' => true]) ?>
    <?php else: ?>
      <input type="hidden" name="head_remark" value="<?= $v->e($headText) ?>">
      <p class="small muted">The head's remark is written by the head or an administrator.</p>
    <?php endif; ?>
    <div class="row-actions">
      <?php if ($next !== null): ?><button type="submit" name="next" value="<?= $next['user_id'] ?>" class="button">Save and go to <?= $v->e($next['name']) ?></button><?php endif; ?>
      <button type="submit" class="button<?= $next !== null ? ' button-quiet' : '' ?>">Save</button>
    </div>
  </form>
</section>
