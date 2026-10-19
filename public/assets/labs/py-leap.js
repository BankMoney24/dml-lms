/*
 * Python exercise (ADR 0027). Python (Skulpt) runs inside the sandboxed runner
 * (/labs/runner, an opaque-origin iframe with no network), never on this page.
 * The interpreter is fetched here as text and handed to the runner once.
 * "Check my answers" runs the function on the session's own years and sends
 * only the outputs and the code to the server, which marks them.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('py-leap', function (root, p, form) {
    var bench = L.el('div', { class: 'sim sim-code' }, null, root);
    var spec = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', {}, 'The rule', spec);
    var ul = L.el('ul', { class: 'small' }, null, spec);
    L.el('li', {}, 'Divides by 4: a leap year…', ul);
    L.el('li', {}, '…unless it divides by 100: then not a leap year…', ul);
    L.el('li', {}, '…unless it also divides by 400: then it is one after all.', ul);
    L.el('p', { class: 'small muted' }, 'So 2024 is, 2023 is not, 1900 is not, 2000 is.', spec);

    var ex = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', {}, 'Examples', ex);
    var table = L.el('table', { class: 'table table-compact' }, null, ex);
    var head = L.el('tr', {}, null, L.el('thead', {}, null, table));
    ['Call', 'Should return', 'Your function returns'].forEach(function (h) { L.el('th', { scope: 'col' }, h, head); });
    var body = L.el('tbody', {}, null, table);
    var cells = p.examples.map(function (e) {
      var tr = L.el('tr', {}, null, body);
      L.el('code', {}, 'is_leap(' + e[0] + ')', L.el('td', {}, null, tr));
      L.el('td', {}, e[1], tr);
      return L.el('td', { class: 'sim-result' }, '—', tr);
    });
    var runBtn = L.el('button', { type: 'button', class: 'button button-small' }, 'Run the examples', ex);
    var status = L.el('p', { class: 'sim-status', 'aria-live': 'polite' }, 'Write your function in the box under “Your answers”, then run the examples.', ex);

    var codeBox = L.answer('code');
    var outputs = L.answer('outputs');
    if (codeBox) {
      codeBox.setAttribute('spellcheck', 'false');
      codeBox.setAttribute('autocapitalize', 'off');
      if (codeBox.value.trim() === '') { codeBox.value = p.starter; }
      var escaped = false;
      codeBox.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { escaped = true; return; }
        if (e.key === 'Tab' && !e.shiftKey && !escaped) {
          e.preventDefault();
          var at = codeBox.selectionStart;
          codeBox.value = codeBox.value.slice(0, at) + '    ' + codeBox.value.slice(codeBox.selectionEnd);
          codeBox.selectionStart = codeBox.selectionEnd = at + 4;
        }
        escaped = false;
      });
    }

    var frame = L.el('iframe', { src: root.getAttribute('data-runner') || '/labs/runner', sandbox: 'allow-scripts', title: 'Code runner', class: 'sim-runner', tabindex: '-1', 'aria-hidden': 'true' }, null, root);
    var ready = false, pending = {}, nextId = 1, lib = null, libSent = false;
    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) { return; }
      var d = event.data || {};
      if (d.ready) { ready = true; return; }
      var cb = pending[d.id];
      if (cb) { delete pending[d.id]; if (d.needLib) { libSent = false; } cb(d); }
    });
    function loadLib() {
      if (lib) { return lib; }
      lib = Promise.all(p.skulpt.map(function (u) { return fetch(u, { credentials: 'omit' }).then(function (r) { if (!r.ok) { throw new Error('load'); } return r.text(); }); }))
        .then(function (parts) { return parts.join('\n'); }, function () { lib = null; throw new Error('Python could not be loaded. Check your connection and try again.'); });
      return lib;
    }
    loadLib().then(function () { status.textContent = 'Python is ready. Write your function, then run the examples.'; }, function () {});

    function run(years, cb) {
      if (!ready) { cb({ error: 'The code runner is still loading. Try again in a moment.' }); return; }
      loadLib().then(function (source) {
        var id = nextId++;
        pending[id] = cb;
        var code = (codeBox ? codeBox.value : '') + '\n\nfor __y in [' + years.join(', ') + ']:\n'
          + '    try:\n        print("§" + repr(' + p['function'] + '(__y)))\n'
          + '    except Exception as __e:\n        print("§!" + type(__e).__name__)\n';
        var msg = { mode: 'python', id: id, code: code, stdin: [] };
        if (!libSent) { msg.lib = source; libSent = true; }
        frame.contentWindow.postMessage(msg, '*');
        window.setTimeout(function () { if (pending[id]) { delete pending[id]; cb({ error: 'The code runner did not answer. Reload the page and try again.' }); } }, 10000);
      }, function (err) { cb({ error: err.message }); });
    }
    function results(r) {
      if (r.error) { return null; }
      return (r.lines || []).filter(function (l) { return String(l).charAt(0) === '§'; }).map(function (l) { return String(l).slice(1); });
    }

    runBtn.addEventListener('click', function () {
      status.textContent = 'Running…';
      run(p.examples.map(function (e) { return e[0]; }), function (r) {
        var out = results(r);
        if (!out) {
          status.textContent = r.error;
          cells.forEach(function (c) { c.textContent = '—'; c.className = 'sim-result'; });
          return;
        }
        var passed = 0;
        p.examples.forEach(function (e, i) {
          var o = out[i] === undefined ? 'nothing' : out[i];
          var ok = o === e[1];
          passed += ok ? 1 : 0;
          cells[i].textContent = (ok ? '✓ ' : '✕ ') + (o.charAt(0) === '!' ? 'Error: ' + o.slice(1) : o);
          cells[i].className = 'sim-result ' + (ok ? 'is-ok' : 'is-wrong');
        });
        status.textContent = passed === p.examples.length ? 'All examples pass. Now press “Check my answers”.' : passed + ' of ' + p.examples.length + ' examples pass.';
      });
    });

    var submitting = false;
    form.addEventListener('submit', function (e) {
      if (submitting) { return; }
      e.preventDefault();
      var button = form.querySelector('[data-lab-submit]');
      if (button) { button.disabled = true; button.textContent = 'Running your function…'; }
      run(p.tests, function (r) {
        var out = results(r);
        outputs.value = out ? JSON.stringify(out) : '';
        submitting = true;
        form.submit();
      });
    });
  });
})();
