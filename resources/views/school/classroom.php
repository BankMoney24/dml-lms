<?php
/**
 * School, Classroom: the school's own Jitsi Meet server (ADR 0029).
 * The app secret is never shown or prefilled.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var ?\App\Modules\LiveClasses\Classroom\ClassroomSettings $settings
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$schoolTab = 'classroom';
$o = static fn (string $k, string $d = ''): string => $old[$k] ?? $d;
$domain = $settings->domain ?? 'live.yourschool.org';
$appId = $settings->appId ?? 'dml-lms';
?>
<header class="page-head">
  <h1>Online classroom</h1>
  <p class="lead">Teach live classes inside this site, on your school’s own Jitsi Meet server. Learners join from the class page with one tap; only learners in that class get in.</p>
</header>
<?php include __DIR__ . '/_tabs.php'; ?>
<?= $v->errorSummary($errors) ?>

<div class="settings">
  <section class="settings-group" aria-labelledby="cr-h">
    <div class="settings-head"><h2 id="cr-h"><?= $settings === null ? 'Connect your classroom server' : 'Your classroom server' ?></h2></div>
    <?php if ($settings !== null): ?>
      <dl class="facts">
        <dt>Server</dt><dd><?= $v->e($settings->domain) ?></dd>
        <dt>App id</dt><dd><code><?= $v->e($settings->appId) ?></code></dd>
        <dt>App secret</dt><dd>Stored encrypted. It is never shown again, not even to you.</dd>
        <dt>Learners start in</dt><dd><?= $settings->lowData ? 'Data saver: small video, one speaker at a time' : 'Normal video' ?></dd>
        <dt>Last changed</dt><dd><?= $v->datetime($settings->updatedAt) ?><?= $settings->updatedBy !== '' ? ' by ' . $v->e($settings->updatedBy) : '' ?></dd>
      </dl>
    <?php else: ?>
      <p>The classroom needs a Jitsi Meet server set up for token sign-in, usually on a subdomain such as <code>live.yourschool.org</code>. Your technical person can follow the steps below; it takes about an hour on a fresh server.</p>
    <?php endif; ?>

    <form method="post" action="/school/classroom" class="form" novalidate autocomplete="off">
      <?= $v->csrfField() ?>
      <?= $v->field('domain', 'Server name', [
          'value' => $o('domain'), 'error' => $errors['domain'] ?? null, 'inputmode' => 'url', 'maxlength' => 190, 'autocomplete' => 'off',
          'hint' => 'Just the name, for example live.yourschool.org.',
      ]) ?>
      <?= $v->field('app_id', 'App id', [
          'value' => $o('app_id'), 'error' => $errors['app_id'] ?? null, 'maxlength' => 100, 'autocomplete' => 'off',
          'hint' => 'The app_id set on the Jitsi server.',
      ]) ?>
      <?= $v->field('secret', 'App secret', [
          'type' => 'password', 'autocomplete' => 'new-password', 'error' => $errors['secret'] ?? null, 'optional' => $settings !== null,
          'hint' => $settings === null
              ? 'The app_secret set on the Jitsi server. It is stored encrypted and never shown again.'
              : 'Leave empty to keep the current secret. Type a new one only if you changed it on the server.',
      ]) ?>
      <label class="choice choice-tight"><input type="checkbox" name="low_data" value="1"<?= $o('low_data', '1') === '1' ? ' checked' : '' ?>>
        <span><strong>Start learners in data saver.</strong> Small video and only the person speaking, so a class uses far less mobile data. Each learner can switch to audio only or normal video.</span></label>
      <button type="submit" class="button"><?= $settings === null ? 'Connect the classroom' : 'Save' ?></button>
    </form>
  </section>

  <section class="settings-group" aria-labelledby="setup-h">
    <div class="settings-head"><h2 id="setup-h">Setting up the server</h2></div>
    <ol class="steps-list small">
      <li>Point <code><?= $v->e($domain) ?></code> at the classroom server and install Jitsi Meet from the official guide (Debian or Ubuntu, or Docker). Allow TCP 443 and UDP 10000 through the firewall.</li>
      <li>Install token sign-in: <code>apt install jitsi-meet-tokens</code>. Choose the app id and a long random secret (<code>openssl rand -hex 32</code>).</li>
      <li>In Prosody’s config for <code><?= $v->e($domain) ?></code>, the lines should read:
        <div class="code-block code-wrap"><pre><code>authentication = "token"
app_id = "<?= $v->e($appId) ?>"
app_secret = "the same secret you paste here"
allow_empty_token = false</code></pre></div>
        so nobody can open a room without a pass from this site.</li>
      <li>So that only teachers run the room (muting others, removing someone), enable token-based moderator rights, for example the community module <code>token_affiliation</code>: passes from this site mark teachers as owners and learners as members. Without it, Jitsi picks moderators its own way, usually whoever joins first.</li>
      <li>For learners behind strict mobile networks, add a TURN relay (coturn) on TCP 443.</li>
      <li>Paste the server name, app id and secret above, then plan a test class in the online classroom and join it from two phones.</li>
    </ol>
    <p class="small muted">Recording is not part of this set-up. After a class, teachers can upload a recording to the class page.</p>
  </section>

  <?php if ($settings !== null): ?>
  <section class="settings-group" aria-labelledby="dis-h">
    <div class="settings-head"><h2 id="dis-h">Disconnect</h2></div>
    <details class="confirm">
      <summary>Disconnect the classroom</summary>
      <form method="post" action="/school/classroom/disconnect">
        <?= $v->csrfField() ?>
        <p class="small">The secret is deleted from this site. Classes planned in the online classroom cannot be joined until it is connected again.</p>
        <button type="submit" class="button button-danger button-small">Yes, disconnect</button>
      </form>
    </details>
  </section>
  <?php endif; ?>
</div>
