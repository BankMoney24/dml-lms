Hello <?= $name ?>,

<?php if ($approved): ?>
<?= $reviewer ?> approved your work for "<?= $lessonTitle ?>" in <?= $courseTitle ?>. That lesson is now complete.
<?php else: ?>
<?= $reviewer ?> reviewed your work for "<?= $lessonTitle ?>" in <?= $courseTitle ?> and asked for some changes before approving it.
<?php endif; ?>
<?php if ($feedback !== ''): ?>

Feedback:
<?= $feedback ?>

<?php endif; ?>

<?= $link ?>

