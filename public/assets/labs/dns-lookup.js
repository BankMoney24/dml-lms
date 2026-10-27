/*
 * DNS lookup (ADR 0035): the learner is the resolver. A dig-style command
 * line asks one server at a time; each server answers only from its own zone:
 * an answer (aa flag), or a referral to servers closer to the name.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  function under(name, zone) { return zone === '.' || name === zone || name.slice(-(zone.length + 1)) === '.' + zone; }

  L.register('dns-lookup', function (root, p) {
    var log = [];
    var bench = L.el('div', { class: 'sim sim-dns' }, null, root);
    var side = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', null, 'Your job', side);
    L.el('p', null, 'There is no ready-made resolver here: you are it. Find the address of ' + p.name + ', starting from a root server.', side);
    L.el('p', { class: 'mono' }, 'Root server: a.root-servers.net ' + p.root, side);
    L.el('h3', null, 'Commands', side);
    var help = L.el('ul', { class: 'small' }, null, side);
    ['dig @' + p.root + ' ' + p.name, 'dig @<server address> <name> MX', 'dig @<server address> <name> NS', 'clear'].forEach(function (c) { L.el('li', { class: 'mono' }, c, help); });
    L.el('p', { class: 'small muted' }, 'A server answers only for its own zone. Otherwise it refers you on: look in AUTHORITY for the next servers and in ADDITIONAL for their addresses.', side);
    var asked = L.el('p', { class: 'small', 'aria-live': 'polite' }, 'Servers asked: none yet.', side);

    var term = L.el('div', { class: 'nt-term' }, null, bench);
    var screen = L.el('pre', { class: 'nt-screen', role: 'log', 'aria-live': 'polite', tabindex: '0', 'aria-label': 'dig output' }, null, term);
    var form = L.el('form', { class: 'nt-line' }, null, term);
    L.el('label', { for: 'dns-cmd', class: 'nt-prompt' }, '$ ', form);
    var input = L.el('input', { id: 'dns-cmd', class: 'nt-input', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', value: 'dig @' + p.root + ' ' + p.name }, null, form);
    L.el('button', { type: 'submit', class: 'button button-small' }, 'Run', form);

    var hist = [], at = 0;
    function print(lines) { lines.forEach(function (l) { screen.appendChild(document.createTextNode(l + '\n')); }); screen.scrollTop = screen.scrollHeight; }
    function row(r) { return (r[0] + '.                                        ').slice(0, 40) + ' ' + String(r[3]).padEnd(7) + 'IN  ' + r[1].padEnd(6) + r[2]; }

    function dig(server, name, type) {
      var s = p.servers[server];
      if (!s) { return [';; connection timed out; no servers could be reached at ' + server, ';; (Use an address from an ADDITIONAL section, or the root ' + p.root + '.)']; }
      name = name.replace(/\.$/, '').toLowerCase();
      type = (type || 'A').toUpperCase();
      log.push([server, name, type]);
      if (log.length > 200) { log.shift(); }
      L.answer('log').value = JSON.stringify(log);
      var servers = {};
      log.forEach(function (q) { servers[q[0]] = true; });
      asked.textContent = 'Servers asked: ' + Object.keys(servers).map(function (ip) { return p.servers[ip] ? p.servers[ip].name : ip; }).join(', ') + '.';
      var out = ['', '; <<>> DiG <<>> @' + server + ' ' + name + ' ' + type, ';; SERVER: ' + server + ' (' + s.name + ')'];
      var auth = under(name, s.zone) && s.zone !== '.';
      var exact = s.records.filter(function (r) { return r[0] === name && (r[1] === type || r[1] === 'CNAME'); });
      // Closest delegation below this server's zone that covers the name.
      var cut = null;
      s.records.forEach(function (r) {
        if (r[1] === 'NS' && under(name, r[0]) && r[0] !== s.zone && (cut === null || r[0].length > cut.length)) { cut = r[0]; }
      });
      if (exact.length && (cut === null || type === 'NS' && cut === name && !auth)) {
        out.push(';; flags: qr aa; status: NOERROR', '', ';; ANSWER SECTION:');
        exact.forEach(function (r) { out.push(row(r)); });
        return out;
      }
      if (cut !== null) {
        var ns = s.records.filter(function (r) { return r[0] === cut && r[1] === 'NS'; });
        out.push(';; flags: qr; status: NOERROR (referral: this server does not hold ' + name + ')', '', ';; AUTHORITY SECTION:');
        ns.forEach(function (r) { out.push(row(r)); });
        var glue = s.records.filter(function (r) { return r[1] === 'A' && ns.some(function (n) { return n[2] === r[0]; }); });
        if (glue.length) { out.push('', ';; ADDITIONAL SECTION:'); glue.forEach(function (r) { out.push(row(r)); }); }
        return out;
      }
      if (auth) {
        var any = s.records.some(function (r) { return r[0] === name; });
        out.push(';; flags: qr aa; status: ' + (any ? 'NOERROR (no ' + type + ' record for this name)' : 'NXDOMAIN (no such name)'));
        return out;
      }
      out.push(';; flags: qr; status: REFUSED (' + s.name + ' does not serve ' + name + ')');
      return out;
    }

    function run(line) {
      var t = line.trim();
      if (/^(clear|cls)$/i.test(t)) { screen.textContent = ''; return; }
      print(['$ ' + t]);
      var m = /^dig\s+@(\S+)\s+(\S+)(?:\s+(A|NS|MX|CNAME))?$/i.exec(t);
      if (!m) {
        print(/^dig\s+[^@]/i.test(t) ? ['This lab has no recursive resolver: say which server to ask, e.g. dig @' + p.root + ' ' + p.name] : ['Usage: dig @<server address> <name> [A|NS|MX]']);
        return;
      }
      print(dig(m[1], m[2], m[3]));
      if (t !== '') { hist.push(t); at = hist.length; }
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value); input.select(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' && at > 0) { at--; input.value = hist[at]; e.preventDefault(); }
      if (e.key === 'ArrowDown') { at = Math.min(hist.length, at + 1); input.value = hist[at] || ''; e.preventDefault(); }
    });
    print(['Type a dig command and press Run. Up and down arrows bring back earlier commands.']);
  });
})();
