<?php
/**
 * Accessibility statement (ADR 0033). Platform page: what is tested, known limits, how to report a problem.
 *
 * @var \App\Core\View\ViewHelpers $v
 * @var \App\Modules\Tenancy\Tenant $school
 */
?>
<?= $v->breadcrumbs(['Home' => '/', 'Accessibility' => null]) ?>
<article class="site-page">
  <header class="page-head">
    <h1>Accessibility</h1>
    <p class="lead">We want everyone to be able to learn here, whatever device, connection or way of using a computer they have.</p>
  </header>
  <div class="prose">
    <h2>What we aim for</h2>
    <p>The platform is built to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA. In practice that means:</p>
    <ul>
      <li>every page works with a keyboard alone, with a visible outline showing where you are;</li>
      <li>text has strong contrast in both light and dark appearance, and you can choose either under your account menu;</li>
      <li>pages work at any width, from a small phone to a large screen, and when zoomed to 200%;</li>
      <li>forms say clearly what went wrong and where, and buttons say what they do;</li>
      <li>nothing moves on its own if your device asks for reduced motion;</li>
      <li>you can save lessons to read offline, and videos load only when you press play.</li>
    </ul>
    <h2>How we check</h2>
    <p>Before each update, an automated test opens every page as a visitor, learner, teacher and administrator at eight screen widths, in light and dark appearance, and checks it with axe-core against WCAG 2.2 A and AA. Updates are not released with known failures.</p>
    <h2>What we know is not yet right</h2>
    <ul>
      <li>We have not yet finished testing with screen readers (NVDA, VoiceOver and TalkBack). If something does not work with yours, please tell us.</li>
      <li>The live classroom uses Jitsi Meet, whose controls are made by another project; most work with a keyboard and screen reader, but not all.</li>
      <li>Videos and audio that teachers upload may not have captions or transcripts. Ask your teacher for a transcript if you need one.</li>
      <li>The Code studio editor works with a keyboard, but reading code with a screen reader is easier in a full code editor on your own device.</li>
    </ul>
    <h2>Tell us about a problem</h2>
    <p>If something on this website is hard or impossible for you to use, tell <?= $v->e($school->name) ?><?= $school->contactEmail !== '' ? ' at <a href="mailto:' . $v->e($school->contactEmail) . '">' . $v->e($school->contactEmail) . '</a>' : '' ?>, or use <strong>Get help</strong> after you sign in. Say which page and what happened; we will find another way to give you what you need while we fix it.</p>
    <p class="small muted">This statement covers the learning platform built by TridaxLabs. Last reviewed 27 October 2026.</p>
  </div>
</article>
