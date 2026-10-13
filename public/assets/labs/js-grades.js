/*
 * JavaScript exercise. The learner's code is sent to the sandboxed runner
 * (/labs/runner in an iframe with sandbox="allow-scripts"), never evaluated
 * on this page. "Check my answers" runs it on the session's test scores and
 * sends only the outputs and the code to the server, which marks them.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('js-grades', function (root, p, form) {
    var bench = L.el('div', { class: 'sim sim-code' }, null, root);
    var spec = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', {}, 'The grading rules', spec);
    var rules = L.el('table', { class: 'table table-compact' }, null, spec);
    var rh = L.el('tr', {}, null, L.el('thead', {}, null, rules));
    L.el('th', { scope: 'col' }, 'Score', rh);
    L.el('th', { scope: 'col' }, 'Grade', rh);
    var rb = L.el('tbody', {}, null, rules);
    [['70 to 100', 'A'], ['60 to 69', 'B'], ['50 to 59', 'C'], ['45 to 49', 'D'], ['40 to 44', 'E'], ['0 to 39', 'F']].forEach(function (r) {
      var tr = L.el('tr', {}, null, rb);
      L.el('td', {}, r[0], tr);
      L.el('td', {}, r[1], tr);
    });

    var ex = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', {}, 'Examples', ex);
    var table = L.el('table', { class: 'table table-compact' }, null, ex);
    var head = L.el('tr', {}, null, L.el('thead', {}, null, table));
    ['Call', 'Should return', 'Your function returns'].forEach(function (h) { L.el('th', { scope: 'col' }, h, head); });
    var body = L.el('tbody', {}, null, table);
    var cells = p.examples.map(function (e) {
      var tr = L.el('tr', {}, null, body);
      L.el('code', {}, 'grade(' + e[0] + ')', L.el('td', {}, null, tr));
      L.el('td', {}, '"' + e[1] + '"', tr);
      return L.el('td', { class: 'sim-result' }, '—', tr);
    });
    var runBtn = L.el('button', { type: 'button', class: 'button button-small' }, 'Run the examples', ex);
    var status = L.el('p', { class: 'sim-status', 'aria-live': 'polite' }, 'Write your function in the box under “Your answers”, then run the examples.', ex);

    var codeBox = L.answer('code');
    var outputs = L.answer('outputs');
    if (codeBox) {
      codeBox.setAttribute('spellcheck', 'false');
      codeBox.setAttribute('autocapitalize', 'off');
      // Tab inserts two spaces instead of leaving the box; Escape then Tab leaves it.
      var escaped = false;
      codeBox.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { escaped = true; return; }
        if (e.key === 'Tab' && !e.shiftKey && !escaped) {
          e.preventDefault();
          var at = codeBox.selectionStart;
          codeBox.value = codeBox.value.slice(0, at) + '  ' + codeBox.value.slice(codeBox.selectionEnd);
          codeBox.selectionStart = codeBox.selectionEnd = at + 2;
        }
        escaped = false;
      });
    }

    // The sandbox.
    var frame = L.el('iframe', { src: root.getAttribute('data-runner') || '/labs/runner', sandbox: 'allow-scripts', title: 'Code runner', class: 'sim-runner', tabindex: '-1', 'aria-hidden': 'true' }, null, root);
    var ready = false;
    var pending = {};
    var nextId = 1;
    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) { return; }
      var d = event.data || {};
      if (d.ready) { ready = true; return; }
      var cb = pending[d.id];
      if (cb) { delete pending[d.id]; cb(d); }
    });

    function run(inputs, cb) {
      if (!ready) { cb({ error: 'The code runner is still loading. Try again in a moment.' }); return; }
      var id = nextId++;
      pending[id] = cb;
      frame.contentWindow.postMessage({ id: id, code: codeBox ? codeBox.value : '', fn: p['function'], inputs: inputs, timeout: p.time_limit_ms }, '*');
      window.setTimeout(function () {
        if (pending[id]) { delete pending[id]; cb({ error: 'The code runner did not answer. Reload the page and try again.' }); }
      }, p.time_limit_ms + 3000);
    }

    runBtn.addEventListener('click', function () {
      status.textContent = 'Running…';
      run(p.examples.map(function (e) { return e[0]; }), function (r) {
        if (r.error) {
          status.textContent = r.error;
          cells.forEach(function (c) { c.textContent = '—'; c.className = 'sim-result'; });
          return;
        }
        var passed = 0;
        r.outputs.forEach(function (o, i) {
          var ok = o === p.examples[i][1];
          passed += ok ? 1 : 0;
          cells[i].textContent = (ok ? '✓ ' : '✕ ') + JSON.stringify(o);
          cells[i].className = 'sim-result ' + (ok ? 'is-ok' : 'is-wrong');
        });
        status.textContent = passed === p.examples.length
          ? 'All examples pass. Check the boundaries too, then press “Check my answers”.'
          : passed + ' of ' + p.examples.length + ' examples pass.';
      });
    });

    // Checking: run the session's own test scores first, then send.
    var submitting = false;
    form.addEventListener('submit', function (e) {
      if (submitting) { return; }
      e.preventDefault();
      var button = form.querySelector('[data-lab-submit]');
      if (button) { button.disabled = true; button.textContent = 'Running your function…'; }
      run(p.tests, function (r) {
        outputs.value = r.error || !Array.isArray(r.outputs) ? '' : JSON.stringify(r.outputs);
        submitting = true;
        form.submit();
      });
    });
  });
})();
