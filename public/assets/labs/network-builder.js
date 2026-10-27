/*
 * Network builder (ADR 0035): place devices, cable them, configure them from
 * forms or a command line, and test with ping, tracert and a step-by-step
 * packet simulation. Shared by the "network-build" and "network-repair" labs.
 *
 * The rules here mirror App\Modules\VirtualLabs\Network\Simulator, which marks
 * the work on the server: this copy is only for the learner's own tests.
 * No inline script or style (CSP); positions are SVG attributes.
 */
(function (global) {
  'use strict';

  /* ---------------------------------------------------------------------
   * Addresses
   * ------------------------------------------------------------------- */
  function ip2n(s) {
    var m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(String(s || '').trim());
    if (!m) { return null; }
    var n = 0;
    for (var i = 1; i <= 4; i++) {
      var o = parseInt(m[i], 10);
      if (o > 255) { return null; }
      n = n * 256 + o;
    }
    return n;
  }
  function n2ip(n) { return [Math.floor(n / 16777216) % 256, Math.floor(n / 65536) % 256, Math.floor(n / 256) % 256, n % 256].join('.'); }
  function prefixMask(bits) { return bits === 0 ? 0 : (4294967296 - Math.pow(2, 32 - bits)); }
  function mask2n(s) {
    var v = String(s || '').trim();
    var m = /^\/?(\d{1,2})$/.exec(v);
    if (m) { var b = parseInt(m[1], 10); return b >= 1 && b <= 32 ? prefixMask(b) : null; }
    var n = ip2n(v);
    if (n === null || n === 0) { return null; }
    var inv = 4294967295 - n;
    return ((inv + 1) & inv) === 0 || inv === 4294967295 ? n : null;
  }
  function prefixOf(mask) { return 32 - Math.round(Math.log(4294967296 - mask) / Math.LN2); }
  function and(a, b) {
    // 32-bit AND without sign trouble.
    var hi = (Math.floor(a / 65536) & Math.floor(b / 65536)) * 65536;
    return hi + ((a % 65536) & (b % 65536));
  }
  function sameNet(a, b, mask) { return and(a, mask) === and(b, mask); }

  var PORTS = {
    pc: ['Fa0'], laptop: ['Fa0'], server: ['Fa0'],
    'switch': ['Fa0/1', 'Fa0/2', 'Fa0/3', 'Fa0/4', 'Fa0/5', 'Fa0/6', 'Fa0/7', 'Fa0/8', 'Gi0/1', 'Gi0/2'],
    router: ['Gi0/0', 'Gi0/1', 'Gi0/2']
  };
  var KIND = { pc: 'PC', laptop: 'Laptop', server: 'Server', 'switch': 'Switch', router: 'Router' };
  var PREFIX = { pc: 'PC', laptop: 'PC', server: 'SRV', 'switch': 'SW', router: 'R' };
  function isHost(d) { return d.t === 'pc' || d.t === 'laptop' || d.t === 'server'; }

  /* ---------------------------------------------------------------------
   * The network model (same rules as the server's Simulator)
   * ------------------------------------------------------------------- */
  function Sim(state) {
    var self = this;
    this.dev = {};
    this.parent = {};
    this.cabled = {};
    this.onSeg = {};
    this.adj = {};
    state.d.forEach(function (d) { self.dev[d.i] = d; });
    state.l.forEach(function (l) {
      var a = l[0] + ':' + l[1], b = l[2] + ':' + l[3];
      self.union(a, b);
      self.cabled[a] = self.cabled[b] = '';
      (self.adj[l[0]] = self.adj[l[0]] || []).push({ port: l[1], to: l[2], toPort: l[3] });
      (self.adj[l[2]] = self.adj[l[2]] || []).push({ port: l[3], to: l[0], toPort: l[1] });
    });
    state.d.forEach(function (d) {
      if (d.t !== 'switch') { return; }
      var ports = PORTS['switch'].filter(function (p) { return Object.prototype.hasOwnProperty.call(self.cabled, d.i + ':' + p); });
      for (var i = 1; i < ports.length; i++) { self.union(d.i + ':' + ports[0], d.i + ':' + ports[i]); }
    });
    Object.keys(this.cabled).forEach(function (node) { self.cabled[node] = self.find(node); });
    state.d.forEach(function (d) {
      self.ifaces(d).forEach(function (f) {
        var seg = self.cabled[d.i + ':' + f.port];
        if (seg !== undefined) { (self.onSeg[seg] = self.onSeg[seg] || []).push({ dev: d.i, port: f.port, ip: f.ip, mask: f.mask }); }
      });
    });
  }
  Sim.prototype.find = function (x) {
    if (this.parent[x] === undefined) { this.parent[x] = x; }
    while (this.parent[x] !== x) { this.parent[x] = this.parent[this.parent[x]]; x = this.parent[x]; }
    return x;
  };
  Sim.prototype.union = function (a, b) { var ra = this.find(a), rb = this.find(b); if (ra !== rb) { this.parent[ra] = rb; } };
  /** Working layer-3 interfaces: [{port, ip, mask}]. */
  Sim.prototype.ifaces = function (d) {
    if (isHost(d)) {
      var ip = ip2n(d.ip), m = mask2n(d.m);
      return ip !== null && m !== null ? [{ port: 'Fa0', ip: ip, mask: m }] : [];
    }
    var out = [];
    if (d.t === 'router') {
      PORTS.router.forEach(function (p) {
        var c = (d['if'] || {})[p] || {};
        var a = ip2n(c.ip), m = mask2n(c.m);
        if (c.u === 1 && a !== null && m !== null) { out.push({ port: p, ip: a, mask: m }); }
      });
    }
    return out;
  };
  Sim.prototype.seg = function (dev, port) { var s = this.cabled[dev + ':' + port]; return s === undefined ? null : s; };
  Sim.prototype.arp = function (seg, ip) {
    var list = this.onSeg[seg] || [];
    for (var i = 0; i < list.length; i++) { if (list[i].ip === ip) { return list[i]; } }
    return null;
  };
  Sim.prototype.owns = function (d, ip) { return this.ifaces(d).some(function (f) { return f.ip === ip; }); };
  /** One forwarding step from device d towards dst. */
  Sim.prototype.step = function (d, dst) {
    var self = this, hit;
    if (isHost(d)) {
      var ip = ip2n(d.ip), m = mask2n(d.m), gw = ip2n(d.g);
      if (ip === null || m === null) { return { ok: false, why: d.n + ' has no IP address and mask' }; }
      var seg = this.seg(d.i, 'Fa0');
      if (seg === null) { return { ok: false, why: d.n + ' has no cable plugged in' }; }
      if (sameNet(dst, ip, m)) {
        hit = this.arp(seg, dst);
        return hit ? { ok: true, next: hit, out: 'Fa0', arpFor: dst } : { ok: false, why: n2ip(dst) + ' does not answer on ' + d.n + '’s network (nothing with that address is connected there)' };
      }
      if (gw === null) { return { ok: false, why: d.n + ' has no default gateway, so it cannot reach other networks' }; }
      if (!sameNet(gw, ip, m)) { return { ok: false, why: d.n + '’s default gateway ' + n2ip(gw) + ' is not on its own network' }; }
      hit = this.arp(seg, gw);
      if (!hit) { return { ok: false, why: d.n + '’s default gateway ' + n2ip(gw) + ' does not answer (no router interface with that address is up and connected)' }; }
      if (this.dev[hit.dev].t !== 'router') { return { ok: false, why: d.n + '’s default gateway ' + n2ip(gw) + ' is not a router' }; }
      return { ok: true, next: hit, out: 'Fa0', arpFor: gw };
    }
    var best = null, bestP = -1;
    this.ifaces(d).forEach(function (f) {
      var p = prefixOf(f.mask);
      if (sameNet(dst, f.ip, f.mask) && p > bestP) { best = { kind: 'c', port: f.port }; bestP = p; }
    });
    (d.rt || []).forEach(function (r) {
      var net = ip2n(r[0]), mk = mask2n(r[1]), via = ip2n(r[2]);
      if (net === null || mk === null || via === null) { return; }
      var p = prefixOf(mk);
      if (and(dst, mk) === and(net, mk) && p > bestP) { best = { kind: 'v', via: via }; bestP = p; }
    });
    if (!best) { return { ok: false, why: d.n + ' has no route to ' + n2ip(dst) }; }
    if (best.kind === 'c') {
      var s = this.seg(d.i, best.port);
      hit = s === null ? null : this.arp(s, dst);
      return hit ? { ok: true, next: hit, out: best.port, arpFor: dst } : { ok: false, why: n2ip(dst) + ' does not answer on ' + d.n + ' ' + best.port + '’s network' };
    }
    var found = null;
    this.ifaces(d).forEach(function (f) { if (!found && sameNet(best.via, f.ip, f.mask)) { found = f; } });
    if (!found) { return { ok: false, why: d.n + '’s next hop ' + n2ip(best.via) + ' is not on any of its connected networks' }; }
    var sg = this.seg(d.i, found.port);
    hit = sg === null ? null : this.arp(sg, best.via);
    if (!hit) { return { ok: false, why: d.n + '’s next hop ' + n2ip(best.via) + ' does not answer' }; }
    void self;
    return { ok: true, next: hit, out: found.port, arpFor: best.via };
  };
  /** Follows one packet: {ok, at, why, hops:[{from, out, to, toPort, toIp, arpFor}], expired}. */
  Sim.prototype.walk = function (fromId, dst, ttl) {
    var d = this.dev[fromId];
    var hops = [];
    ttl = ttl || 32;
    for (var guard = 0; guard < 64; guard++) {
      if (this.owns(d, dst)) { return { ok: true, at: d.i, hops: hops }; }
      if (isHost(d)) {
        if (guard > 0) { return { ok: false, at: d.i, hops: hops, why: d.n + ' is not ' + n2ip(dst) + ' and does not forward packets' }; }
      } else if (guard > 0) {
        ttl -= 1;
        if (ttl <= 0) { return { ok: false, at: d.i, hops: hops, expired: true, why: 'the packet goes round in a loop (TTL expired at ' + d.n + ')' }; }
      }
      var st = this.step(d, dst);
      if (!st.ok) { return { ok: false, at: d.i, hops: hops, why: st.why }; }
      hops.push({ from: d.i, out: st.out, to: st.next.dev, toPort: st.next.port, toIp: st.next.ip, arpFor: st.arpFor });
      d = this.dev[st.next.dev];
    }
    return { ok: false, at: d.i, hops: hops, why: 'the packet goes round in a loop' };
  };
  Sim.prototype.sourceFor = function (d, dst) {
    var f = this.ifaces(d);
    if (!f.length) { return null; }
    for (var i = 0; i < f.length; i++) { if (sameNet(dst, f[i].ip, f[i].mask)) { return f[i].ip; } }
    return f[0].ip;
  };
  Sim.prototype.ping = function (fromId, dst) {
    var d = this.dev[fromId];
    var src = this.sourceFor(d, dst);
    if (src === null) { return { ok: false, why: d.n + ' has no working address to send from', there: null, back: null }; }
    var there = this.walk(fromId, dst);
    if (!there.ok) { return { ok: false, why: there.why, there: there, back: null, src: src }; }
    var back = this.walk(there.at, src);
    if (!back.ok) { return { ok: false, why: 'the request arrives but the reply cannot get back: ' + back.why, there: there, back: back, src: src }; }
    return { ok: true, there: there, back: back, src: src };
  };
  /** Devices a frame passes between two cabled ports (through switches), by breadth-first search. */
  Sim.prototype.physical = function (fromId, fromPort, toId) {
    var self = this;
    var start = (this.adj[fromId] || []).filter(function (a) { return a.port === fromPort; })[0];
    if (!start) { return [fromId, toId]; }
    var prev = {}, queue = [start.to], seen = {};
    seen[start.to] = true;
    prev[start.to] = fromId;
    while (queue.length) {
      var cur = queue.shift();
      if (cur === toId) { break; }
      if (this.dev[cur] && this.dev[cur].t === 'switch') {
        (this.adj[cur] || []).forEach(function (a) {
          if (!seen[a.to]) { seen[a.to] = true; prev[a.to] = cur; queue.push(a.to); }
        });
      }
    }
    if (!seen[toId]) { return [fromId, toId]; }
    var path = [toId];
    while (path[0] !== fromId) { path.unshift(prev[path[0]]); }
    void self;
    return path;
  };

  if (typeof module === 'object' && module.exports) { module.exports = { Sim: Sim, ip2n: ip2n, mask2n: mask2n }; return; }

  /* ---------------------------------------------------------------------
   * The workspace
   * ------------------------------------------------------------------- */
  var L = global.DMLLab;
  var el = L.el, S = L.svg;
  var W = 960, H = 540;

  function mount(root, p) {
    var mode = p.mode === 'repair' ? 'repair' : 'build';
    var storeKey = 'dml-nb:' + location.pathname + '|' + p.server + '|' + mode;
    var state = load() || (mode === 'repair' && p.start ? JSON.parse(JSON.stringify(p.start)) : { d: [], l: [] });
    var selected = null;
    var cableFrom = null;
    var quickCable = false;
    var lastEvents = [];
    var playing = null;

    function load() {
      try {
        var raw = global.localStorage.getItem(storeKey);
        var v = raw ? JSON.parse(raw) : null;
        return v && Array.isArray(v.d) && Array.isArray(v.l) ? v : null;
      } catch (e) { return null; }
    }
    function save() {
      var json = JSON.stringify(state);
      var field = L.answer('topology');
      if (field) { field.value = json; }
      try { global.localStorage.setItem(storeKey, json); } catch (e) { /* private window: the answer field still has it */ }
    }
    function byId(id) { return state.d.filter(function (d) { return d.i === id; })[0] || null; }
    function linkAt(id, port) {
      return state.l.filter(function (l) { return (l[0] === id && l[1] === port) || (l[2] === id && l[3] === port); })[0] || null;
    }
    function freePorts(d) { return PORTS[d.t].filter(function (pt) { return !linkAt(d.i, pt); }); }
    function nextName(t) {
      var pre = PREFIX[t], n = 1;
      var taken = {};
      state.d.forEach(function (d) { taken[d.n.toUpperCase()] = true; });
      while (taken[(pre + n).toUpperCase()]) { n++; }
      return pre + n;
    }
    function newId() {
      var n = 1;
      while (byId('n' + n)) { n++; }
      return 'n' + n;
    }

    /* ---- layout ---- */
    var wrap = el('div', { class: 'nb' }, null, root);
    var brief = el('div', { class: 'nb-brief' }, null, wrap);
    if (mode === 'build') {
      el('p', null, 'Connect ' + p.site + ' to the server room. The PCs go on LAN A, ' + p.lanA + '. The server SRV1 (' + p.server + ') is on LAN B, ' + p.lanB + '. R1 and R2 are joined by the link ' + p.link + '.', brief);
    } else {
      el('p', null, 'People in ' + p.site + ' cannot reach the server SRV1 (' + p.server + '). The network is built, but three things are wrong. Test, find and fix them.', brief);
    }
    var planBox = el('details', { class: 'nb-plan' }, null, brief);
    if (mode === 'build') { planBox.setAttribute('open', ''); }
    el('summary', null, 'Addressing plan', planBox);
    var planWrap = el('div', { class: 'table-wrap' }, null, planBox);
    var table = el('table', { class: 'table table-compact nb-plan-table' }, null, planWrap);
    var thead = el('thead', null, null, table);
    var hr = el('tr', null, null, thead);
    ['Device', 'Interface', 'Address', 'Mask', 'Default gateway'].forEach(function (h) { el('th', { scope: 'col' }, h, hr); });
    var tb = el('tbody', null, null, table);
    p.plan.forEach(function (r) {
      var tr = el('tr', null, null, tb);
      el('th', { scope: 'row' }, r.device, tr);
      el('td', null, r.port, tr);
      el('td', { class: 'mono' }, r.address, tr);
      el('td', { class: 'mono' }, r.mask, tr);
      el('td', { class: 'mono' }, r.gateway || '–', tr);
    });

    var bar = el('div', { class: 'nb-bar', role: 'toolbar', 'aria-label': 'Network builder tools' }, null, wrap);
    var addGroup = el('div', { class: 'nb-add', role: 'group', 'aria-label': 'Add a device' }, null, bar);
    el('span', { class: 'nb-bar-label', 'aria-hidden': 'true' }, 'Add', addGroup);
    ['pc', 'laptop', 'server', 'switch', 'router'].forEach(function (t) {
      var b = el('button', { type: 'button', class: 'nb-tool', 'data-kind': t }, null, addGroup);
      b.appendChild(icon(t, 22));
      el('span', null, KIND[t], b);
      b.setAttribute('aria-label', 'Add a ' + KIND[t].toLowerCase());
      b.addEventListener('click', function () { addDevice(t); });
    });
    var cableBtn = el('button', { type: 'button', class: 'nb-tool nb-cable', 'aria-pressed': 'false' }, null, bar);
    cableBtn.appendChild(icon('cable', 22));
    el('span', null, 'Quick cable', cableBtn);
    cableBtn.addEventListener('click', function () { setQuickCable(!quickCable); });
    var resetBtn = el('button', { type: 'button', class: 'nb-tool nb-reset' }, mode === 'repair' ? 'Start again' : 'Clear all', bar);
    var resetArmed = false;
    resetBtn.addEventListener('click', function () {
      if (!resetArmed) { resetArmed = true; resetBtn.textContent = 'Press again to confirm'; window.setTimeout(function () { resetArmed = false; resetBtn.textContent = mode === 'repair' ? 'Start again' : 'Clear all'; }, 4000); return; }
      resetArmed = false;
      state = mode === 'repair' && p.start ? JSON.parse(JSON.stringify(p.start)) : { d: [], l: [] };
      selected = null; cableFrom = null;
      changed('Workspace reset.');
    });
    var hint = el('p', { class: 'nb-hint small muted', id: 'nb-hint' }, '', wrap);

    var work = el('div', { class: 'nb-work' }, null, wrap);
    var canvasBox = el('div', { class: 'nb-canvas' }, null, work);
    var svg = S('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'nb-svg', role: 'group', 'aria-label': 'Workspace. Devices are buttons: press one to open its settings; drag, or use the arrow keys, to move it.', 'aria-describedby': 'nb-hint' }, canvasBox);
    var gLinks = S('g', { class: 'nb-links' }, svg);
    var gDevs = S('g', { class: 'nb-devs' }, svg);
    var gPk = S('g', { class: 'nb-packets', 'aria-hidden': 'true' }, svg);
    var inspector = el('section', { class: 'nb-inspector', 'aria-label': 'Device settings' }, null, work);

    var simBox = el('section', { class: 'nb-sim', 'aria-labelledby': 'nb-sim-h' }, null, work);
    el('h3', { id: 'nb-sim-h' }, 'Test it', simBox);
    var simForm = el('form', { class: 'nb-sim-form' }, null, simBox);
    var fromWrap = el('div', { class: 'nb-field' }, null, simForm);
    el('label', { for: 'nb-from' }, 'Ping from', fromWrap);
    var fromSel = el('select', { id: 'nb-from' }, null, fromWrap);
    var toWrap = el('div', { class: 'nb-field' }, null, simForm);
    el('label', { for: 'nb-to' }, 'To address', toWrap);
    var toIn = el('input', { id: 'nb-to', class: 'mono', autocomplete: 'off', inputmode: 'decimal', value: p.server, maxlength: '15' }, null, toWrap);
    el('button', { type: 'submit', class: 'button button-small' }, 'Send ping', simForm);
    var allBtn = el('button', { type: 'button', class: 'button button-small button-quiet' }, 'Ping the server from every PC', simForm);
    var simOut = el('div', { class: 'nb-sim-out', role: 'status', 'aria-live': 'polite' }, null, simBox);
    var player = el('div', { class: 'nb-player', hidden: '' }, null, simBox);
    var playBtn = el('button', { type: 'button', class: 'button button-small' }, 'Play', player);
    var stepBtn = el('button', { type: 'button', class: 'button button-small button-quiet' }, 'Step', player);
    var rewBtn = el('button', { type: 'button', class: 'button button-small button-quiet' }, 'Back to start', player);
    var evWrap = el('div', { class: 'table-wrap nb-events-wrap' }, null, simBox);
    var evTable = el('table', { class: 'table table-compact nb-events', hidden: '' }, null, evWrap);
    el('caption', { class: 'visually-hidden' }, 'Packet events, in order', evTable);
    var evHead = el('tr', null, null, el('thead', null, null, evTable));
    ['#', 'From', 'To', 'Kind', 'What happens'].forEach(function (h) { el('th', { scope: 'col' }, h, evHead); });
    var evBody = el('tbody', null, null, evTable);

    /* ---- drawing ---- */
    function icon(t, size) {
      var s = S('svg', { viewBox: '0 0 40 40', width: size, height: size, class: 'nb-ico nb-' + t, 'aria-hidden': 'true', focusable: 'false' });
      drawShape(s, t, 20, 20, 0.9);
      return s;
    }
    function drawShape(g, t, x, y, k) {
      k = k || 1;
      if (t === 'pc') {
        S('rect', { x: x - 15 * k, y: y - 14 * k, width: 30 * k, height: 21 * k, rx: 2 * k, class: 'nb-shape' }, g);
        S('rect', { x: x - 11 * k, y: y - 10 * k, width: 22 * k, height: 13 * k, class: 'nb-display' }, g);
        S('path', { d: 'M' + (x - 8 * k) + ' ' + (y + 14 * k) + 'h' + 16 * k + 'l-3 ' + (-7 * k) + 'h' + (-10 * k) + 'z', class: 'nb-shape' }, g);
      } else if (t === 'laptop') {
        S('rect', { x: x - 13 * k, y: y - 13 * k, width: 26 * k, height: 18 * k, rx: 2 * k, class: 'nb-shape' }, g);
        S('rect', { x: x - 10 * k, y: y - 10 * k, width: 20 * k, height: 12 * k, class: 'nb-display' }, g);
        S('path', { d: 'M' + (x - 18 * k) + ' ' + (y + 11 * k) + 'h' + 36 * k + 'l-4 ' + (-5 * k) + 'h' + (-28 * k) + 'z', class: 'nb-shape' }, g);
      } else if (t === 'server') {
        S('rect', { x: x - 11 * k, y: y - 17 * k, width: 22 * k, height: 34 * k, rx: 2 * k, class: 'nb-shape' }, g);
        [-10, -2, 6].forEach(function (o) { S('line', { x1: x - 7 * k, y1: y + o * k, x2: x + 7 * k, y2: y + o * k, class: 'nb-detail' }, g); });
      } else if (t === 'switch') {
        S('rect', { x: x - 19 * k, y: y - 9 * k, width: 38 * k, height: 18 * k, rx: 3 * k, class: 'nb-shape' }, g);
        S('path', { d: 'M' + (x - 11 * k) + ' ' + (y - 3 * k) + 'h' + 22 * k + 'm-4 -3l4 3l-4 3M' + (x + 11 * k) + ' ' + (y + 3 * k) + 'h' + (-22 * k) + 'm4 -3l-4 3l4 3', class: 'nb-arrows' }, g);
      } else if (t === 'router') {
        S('circle', { cx: x, cy: y, r: 17 * k, class: 'nb-shape' }, g);
        S('path', { d: 'M' + (x - 10 * k) + ' ' + y + 'h' + 20 * k + 'M' + x + ' ' + (y - 10 * k) + 'v' + 20 * k + 'M' + (x + 6 * k) + ' ' + (y - 4 * k) + 'l4 4l-4 4M' + (x - 6 * k) + ' ' + (y - 4 * k) + 'l-4 4l4 4M' + (x - 4 * k) + ' ' + (y - 6 * k) + 'l4 -4l4 4M' + (x - 4 * k) + ' ' + (y + 6 * k) + 'l4 4l4 -4', class: 'nb-arrows' }, g);
      } else if (t === 'cable') {
        S('path', { d: 'M8 30 C 14 8, 26 32, 32 10', class: 'nb-cable-ico' }, g);
        S('circle', { cx: 8, cy: 30, r: 3.5, class: 'nb-plug' }, g);
        S('circle', { cx: 32, cy: 10, r: 3.5, class: 'nb-plug' }, g);
      }
    }
    function portUp(d, port) {
      if (d.t !== 'router') { return true; }
      var c = (d['if'] || {})[port];
      return !!c && c.u === 1;
    }
    function label(d) {
      if (isHost(d)) { return d.ip || 'no address'; }
      if (d.t === 'router') {
        var n = PORTS.router.filter(function (pt) { return ((d['if'] || {})[pt] || {}).ip; }).length;
        return n === 0 ? 'not configured' : n + (n === 1 ? ' interface' : ' interfaces');
      }
      return state.l.filter(function (l) { return l[0] === d.i || l[2] === d.i; }).length + ' cables';
    }
    function drawLinks() {
      gLinks.textContent = '';
      state.l.forEach(function (l) {
        var a = byId(l[0]), b = byId(l[2]);
        if (!a || !b) { return; }
        var up = portUp(a, l[1]) && portUp(b, l[3]);
        var g = S('g', { class: 'nb-link' + (up ? '' : ' is-down') }, gLinks);
        S('line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, class: 'nb-wire' }, g);
        [[a, l[1], b], [b, l[3], a]].forEach(function (e) {
          var dx = e[2].x - e[0].x, dy = e[2].y - e[0].y, len = Math.max(1, Math.sqrt(dx * dx + dy * dy));
          S('circle', { cx: e[0].x + dx / len * 40, cy: e[0].y + dy / len * 40, r: 4.5, class: 'nb-light ' + (up ? 'is-up' : 'is-down') }, g);
          S('text', { x: e[0].x + dx / len * 62, y: e[0].y + dy / len * 62 - 7, class: 'nb-port', 'text-anchor': 'middle' }, g, e[1]);
        });
      });
    }
    function draw() {
      drawLinks();
      gDevs.textContent = '';
      state.d.forEach(function (d) {
        var g = S('g', {
          class: 'nb-dev nb-' + d.t + (selected === d.i ? ' is-selected' : '') + (cableFrom === d.i ? ' is-cabling' : ''),
          tabindex: '0', role: 'button', 'data-id': d.i,
          'aria-label': d.n + ', ' + KIND[d.t] + ', ' + label(d) + (selected === d.i ? ', selected' : ''),
          'aria-pressed': selected === d.i ? 'true' : 'false'
        }, gDevs);
        S('rect', { x: d.x - 46, y: d.y - 34, width: 92, height: 104, rx: 10, class: 'nb-hit' }, g);
        drawShape(g, d.t, d.x, d.y, 1.4);
        S('text', { x: d.x, y: d.y + 44, class: 'nb-name', 'text-anchor': 'middle' }, g, d.n);
        S('text', { x: d.x, y: d.y + 61, class: 'nb-sub', 'text-anchor': 'middle' }, g, label(d));
        bindDevice(g, d);
      });
      var hosts = state.d.filter(function (d) { return isHost(d) || d.t === 'router'; });
      var keep = fromSel.value;
      fromSel.textContent = '';
      hosts.forEach(function (d) { el('option', { value: d.i }, d.n, fromSel); });
      if (keep && byId(keep)) { fromSel.value = keep; } else {
        var firstPc = hosts.filter(function (d) { return d.t === 'pc' || d.t === 'laptop'; })[0];
        if (firstPc) { fromSel.value = firstPc.i; }
      }
      hint.textContent = state.d.length === 0
        ? 'Start by adding devices with the buttons above.'
        : quickCable ? (cableFrom ? 'Now choose the device to plug the other end into. Press Escape to stop.' : 'Quick cable is on: choose the first device, then the second. Free ports are used.')
          : 'Choose a device to see and change its settings. Drag it, or use the arrow keys, to move it.';
    }

    /* ---- moving and choosing devices ---- */
    function toSvg(evt) {
      var pt = svg.createSVGPoint();
      pt.x = evt.clientX; pt.y = evt.clientY;
      var m = svg.getScreenCTM();
      return m ? pt.matrixTransform(m.inverse()) : { x: 0, y: 0 };
    }
    function clamp(d) { d.x = Math.max(50, Math.min(W - 50, Math.round(d.x))); d.y = Math.max(40, Math.min(H - 72, Math.round(d.y))); }
    function bindDevice(g, d) {
      var start = null, moved = false;
      g.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) { return; }
        var pt = toSvg(e);
        start = { x: pt.x, y: pt.y, dx: pt.x - d.x, dy: pt.y - d.y, ox: d.x, oy: d.y };
        moved = false;
        try { g.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
      });
      g.addEventListener('pointermove', function (e) {
        if (!start) { return; }
        var pt = toSvg(e);
        if (!moved && Math.abs(pt.x - start.x) + Math.abs(pt.y - start.y) < 5) { return; }
        moved = true;
        d.x = pt.x - start.dx; d.y = pt.y - start.dy;
        clamp(d);
        // Move just this device (by transform) and redraw the cables; the device keeps the pointer.
        g.setAttribute('transform', 'translate(' + (d.x - start.ox) + ' ' + (d.y - start.oy) + ')');
        g.classList.add('is-dragging');
        drawLinks();
      });
      g.addEventListener('pointerup', function () {
        var wasMoved = moved;
        start = null; moved = false;
        if (wasMoved) { save(); draw(); focusDevice(d.i); return; }
        choose(d.i);
      });
      g.addEventListener('keydown', function (e) {
        var step = e.shiftKey ? 40 : 10;
        var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(d.i); return; }
        if (moves[e.key]) {
          e.preventDefault();
          d.x += moves[e.key][0]; d.y += moves[e.key][1];
          clamp(d);
          save(); draw(); focusDevice(d.i);
        }
        if (e.key === 'Escape' && quickCable) { setQuickCable(false); }
      });
    }
    function focusDevice(id) {
      var g = gDevs.querySelector('[data-id="' + id + '"]');
      if (g && g.focus) { g.focus(); }
    }
    function choose(id) {
      if (quickCable) {
        if (!cableFrom) { cableFrom = id; draw(); focusDevice(id); L.say('Cable from ' + byId(id).n + '. Choose the other device.'); return; }
        if (cableFrom === id) { cableFrom = null; draw(); focusDevice(id); return; }
        connectFree(byId(cableFrom), byId(id));
        cableFrom = null;
        draw(); focusDevice(id);
        return;
      }
      selected = id;
      draw();
      renderInspector('settings');
      focusDevice(id);
    }
    function setQuickCable(on) {
      quickCable = on;
      cableFrom = null;
      cableBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      cableBtn.classList.toggle('is-on', on);
      draw();
      L.say(on ? 'Quick cable on. Choose two devices to join them.' : 'Quick cable off.');
    }
    function connectFree(a, b) {
      var fa = freePorts(a), fb = freePorts(b);
      if (!fa.length || !fb.length) { L.say((fa.length ? b.n : a.n) + ' has no free port.'); flash((fa.length ? b.n : a.n) + ' has no free port.', true); return; }
      // Switch to router: use a gigabit uplink when one is free.
      var pa = a.t === 'switch' && b.t === 'router' && fa.indexOf('Gi0/1') >= 0 ? 'Gi0/1' : fa[0];
      var pb = b.t === 'switch' && a.t === 'router' && fb.indexOf('Gi0/1') >= 0 ? 'Gi0/1' : fb[0];
      connect(a, pa, b, pb);
    }
    function connect(a, pa, b, pb) {
      if (a.i === b.i) { flash('A cable needs two different devices.', true); return; }
      if (linkAt(a.i, pa) || linkAt(b.i, pb)) { flash('That port already has a cable.', true); return; }
      state.l.push([a.i, pa, b.i, pb]);
      changed('Cable: ' + a.n + ' ' + pa + ' to ' + b.n + ' ' + pb + '.');
    }
    function addDevice(t) {
      if (state.d.length >= 30) { flash('That is the most devices one workspace can hold (30).', true); return; }
      var count = state.d.length;
      var d = { i: newId(), t: t, n: nextName(t), x: 110 + (count % 4) * 240, y: 80 + (Math.floor(count / 4) % 3) * 150 + (count >= 12 ? 40 : 0) };
      clamp(d);
      if (isHost(d)) { d.ip = ''; d.m = ''; d.g = ''; }
      if (t === 'router') { d['if'] = {}; PORTS.router.forEach(function (pt) { d['if'][pt] = { ip: '', m: '', u: 0 }; }); d.rt = []; }
      state.d.push(d);
      selected = d.i;
      changed(KIND[t] + ' ' + d.n + ' added.');
      focusDevice(d.i);
    }
    function removeDevice(d) {
      state.d = state.d.filter(function (x) { return x.i !== d.i; });
      state.l = state.l.filter(function (l) { return l[0] !== d.i && l[2] !== d.i; });
      selected = null;
      changed(d.n + ' removed.');
    }
    function changed(message) {
      save();
      draw();
      renderInspector();
      if (message) { L.say(message); flash(message); }
    }
    var flashTimer = null;
    function flash(text, bad) {
      hint.textContent = text;
      hint.classList.toggle('is-bad', !!bad);
      if (flashTimer) { window.clearTimeout(flashTimer); }
      flashTimer = window.setTimeout(function () { hint.classList.remove('is-bad'); draw(); }, 3500);
    }

    /* ---- the inspector ---- */
    var tab = 'settings';
    function renderInspector(which) {
      if (which) { tab = which; }
      inspector.textContent = '';
      var d = selected ? byId(selected) : null;
      if (!d) {
        el('h3', null, 'No device chosen', inspector);
        el('p', { class: 'small muted' }, 'Choose a device in the workspace to set its addresses, cable it or open its command line.', inspector);
        var list = el('ul', { class: 'nb-devlist' }, null, inspector);
        state.d.forEach(function (x) {
          var li = el('li', null, null, list);
          var b = el('button', { type: 'button', class: 'linklike' }, x.n, li);
          el('span', { class: 'small muted' }, ' ' + KIND[x.t] + ', ' + label(x), li);
          b.addEventListener('click', function () { choose(x.i); });
        });
        return;
      }
      var head = el('div', { class: 'nb-ins-head' }, null, inspector);
      var ico = el('span', { class: 'nb-ins-ico' }, null, head);
      ico.appendChild(icon(d.t, 30));
      el('h3', null, d.n, head);
      el('span', { class: 'small muted' }, KIND[d.t], head);
      var close = el('button', { type: 'button', class: 'linklike small nb-close' }, 'Close', head);
      close.addEventListener('click', function () { var id = d.i; selected = null; draw(); renderInspector(); focusDevice(id); });

      var tabs = el('div', { class: 'nb-tabs', role: 'tablist', 'aria-label': d.n + ' views' }, null, inspector);
      var views = isHost(d) ? [['settings', 'IP settings'], ['cli', 'Command prompt'], ['ports', 'Cables']]
        : d.t === 'router' ? [['settings', 'Interfaces'], ['routes', 'Routes'], ['cli', 'CLI'], ['ports', 'Cables']]
          : [['ports', 'Cables'], ['settings', 'Name']];
      if (!views.some(function (v) { return v[0] === tab; })) { tab = views[0][0]; }
      views.forEach(function (v) {
        var b = el('button', { type: 'button', role: 'tab', class: 'nb-tab', 'aria-selected': tab === v[0] ? 'true' : 'false', id: 'nb-tab-' + v[0] }, v[1], tabs);
        b.addEventListener('click', function () { renderInspector(v[0]); var again = document.getElementById('nb-tab-' + v[0]); if (again) { again.focus(); } });
      });
      var panel = el('div', { class: 'nb-panel', role: 'tabpanel', 'aria-labelledby': 'nb-tab-' + tab }, null, inspector);
      if (tab === 'ports') { portsView(panel, d); } else if (tab === 'cli') { cliView(panel, d); } else if (tab === 'routes') { routesView(panel, d); } else if (isHost(d)) { hostView(panel, d); } else if (d.t === 'router') { ifaceView(panel, d); } else { nameView(panel, d); }
    }
    function field(form, id, text, value, attrs) {
      var w = el('div', { class: 'nb-field' }, null, form);
      el('label', { for: id }, text, w);
      var a = { id: id, value: value || '', autocomplete: 'off' };
      Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
      return el('input', a, null, w);
    }
    function nameField(form, d) {
      return field(form, 'nb-name', 'Name', d.n, { maxlength: '20' });
    }
    function rename(d, value, dry) {
      var v = String(value || '').trim();
      if (!/^[A-Za-z0-9_-]{1,20}$/.test(v)) { return 'Names use letters, numbers, - and _ (up to 20).'; }
      if (state.d.some(function (x) { return x.i !== d.i && x.n.toUpperCase() === v.toUpperCase(); })) { return 'Another device is already called ' + v + '.'; }
      if (!dry) { d.n = v; }
      return null;
    }
    function problem(form, text) {
      var old = form.querySelector('.nb-error');
      if (old) { old.remove(); }
      if (text) { el('p', { class: 'nb-error', role: 'alert' }, text, form); }
    }
    function nameView(panel, d) {
      var form = el('form', { class: 'nb-form' }, null, panel);
      var name = nameField(form, d);
      el('button', { type: 'submit', class: 'button button-small' }, 'Save', form);
      deleteButton(panel, d);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = rename(d, name.value);
        if (err) { problem(form, err); return; }
        changed(d.n + ' saved.');
      });
    }
    function hostView(panel, d) {
      var form = el('form', { class: 'nb-form' }, null, panel);
      var name = nameField(form, d);
      var ip = field(form, 'nb-ip', 'IP address', d.ip, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var m = field(form, 'nb-mask', 'Subnet mask', d.m, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var g = field(form, 'nb-gw', 'Default gateway', d.g, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Save settings', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = rename(d, name.value, true)
          || (ip.value.trim() !== '' && ip2n(ip.value) === null ? 'The IP address should look like 192.168.1.10.' : null)
          || (m.value.trim() !== '' && mask2n(m.value) === null ? 'The mask should look like 255.255.255.0 (or /24).' : null)
          || (g.value.trim() !== '' && ip2n(g.value) === null ? 'The gateway should be an address like 192.168.1.1.' : null);
        if (err) { problem(form, err); return; }
        rename(d, name.value);
        d.ip = ip.value.trim(); d.g = g.value.trim();
        var mk = mask2n(m.value);
        d.m = m.value.trim() === '' ? '' : n2ip(mk);
        changed(d.n + ' settings saved.');
      });
      deleteButton(panel, d);
    }
    function ifaceView(panel, d) {
      var form = el('form', { class: 'nb-form' }, null, panel);
      var name = nameField(form, d);
      var inputs = {};
      PORTS.router.forEach(function (pt) {
        var c = d['if'][pt] || { ip: '', m: '', u: 0 };
        var fs = el('fieldset', { class: 'nb-if' }, null, form);
        var lk = linkAt(d.i, pt);
        var other = lk ? byId(lk[0] === d.i ? lk[2] : lk[0]) : null;
        el('legend', null, pt + (other ? ' (to ' + other.n + ')' : ' (no cable)'), fs);
        var id = pt.replace('/', '-');
        inputs[pt] = {
          ip: field(fs, 'nb-' + id + '-ip', 'IP address', c.ip, { inputmode: 'decimal', class: 'mono', maxlength: '15' }),
          m: field(fs, 'nb-' + id + '-m', 'Mask', c.m, { inputmode: 'decimal', class: 'mono', maxlength: '15' })
        };
        var up = el('label', { class: 'choice choice-tight nb-up' }, null, fs);
        inputs[pt].u = el('input', { type: 'checkbox', id: 'nb-' + id + '-u' }, null, up);
        inputs[pt].u.checked = c.u === 1;
        el('span', null, 'Up (no shutdown)', up);
      });
      el('button', { type: 'submit', class: 'button button-small' }, 'Save interfaces', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = rename(d, name.value, true);
        PORTS.router.forEach(function (pt) {
          if (err) { return; }
          var a = inputs[pt].ip.value.trim(), m = inputs[pt].m.value.trim();
          if (a !== '' && ip2n(a) === null) { err = pt + ': the address should look like 10.0.0.1.'; }
          else if (m !== '' && mask2n(m) === null) { err = pt + ': the mask should look like 255.255.255.0 (or /24).'; }
        });
        if (err) { problem(form, err); return; }
        rename(d, name.value);
        PORTS.router.forEach(function (pt) {
          var mk = mask2n(inputs[pt].m.value);
          d['if'][pt] = { ip: inputs[pt].ip.value.trim(), m: inputs[pt].m.value.trim() === '' ? '' : n2ip(mk), u: inputs[pt].u.checked ? 1 : 0 };
        });
        changed(d.n + ' interfaces saved.');
      });
      deleteButton(panel, d);
    }
    function routesView(panel, d) {
      el('p', { class: 'small muted' }, 'A static route tells ' + d.n + ' where to send traffic for a network it is not connected to: the network, its mask, and the next router’s address on a shared link.', panel);
      var list = el('ul', { class: 'nb-routes' }, null, panel);
      if (!(d.rt || []).length) { el('li', { class: 'muted' }, 'No static routes yet.', list); }
      (d.rt || []).forEach(function (r, i) {
        var li = el('li', null, null, list);
        el('span', { class: 'mono' }, r[0] + ' ' + r[1] + ' via ' + r[2], li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
        x.setAttribute('aria-label', 'Remove the route to ' + r[0]);
        x.addEventListener('click', function () { d.rt.splice(i, 1); changed('Route removed.'); });
      });
      var form = el('form', { class: 'nb-form nb-form-row' }, null, panel);
      var net = field(form, 'nb-rt-net', 'Network', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var m = field(form, 'nb-rt-m', 'Mask', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var via = field(form, 'nb-rt-via', 'Next hop', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Add route', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var r = addRoute(d, net.value, m.value, via.value);
        if (r) { problem(form, r); return; }
        changed('Route added on ' + d.n + '.');
        var again = document.getElementById('nb-rt-net');
        if (again) { again.focus(); }
      });
    }
    function addRoute(d, net, m, via) {
      var n = ip2n(net), mk = mask2n(m), v = ip2n(via);
      if (n === null) { return 'The network should be an address like 10.1.2.0.'; }
      if (mk === null) { return 'The mask should look like 255.255.255.0.'; }
      if (v === null) { return 'The next hop should be an address like 172.16.0.2.'; }
      if ((d.rt || []).length >= 12) { return 'A router here can hold 12 static routes.'; }
      d.rt = d.rt || [];
      d.rt.push([n2ip(and(n, mk)), n2ip(mk), n2ip(v)]);
      return null;
    }
    function portsView(panel, d) {
      var list = el('ul', { class: 'nb-ports' }, null, panel);
      PORTS[d.t].forEach(function (pt) {
        var lk = linkAt(d.i, pt);
        var li = el('li', null, null, list);
        el('span', { class: 'mono nb-port-name' }, pt, li);
        if (!lk) { el('span', { class: 'muted' }, 'free', li); return; }
        var other = byId(lk[0] === d.i ? lk[2] : lk[0]);
        el('span', null, 'to ' + (other ? other.n : '?') + ' ' + (lk[0] === d.i ? lk[3] : lk[1]), li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Unplug', li);
        x.setAttribute('aria-label', 'Unplug ' + pt);
        x.addEventListener('click', function () {
          state.l = state.l.filter(function (l) { return l !== lk; });
          changed('Cable on ' + d.n + ' ' + pt + ' unplugged.');
        });
      });
      var free = freePorts(d);
      var others = state.d.filter(function (x) { return x.i !== d.i && freePorts(x).length; });
      if (!free.length || !others.length) { return; }
      var form = el('form', { class: 'nb-form' }, null, panel);
      el('p', { class: 'nb-form-title' }, 'Connect a cable', form);
      var w1 = el('div', { class: 'nb-field' }, null, form);
      el('label', { for: 'nb-c-from' }, 'From ' + d.n + ' port', w1);
      var s1 = el('select', { id: 'nb-c-from' }, null, w1);
      free.forEach(function (pt) { el('option', { value: pt }, pt, s1); });
      var w2 = el('div', { class: 'nb-field' }, null, form);
      el('label', { for: 'nb-c-to' }, 'To device and port', w2);
      var s2 = el('select', { id: 'nb-c-to' }, null, w2);
      others.forEach(function (x) { freePorts(x).forEach(function (pt) { el('option', { value: x.i + '|' + pt }, x.n + ' ' + pt, s2); }); });
      el('button', { type: 'submit', class: 'button button-small' }, 'Connect', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var to = s2.value.split('|');
        connect(d, s1.value, byId(to[0]), to[1]);
      });
      deleteButton(panel, d);
    }
    function deleteButton(panel, d) {
      var b = el('button', { type: 'button', class: 'linklike small danger-link nb-delete' }, 'Remove ' + d.n, panel);
      var armed = false;
      b.addEventListener('click', function () {
        if (!armed) { armed = true; b.textContent = 'Press again to remove ' + d.n; return; }
        removeDevice(d);
      });
    }

    /* ---- command lines ---- */
    var cli = {};
    function cliView(panel, d) {
      var c = cli[d.i] = cli[d.i] || { lines: d.t === 'router' ? [d.n + ' con0 is now available', '', 'Press RETURN to get started.', ''] : ['Command prompt on ' + d.n + '. Type help to see what you can run.', ''], mode: 'user', iface: null, hist: [], at: 0 };
      var term = el('div', { class: 'nt-term nb-term' }, null, panel);
      var screen = el('pre', { class: 'nt-screen nb-screen', role: 'log', 'aria-live': 'polite', tabindex: '0', 'aria-label': d.n + ' output' }, c.lines.join('\n') + '\n', term);
      var form = el('form', { class: 'nt-line' }, null, term);
      var promptLabel = el('label', { for: 'nb-cmd', class: 'nt-prompt' }, prompt(d, c), form);
      var input = el('input', { id: 'nb-cmd', class: 'nt-input', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false' }, null, form);
      el('button', { type: 'submit', class: 'button button-small' }, 'Run', form);
      screen.scrollTop = screen.scrollHeight;
      var quick = el('div', { class: 'nt-quick', role: 'group', 'aria-label': 'Common commands' }, null, panel);
      (d.t === 'router' ? ['enable', 'configure terminal', 'show ip interface brief', 'show ip route', 'show running-config', 'ping ' + p.server] : ['ipconfig', 'ping ' + p.server, 'tracert ' + p.server, 'arp -a']).forEach(function (q) {
        var b = el('button', { type: 'button', class: 'pc-chip' }, q, quick);
        b.addEventListener('click', function () { run(q); input.focus(); });
      });
      function out(lines) { lines.forEach(function (l) { c.lines.push(l); }); if (c.lines.length > 400) { c.lines = c.lines.slice(-300); } screen.textContent = c.lines.join('\n') + '\n'; screen.scrollTop = screen.scrollHeight; }
      function run(line) {
        if (/^\s*(clear|cls)\s*$/i.test(line)) { c.lines = []; screen.textContent = ''; return; }
        out([prompt(d, c) + line]);
        var before = JSON.stringify(state);
        out(d.t === 'router' ? ios(d, c, line) : shell(d, line));
        promptLabel.textContent = prompt(d, c);
        if (line.trim() !== '') { c.hist.push(line); c.at = c.hist.length; }
        if (JSON.stringify(state) !== before) { save(); draw(); }
      }
      form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value); input.value = ''; });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowUp' && c.at > 0) { c.at--; input.value = c.hist[c.at]; e.preventDefault(); }
        if (e.key === 'ArrowDown') { c.at = Math.min(c.hist.length, c.at + 1); input.value = c.hist[c.at] || ''; e.preventDefault(); }
      });
    }
    function prompt(d, c) {
      if (isHost(d)) { return 'C:\\> '; }
      return d.n + ({ user: '>', priv: '#', config: '(config)#', 'if': '(config-if)#' }[c.mode] || '>') + ' ';
    }
    function shell(d, line) {
      var w = line.trim().split(/\s+/), cmd = (w[0] || '').toLowerCase();
      if (cmd === '') { return []; }
      if (cmd === 'help' || cmd === '?') {
        return ['  ipconfig             this computer’s address, mask and gateway', '  ping <address>       send 4 echo requests', '  tracert <address>    list the routers on the way', '  arp -a               addresses this computer has found on its network', '  clear                clear the screen'];
      }
      if (cmd === 'ipconfig') {
        return ['', 'Ethernet adapter Ethernet (' + d.n + ' Fa0):', '', '   IPv4 Address. . . . . . . . . . : ' + (d.ip || '(not set)'), '   Subnet Mask . . . . . . . . . . : ' + (d.m || '(not set)'), '   Default Gateway . . . . . . . . : ' + (d.g || '(not set)'), '   Cable . . . . . . . . . . . . . : ' + (linkAt(d.i, 'Fa0') ? 'connected' : 'unplugged')];
      }
      if (cmd === 'ping') { return w[1] ? pingLines(d, w[1], false) : ['Usage: ping <address>']; }
      if (cmd === 'tracert' || cmd === 'traceroute') { return w[1] ? traceLines(d, w[1]) : ['Usage: tracert <address>']; }
      if (cmd === 'arp') {
        var sim = new Sim(state), seg = sim.seg(d.i, 'Fa0');
        if (seg === null) { return ['No ARP Entries Found.']; }
        var rows = (sim.onSeg[seg] || []).filter(function (x) { return x.dev !== d.i; }).slice(0, 8);
        if (!rows.length) { return ['No ARP Entries Found.']; }
        return [''].concat(['  Internet Address      Device']).concat(rows.map(function (x) { return '  ' + (n2ip(x.ip) + '                      ').slice(0, 22) + sim.dev[x.dev].n + ' ' + x.port; }));
      }
      return ['“' + w[0] + '” is not a command here. Type help to see what you can run.'];
    }
    function pingLines(d, target, router) {
      var dst = ip2n(target);
      if (dst === null) { return [router ? '% Unrecognized host or address.' : 'Ping request could not find host ' + target + '. Use an IP address.']; }
      var sim = new Sim(state);
      var r = sim.ping(d.i, dst);
      if (router) {
        return ['Type escape sequence to abort.', 'Sending 5, 100-byte ICMP Echos to ' + target + ', timeout is 2 seconds:', r.ok ? '!!!!!' : '.....', 'Success rate is ' + (r.ok ? '100 percent (5/5)' : '0 percent (0/5)'), r.ok ? '' : '(' + r.why + ')'];
      }
      var lines = ['', 'Pinging ' + target + ' with 32 bytes of data:'];
      for (var i = 0; i < 4; i++) {
        if (r.ok) { lines.push('Reply from ' + target + ': bytes=32 time=' + (1 + r.there.hops.length * 2 + i % 2) + 'ms TTL=' + (128 - Math.max(0, r.there.hops.length - 1))); }
        else if (r.there && !r.there.ok && r.there.hops.length === 0) { lines.push(r.src !== undefined && r.src !== null ? 'Reply from ' + n2ip(r.src) + ': Destination host unreachable.' : 'PING: transmit failed. General failure.'); }
        else { lines.push('Request timed out.'); }
      }
      lines.push('', 'Ping statistics for ' + target + ':', '    Packets: Sent = 4, Received = ' + (r.ok ? 4 : (r.there && !r.there.ok && r.there.hops.length === 0 && r.src !== null && r.src !== undefined ? 4 : 0)) + ', Lost = ' + (r.ok ? 0 : (r.there && !r.there.ok && r.there.hops.length === 0 && r.src !== null && r.src !== undefined ? 0 : 4)));
      if (!r.ok) { lines.push('', '(Why: ' + r.why + '.)'); }
      return lines;
    }
    function traceLines(d, target) {
      var dst = ip2n(target);
      if (dst === null) { return ['Unable to resolve target system name ' + target + '.']; }
      var sim = new Sim(state);
      var src = sim.sourceFor(d, dst);
      var lines = ['', 'Tracing route to ' + target + ' over a maximum of 15 hops:', ''];
      if (src === null) { return lines.concat(['  1     *        *        *     ' + d.n + ' has no address.']); }
      for (var ttl = 1; ttl <= 15; ttl++) {
        var w = sim.walk(d.i, dst, ttl);
        var pad = ('  ' + ttl).slice(-3) + '  ';
        var answerer = w.ok ? w.at : (w.expired ? w.at : null);
        var from = w.ok ? dst : (w.expired && w.hops.length ? w.hops[w.hops.length - 1].toIp : null);
        if (answerer === null || from === null) { lines.push(pad + '   *        *        *     Request timed out.  (' + w.why + ')'); return lines.concat(['', 'Trace complete.']); }
        var back = sim.walk(answerer, src);
        if (!back.ok) { lines.push(pad + '   *        *        *     Request timed out.'); continue; }
        lines.push(pad + ' ' + (ttl * 2) + ' ms    ' + (ttl * 2 + 1) + ' ms    ' + (ttl * 2) + ' ms    ' + n2ip(from) + '  [' + sim.dev[answerer].n + ']');
        if (w.ok) { return lines.concat(['', 'Trace complete.']); }
      }
      return lines.concat(['', 'Trace complete.']);
    }
    function portName(word) {
      var m = /^(g|gi|gig|giga|gigabit|gigabitethernet)\s*(\d\/\d)$/i.exec(String(word || '').replace(/\s+/g, ''));
      if (!m) { m = /^(g|gi|gig|gigabitethernet)(\d\/\d)$/i.exec(String(word || '')); }
      return m && PORTS.router.indexOf('Gi' + m[2]) >= 0 ? 'Gi' + m[2] : null;
    }
    function starts(word, full, min) { word = String(word || '').toLowerCase(); return word.length >= (min || 1) && full.indexOf(word) === 0; }
    function ios(d, c, line) {
      var raw = line.trim();
      if (raw === '') { return []; }
      var w = raw.split(/\s+/);
      var k = w.map(function (x) { return x.toLowerCase(); });
      if (k[0] === 'do' && (c.mode === 'config' || c.mode === 'if')) { return iosShow(d, k.slice(1), w.slice(1)); }
      if (k[0] === '?' || k[0] === 'help') {
        return {
          user: ['  enable                      go to privileged mode', '  ping <address>              send echo requests', '  show ip interface brief     interfaces and addresses', '  show ip route               the routing table'],
          priv: ['  configure terminal          change the configuration', '  show ip interface brief', '  show ip route', '  show running-config', '  ping <address>   traceroute <address>', '  disable / exit'],
          config: ['  hostname <name>', '  interface <Gi0/0|Gi0/1|Gi0/2>', '  ip route <network> <mask> <next hop>', '  no ip route <network> <mask> <next hop>', '  do show …   end   exit'],
          'if': ['  ip address <address> <mask>', '  no ip address', '  no shutdown   (bring the interface up)', '  shutdown', '  exit   end']
        }[c.mode];
      }
      if (starts(k[0], 'end', 3)) { c.mode = c.mode === 'user' ? 'user' : 'priv'; c.iface = null; return []; }
      if (starts(k[0], 'exit', 2)) {
        if (c.mode === 'if') { c.mode = 'config'; c.iface = null; } else if (c.mode === 'config') { c.mode = 'priv'; } else { c.mode = 'user'; }
        return [];
      }
      if (c.mode === 'user' || c.mode === 'priv') {
        if (starts(k[0], 'enable', 2)) { c.mode = 'priv'; return []; }
        if (starts(k[0], 'disable', 4)) { c.mode = 'user'; return []; }
        if (starts(k[0], 'ping', 2)) { return w[1] ? pingLines(d, w[1], true) : ['% Incomplete command.']; }
        if (starts(k[0], 'traceroute', 5) || k[0] === 'tracert') { return w[1] ? traceLines(d, w[1]) : ['% Incomplete command.']; }
        if (starts(k[0], 'show', 2)) { return iosShow(d, k, w); }
        if (starts(k[0], 'configure', 4)) {
          if (c.mode !== 'priv') { return ['% Invalid input detected. Type enable first.']; }
          c.mode = 'config';
          return ['Enter configuration commands, one per line.  End with CNTL/Z.'];
        }
        return ['% Invalid input detected at \'^\' marker. Type ? for the commands.'];
      }
      if (c.mode === 'config') {
        if (starts(k[0], 'hostname', 4)) {
          var err = rename(d, w[1]);
          return err ? ['% ' + err] : [];
        }
        if (starts(k[0], 'interface', 3)) {
          var pt = portName(w.slice(1).join(''));
          if (!pt) { return ['% Invalid interface. This router has GigabitEthernet0/0, 0/1 and 0/2.']; }
          c.mode = 'if'; c.iface = pt;
          return [];
        }
        if (k[0] === 'ip' && starts(k[1], 'route', 3)) {
          if (w.length < 5) { return ['% Incomplete command. ip route <network> <mask> <next hop>']; }
          var e = addRoute(d, w[2], w[3], w[4]);
          return e ? ['% ' + e] : [];
        }
        if (k[0] === 'no' && k[1] === 'ip' && starts(k[2], 'route', 3)) {
          var before = (d.rt || []).length;
          d.rt = (d.rt || []).filter(function (r) { return !(ip2n(r[0]) === and(ip2n(w[3]) || 0, mask2n(w[4]) || 0) && r[1] === n2ip(mask2n(w[4]) || 0) && (!w[5] || r[2] === w[5])); });
          return d.rt.length === before ? ['% No matching route to delete.'] : [];
        }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      // interface mode
      var cfg = d['if'][c.iface];
      if (k[0] === 'ip' && starts(k[1], 'address', 3)) {
        var a = ip2n(w[2]), m = mask2n(w[3]);
        if (a === null || m === null) { return ['% Incomplete or invalid. ip address <address> <mask>, for example ip address 10.0.0.1 255.255.255.0']; }
        cfg.ip = n2ip(a); cfg.m = n2ip(m);
        return [];
      }
      if (k[0] === 'no' && k[1] === 'ip' && starts(k[2], 'address', 3)) { cfg.ip = ''; cfg.m = ''; return []; }
      if (k[0] === 'no' && starts(k[1], 'shutdown', 4)) {
        if (cfg.u === 1) { return []; }
        cfg.u = 1;
        return ['', '%LINK-5-CHANGED: Interface GigabitEthernet' + c.iface.slice(2) + ', changed state to up', linkAt(d.i, c.iface) ? '%LINEPROTO-5-UPDOWN: Line protocol on Interface GigabitEthernet' + c.iface.slice(2) + ', changed state to up' : '(No cable is plugged into ' + c.iface + ' yet.)'];
      }
      if (starts(k[0], 'shutdown', 4)) {
        cfg.u = 0;
        return ['', '%LINK-5-CHANGED: Interface GigabitEthernet' + c.iface.slice(2) + ', changed state to administratively down'];
      }
      if (starts(k[0], 'interface', 3)) {
        var p2 = portName(w.slice(1).join(''));
        if (!p2) { return ['% Invalid interface.']; }
        c.iface = p2;
        return [];
      }
      return ['% Invalid input detected. Type ? for the commands.'];
    }
    function iosShow(d, k, w) {
      var what = k.slice(1).join(' ');
      if (/^ip\s+int(erface)?\s+br(ief)?/.test(what) || /^ip\s+int\s+b/.test(what)) {
        var rows = ['Interface              IP-Address      OK? Method Status                Protocol'];
        PORTS.router.forEach(function (pt) {
          var c = d['if'][pt] || {};
          var cabled = !!linkAt(d.i, pt);
          var status = c.u === 1 ? 'up' : 'administratively down';
          var proto = c.u === 1 && cabled ? 'up' : 'down';
          rows.push(('GigabitEthernet' + pt.slice(2) + '                   ').slice(0, 23) + ((c.ip || 'unassigned') + '                ').slice(0, 16) + 'YES manual ' + (status + '                      ').slice(0, 22) + proto);
        });
        return rows;
      }
      if (/^ip\s+ro/.test(what)) {
        var sim = new Sim(state);
        var out = ['Codes: C - connected, L - local, S - static', ''];
        var def = (d.rt || []).filter(function (r) { return r[0] === '0.0.0.0' && r[1] === '0.0.0.0'; })[0];
        out.push(def ? 'Gateway of last resort is ' + def[2] + ' to network 0.0.0.0' : 'Gateway of last resort is not set', '');
        sim.ifaces(d).forEach(function (f) {
          out.push('C    ' + n2ip(and(f.ip, f.mask)) + '/' + prefixOf(f.mask) + ' is directly connected, GigabitEthernet' + f.port.slice(2));
          out.push('L    ' + n2ip(f.ip) + '/32 is directly connected, GigabitEthernet' + f.port.slice(2));
        });
        (d.rt || []).forEach(function (r) { out.push((r[0] === '0.0.0.0' ? 'S*   ' : 'S    ') + r[0] + '/' + prefixOf(mask2n(r[1]) || 0) + ' [1/0] via ' + r[2]); });
        if (out.length === 4) { out.push('(empty: no interface is up with an address, and there are no static routes)'); }
        return out;
      }
      if (/^run/.test(what) || /^start/.test(what)) {
        var cfg = ['Building configuration...', '', 'hostname ' + d.n, '!'];
        PORTS.router.forEach(function (pt) {
          var c = d['if'][pt] || {};
          cfg.push('interface GigabitEthernet' + pt.slice(2), c.ip ? ' ip address ' + c.ip + ' ' + c.m : ' no ip address', c.u === 1 ? ' no shutdown' : ' shutdown', '!');
        });
        (d.rt || []).forEach(function (r) { cfg.push('ip route ' + r[0] + ' ' + r[1] + ' ' + r[2]); });
        return cfg.concat(['!', 'end']);
      }
      return ['% Try: show ip interface brief, show ip route, show running-config'];
    }

    /* ---- simulation (events and animation) ---- */
    function eventsFor(sim, fromId, dst, r) {
      var ev = [];
      var arpDone = {};
      function legs(walk, kind) {
        walk.hops.forEach(function (h) {
          var from = sim.dev[h.from], to = sim.dev[h.to];
          var key = h.from + '>' + h.arpFor;
          var path = sim.physical(h.from, h.out, h.to);
          if (!arpDone[key]) {
            arpDone[key] = true;
            ev.push({ from: from.n, to: 'everyone on the network', kind: 'ARP', text: from.n + ' asks: who has ' + n2ip(h.arpFor) + '?', path: path, cls: 'is-arp' });
            ev.push({ from: to.n, to: from.n, kind: 'ARP', text: to.n + ' answers: ' + n2ip(h.arpFor) + ' is at my ' + h.toPort + '.', path: path.slice().reverse(), cls: 'is-arp' });
          }
          ev.push({ from: from.n, to: to.n, kind: 'ICMP', text: kind + ' for ' + n2ip(dst === null ? 0 : (kind === 'Echo reply' ? r.src : dst)) + (to.t === 'router' && !sim.owns(to, kind === 'Echo reply' ? r.src : dst) ? ', handed to ' + to.n + ' to forward' : ''), path: path, cls: kind === 'Echo reply' ? 'is-reply' : 'is-icmp' });
        });
      }
      if (r.there) { legs(r.there, 'Echo request'); }
      if (r.there && !r.there.ok) { ev.push({ from: sim.dev[r.there.at].n, to: '', kind: 'Dropped', text: 'Dropped: ' + r.there.why + '.', path: [r.there.at], cls: 'is-drop' }); return ev; }
      if (r.back) { legs(r.back, 'Echo reply'); }
      if (r.back && !r.back.ok) { ev.push({ from: sim.dev[r.back.at].n, to: '', kind: 'Dropped', text: 'Reply dropped: ' + r.back.why + '.', path: [r.back.at], cls: 'is-drop' }); return ev; }
      if (r.ok) { ev.push({ from: sim.dev[fromId].n, to: '', kind: 'Done', text: 'The reply arrived. Ping works.', path: [fromId], cls: 'is-done' }); }
      return ev;
    }
    function showEvents(ev) {
      lastEvents = ev;
      evBody.textContent = '';
      ev.forEach(function (e, i) {
        var tr = el('tr', { class: e.cls }, null, evBody);
        el('td', null, String(i + 1), tr);
        el('td', null, e.from, tr);
        el('td', null, e.to || '–', tr);
        el('td', null, e.kind, tr);
        el('td', null, e.text, tr);
      });
      evTable.hidden = ev.length === 0;
      player.hidden = ev.length === 0;
      cursor = 0;
      stop();
      gPk.textContent = '';
    }
    var cursor = 0;
    var reduce = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function stop() { if (playing) { global.cancelAnimationFrame(playing); playing = null; } playBtn.textContent = 'Play'; }
    function mark(i) {
      Array.prototype.forEach.call(evBody.children, function (tr, j) { tr.classList.toggle('is-now', j === i); });
      var row = evBody.children[i];
      if (row && row.scrollIntoView) { row.scrollIntoView({ block: 'nearest' }); }
    }
    function animate(e, done) {
      gPk.textContent = '';
      var pts = e.path.map(function (id) { var d = byId(id); return d ? [d.x, d.y] : null; }).filter(Boolean);
      var pk = S('g', { class: 'nb-pk ' + e.cls }, gPk);
      S('rect', { x: -11, y: -8, width: 22, height: 16, rx: 2 }, pk);
      S('path', { d: 'M-11 -8l11 9l11 -9' }, pk);
      if (pts.length < 2 || reduce) {
        var last = pts[pts.length - 1] || [0, 0];
        pk.setAttribute('transform', 'translate(' + last[0] + ' ' + (last[1] - 40) + ')');
        done();
        return;
      }
      var seg = 0, t0 = null, per = 650;
      function frame(ts) {
        if (t0 === null) { t0 = ts; }
        var f = Math.min(1, (ts - t0) / per);
        var a = pts[seg], b = pts[seg + 1];
        pk.setAttribute('transform', 'translate(' + (a[0] + (b[0] - a[0]) * f) + ' ' + (a[1] + (b[1] - a[1]) * f - 36) + ')');
        if (f < 1) { playing = global.requestAnimationFrame(frame); return; }
        seg++; t0 = null;
        if (seg < pts.length - 1) { playing = global.requestAnimationFrame(frame); return; }
        playing = null;
        done();
      }
      playing = global.requestAnimationFrame(frame);
    }
    function stepOnce(then) {
      if (cursor >= lastEvents.length) { stop(); return; }
      var i = cursor++;
      mark(i);
      L.say('Step ' + (i + 1) + ': ' + lastEvents[i].text);
      animate(lastEvents[i], function () { if (then) { then(); } });
    }
    playBtn.addEventListener('click', function () {
      if (playing || playBtn.textContent === 'Pause') { stop(); return; }
      if (cursor >= lastEvents.length) { cursor = 0; }
      playBtn.textContent = 'Pause';
      (function loop() {
        if (playBtn.textContent !== 'Pause') { return; }
        if (cursor >= lastEvents.length) { stop(); return; }
        stepOnce(function () { global.setTimeout(loop, reduce ? 900 : 250); });
      })();
    });
    stepBtn.addEventListener('click', function () { stop(); stepOnce(null); });
    rewBtn.addEventListener('click', function () { stop(); cursor = 0; mark(-1); gPk.textContent = ''; });

    simForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var from = byId(fromSel.value);
      simOut.textContent = '';
      if (!from) { el('p', { class: 'nb-result is-bad' }, 'Add a PC or router first.', simOut); return; }
      var dst = ip2n(toIn.value);
      if (dst === null) { el('p', { class: 'nb-result is-bad' }, 'Type an address like ' + p.server + '.', simOut); return; }
      var sim = new Sim(state);
      var r = sim.ping(from.i, dst);
      el('p', { class: 'nb-result ' + (r.ok ? 'is-good' : 'is-bad') }, r.ok ? from.n + ' → ' + toIn.value + ': replies arrive.' : from.n + ' → ' + toIn.value + ': no reply. ' + r.why.charAt(0).toUpperCase() + r.why.slice(1) + '.', simOut);
      showEvents(r.there || r.back ? eventsFor(sim, from.i, dst, r) : []);
      if (lastEvents.length) { el('p', { class: 'small muted' }, 'Press Play or Step to watch the packets.', simOut); }
    });
    allBtn.addEventListener('click', function () {
      var sim = new Sim(state);
      var dst = ip2n(p.server);
      simOut.textContent = '';
      var hosts = state.d.filter(function (d) { return d.t === 'pc' || d.t === 'laptop'; });
      if (!hosts.length) { el('p', { class: 'nb-result is-bad' }, 'There are no PCs yet.', simOut); return; }
      var ul = el('ul', { class: 'nb-allping' }, null, simOut);
      hosts.forEach(function (d) {
        var r = sim.ping(d.i, dst);
        el('li', { class: r.ok ? 'is-good' : 'is-bad' }, d.n + ': ' + (r.ok ? 'replies arrive' : 'no reply. ' + r.why.charAt(0).toUpperCase() + r.why.slice(1) + '.'), ul);
      });
      showEvents([]);
    });

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && quickCable) { setQuickCable(false); } });

    save();
    draw();
    renderInspector();
  }

  L.register('network-build', mount);
  L.register('network-repair', mount);
})(typeof window !== 'undefined' ? window : this);
