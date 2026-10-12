<?php /** @var \App\Core\View\ViewHelpers $v */ ?>
<details class="format-help">
  <summary>Formatting help</summary>
  <table class="table format-table">
    <thead><tr><th scope="col">Type this</th><th scope="col">To get</th></tr></thead>
    <tbody>
      <tr><td><code>## Heading</code> / <code>### Smaller</code></td><td>Section headings</td></tr>
      <tr><td><code>**bold**</code> <code>*italic*</code> <code>`code`</code></td><td><strong>bold</strong>, <em>italic</em>, <code>code</code></td></tr>
      <tr><td><code>- item</code> or <code>1. step</code></td><td>Lists (indent two spaces to nest)</td></tr>
      <tr><td><code>```bash</code> … <code>```</code></td><td>A code block with a copy button</td></tr>
      <tr><td><code>[text](https://…)</code></td><td>A link</td></tr>
      <tr><td><code>&gt; **Tip:** …</code></td><td>A callout (also Note, Warning, Important)</td></tr>
      <tr><td><code>| a | b |</code> then <code>|---|---|</code></td><td>A table</td></tr>
    </tbody>
  </table>
  <p class="small muted">Leave a blank line between paragraphs. HTML is shown as text, never run.</p>
</details>
