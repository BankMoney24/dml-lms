<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var string $platformTab 'health' | 'schools'
 */
?>
<nav class="tabs" aria-label="Platform">
  <a href="/system"<?= $platformTab === 'health' ? ' aria-current="page"' : '' ?>>Health</a>
  <a href="/system/schools"<?= $platformTab === 'schools' ? ' aria-current="page"' : '' ?>>Schools</a>
</nav>
