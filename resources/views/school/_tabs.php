<?php
/**
 * Tabs shared by the school's administration pages.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $schoolTab 'overview' | 'website' | 'payments' | 'support' | 'people' | 'activity'
 */
$tabs = [];
if ($v->can('school.manage')) {
    $tabs['overview'] = ['/school', 'Settings'];
}
if ($v->can('school.manage')) {
    $tabs['website'] = ['/school/website', 'Website'];
}
if ($v->can('school.manage')) {
    $tabs['payments'] = ['/school/payments', 'Payments'];
}
if ($v->can('support.manage')) {
    $tabs['support'] = ['/school/support', 'Help desk'];
}
if ($v->can('members.manage')) {
    $tabs['people'] = ['/people', 'People'];
}
if ($v->can('audit.view')) {
    $tabs['activity'] = ['/activity', 'Activity'];
}
?>
<?php if (count($tabs) > 1): ?>
<nav class="tabs" aria-label="School">
  <?php foreach ($tabs as $key => [$href, $label]): ?>
    <a href="<?= $href ?>"<?= $schoolTab === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
