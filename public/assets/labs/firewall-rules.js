/*
 * Firewall rules (ADR 0035): an ordered rule table the learner edits, and a
 * sample of traffic that shows, rule by rule, what the firewall would do.
 * First match wins; anything not allowed is blocked. Marked on the server
 * against traffic the learner does not see.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  function ip2n(s) {
    var m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(String(s || '').trim());
    if (!m) { return null; }
    var n = 0;
    for (var i = 1; i <= 4; i++) { var o = parseInt(m[i], 10); if (o > 255) { return null; } n = n * 256 + o; }
    return n;
  }
  function inCidr(ip, cidr) {
    var parts = cidr.split('/');
    var bits = parseInt(parts[1] || '32', 10);
    var a = ip2n(ip), n = ip2n(parts[0]);
    if (a === null || n === null) { return false; }
    var size = Math.pow(2, 32 - bits);
    return Math.floor(a / size) === Math.floor(n / size);
  }
  function decide(rules, pk) {
    for (var i = 0; i < rules.length; i++) {
      var r = rules[i];
      if ((r[1] === 'any' || r[1] === pk[0]) && (r[2] === 'any' || inCidr(pk[1], r[2])) && (r[3] === 'any' || parseInt(r[3], 10) === pk[2])) {
        return { allow: r[0] === 'allow', rule: i + 1 };
      }
    }
    return { allow: false, rule: 0 };
  }

  L.register('firewall-rules', function (root, p) {
    var key = 'dml-fw:' + location.pathname + '|' + p.server;
    var rules = [];
    try { var saved = JSON.parse(window.localStorage.getItem(key) || 'null'); if (Array.isArray(saved)) { rules = saved; } } catch (e) { /* ignore */ }

    var bench = L.el('div', { class: 'fw' }, null, root);
    var pol = L.el('section', { class: 'fw-policy', 'aria-labelledby': 'fw-pol-h' }, null, bench);
    L.el('h3', { id: 'fw-pol-h' }, 'Policy for the server ' + p.server, pol);
    var ol = L.el('ol', null, null, pol);
    p.policy.forEach(function (line) { L.el('li', null, line, ol); });

    var editor = L.el('section', { class: 'fw-editor', 'aria-labelledby': 'fw-rules-h' }, null, bench);
    L.el('h3', { id: 'fw-rules-h' }, 'Your rules (checked from the top; the first match decides)', editor);
    var wrap = L.el('div', { class: 'table-wrap' }, null, editor);
    var table = L.el('table', { class: 'table table-compact fw-table' }, null, wrap);
    var hr = L.el('tr', null, null, L.el('thead', null, null, table));
    ['#', 'Action', 'Protocol', 'Source', 'Port', ''].forEach(function (h) { L.el('th', { scope: 'col' }, h, hr); });
    var body = L.el('tbody', null, null, table);

    var form = L.el('form', { class: 'fw-add' }, null, editor);
    function sel(id, label, opts) {
      var w = L.el('div', { class: 'nb-field' }, null, form);
      L.el('label', { for: id }, label, w);
      var s = L.el('select', { id: id }, null, w);
      opts.forEach(function (o) { L.el('option', { value: o[0] }, o[1], s); });
      return s;
    }
    function inp(id, label, ph) {
      var w = L.el('div', { class: 'nb-field' }, null, form);
      L.el('label', { for: id }, label, w);
      return L.el('input', { id: id, class: 'mono', autocomplete: 'off', placeholder: ph, maxlength: '18' }, null, w);
    }
    var fAct = sel('fw-act', 'Action', [['allow', 'Allow'], ['deny', 'Block']]);
    var fProto = sel('fw-proto', 'Protocol', [['tcp', 'TCP'], ['udp', 'UDP'], ['any', 'Any']]);
    var fSrc = inp('fw-src', 'Source', 'any, 10.0.0.5 or 10.0.0.0/24');
    var fPort = inp('fw-port', 'Port', 'any or 1–65535');
    L.el('button', { type: 'submit', class: 'button button-small' }, 'Add rule', form);
    var err = L.el('p', { class: 'nb-error', role: 'alert' }, '', editor);
    L.el('p', { class: 'small muted' }, 'After your rules: block everything (this is always last and cannot be removed).', editor);

    var log = L.el('section', { class: 'fw-log', 'aria-labelledby': 'fw-log-h' }, null, bench);
    L.el('h3', { id: 'fw-log-h' }, 'Sample traffic', log);
    L.el('p', { class: 'small muted' }, 'What your rules do with some typical traffic. Decide for each one whether the policy wants it allowed.', log);
    var lwrap = L.el('div', { class: 'table-wrap' }, null, log);
    var ltable = L.el('table', { class: 'table table-compact fw-traffic' }, null, lwrap);
    var lh = L.el('tr', null, null, L.el('thead', null, null, ltable));
    ['Traffic', 'From', 'To port', 'Your firewall'].forEach(function (h) { L.el('th', { scope: 'col' }, h, lh); });
    var lbody = L.el('tbody', null, null, ltable);

    function render() {
      body.textContent = '';
      if (!rules.length) {
        var tr0 = L.el('tr', null, null, body);
        L.el('td', { colspan: '6', class: 'muted' }, 'No rules yet: everything is blocked.', tr0);
      }
      rules.forEach(function (r, i) {
        var tr = L.el('tr', null, null, body);
        L.el('td', null, String(i + 1), tr);
        L.el('td', { class: r[0] === 'allow' ? 'fw-allow' : 'fw-deny' }, r[0] === 'allow' ? 'Allow' : 'Block', tr);
        L.el('td', null, r[1].toUpperCase(), tr);
        L.el('td', { class: 'mono' }, r[2], tr);
        L.el('td', { class: 'mono' }, r[3], tr);
        var cell = L.el('td', { class: 'fw-move' }, null, tr);
        [['Up', -1], ['Down', 1]].forEach(function (m) {
          var b = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Move rule ' + (i + 1) + ' ' + m[0].toLowerCase() }, m[0], cell);
          b.disabled = (m[1] < 0 && i === 0) || (m[1] > 0 && i === rules.length - 1);
          b.addEventListener('click', function () { var j = i + m[1]; var t = rules[i]; rules[i] = rules[j]; rules[j] = t; changed('Rule moved.'); });
        });
        var x = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Remove rule ' + (i + 1) }, 'Remove', cell);
        x.addEventListener('click', function () { rules.splice(i, 1); changed('Rule removed.'); });
      });
      lbody.textContent = '';
      p.sample.forEach(function (s) {
        var d = decide(rules, [s[0], s[1], s[2]]);
        var tr = L.el('tr', null, null, lbody);
        L.el('td', null, s[3], tr);
        L.el('td', { class: 'mono' }, s[1], tr);
        L.el('td', { class: 'mono' }, s[0].toUpperCase() + ' ' + s[2], tr);
        L.el('td', { class: d.allow ? 'fw-allow' : 'fw-deny' }, (d.allow ? 'Allowed' : 'Blocked') + (d.rule ? ' by rule ' + d.rule : ' (no rule matched)'), tr);
      });
      L.answer('rules').value = JSON.stringify(rules);
      try { window.localStorage.setItem(key, JSON.stringify(rules)); } catch (e) { /* ignore */ }
    }
    function changed(msg) { render(); L.say(msg); }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.textContent = '';
      var src = fSrc.value.trim().toLowerCase() || 'any';
      var port = fPort.value.trim().toLowerCase() || 'any';
      if (src !== 'any') {
        var parts = src.split('/');
        if (ip2n(parts[0]) === null || (parts[1] !== undefined && !/^\d{1,2}$/.test(parts[1])) || parseInt(parts[1] || '32', 10) > 32) { err.textContent = 'Source: type any, an address like 10.0.0.5, or a network like 192.168.1.0/24.'; fSrc.focus(); return; }
        if (parts[1] === undefined) { src = src + '/32'; }
      }
      if (port !== 'any' && (!/^\d{1,5}$/.test(port) || parseInt(port, 10) < 1 || parseInt(port, 10) > 65535)) { err.textContent = 'Port: type any, or a number from 1 to 65535.'; fPort.focus(); return; }
      if (rules.length >= 15) { err.textContent = 'Fifteen rules is the limit here. A good answer needs far fewer.'; return; }
      rules.push([fAct.value, fProto.value, src, port]);
      fSrc.value = ''; fPort.value = '';
      changed('Rule ' + rules.length + ' added.');
    });
    render();
  });
})();
