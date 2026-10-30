<?php
/**
 * The tests starter kit panel (ADR 0043), on the live quiz, exam and bank lists.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var array{banks: int, questions: int, quizzes: int, exams: int, missing: int} $kit
 * @var string $kitBack where to come back to after adding it
 */
$kitAll = $kit['missing'] >= $kit['questions'] + $kit['quizzes'] + $kit['exams'];
?>
<section class="panel panel-quiet starter-kit" aria-labelledby="kit-h">
  <h2 id="kit-h">Starter kit</h2>
  <p class="small"><?= $kit['banks'] ?> sample question banks with <?= $kit['questions'] ?> questions (UTME and WAEC subjects, plus networking, cybersecurity, Python and Linux), <?= $kit['quizzes'] ?> live quizzes ready to play, and <?= $kit['exams'] ?> draft exams, including two UTME-style mocks.</p>
  <p class="small muted">All written for this site: they are not past JAMB, WAEC or NECO questions. Check them and make them your own; the exams stay drafts until you publish them.</p>
  <?php if ($kit['missing'] > 0): ?>
  <form method="post" action="/teach/banks/samples">
    <?= $v->csrfField() ?>
    <input type="hidden" name="back" value="<?= $v->e($kitBack) ?>">
    <button type="submit" class="button button-quiet button-small"><?= $kitAll ? 'Add the starter kit' : 'Add what is missing from the kit' ?></button>
  </form>
  <?php else: ?>
  <p class="small muted">Everything in the kit is in your lists.</p>
  <?php endif; ?>
</section>
