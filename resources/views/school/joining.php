<?php
/**
 * School → Joining: how people sign up, and requests waiting for a decision.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 * @var bool $mayConfigure
 * @var list<\App\Modules\Joining\JoinRequest> $requests
 * @var string $joinUrl
 */
$schoolTab = 'joining';
$pending = array_values(array_filter($requests, static fn ($r): bool => $r->isPending()));
$decided = array_values(array_filter($requests, static fn ($r): bool => !$r->isPending()));
$modes = [
    'closed' => ['Only staff add people', 'Learners get accounts from the school, as now.'],
    'code' => ['With the school\'s join code', 'Anyone who has the code can create a learner account. Share it in class or in your group chat.'],
    'open' => ['Anyone can sign up', 'The join page is public and listed for search engines. Good for open courses.'],
];
?>
<header class="page-head">
  <h1>Your school</h1>
  <p class="lead">How new learners join <?= $v->e($school->name) ?>, and requests from people who want to teach or follow a child.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>

<div class="teach-layout">
  <section aria-labelledby="req-h">
    <h2 id="req-h">Requests <span class="muted small"><?= count($pending) === 0 ? 'none waiting' : $v->e(count($pending) . ' waiting') ?></span></h2>
    <?php if ($pending === []): ?>
      <p class="muted">When someone asks to teach, or a parent asks to follow their child, it appears here and in your notifications.</p>
    <?php endif; ?>
    <ul class="request-list">
      <?php foreach ($pending as $r): ?>
      <li class="request is-pending">
        <div class="request-head">
          <span class="request-kind"><?= $v->icon($r->kind === 'teach' ? 'teach' : 'child') ?><?= $v->e($r->kindLabel()) ?></span>
          <span class="small muted"><?= $v->datetime($r->createdAt, 'j M, H:i') ?></span>
        </div>
        <p class="request-who"><strong><?= $v->e($r->name) ?></strong> <a href="mailto:<?= $v->e($r->email) ?>"><?= $v->e($r->email) ?></a><?= $r->phone !== '' ? ' <a href="tel:' . $v->e((string) preg_replace('/[^0-9+]/', '', $r->phone)) . '">' . $v->e($r->phone) . '</a>' : '' ?></p>
        <?php if ($r->childName !== ''): ?><p class="request-child">Child: <strong><?= $v->e($r->childName) ?></strong></p><?php endif; ?>
        <?php if ($r->message !== ''): ?><div class="request-message"><?= $v->paragraphs($r->message) ?></div><?php endif; ?>
        <div class="row-actions">
          <?php if ($r->kind === 'teach'): ?>
            <form method="post" action="/school/joining/requests/<?= $r->id ?>/approve"><?= $v->csrfField() ?><button type="submit" class="button button-small">Let <?= $v->e(explode(' ', $r->name)[0]) ?> teach</button></form>
          <?php else: ?>
            <a class="button button-small" href="/people?find=<?= $v->e(rawurlencode($r->childName)) ?>">Find <?= $v->e(explode(' ', $r->childName)[0]) ?> in People</a>
            <form method="post" action="/school/joining/requests/<?= $r->id ?>/approve"><?= $v->csrfField() ?><button type="submit" class="button button-small button-quiet">Mark as done</button></form>
          <?php endif; ?>
          <form method="post" action="/school/joining/requests/<?= $r->id ?>/decline"><?= $v->csrfField() ?><button type="submit" class="linklike small">Decline</button></form>
        </div>
        <?php if ($r->kind === 'guardian'): ?><p class="small muted">Check who they are first (phone them or meet them), then open the learner in People and use "Link a parent or guardian".</p><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($decided !== []): ?>
    <details class="request-history">
      <summary>Recent decisions (<?= count($decided) ?>)</summary>
      <ul class="plain-list">
        <?php foreach ($decided as $r): ?>
          <li><?= $v->badge($r->status === 'approved' ? ($r->kind === 'teach' ? 'Approved' : 'Done') : 'Declined', $r->status === 'approved' ? 'ok' : 'neutral') ?> <?= $v->e($r->name) ?>, <?= $v->e(strtolower($r->kindLabel())) ?><span class="small muted"><?= $r->decidedBy !== null ? ' by ' . $v->e($r->decidedBy) : '' ?><?= $r->decidedAt !== null ? ', ' . $v->datetime($r->decidedAt, 'j M') : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </section>

  <aside>
    <?php if ($mayConfigure): ?>
    <section class="panel" aria-labelledby="signup-h">
      <h2 id="signup-h">Signing up</h2>
      <form method="post" action="/school/joining/settings" class="form">
        <?= $v->csrfField() ?>
        <fieldset class="field pick-list">
          <legend class="visually-hidden">Who can create a learner account</legend>
          <?php foreach ($modes as $mode => [$label, $hint]): ?>
          <label class="choice pick">
            <input type="radio" name="registration" value="<?= $mode ?>"<?= $school->registration === $mode ? ' checked' : '' ?>>
            <span><strong><?= $v->e($label) ?></strong><span class="pick-hint"><?= $v->e($hint) ?></span></span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <label class="choice choice-tight"><input type="checkbox" name="parent_requests" value="1"<?= $school->parentRequests ? ' checked' : '' ?>><span>Parents can ask to follow their child (you check who they are before linking)</span></label>
        <button type="submit" class="button button-small">Save</button>
      </form>
      <?php if ($school->registration !== 'closed'): ?>
      <div class="join-share">
        <p class="small muted">Share this link<?= $school->registration === 'code' && $school->joinCode !== null ? ' and code' : '' ?>:</p>
        <p><a href="/join"><?= $v->e($joinUrl) ?></a></p>
        <?php if ($school->registration === 'code' && $school->joinCode !== null): ?>
          <p class="join-code"><span class="secret"><?= $v->e(substr($school->joinCode, 0, 4) . '-' . substr($school->joinCode, 4)) ?></span></p>
          <p class="small muted">Or share <a href="/join?code=<?= $v->e($school->joinCode) ?>">the link with the code filled in</a>.</p>
          <form method="post" action="/school/joining/code"><?= $v->csrfField() ?><button type="submit" class="linklike small">Make a new code (the old one stops working)</button></form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="panel" aria-labelledby="who-h">
      <h2 id="who-h">How each person joins</h2>
      <ul class="checklist-plain">
        <li class="is-on">Learners: created by staff in People, or by themselves when signing up is on.</li>
        <li class="is-on">Teachers: added by staff, or a member asks here and you approve.</li>
        <li class="is-on">Parents and guardians: always linked by staff after checking who they are.</li>
      </ul>
    </section>
  </aside>
</div>
