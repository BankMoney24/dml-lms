<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
?>
<?= $v->field('people', 'People to invite', [
    'type' => 'textarea',
    'rows' => 8,
    'value' => (string) ($values['people'] ?? ''),
    'error' => $errors['people'] ?? null,
    'hint' => 'One person per line: name, email, role. The role is admin, educator or learner; leave it out for educator. You can paste straight from a spreadsheet.',
    'class' => 'field-code',
]) ?>
<div class="example-block" aria-label="Example">
  <p class="small muted">For example:</p>
  <pre><code>Ngozi Eze, ngozi.eze@yourschool.ng, admin
Tunde Bello, tunde@yourschool.ng, educator
Aisha Musa, aisha.musa@yourschool.ng</code></pre>
</div>
<p class="small muted">Nobody is contacted until you confirm on the last step.</p>
