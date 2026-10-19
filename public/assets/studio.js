/*
 * The Code studio (ADR 0027): a small editor with highlighting, and three
 * ways to run code, all sealed off in opaque-origin iframes:
 *   JavaScript  → /labs/runner, a Web Worker (no DOM, no network);
 *   Python      → /labs/runner, Skulpt in a Web Worker (sent as text once);
 *   Web page    → /labs/web-runner, the learner's page with no network.
 * Challenges add tests: the function is called with each case and the result
 * compared here. Nothing the learner writes runs on this page or the server.
 */
(function () {
  'use strict';

  // ---------- Highlighting ----------
  var JS_KW = /^(?:break|case|catch|class|const|continue|default|delete|do|else|export|extends|finally|for|function|if|import|in|instanceof|let|new|of|return|super|switch|this|throw|try|typeof|var|void|while|yield|async|await|static|get|set)$/;
  var JS_LIT = /^(?:true|false|null|undefined|NaN|Infinity)$/;
  var PY_KW = /^(?:and|as|assert|async|await|break|class|continue|def|del|elif|else|except|finally|for|from|global|if|import|in|is|lambda|nonlocal|not|or|pass|raise|return|try|while|with|yield)$/;
  var PY_LIT = /^(?:True|False|None|self)$/;
  var PY_BUILTIN = /^(?:print|input|len|range|int|float|str|list|dict|set|tuple|sum|min|max|abs|round|sorted|enumerate|zip|map|filter|open|type|isinstance|bool|repr)$/;

  function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function span(cls, s) { return '<span class="hl-' + cls + '">' + esc(s) + '</span>'; }

  function code(text, lang) {
    var py = lang === 'python';
    var re = py
      ? /(#[^\n]*)|("""[\s\S]*?(?:"""|$)|'''[\s\S]*?(?:'''|$)|[rbfRBF]{0,2}"(?:\\.|[^"\\\n])*"?|[rbfRBF]{0,2}'(?:\\.|[^'\\\n])*'?)|(\b\d[\d_]*(?:\.\d+)?(?:[eE][+-]?\d+)?\b)|([A-Za-z_][\w]*)|(@[\w.]+)/g
      : /(\/\/[^\n]*|\/\*[\s\S]*?(?:\*\/|$))|(`(?:\\[\s\S]|[^`\\])*`?|"(?:\\.|[^"\\\n])*"?|'(?:\\.|[^'\\\n])*'?)|(\b(?:0[xX][\da-fA-F]+|\d[\d_]*(?:\.\d+)?(?:[eE][+-]?\d+)?)\b)|([A-Za-z_$][\w$]*)/g;
    var out = '', last = 0, m;
    while ((m = re.exec(text)) !== null) {
      out += esc(text.slice(last, m.index));
      last = re.lastIndex;
      if (m[1]) { out += span('comment', m[1]); continue; }
      if (m[2]) { out += span('string', m[2]); continue; }
      if (m[3]) { out += span('number', m[3]); continue; }
      if (m[5]) { out += span('builtin', m[5]); continue; }
      var w = m[4];
      var after = text.charAt(last);
      if ((py ? PY_KW : JS_KW).test(w)) { out += span('keyword', w); } else if ((py ? PY_LIT : JS_LIT).test(w)) { out += span('literal', w); } else if (py && PY_BUILTIN.test(w)) { out += span('builtin', w); } else if (after === '(') { out += span('fn', w); } else { out += esc(w); }
      if (re.lastIndex === m.index) { re.lastIndex++; }
    }
    return out + esc(text.slice(last));
  }

  function css(text) {
    return text.replace(/(\/\*[\s\S]*?(?:\*\/|$))|("[^"\n]*"?|'[^'\n]*'?)|([^{};\n]+)(?=\{)|([\w-]+)(\s*:)|(#[\da-fA-F]{3,8}\b|-?\d*\.?\d+(?:px|rem|em|%|vh|vw|s|ms|deg|fr)?)|([^/"'{};\n#\d-]+|[\s\S])/g, function (all, c, s, sel, prop, colon, num, other) {
      if (c) { return span('comment', c); }
      if (s) { return span('string', s); }
      if (sel) { return span('tag', sel); }
      if (prop) { return span('attr', prop) + esc(colon); }
      if (num) { return span('number', num); }
      return esc(other || all);
    });
  }

  function html(text) {
    var out = '', i = 0, m;
    var re = /(<!--[\s\S]*?(?:-->|$))|(<style\b[^>]*>)([\s\S]*?)(<\/style>|$)|(<script\b[^>]*>)([\s\S]*?)(<\/script>|$)|(<\/?[A-Za-z!][^>]*>?)/g;
    while ((m = re.exec(text)) !== null) {
      out += esc(text.slice(i, m.index));
      i = re.lastIndex;
      if (m[1]) { out += span('comment', m[1]); } else if (m[2]) { out += tag(m[2]) + css(m[3]) + tag(m[4]); } else if (m[5]) { out += tag(m[5]) + code(m[6], 'javascript') + tag(m[7]); } else { out += tag(m[8]); }
      if (re.lastIndex === m.index) { re.lastIndex++; }
    }
    return out + esc(text.slice(i));
  }

  function tag(t) {
    if (!t) { return ''; }
    return t.replace(/^(<\/?)([\w!-]+)|([\w-:]+)(=)("[^"]*"?|'[^']*'?)|([\s\S])/g, function (all, open, name, attr, eq, val, ch) {
      if (name) { return esc(open) + span('tag', name); }
      if (attr) { return span('attr', attr) + esc(eq) + span('string', val); }
      return esc(ch);
    });
  }

  function highlight(text, lang) { return lang === 'web' ? html(text) : code(text, lang); }

  // ---------- The editor ----------
  function Editor(root, lang, onRun) {
    var ta = root.querySelector('[data-pg-code]');
    var hl = root.querySelector('[data-studio-hl]');
    var gutter = root.querySelector('[data-pg-gutter]');
    var pos = root.querySelector('[data-studio-pos]');
    var form = root.querySelector('[data-studio-form]') || root.closest('form');
    var unit = lang === 'python' ? '    ' : '  ';
    var comment = lang === 'python' ? '# ' : (lang === 'web' ? null : '// ');
    var self = this;
    this.ta = ta;
    this.dirty = false;

    function sync() {
      hl.innerHTML = highlight(ta.value, lang) + '\n';
      var n = ta.value.split('\n').length, t = '';
      for (var i = 1; i <= n; i++) { t += i + '\n'; }
      gutter.textContent = t;
      scroll();
      caret();
    }
    function scroll() {
      hl.style.transform = 'translate(' + (-ta.scrollLeft) + 'px,' + (-ta.scrollTop) + 'px)';
      gutter.scrollTop = ta.scrollTop;
    }
    function caret() {
      if (!pos) { return; }
      var before = ta.value.slice(0, ta.selectionStart).split('\n');
      pos.textContent = 'Ln ' + before.length + ', Col ' + (before[before.length - 1].length + 1);
    }
    function insert(text, from, to) {
      ta.focus();
      ta.setSelectionRange(from, to);
      var ok = false;
      try { ok = document.execCommand('insertText', false, text); } catch (e) { ok = false; }
      if (!ok) { ta.setRangeText(text, from, to, 'end'); }
    }
    function lineStart(i) { return ta.value.lastIndexOf('\n', i - 1) + 1; }

    // Indents or outdents every selected line, keeping the selection.
    function indent(out) {
      var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
      var a = lineStart(s);
      var b = e > s && v.charAt(e - 1) === '\n' ? e - 1 : e;
      var z = v.indexOf('\n', b); if (z === -1) { z = v.length; }
      var lines = v.slice(a, z).split('\n');
      var first = 0, total = 0;
      var changed = lines.map(function (l, i) {
        if (!out) { if (i === 0) { first = unit.length; } total += unit.length; return unit + l; }
        var lead = (/^ */.exec(l) || [''])[0].length;
        var cut = Math.min(lead, unit.length);
        if (i === 0) { first = -cut; }
        total -= cut;
        return l.slice(cut);
      }).join('\n');
      insert(changed, a, z);
      ta.setSelectionRange(Math.max(a, s + first), Math.max(a, e + total));
    }

    function toggleComment() {
      var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
      var a = lineStart(s);
      var z = v.indexOf('\n', e > s && v.charAt(e - 1) === '\n' ? e - 1 : e); if (z === -1) { z = v.length; }
      var lines = v.slice(a, z).split('\n');
      var open = lang === 'web' ? '<!-- ' : comment, close = lang === 'web' ? ' -->' : '';
      var all = lines.every(function (l) { return l.trim() === '' || l.trim().indexOf(open.trim()) === 0; });
      var changed = lines.map(function (l) {
        if (l.trim() === '') { return l; }
        if (all) { return l.replace(open, '').replace(open.trim(), '').replace(close, ''); }
        var lead = (/^\s*/.exec(l) || [''])[0];
        return lead + open + l.slice(lead.length) + close;
      }).join('\n');
      insert(changed, a, z);
      ta.setSelectionRange(a, a + changed.length);
    }

    var PAIRS = { '(': ')', '[': ']', '{': '}', '"': '"', "'": "'", '`': '`' };
    ta.addEventListener('keydown', function (e) {
      var s = ta.selectionStart, en = ta.selectionEnd, v = ta.value;
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); onRun(); return; }
      if ((e.key === 's' || e.key === 'S') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); if (form && form.requestSubmit) { form.requestSubmit(); } else if (form) { form.submit(); } return; }
      if (e.key === '/' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); toggleComment(); return; }
      if (e.key === 'Escape') { ta.dataset.escaped = '1'; return; }
      if (e.key === 'Tab' && !ta.dataset.escaped) {
        e.preventDefault();
        if (e.shiftKey || s !== en) { indent(e.shiftKey); } else { insert(unit, s, en); }
        return;
      }
      delete ta.dataset.escaped;
      if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
        // Keep the indentation; go one deeper after { ( [ or a Python colon.
        var a = lineStart(s);
        var line = v.slice(a, s);
        var lead = (/^[ \t]*/.exec(line) || [''])[0];
        var prev = line.trimEnd().slice(-1);
        var deeper = (lang === 'python' && prev === ':') || (lang !== 'python' && /[{([]/.test(prev)) || (lang === 'web' && /<(?!\/)[^>]*>$/.test(line.trimEnd()) && !/<(br|hr|img|input|meta|link)\b/i.test(line));
        e.preventDefault();
        var next = v.charAt(en);
        if (deeper && /[})\]]/.test(next)) {
          insert('\n' + lead + unit + '\n' + lead, s, en);
          ta.setSelectionRange(s + 1 + lead.length + unit.length, s + 1 + lead.length + unit.length);
        } else {
          insert('\n' + lead + (deeper ? unit : ''), s, en);
        }
        return;
      }
      if (e.key === 'Backspace' && s === en && s > 0) {
        var ch = v.charAt(s - 1);
        if (PAIRS[ch] && v.charAt(s) === PAIRS[ch]) { e.preventDefault(); insert('', s - 1, s + 1); return; }
        var ls = lineStart(s);
        if (s > ls && /^ +$/.test(v.slice(ls, s)) && (s - ls) % unit.length === 0) { e.preventDefault(); insert('', s - unit.length, s); return; }
      }
      if (e.ctrlKey || e.metaKey || e.altKey) { return; }
      if (/[)\]}"'`]/.test(e.key) && s === en && v.charAt(s) === e.key) { e.preventDefault(); ta.setSelectionRange(s + 1, s + 1); return; }
      if (PAIRS[e.key]) {
        var quote = /["'`]/.test(e.key);
        var prevCh = v.charAt(s - 1);
        if (quote && (/\w/.test(prevCh) || (e.key === '`' && lang === 'python'))) { return; }
        if (s !== en) { e.preventDefault(); insert(e.key + v.slice(s, en) + PAIRS[e.key], s, en); ta.setSelectionRange(s + 1, en + 1); return; }
        if (/[\w]/.test(v.charAt(s))) { return; }
        e.preventDefault();
        insert(e.key + PAIRS[e.key], s, en);
        ta.setSelectionRange(s + 1, s + 1);
      }
    });
    ta.addEventListener('input', function () { self.dirty = true; sync(); });
    ta.addEventListener('scroll', scroll);
    ta.addEventListener('click', caret);
    ta.addEventListener('keyup', caret);
    if (form) { form.addEventListener('submit', function () { self.dirty = false; }); }
    this.set = function (text) { ta.value = text; self.dirty = false; sync(); };
    sync();
  }

  // ---------- Running ----------
  var skulpt = null;
  function loadPython(say) {
    if (skulpt) { return skulpt; }
    say('Loading Python (about 1 MB, the first time only)…');
    var get = function (path) { return fetch(path, { credentials: 'omit' }).then(function (r) { if (!r.ok) { throw new Error('load'); } return r.text(); }); };
    skulpt = Promise.all([get('/assets/vendor/skulpt/skulpt.min.js'), get('/assets/vendor/skulpt/skulpt-stdlib.js')])
      .then(function (parts) { return parts.join('\n'); })
      .catch(function () { skulpt = null; throw new Error('Python could not be loaded. Check your connection and press Run again.'); });
    return skulpt;
  }

  function Runner(frame, say) {
    var ready = false, queue = [], waiting = {}, seq = 0, libSent = false;
    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) { return; }
      var d = event.data || {};
      if (d.ready) {
        if (!ready) { ready = true; queue.splice(0).forEach(function (f) { f(); }); }
        return;
      }
      var cb = waiting[d.id];
      if (!cb) { return; }
      delete waiting[d.id];
      if (d.needLib) { libSent = false; }
      cb(d);
    });
    function whenReady(f) {
      if (ready) { f(); return; }
      queue.push(f);
      try { frame.contentWindow.postMessage({ mode: 'ping' }, '*'); } catch (e) { /* loading */ }
    }
    this.run = function (lang, codeText, stdin, done) {
      var id = 'r' + (++seq);
      var send = function (lib) {
        whenReady(function () {
          waiting[id] = done;
          var msg = { mode: lang === 'python' ? 'python' : 'script', id: id, code: codeText, stdin: stdin };
          if (lib && !libSent) { msg.lib = lib; libSent = true; }
          frame.contentWindow.postMessage(msg, '*');
          setTimeout(function () {
            if (waiting[id]) { delete waiting[id]; done({ error: 'The runner did not answer. Reload the page and try again.', lines: [] }); }
          }, 9000);
        });
      };
      if (lang === 'python') {
        loadPython(say).then(send, function (err) { done({ error: err.message, lines: [] }); });
      } else {
        send(null);
      }
    };
  }

  function WebPreview(frame, onLog) {
    var pending = null, n = 0;
    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) { return; }
      var d = event.data || {};
      if (d.webReady && pending !== null) { frame.contentWindow.postMessage({ html: pending }, '*'); pending = null; return; }
      if (d.webLog) { onLog(String(d.kind || 'log'), String(d.text || '')); }
    });
    this.show = function (page) {
      pending = page;
      frame.src = '/labs/web-runner?r=' + (++n);
    };
  }

  // ---------- Pages ----------
  Array.prototype.forEach.call(document.querySelectorAll('[data-studio]'), function (root) {
    var lang = root.getAttribute('data-language') || 'javascript';
    var out = root.querySelector('[data-pg-output]');
    var status = root.querySelector('[data-pg-status]');
    var runBtn = root.querySelector('[data-pg-run]');
    var clearBtn = root.querySelector('[data-pg-clear]');
    var frame = root.querySelector('[data-pg-runner]');
    var preview = root.querySelector('[data-studio-preview]');
    var stdinBox = root.querySelector('[data-pg-stdin]');
    var example = document.querySelector('[data-pg-example]');
    var say = function (t) { if (status) { status.textContent = t; } };
    var line = function (text, cls) {
      var s = document.createElement('span');
      s.className = cls || 'pg-line';
      s.textContent = text;
      out.appendChild(s);
      out.scrollTop = out.scrollHeight;
    };
    var runner = frame ? new Runner(frame, say) : null;
    var web = preview ? new WebPreview(preview, function (kind, text) {
      if (out.querySelector('.pg-hint')) { out.textContent = ''; }
      line(text, kind === 'error' ? 'pg-error' : 'pg-line');
    }) : null;

    var editor;
    var run = function () {
      var text = editor.ta.value;
      if (web) {
        out.textContent = '';
        say('Page updated ' + new Date().toLocaleTimeString());
        web.show(text);
        return;
      }
      runBtn.disabled = true;
      say(lang === 'python' ? 'Running Python…' : 'Running…');
      var stdin = stdinBox && stdinBox.value !== '' ? stdinBox.value.split('\n') : [];
      runner.run(lang, text, stdin, function (d) {
        runBtn.disabled = false;
        out.textContent = '';
        (Array.isArray(d.lines) ? d.lines : []).forEach(function (l) { if (String(l).charAt(0) !== '§') { line(String(l)); } });
        if (d.cut) { line('… more lines were printed than can be shown (300 at most).', 'pg-note'); }
        if (typeof d.error === 'string' && d.error) { line(d.error, 'pg-error'); }
        if (!(d.lines && d.lines.length) && !d.error) { line('Your code ran, but printed nothing. Use ' + (lang === 'python' ? 'print(…)' : 'console.log(…)') + ' to show a value.', 'pg-note'); }
        say(d.error ? 'Stopped with an error' : 'Ran in ' + (d.ms || 0) + ' ms');
      });
    };
    editor = new Editor(root, lang, run);
    runBtn.hidden = false;
    runBtn.addEventListener('click', run);
    if (clearBtn) { clearBtn.hidden = false; clearBtn.addEventListener('click', function () { out.textContent = ''; say(''); }); }
    if (lang === 'python') { setTimeout(function () { loadPython(function () {}).then(function () { say('Python is ready'); }, function () {}); }, 400); }
    if (web) { setTimeout(run, 300); }

    if (example) {
      example.hidden = false;
      example.addEventListener('change', function () {
        if (example.value === '') { return; }
        editor.set(example.value);
        example.value = '';
        editor.ta.focus();
        if (web) { run(); }
      });
    }

    // Leaving for another language with unsaved changes: say so once.
    var note = document.querySelector('[data-studio-leave-note]');
    var armed = false;
    Array.prototype.forEach.call(document.querySelectorAll('[data-studio-leave]'), function (a) {
      a.addEventListener('click', function (e) {
        if (!editor.dirty || armed || a.getAttribute('aria-current') === 'page') { return; }
        e.preventDefault();
        armed = true;
        if (note) { note.hidden = false; }
      });
    });

    // Challenges: run each case, compare, and offer to save when all pass.
    var casesAttr = root.getAttribute('data-cases');
    if (!casesAttr) { return; }
    var cases = JSON.parse(casesAttr);
    var fn = root.getAttribute('data-fn');
    var testBtn = root.querySelector('[data-ch-test]');
    var submitBtn = root.querySelector('[data-ch-submit]');
    var summary = root.querySelector('[data-ch-summary]');
    var rows = root.querySelectorAll('[data-ch-row]');
    testBtn.hidden = false;
    var close = function (a, b) { return Math.abs(a - b) <= 1e-6 * Math.max(1, Math.abs(b)); };
    testBtn.addEventListener('click', function () {
      var mark = '§';
      var harness;
      if (lang === 'python') {
        harness = editor.ta.value + '\n\n__cases = [' + cases.map(function (c) { return '(' + c.py_args + (c.args.length === 1 ? ',)' : ')'); }).join(', ') + ']\n'
          + 'for __i, __a in enumerate(__cases):\n'
          + '    try:\n'
          + '        print("' + mark + '" + str(__i) + "' + mark + '" + repr(' + fn + '(*__a)))\n'
          + '    except Exception as __e:\n'
          + '        print("' + mark + '" + str(__i) + "' + mark + '!" + type(__e).__name__ + ": " + str(__e))\n';
      } else {
        harness = editor.ta.value + '\n;(function () {\n'
          + '  var __f = (typeof ' + fn + ' === "function") ? ' + fn + ' : null;\n'
          + '  var __c = ' + JSON.stringify(cases.map(function (c) { return c.args; })) + ';\n'
          + '  for (var __i = 0; __i < __c.length; __i++) {\n'
          + '    if (!__f) { console.log("' + mark + '" + __i + "' + mark + '!There is no function called ' + fn + '"); continue; }\n'
          + '    try { var __r = __f.apply(null, __c[__i]); console.log("' + mark + '" + __i + "' + mark + '" + (__r === undefined ? "undefined" : JSON.stringify(__r))); }\n'
          + '    catch (__e) { console.log("' + mark + '" + __i + "' + mark + '!" + (__e && __e.message ? __e.message : __e)); }\n'
          + '  }\n'
          + '})();\n';
      }
      testBtn.disabled = true;
      summary.textContent = 'Testing…';
      runner.run(lang, harness, [], function (d) {
        testBtn.disabled = false;
        out.textContent = '';
        var got = {};
        (d.lines || []).forEach(function (l) {
          var m = /^§(\d+)§([\s\S]*)$/.exec(String(l));
          if (m) { got[m[1]] = m[2]; } else { line(String(l)); }
        });
        if (d.error) { line(d.error, 'pg-error'); }
        var pass = 0;
        cases.forEach(function (c, i) {
          var cell = rows[i] && rows[i].querySelector('[data-ch-got]');
          var g = got[String(i)];
          var ok = false, shown;
          if (g === undefined) { shown = d.error ? 'error' : 'no result'; } else if (g.charAt(0) === '!') { shown = 'Error: ' + g.slice(1); } else {
            shown = g;
            if (typeof c.expected === 'number') {
              var num = parseFloat(g);
              ok = !isNaN(num) && /^-?[\d.]+(e[+-]?\d+)?$/i.test(g.trim()) && close(num, c.expected);
            } else {
              ok = lang === 'python' ? g === c.py_expected : g === JSON.stringify(c.expected);
            }
          }
          if (ok) { pass++; }
          if (cell) {
            cell.textContent = '';
            var mk = document.createElement('span');
            mk.className = 'ch-result ' + (ok ? 'is-pass' : 'is-fail');
            mk.textContent = (ok ? '✓ ' : '✗ ') + shown;
            cell.appendChild(mk);
          }
          if (rows[i]) { rows[i].classList.toggle('is-pass', ok); rows[i].classList.toggle('is-fail', !ok); }
        });
        summary.textContent = pass + ' of ' + cases.length + ' tests pass' + (pass === cases.length ? '. Well done!' : '.');
        submitBtn.hidden = pass !== cases.length;
      });
    });
  });
})();
