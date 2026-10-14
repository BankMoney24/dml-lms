<?php
/**
 * The navy side of the join pages: who learns here and how it starts.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 */
?>
<aside class="join-panel" aria-label="Learning at <?= $v->e($school->name) ?>">
  <?php $pixelClass = 'pixels-hero'; include __DIR__ . '/../partials/pixels.php'; ?>
  <p class="join-school"><?= $v->e($school->name) ?></p>
  <?php if ($school->tagline !== ''): ?><p class="join-tagline"><?= $v->e($school->tagline) ?></p><?php endif; ?>
  <ul class="persona-list">
    <li><?= $v->icon('code') ?><span><strong>Programmers</strong>Skip the basics, practise in labs and the code playground.</span></li>
    <li><?= $v->icon('school') ?><span><strong>Students</strong>Lessons on a phone, quizzes that explain every answer.</span></li>
    <li><?= $v->icon('briefcase') ?><span><strong>Changing career</strong>Practical skills<?= $school->certificatesEnabled ? ' and certificates anyone can check' : '' ?>.</span></li>
  </ul>
  <ol class="join-steps">
    <li><span class="join-step-node" aria-hidden="true"></span>Create your account</li>
    <li><span class="join-step-node" aria-hidden="true"></span>Tell us what you want to learn (one minute)</li>
    <li><span class="join-step-node" aria-hidden="true"></span>Start your first lesson</li>
  </ol>
</aside>
