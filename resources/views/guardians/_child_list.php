<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Guardians\GuardianLink> $children
 */
?>
<ul class="mini-groups child-list">
  <?php foreach ($children as $c): ?>
  <li><a href="/children/<?= $c->learnerId ?>"><span class="mini-group-name"><?= $v->e($c->learnerName) ?></span><span class="small muted">You are their <?= $v->e(strtolower($c->relationshipLabel())) ?></span></a></li>
  <?php endforeach; ?>
</ul>
