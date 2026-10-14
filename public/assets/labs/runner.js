/*
 * The code runner, served inline (with a nonce) by /labs/runner inside an
 * iframe with sandbox="allow-scripts". That gives it a unique opaque origin:
 * no cookies, no storage, no access to the page around it. Its CSP blocks
 * every network request. Learner code runs in a fresh Web Worker (no DOM at
 * all) that is killed if it runs too long. Only plain strings go back.
 */
(function () {
  'use strict';

  var WORKER = [
    'self.onmessage = function (e) {',
    '  var d = e.data, out = [];',
    '  var f;',
    '  try {',
    '    f = (new Function(String(d.code) + "\\n;return (typeof " + d.fn + " === \\"function\\") ? " + d.fn + " : null;"))();',
    '  } catch (err) { self.postMessage({ error: "Your code has an error: " + (err && err.message ? err.message : err) }); return; }',
    '  if (!f) { self.postMessage({ error: "There is no function called " + d.fn + " in your code." }); return; }',
    '  for (var i = 0; i < d.inputs.length; i++) {',
    '    try { var r = f(d.inputs[i]); out.push(r === undefined ? "undefined" : String(r).slice(0, 60)); }',
    '    catch (err) { out.push("Error: " + String(err && err.message ? err.message : err).slice(0, 60)); }',
    '  }',
    '  self.postMessage({ outputs: out });',
    '};'
  ].join('\n');

  // Playground mode: run a whole script and collect what it prints with console.log.
  // Lines are capped in number and length; timers and promises get a short moment to finish.
  var SCRIPT = [
    'self.onmessage = function (e) {',
    '  var lines = [], cut = false;',
    '  function show(v) {',
    '    if (typeof v === "string") { return v; }',
    '    if (v === undefined) { return "undefined"; }',
    '    if (typeof v === "function") { return "[function " + (v.name || "anonymous") + "]"; }',
    '    try { var j = JSON.stringify(v); return j === undefined ? String(v) : j; } catch (err) { return String(v); }',
    '  }',
    '  function log() {',
    '    if (lines.length >= 300) { cut = true; return; }',
    '    lines.push(Array.prototype.map.call(arguments, show).join(" ").slice(0, 1000));',
    '  }',
    '  var out = { log: log, info: log, warn: log, error: log, debug: log, table: log };',
    '  var failed = null;',
    '  function fail(err) { failed = (err && err.name ? err.name + ": " : "") + (err && err.message ? err.message : String(err)); }',
    '  self.addEventListener("unhandledrejection", function (ev) { fail(ev.reason); });',
    '  try { (new Function("console", String(e.data.code)))(out); } catch (err) { fail(err); }',
    '  setTimeout(function () { self.postMessage({ lines: lines, error: failed, cut: cut }); }, Math.min(Math.max(e.data.settle | 0, 0), 1500));',
    '};'
  ].join('\n');

  var url = null;
  var scriptUrl = null;
  try { url = URL.createObjectURL(new Blob([WORKER], { type: 'text/javascript' })); } catch (e) { url = null; }
  try { scriptUrl = URL.createObjectURL(new Blob([SCRIPT], { type: 'text/javascript' })); } catch (e) { scriptUrl = null; }

  function runScript(d) {
    if (!scriptUrl) { reply(d.id, { error: 'This browser cannot run code safely. Try a recent version of Chrome, Firefox or Edge.' }); return; }
    var worker;
    try { worker = new Worker(scriptUrl); } catch (e) { reply(d.id, { error: 'This browser cannot run code safely. Try a recent version of Chrome, Firefox or Edge.' }); return; }
    var done = false;
    var started = Date.now();
    var timer = setTimeout(function () {
      if (done) { return; }
      done = true;
      worker.terminate();
      reply(d.id, { error: 'Your code ran for more than 4 seconds and was stopped. Check for a loop that never ends.', lines: [] });
    }, 4000);
    worker.onmessage = function (e) {
      if (done) { return; }
      done = true;
      clearTimeout(timer);
      worker.terminate();
      var r = e.data || {};
      reply(d.id, {
        lines: Array.isArray(r.lines) ? r.lines.map(function (l) { return String(l).slice(0, 1000); }) : [],
        error: typeof r.error === 'string' ? r.error.slice(0, 500) : null,
        cut: r.cut === true,
        ms: Date.now() - started
      });
    };
    worker.onerror = function (e) {
      if (done) { return; }
      done = true;
      clearTimeout(timer);
      worker.terminate();
      reply(d.id, { error: (e && e.message ? e.message : 'Your code has an error.'), lines: [] });
      if (e && e.preventDefault) { e.preventDefault(); }
    };
    worker.postMessage({ code: d.code, settle: 300 });
  }

  function reply(id, data) {
    data.id = id;
    window.parent.postMessage(data, '*');
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) { return; }
    var d = event.data || {};
    if (d.mode === 'ping') { window.parent.postMessage({ ready: true }, '*'); return; }
    if (d.mode === 'script') {
      if (typeof d.code === 'string' && d.code.length <= 20000) { runScript(d); }
      return;
    }
    if (typeof d.code !== 'string' || !/^[A-Za-z_$][\w$]{0,40}$/.test(String(d.fn)) || !Array.isArray(d.inputs)) { return; }
    if (!url) { reply(d.id, { error: 'This browser cannot run code safely. Try a recent version of Chrome, Firefox or Edge.' }); return; }
    var worker;
    try { worker = new Worker(url); } catch (e) { reply(d.id, { error: 'This browser cannot run code safely. Try a recent version of Chrome, Firefox or Edge.' }); return; }
    var done = false;
    var timer = setTimeout(function () {
      if (done) { return; }
      done = true;
      worker.terminate();
      reply(d.id, { error: 'Your code took too long and was stopped. Check for a loop that never ends.' });
    }, Math.min(Math.max(parseInt(d.timeout, 10) || 2000, 200), 5000));
    worker.onmessage = function (e) {
      if (done) { return; }
      done = true;
      clearTimeout(timer);
      worker.terminate();
      var r = e.data || {};
      reply(d.id, {
        error: typeof r.error === 'string' ? r.error.slice(0, 300) : null,
        outputs: Array.isArray(r.outputs) ? r.outputs.map(function (o) { return String(o).slice(0, 60); }) : null
      });
    };
    worker.onerror = function (e) {
      if (done) { return; }
      done = true;
      clearTimeout(timer);
      worker.terminate();
      reply(d.id, { error: 'Your code has an error: ' + (e && e.message ? e.message : 'unknown') });
      if (e && e.preventDefault) { e.preventDefault(); }
    };
    worker.postMessage({ code: d.code, fn: d.fn, inputs: d.inputs });
  });

  window.parent.postMessage({ ready: true }, '*');
})();
