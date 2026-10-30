/*
 * The CBT exam room (ADR 0042). Loads the paper as JSON, shows one question at
 * a time with subject tabs and a numbered palette, saves every answer as it is
 * chosen, and keeps the server's time.
 *
 * Saving: changes collect in `pending` and go to the server in one request a
 * moment after the last change (and at once when moving on). Each request has
 * a higher sequence number than the last, so a slow old request can never
 * overwrite a newer one. Unsent changes are also kept in this browser
 * (localStorage), so a reload or a power cut loses nothing that was chosen.
 *
 * Time: the server says how many seconds are left; the countdown runs from
 * the moment that answer arrived (the device's clock is never trusted), and
 * every save brings the server's figure again (a teacher may add time).
 *
 * Keys: A to E choose, N next, P previous, F flag, S submit.
 * No inline script or style (CSP).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-cbt]');
  if (!root) { return; }

  var LETTERS = ['A', 'B', 'C', 'D', 'E'];
  var attemptId = root.getAttribute('data-attempt');
  var urls = { paper: root.getAttribute('data-paper'), save: root.getAttribute('data-save'), submit: root.getAttribute('data-submit'), result: root.getAttribute('data-result') };
  var csrf = root.getAttribute('data-csrf') || '';
  var STORE = 'dml.cbt.' + attemptId;

  var $ = function (sel) { return root.querySelector(sel); };
  var tabsEl = $('[data-cbt-tabs]');
  var qnumEl = $('[data-cbt-qnum]');
  var passageEl = $('[data-cbt-passage]');
  var questionEl = $('[data-cbt-question]');
  var paletteEl = $('[data-cbt-palette]');
  var paletteTitle = $('[data-cbt-palette-title]');
  var progressEl = $('[data-cbt-progress]');
  var timeEl = $('[data-cbt-time]');
  var timerBox = $('[data-cbt-timer]');
  var saveEl = $('[data-cbt-save]');
  var alertEl = $('[data-cbt-alert]');
  var flagBtn = $('[data-cbt-flag]');
  var flagText = $('[data-cbt-flag-text]');
  var prevBtn = $('[data-cbt-prev]');
  var nextBtn = $('[data-cbt-next]');
  var submitOpen = $('[data-cbt-submit-open]');
  var dialog = $('[data-cbt-dialog]');
  var summaryEl = $('[data-cbt-summary]');
  var paletteToggle = $('[data-cbt-palette-toggle]');
  var paletteBox = $('[data-cbt-palette-box]');

  var paper = null;          // { sections: [{label, questions: [{id, n, prompt, image, passage, options, answer, flag}]}], passages: {} }
  var flat = [];             // every question in paper order: {s, i, q}
  var at = 0;                // index into flat
  var pending = {};          // question id -> shown option index, or null to clear
  var flagsDirty = false;
  var seq = 0;
  // Leaving the page (another tab or app, a minimised browser) is counted for the teacher (ADR 0045).
  var away = { count: 0, seconds: 0 }, awaySince = null, watched = false, toldAway = false;
  var saving = false;
  var saveTimer = null;
  var retryTimer = null;
  var deadline = 0;          // performance.now() value when time runs out
  var untimed = false;
  var finished = false;
  var warned = {};

  function el(tag, attrs, text, parent) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== null && text !== undefined) { e.textContent = text; }
    if (parent) { parent.appendChild(e); }
    return e;
  }
  function say(text) { alertEl.textContent = ''; window.setTimeout(function () { alertEl.textContent = text; }, 50); }
  function status(text, tone) { saveEl.textContent = text; saveEl.className = 'cbt-save' + (tone ? ' is-' + tone : ''); }

  function store() {
    try {
      var flags = [];
      flat.forEach(function (f) { if (f.q.flag) { flags.push(f.q.id); } });
      if (Object.keys(pending).length || flagsDirty) {
        window.localStorage.setItem(STORE, JSON.stringify({ pending: pending, flags: flags, seq: seq }));
      } else {
        window.localStorage.removeItem(STORE);
      }
    } catch (e) { /* private mode: the server copy is all there is */ }
  }
  function restore() {
    try {
      var raw = window.localStorage.getItem(STORE);
      if (!raw) { return; }
      var kept = JSON.parse(raw);
      if (kept && kept.pending) {
        Object.keys(kept.pending).forEach(function (id) {
          var f = byId(parseInt(id, 10));
          if (f) { f.q.answer = kept.pending[id]; pending[id] = kept.pending[id]; }
        });
      }
      if (kept && Array.isArray(kept.flags)) {
        flat.forEach(function (f) { f.q.flag = kept.flags.indexOf(f.q.id) >= 0; });
        flagsDirty = true;
      }
      if (kept && typeof kept.seq === 'number' && kept.seq > seq) { seq = kept.seq; }
    } catch (e) { /* ignore a damaged copy */ }
  }
  function byId(id) {
    for (var i = 0; i < flat.length; i++) { if (flat[i].q.id === id) { return flat[i]; } }
    return null;
  }

  /* ---------- time ---------- */
  function setTime(seconds) {
    if (untimed) { return; }
    var target = performance.now() + seconds * 1000;
    if (!deadline || Math.abs(target - deadline) > 2500) { deadline = target; }
  }
  function tick() {
    if (finished) { return; }
    if (untimed) { timeEl.textContent = 'No time limit'; return; }
    var left = Math.max(0, Math.round((deadline - performance.now()) / 1000));
    var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
    timeEl.textContent = (h > 0 ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
    timerBox.classList.toggle('is-low', left <= 300);
    timerBox.classList.toggle('is-last', left <= 60);
    [[600, '10 minutes left.'], [300, '5 minutes left.'], [60, 'One minute left. Your answers are saved; the exam submits itself when the time is up.']].forEach(function (w) {
      if (left <= w[0] && left > w[0] - 30 && !warned[w[0]]) { warned[w[0]] = true; say(w[1]); }
    });
    if (left <= 0) { submit('time'); }
  }

  /* ---------- drawing ---------- */
  function sectionStats(si) {
    var qs = paper.sections[si].questions, done = 0;
    qs.forEach(function (q) { if (q.answer !== null && q.answer !== undefined) { done++; } });
    return { done: done, total: qs.length };
  }
  function drawTabs() {
    tabsEl.textContent = '';
    if (paper.sections.length < 2) { tabsEl.hidden = true; return; }
    paper.sections.forEach(function (s, si) {
      var st = sectionStats(si);
      var b = el('button', { type: 'button', class: 'cbt-tab', 'aria-current': flat[at].s === si ? 'true' : 'false' }, null, tabsEl);
      el('span', { class: 'cbt-tab-name' }, s.label, b);
      el('span', { class: 'cbt-tab-count' }, st.done + '/' + st.total, b);
      b.addEventListener('click', function () {
        for (var i = 0; i < flat.length; i++) { if (flat[i].s === si) { go(i); break; } }
      });
    });
  }
  function drawPalette() {
    var si = flat[at].s;
    paletteEl.textContent = '';
    paletteTitle.textContent = paper.sections.length > 1 ? paper.sections[si].label : 'Questions';
    var answered = 0;
    flat.forEach(function (f) { if (f.q.answer !== null && f.q.answer !== undefined) { answered++; } });
    progressEl.textContent = 'Questions: ' + answered + ' of ' + flat.length + ' answered';
    flat.forEach(function (f, i) {
      if (f.s !== si) { return; }
      var has = f.q.answer !== null && f.q.answer !== undefined;
      var cls = 'cbt-num' + (has ? ' is-answered' : '') + (f.q.flag ? ' is-flagged' : '') + (i === at ? ' is-current' : '');
      var label = 'Question ' + f.q.n + (has ? ', answered ' + LETTERS[f.q.answer] : ', not answered') + (f.q.flag ? ', flagged' : '');
      var b = el('button', { type: 'button', class: cls, 'aria-label': label }, String(f.q.n), paletteEl);
      if (i === at) { b.setAttribute('aria-current', 'true'); }
      b.addEventListener('click', function () { go(i); closePalette(); });
    });
  }
  function drawQuestion() {
    var f = flat[at], q = f.q, sec = paper.sections[f.s];
    qnumEl.textContent = 'Question ' + q.n + ' of ' + flat.length + (paper.sections.length > 1 ? ', ' + sec.label : '');
    var p = q.passage !== null && q.passage !== undefined ? paper.passages[String(q.passage)] : null;
    passageEl.hidden = !p;
    passageEl.textContent = '';
    if (p) {
      el('h2', { class: 'cbt-passage-title' }, p.title, passageEl);
      var body = el('div', { class: 'prose prose-small' }, null, passageEl);
      body.innerHTML = p.body; // rendered on the server by the lesson Markdown renderer
    }
    questionEl.textContent = '';
    var fs = el('fieldset', { class: 'cbt-options' }, null, questionEl);
    var legend = el('legend', { class: 'cbt-prompt prose' }, null, fs);
    legend.innerHTML = q.prompt; // rendered on the server
    if (q.image) {
      var fig = el('figure', { class: 'qpic cbt-pic' }, null, fs);
      el('img', { src: q.image.src, alt: q.image.alt, loading: 'eager', decoding: 'async' }, null, fig);
    }
    q.options.forEach(function (text, i) {
      var id = 'opt-' + q.id + '-' + i;
      var lab = el('label', { class: 'cbt-option' + (q.answer === i ? ' is-chosen' : ''), for: id }, null, fs);
      var input = el('input', { type: 'radio', name: 'q' + q.id, id: id, value: String(i) }, null, lab);
      input.checked = q.answer === i;
      el('span', { class: 'cbt-letter', 'aria-hidden': 'true' }, LETTERS[i], lab);
      el('span', { class: 'cbt-option-text' }, text, lab);
      input.addEventListener('change', function () { choose(i); });
    });
    if (q.answer !== null && q.answer !== undefined) {
      var clear = el('button', { type: 'button', class: 'linklike small cbt-clear' }, 'Clear my answer', questionEl);
      clear.addEventListener('click', function () { choose(null); });
    }
    flagBtn.setAttribute('aria-pressed', q.flag ? 'true' : 'false');
    flagText.textContent = q.flag ? 'Flagged' : 'Flag';
    prevBtn.disabled = at === 0;
    nextBtn.disabled = at === flat.length - 1;
  }
  function draw() { drawTabs(); drawQuestion(); drawPalette(); }

  function go(i) {
    if (i < 0 || i >= flat.length) { return; }
    at = i;
    draw();
    flushSoon(0);
    var first = questionEl.querySelector('input:checked') || questionEl.querySelector('input');
    if (first && document.activeElement && document.activeElement.closest && document.activeElement.closest('.cbt-options')) { first.focus(); }
  }
  function choose(i) {
    var q = flat[at].q;
    q.answer = i;
    pending[String(q.id)] = i;
    store();
    drawQuestion();
    drawTabs();
    drawPalette();
    var checked = questionEl.querySelector('input:checked');
    if (checked) { checked.focus(); }
    flushSoon(800);
  }
  function toggleFlag() {
    var q = flat[at].q;
    q.flag = !q.flag;
    flagsDirty = true;
    store();
    drawQuestion();
    drawPalette();
    say(q.flag ? 'Question ' + q.n + ' flagged.' : 'Flag removed.');
    flushSoon(800);
  }

  /* ---------- saving ---------- */
  function flags() {
    var out = [];
    flat.forEach(function (f) { if (f.q.flag) { out.push(f.q.id); } });
    return out;
  }
  function flushSoon(ms) {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(flush, ms);
  }
  function post(url, body) {
    return window.fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    });
  }
  function flush(heartbeat) {
    if (finished || saving) { return; }
    var changes = {};
    Object.keys(pending).forEach(function (k) { changes[k] = pending[k]; });
    if (!Object.keys(changes).length && !flagsDirty && !heartbeat) { return; }
    var sending = Object.keys(changes).length > 0 || flagsDirty;
    var mySeq = sending ? seq + 1 : seq;
    saving = true;
    if (sending) { status('Saving…', ''); }
    post(urls.save, { seq: mySeq, answers: changes, flags: flags(), away: away }).then(function (r) {
      return r.json().then(function (data) { return { http: r.status, data: data }; });
    }).then(function (res) {
      saving = false;
      var data = res.data || {};
      if (data.submitted) { finished = true; status('This exam has been submitted.', ''); window.location.href = urls.result; return; }
      if (!data.ok) { throw new Error('save'); }
      seq = Math.max(seq, data.seq || mySeq);
      Object.keys(changes).forEach(function (k) { if (pending[k] === changes[k]) { delete pending[k]; } });
      if (sending) { flagsDirty = false; }
      store();
      if (typeof data.seconds_left === 'number') { setTime(data.seconds_left); }
      if (sending || saveEl.classList.contains('is-warn')) { status('All answers saved', 'ok'); }
      window.clearTimeout(retryTimer);
      if (Object.keys(pending).length || flagsDirty) { flushSoon(300); }
    }).catch(function () {
      saving = false;
      status('Offline: your answers are kept on this device and will be sent when the connection is back', 'warn');
      window.clearTimeout(retryTimer);
      retryTimer = window.setTimeout(function () { flush(true); }, 5000);
    });
  }

  /* ---------- submitting ---------- */
  function openDialog() {
    if (finished) { return; }
    summaryEl.textContent = '';
    var list = el('ul', { class: 'cbt-summary' }, null, summaryEl);
    var blank = 0, flagged = 0;
    paper.sections.forEach(function (s, si) {
      var st = sectionStats(si);
      blank += st.total - st.done;
      el('li', null, s.label + ': ' + st.done + ' of ' + st.total + ' answered', list);
    });
    flat.forEach(function (f) { if (f.q.flag) { flagged++; } });
    if (blank > 0) { el('p', { class: 'notice' }, blank + (blank === 1 ? ' question is' : ' questions are') + ' not answered yet. An unanswered question scores nothing.', summaryEl); }
    if (flagged > 0) { el('p', { class: 'small' }, flagged + (flagged === 1 ? ' question is' : ' questions are') + ' flagged to come back to.', summaryEl); }
    if (dialog.showModal) { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
    var b = dialog.querySelector('[data-cbt-cancel]');
    if (b) { b.focus(); }
  }
  function closeDialog() { if (dialog.close) { dialog.close(); } else { dialog.removeAttribute('open'); } }
  function submit(reason) {
    if (finished) { return; }
    finished = true;
    closeDialog();
    window.clearTimeout(saveTimer);
    status(reason === 'time' ? 'Time is up. Submitting your answers…' : 'Submitting…', '');
    if (reason === 'time') { say('Time is up. Your answers are being submitted.'); }
    var changes = {};
    Object.keys(pending).forEach(function (k) { changes[k] = pending[k]; });
    var send = function () {
      post(urls.submit, { seq: seq + 1, answers: changes, flags: flags(), reason: reason, away: away }).then(function (r) { return r.json(); }).then(function (data) {
        try { window.localStorage.removeItem(STORE); } catch (e) { /* fine */ }
        window.location.href = (data && data.redirect) || urls.result;
      }).catch(function () {
        status('Could not reach the school just now. Trying again… Keep this page open.', 'warn');
        window.setTimeout(send, 4000);
      });
    };
    send();
  }

  /* ---------- tools ---------- */
  function closePalette() {
    if (window.matchMedia && window.matchMedia('(max-width: 59.99rem)').matches) {
      paletteBox.classList.remove('is-open');
      paletteToggle.setAttribute('aria-expanded', 'false');
    }
  }
  paletteToggle.addEventListener('click', function () {
    var open = !paletteBox.classList.contains('is-open');
    paletteBox.classList.toggle('is-open', open);
    paletteToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // A basic calculator, as candidates have in the exam hall: no expressions are evaluated, it works like a pocket calculator.
  (function calculator() {
    var box = $('[data-cbt-calc]'), screen = $('[data-cbt-calc-screen]'), toggle = $('[data-cbt-calc-toggle]');
    var acc = null, op = null, entry = '0', fresh = true;
    function show(v) {
      var s = typeof v === 'number' ? (isFinite(v) ? String(Math.round(v * 1e10) / 1e10) : 'Error') : v;
      screen.textContent = s.length > 14 ? Number(s).toPrecision(10) : s;
    }
    function apply(a, b, o) { return o === '+' ? a + b : o === '-' ? a - b : o === '*' ? a * b : o === '/' ? (b === 0 ? NaN : a / b) : b; }
    function press(k) {
      if (/^[0-9]$/.test(k)) { entry = fresh || entry === '0' ? k : (entry.length < 14 ? entry + k : entry); fresh = false; show(entry); return; }
      if (k === '.') { if (fresh) { entry = '0.'; fresh = false; } else if (entry.indexOf('.') < 0) { entry += '.'; } show(entry); return; }
      if (k === 'clear') { acc = null; op = null; entry = '0'; fresh = true; show(entry); return; }
      if (k === 'neg') { entry = String(-parseFloat(entry)); show(entry); return; }
      if (k === 'sqrt') { var r = Math.sqrt(parseFloat(entry)); entry = String(r); fresh = true; show(r); return; }
      if (k === 'pct') { var p = acc !== null ? acc * parseFloat(entry) / 100 : parseFloat(entry) / 100; entry = String(p); show(p); return; }
      if (k === '=' ) { if (op !== null && acc !== null) { var res = apply(acc, parseFloat(entry), op); acc = null; op = null; entry = String(res); fresh = true; show(res); } return; }
      if (acc !== null && op !== null && !fresh) { acc = apply(acc, parseFloat(entry), op); show(acc); } else if (acc === null) { acc = parseFloat(entry); }
      op = k; fresh = true;
    }
    Array.prototype.forEach.call(box.querySelectorAll('[data-calc]'), function (b) {
      b.addEventListener('click', function () { press(b.getAttribute('data-calc')); });
    });
    toggle.addEventListener('click', function () {
      box.hidden = !box.hidden;
      toggle.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
    });
    box.addEventListener('keydown', function (e) {
      var map = { Enter: '=', '=': '=', Escape: 'clear', Backspace: 'clear', '+': '+', '-': '-', '*': '*', '/': '/', '.': '.' };
      var k = /^[0-9]$/.test(e.key) ? e.key : map[e.key];
      if (k) { e.preventDefault(); e.stopPropagation(); press(k); }
    });
  }());

  /* ---------- keys and buttons ---------- */
  prevBtn.addEventListener('click', function () { go(at - 1); });
  nextBtn.addEventListener('click', function () { go(at + 1); });
  flagBtn.addEventListener('click', toggleFlag);
  submitOpen.addEventListener('click', openDialog);
  dialog.querySelector('[data-cbt-submit]').addEventListener('click', function () { submit('candidate'); });
  dialog.querySelector('[data-cbt-cancel]').addEventListener('click', closeDialog);
  document.addEventListener('keydown', function (e) {
    if (!paper || finished || e.ctrlKey || e.metaKey || e.altKey) { return; }
    var t = e.target;
    if (t && (t.tagName === 'INPUT' && t.type !== 'radio' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || (t.closest && t.closest('[data-cbt-calc]')))) { return; }
    if (dialog.hasAttribute('open')) { return; }
    var k = e.key.toUpperCase();
    var idx = LETTERS.indexOf(k);
    if (idx >= 0 && idx < flat[at].q.options.length) { e.preventDefault(); choose(idx); say('Answer ' + k + ' chosen.'); return; }
    if (k === 'N') { e.preventDefault(); go(at + 1); } else if (k === 'P') { e.preventDefault(); go(at - 1); } else if (k === 'F') { e.preventDefault(); toggleFlag(); } else if (k === 'S') { e.preventDefault(); openDialog(); }
  });
  window.addEventListener('online', function () { flush(true); });
  function leave() { if (paper && !finished && awaySince === null) { awaySince = Date.now(); } }
  function back() {
    if (awaySince === null) { return; }
    var secs = Math.round((Date.now() - awaySince) / 1000);
    awaySince = null;
    if (finished || secs < 2) { return; } // a flicker of focus is not leaving
    away.count++;
    away.seconds += secs;
    if (watched && !toldAway) { toldAway = true; say('You left the exam page. Your teacher can see how often, and for how long.'); }
    flush(true);
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { leave(); } else if (document.hasFocus()) { back(); }
  });
  window.addEventListener('blur', leave);
  window.addEventListener('focus', function () { if (document.visibilityState === 'visible') { back(); } });
  /* ---------- start ---------- */
  window.fetch(urls.paper, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
    if (!r.ok) { throw new Error('paper'); }
    return r.json();
  }).then(function (data) {
    if (data.submitted) { window.location.href = urls.result; return; }
    paper = data;
    seq = data.seq || 0;
    untimed = !!data.untimed;
    watched = !!data.watched;
    if (data.away) { away.count = data.away.count || 0; away.seconds = data.away.seconds || 0; }
    paper.sections.forEach(function (s, si) { s.questions.forEach(function (q, i) { flat.push({ s: si, i: i, q: q }); }); });
    if (!flat.length) { status('This paper has no questions. Tell your teacher.', 'warn'); return; }
    restore();
    setTime(data.seconds_left);
    submitOpen.disabled = false;
    var firstBlank = 0;
    for (var i = 0; i < flat.length; i++) { if (flat[i].q.answer === null || flat[i].q.answer === undefined) { firstBlank = i; break; } }
    at = firstBlank;
    draw();
    status(Object.keys(pending).length ? 'Sending answers kept on this device…' : 'All answers saved', Object.keys(pending).length ? '' : 'ok');
    if (Object.keys(pending).length || flagsDirty) { flush(); }
    tick();
    window.setInterval(tick, 1000);
    window.setInterval(function () { flush(true); }, 45000);
  }).catch(function () {
    status('The paper could not load. Check the connection and reload the page; your time is running.', 'warn');
  });
}());
