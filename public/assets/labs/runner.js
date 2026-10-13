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

  var url = null;
  try { url = URL.createObjectURL(new Blob([WORKER], { type: 'text/javascript' })); } catch (e) { url = null; }

  function reply(id, data) {
    data.id = id;
    window.parent.postMessage(data, '*');
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) { return; }
    var d = event.data || {};
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
