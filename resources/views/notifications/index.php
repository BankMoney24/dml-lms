<?php
/**
 * @var \App\Core\View\ViewHelpers $v
 * @var list<\App\Modules\Notifications\Notification> $items
 */
$unread = array_filter($items, static fn ($n): bool => !$n->isRead());
?>
<header class="page-head page-head-row">
  <div>
    <h1>Notifications</h1>
    <p class="lead"><?= $unread === [] ? 'You are up to date.' : $v->e($v->plural(count($unread), 'new notification')) ?></p>
  </div>
  <?php if ($unread !== []): ?>
  <form method="post" action="/notifications/read-all" class="page-head-actions">
    <?= $v->csrfField() ?>
    <button type="submit" class="button button-small button-quiet">Mark all as read</button>
  </form>
  <?php endif; ?>
</header>

<?php if ($items === []): ?>
  <?= $v->emptyState('Nothing here yet', 'When a teacher reviews your work, sets a course or posts an announcement, it shows up here.') ?>
<?php else: ?>
<ol class="notice-list">
  <?php foreach ($items as $n): ?>
  <li class="notice-item<?= $n->isRead() ? '' : ' is-new' ?>">
    <a href="/notifications/<?= $n->id ?>">
      <span class="notice-title"><?= $v->e($n->title) ?><?php if (!$n->isRead()): ?><span class="visually-hidden"> (new)</span><?php endif; ?></span>
      <?php if ($n->body !== null): ?><span class="notice-body"><?= $v->e($n->body) ?></span><?php endif; ?>
      <span class="notice-time"><?= $v->datetime($n->createdAt) ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ol>
<p class="small muted">Read notifications are cleared after <?= \App\Modules\Notifications\NotificationRepository::KEEP_READ_DAYS ?> days.</p>
<?php endif; ?>
