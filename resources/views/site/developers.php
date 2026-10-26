<?php
/**
 * Who built the platform and how to reach them (ADR 0033). Platform page, the same for every school.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var string $securityContact mailto: or https: address
 */
/** @var array{name: string, url: string, pitch: string, sectors: array<string, list<string>>} $tridax */
$tridax = require __DIR__ . '/../../data/tridax.php';
$school = $v->shared('tenant');
$securityLabel = str_starts_with($securityContact, 'mailto:') ? substr($securityContact, 7) : preg_replace('#^https://#', '', $securityContact);
?>
<?= $v->breadcrumbs(['Home' => '/', 'Developers' => null]) ?>
<article class="site-page dev-page">
  <header class="page-head">
    <h1>Developers</h1>
    <p class="lead">This learning platform was designed and built by <?= $v->e($tridax['name']) ?>. <?= $school !== null ? $v->e($school->name) . ' runs it and decides what is taught here.' : '' ?></p>
  </header>

  <div class="dev-grid">
    <section class="dev-card" aria-labelledby="dev-help-h">
      <h2 id="dev-help-h">Need help with a course or your account?</h2>
      <p>Your school answers those questions, not the developers. <?= $v->shared('user') !== null ? 'Use <a href="/help">Get help</a>: your message goes to the school\'s administrators.' : 'Sign in and use <strong>Get help</strong>' . (isset(((array) $v->shared('site_pages', []))['/contact']) ? ', or see the school\'s <a href="/contact">contact page</a>.' : '.') ?></p>
    </section>

    <section class="dev-card" id="security" aria-labelledby="dev-sec-h">
      <h2 id="dev-sec-h">Found a security problem?</h2>
      <p>Please tell us privately at <a href="<?= $v->e($securityContact) ?>" rel="noopener"><?= $v->e((string) $securityLabel) ?></a>. Say what you found and how to see it. Do not look at or change other people's information, and give us a reasonable time to fix it before telling anyone else. We thank everyone who reports in good faith.</p>
      <p class="small muted">The same address is in <a href="/.well-known/security.txt">security.txt</a>.</p>
    </section>

    <section class="dev-card" aria-labelledby="dev-work-h">
      <h2 id="dev-work-h">Work with <?= $v->e($tridax['name']) ?></h2>
      <p><?= $v->e($tridax['pitch']) ?></p>
      <p><a class="button button-small" href="<?= $v->e($tridax['url']) ?>" rel="noopener">Talk to <?= $v->e($tridax['name']) ?> about your project</a></p>
    </section>
  </div>

  <section class="dev-section" aria-labelledby="dev-about-h">
    <h2 id="dev-about-h">About the platform</h2>
    <ul class="dev-facts">
      <li><strong>Built for phones and slow connections:</strong> pages work without a fast connection, lessons can be saved for offline reading, video loads only when you press play.</li>
      <li><strong>Each school separate:</strong> every school has its own web address, people and records, and no school can see another's.</li>
      <li><strong>Private by design:</strong> one cookie to keep you signed in, no advertising trackers, passwords and keys stored protected.</li>
      <li><strong>Accessible:</strong> built to WCAG 2.2 AA. See the <a href="/accessibility">accessibility statement</a>.</li>
      <li><strong>Open standards:</strong> certificates anyone can check, calendar links for any calendar app, spreadsheets for every report.</li>
    </ul>
  </section>

  <section class="dev-section" aria-labelledby="dev-also-h">
    <h2 id="dev-also-h">What else <?= $v->e($tridax['name']) ?> builds</h2>
    <div class="tridax-pop-grid dev-sectors">
      <?php foreach ($tridax['sectors'] as $sector => $items): ?>
      <section class="tridax-sector">
        <h3><?= $v->e($sector) ?></h3>
        <ul><?php foreach ($items as $item): ?><li><?= $v->e($item) ?></li><?php endforeach; ?></ul>
      </section>
      <?php endforeach; ?>
    </div>
  </section>
</article>
