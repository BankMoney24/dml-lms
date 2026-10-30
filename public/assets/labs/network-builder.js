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
  /** A route's mask: any mask, or 0.0.0.0 (/0) for a default route. */
  function routeMask(s) {
    var v = String(s === null || s === undefined ? '' : s).trim();
    return v === '0.0.0.0' || v === '/0' || v === '0' ? 0 : mask2n(v);
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
   * The network model (same rules as the server's Simulator; VLANs and DHCP: ADR 0038)
   * ------------------------------------------------------------------- */
  function vlanOf(v) {
    if (typeof v === 'string' && /^\d{1,4}$/.test(v)) { v = parseInt(v, 10); }
    return typeof v === 'number' && Math.floor(v) === v && v >= 1 && v <= 4094 ? v : null;
  }
  function poolOf(raw) {
    var start = ip2n(raw && raw.s), mask = mask2n(raw && raw.m);
    if (start === null || mask === null || prefixOf(mask) > 30) { return null; }
    var bcast = and(start, mask) + (4294967295 - mask);
    var room = Math.max(0, bcast - start);
    var c = raw.c;
    var count = (typeof c === 'number' || (typeof c === 'string' && c.trim() !== '' && !isNaN(Number(c)))) ? Math.trunc(Number(c)) : room;
    var name = String(raw.n || '').trim().slice(0, 20);
    return { name: /^[A-Za-z0-9_-]{1,20}$/.test(name) ? name : 'POOL', start: start, mask: mask, gateway: ip2n(raw.g), count: Math.max(1, Math.min(room, count, 1000)) };
  }
  /** Switch port settings: {trunk, vlan}; default access VLAN 1. */
  function portCfg(d, port) {
    var c = (d.sp || {})[port];
    if (!c || !c.length) { return { trunk: false, vlan: 1 }; }
    return { trunk: c[0] === 't', vlan: vlanOf(c[1]) || 1 };
  }
  /** Router subinterfaces, checked and sorted: [{name, parent, vlan, ip, mask, helper}]. */
  function subsOf(d) {
    var out = [];
    Object.keys(d.si || {}).forEach(function (name) {
      if (out.length >= 8) { return; }
      var c = d.si[name], m = /^(Gi0\/[0-2])\.(\d{1,4})$/.exec(name);
      if (!c || typeof c !== 'object' || !m || parseInt(m[2], 10) < 1) { return; }
      var v = vlanOf(c.v);
      if (v === null) { return; }
      out.push({ name: name, parent: m[1], vlan: v, ip: ip2n(c.ip), mask: mask2n(c.m), helper: ip2n(c.h) });
    });
    return out.sort(function (a, b) { return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0); });
  }
  function poolsOf(d) {
    if (d.t !== 'router' && d.t !== 'server') { return []; }
    return (d.pl || []).slice(0, 4).map(poolOf).filter(function (x) { return x !== null; });
  }
  function excludedOf(d) {
    if (d.t !== 'router') { return []; }
    return (d.ex || []).slice(0, 4).map(function (r) {
      var lo = r ? ip2n(r[0]) : null, hi = r ? ip2n(r[1]) : null;
      if (hi === null) { hi = lo; }
      return lo !== null && hi !== null && hi >= lo ? [lo, hi] : null;
    }).filter(function (x) { return x !== null; });
  }
  /** An OSPF area: a whole number up to 4294967295, or dotted ("0.0.0.0"). */
  function areaOf(v) {
    if (typeof v === 'number') { return Math.floor(v) === v && v >= 0 && v <= 4294967295 ? v : null; }
    if (typeof v === 'string' && /^\d{1,10}$/.test(v)) { var n = parseInt(v, 10); return n <= 4294967295 ? n : null; }
    return ip2n(v);
  }
  /** A router's OSPF settings (ADR 0041), checked: {networks:[{net, wildcard, area}], passive:[port], def} or null. */
  function ospfOf(d) {
    if (d.t !== 'router' || !d.os || typeof d.os !== 'object' || Array.isArray(d.os)) { return null; }
    var subs = {};
    subsOf(d).forEach(function (x) { subs[x.name] = true; });
    var nets = [];
    (Array.isArray(d.os.n) ? d.os.n : []).slice(0, 8).forEach(function (n) {
      if (!Array.isArray(n)) { return; }
      var net = ip2n(n[0]), wc = ip2n(n[1]), area = areaOf(n[2]);
      if (net !== null && wc !== null && area !== null) { nets.push({ net: and(net, 4294967295 - wc), wildcard: wc, area: area }); }
    });
    var passive = [];
    (Array.isArray(d.os.pi) ? d.os.pi : []).slice(0, 12).forEach(function (pt) {
      pt = String(pt === null || pt === undefined ? '' : pt).trim().slice(0, 12);
      if ((PORTS.router.indexOf(pt) >= 0 || subs[pt]) && passive.indexOf(pt) < 0) { passive.push(pt); }
    });
    return { networks: nets, passive: passive, def: d.os.di === 1 || d.os.di === '1' || d.os.di === true };
  }
  function isHostAddr(ip, mask) {
    if (prefixOf(mask) >= 31) { return true; }
    var host = ip - and(ip, mask), all = 4294967295 - mask;
    return host !== 0 && host !== all;
  }

  function Sim(state) {
    var self = this;
    this.dev = {};
    this.parent = {};
    this.cabled = {};
    this.onSeg = {};
    this.adj = {};
    this.leases = {};
    this.ospfIfs = {};
    this.nbrs = {};
    this.ospfRt = {};
    var vl = { 1: true };
    state.d.forEach(function (d) {
      self.dev[d.i] = d;
      if (d.t === 'switch') { PORTS['switch'].forEach(function (p) { vl[portCfg(d, p).vlan] = true; }); }
      if (d.t === 'router') { subsOf(d).forEach(function (x) { vl[x.vlan] = true; }); }
    });
    var vlans = Object.keys(vl).map(Number).sort(function (a, b) { return a - b; });
    var nodes = {};
    state.l.forEach(function (l) {
      var ea = self.ends(l[0], l[1], vlans), eb = self.ends(l[2], l[3], vlans);
      [ea, eb].forEach(function (e) { Object.keys(e).forEach(function (k) { nodes[e[k]] = true; self.find(e[k]); }); });
      Object.keys(ea).forEach(function (k) { if (eb[k] !== undefined) { self.union(ea[k], eb[k]); } });
      (self.adj[l[0]] = self.adj[l[0]] || []).push({ port: l[1], to: l[2], toPort: l[3] });
      (self.adj[l[2]] = self.adj[l[2]] || []).push({ port: l[3], to: l[0], toPort: l[1] });
    });
    Object.keys(nodes).forEach(function (node) { self.cabled[node] = self.find(node); });
    state.d.forEach(function (d) {
      if (isHost(d) && d.dh === 1) { return; }
      self.ifaces(d).forEach(function (f) {
        var seg = self.cabled[d.i + ':' + f.port];
        if (seg !== undefined) { (self.onSeg[seg] = self.onSeg[seg] || []).push({ dev: d.i, port: f.port, ip: f.ip, mask: f.mask }); }
      });
    });
    this.runOspf(state);
    this.leaseAll(state);
  }
  /** OSPF interfaces, neighbours and learned routes: the same rules as the server (ADR 0041). */
  Sim.prototype.runOspf = function (state) {
    var self = this, order = [];
    state.d.forEach(function (d) {
      var os = ospfOf(d);
      if (!os) { return; }
      var list = [];
      self.ifaces(d).forEach(function (f) {
        for (var i = 0; i < os.networks.length; i++) {
          var n = os.networks[i];
          if (and(f.ip, 4294967295 - n.wildcard) === n.net) { list.push({ port: f.port, ip: f.ip, mask: f.mask, area: n.area, passive: os.passive.indexOf(f.port) >= 0 }); break; }
        }
      });
      self.ospfIfs[d.i] = list;
      order.push(d.i);
    });
    order.forEach(function (a) {
      self.nbrs[a] = [];
      self.ospfIfs[a].forEach(function (ia) {
        var seg = ia.passive ? null : self.seg(a, ia.port);
        if (seg === null) { return; }
        order.forEach(function (b) {
          if (b === a) { return; }
          self.ospfIfs[b].forEach(function (ib) {
            if (!ib.passive && ib.ip !== ia.ip && ib.mask === ia.mask && ib.area === ia.area && sameNet(ib.ip, ia.ip, ia.mask) && self.seg(b, ib.port) === seg) {
              self.nbrs[a].push({ dev: b, port: ia.port, ip: ib.ip, theirPort: ib.port, area: ia.area });
            }
          });
        });
      });
    });
    order.forEach(function (x) {
      var dist = {}, first = {}, queue = [x], seen = [x];
      dist[x] = 0;
      while (queue.length) {
        var u = queue.shift();
        self.nbrs[u].forEach(function (nb) {
          if (dist[nb.dev] !== undefined) { return; }
          dist[nb.dev] = dist[u] + 1;
          first[nb.dev] = u === x ? { via: nb.ip, port: nb.port } : first[u];
          queue.push(nb.dev);
          seen.push(nb.dev);
        });
      }
      var connected = {};
      self.ifaces(self.dev[x]).forEach(function (f) { connected[and(f.ip, f.mask) + '/' + f.mask] = true; });
      var best = {}, keys = [];
      seen.forEach(function (r) {
        if (r === x) { return; }
        self.ospfIfs[r].forEach(function (i) {
          var key = and(i.ip, i.mask) + '/' + i.mask;
          if (connected[key] || (best[key] && best[key].metric <= dist[r] + 1)) { return; }
          if (!best[key]) { keys.push(key); }
          best[key] = { net: and(i.ip, i.mask), mask: i.mask, via: first[r].via, port: first[r].port, metric: dist[r] + 1, external: false };
        });
        var os = ospfOf(self.dev[r]);
        if (!best['0/0'] && os.def && (self.dev[r].rt || []).slice(0, 12).some(function (rt) { return Array.isArray(rt) && routeMask(rt[1]) === 0 && ip2n(rt[0]) !== null && ip2n(rt[2]) !== null; })) {
          keys.push('0/0');
          best['0/0'] = { net: 0, mask: 0, via: first[r].via, port: first[r].port, metric: 1, external: true };
        }
      });
      self.ospfRt[x] = keys.map(function (k) { return best[k]; });
    });
  };
  /** What one end of a cable carries: {u: node, t10: node, …}. */
  Sim.prototype.ends = function (id, port, vlans) {
    var d = this.dev[id], out = {};
    if (d.t === 'switch') {
      var c = portCfg(d, port);
      out.u = id + '#' + c.vlan;
      if (c.trunk) { vlans.forEach(function (v) { if (v !== c.vlan) { out['t' + v] = id + '#' + v; } }); }
      return out;
    }
    out.u = id + ':' + port;
    if (d.t === 'router') { subsOf(d).forEach(function (x) { if (x.parent === port && out['t' + x.vlan] === undefined) { out['t' + x.vlan] = id + ':' + x.name; } }); }
    return out;
  };
  Sim.prototype.leaseAll = function (state) {
    var self = this, used = {};
    state.d.forEach(function (d) {
      if (isHost(d) && d.dh === 1) { return; }
      self.ifaces(d).forEach(function (f) { used[f.ip] = true; });
    });
    state.d.forEach(function (d) {
      if (!isHost(d) || d.dh !== 1) { return; }
      var seg = self.seg(d.i, 'Fa0');
      if (seg === null) { self.leases[d.i] = 'it has no cable plugged in, so its DHCP request goes nowhere'; return; }
      var found = self.dhcpServer(seg);
      if (typeof found === 'string') { self.leases[d.i] = found; return; }
      var pool = found.pool, ex = excludedOf(self.dev[found.server]), lease = null;
      for (var a = pool.start, last = pool.start + pool.count - 1; a <= last; a++) {
        if (used[a] || !isHostAddr(a, pool.mask) || ex.some(function (r) { return a >= r[0] && a <= r[1]; })) { continue; }
        lease = a;
        break;
      }
      if (lease === null) { self.leases[d.i] = 'the DHCP pool ' + pool.name + ' on ' + self.dev[found.server].n + ' has no free addresses left'; return; }
      used[lease] = true;
      self.leases[d.i] = { ip: lease, mask: pool.mask, gateway: pool.gateway, server: found.server };
      (self.onSeg[seg] = self.onSeg[seg] || []).push({ dev: d.i, port: 'Fa0', ip: lease, mask: pool.mask });
    });
  };
  Sim.prototype.dhcpServer = function (seg) {
    var self = this, here = this.onSeg[seg] || [], i, j;
    for (i = 0; i < here.length; i++) {
      var pools = poolsOf(this.dev[here[i].dev]);
      for (j = 0; j < pools.length; j++) {
        if (and(here[i].ip, pools[j].mask) === and(pools[j].start, pools[j].mask)) { return { server: here[i].dev, pool: pools[j] }; }
      }
    }
    var why = 'no DHCP server answers on its network';
    for (i = 0; i < here.length; i++) {
      var r = this.dev[here[i].dev];
      var helper = r.t === 'router' ? this.helperOf(r, here[i].port) : null;
      if (helper === null) { continue; }
      var there = this.walk(r.i, helper);
      if (!there.ok) { why = 'the DHCP request is relayed by ' + r.n + ' but cannot reach ' + n2ip(helper) + ': ' + there.why; continue; }
      if (!this.walk(there.at, here[i].ip).ok) { why = 'the DHCP server at ' + n2ip(helper) + ' cannot send its answer back to ' + r.n; continue; }
      var sp = poolsOf(this.dev[there.at]);
      for (j = 0; j < sp.length; j++) {
        if (and(here[i].ip, sp[j].mask) === and(sp[j].start, sp[j].mask)) { return { server: there.at, pool: sp[j] }; }
      }
      why = 'the DHCP server at ' + n2ip(helper) + ' has no pool for ' + n2ip(and(here[i].ip, here[i].mask));
    }
    void self;
    return why;
  };
  Sim.prototype.helperOf = function (r, port) {
    var sub = subsOf(r).filter(function (x) { return x.name === port; })[0];
    if (sub) { return sub.helper; }
    var c = (r['if'] || {})[port];
    return c ? ip2n(c.h) : null;
  };
  /** A host's working [ip, mask, gateway]: its own settings, or its DHCP lease. */
  Sim.prototype.addressing = function (d) {
    if (d.dh !== 1) { return [ip2n(d.ip), mask2n(d.m), ip2n(d.g)]; }
    var l = this.leases[d.i];
    return l && typeof l === 'object' ? [l.ip, l.mask, l.gateway] : [null, null, null];
  };
  Sim.prototype.find = function (x) {
    if (this.parent[x] === undefined) { this.parent[x] = x; }
    while (this.parent[x] !== x) { this.parent[x] = this.parent[this.parent[x]]; x = this.parent[x]; }
    return x;
  };
  Sim.prototype.union = function (a, b) { var ra = this.find(a), rb = this.find(b); if (ra !== rb) { this.parent[ra] = rb; } };
  /** Working layer-3 interfaces: [{port, ip, mask}]. */
  Sim.prototype.ifaces = function (d) {
    if (isHost(d)) {
      var a = this.addressing(d);
      return a[0] !== null && a[1] !== null ? [{ port: 'Fa0', ip: a[0], mask: a[1] }] : [];
    }
    var out = [];
    if (d.t === 'router') {
      PORTS.router.forEach(function (p) {
        var c = (d['if'] || {})[p] || {};
        var ip = ip2n(c.ip), m = mask2n(c.m);
        if (c.u === 1 && ip !== null && m !== null) { out.push({ port: p, ip: ip, mask: m }); }
      });
      subsOf(d).forEach(function (x) {
        var c = (d['if'] || {})[x.parent] || {};
        if (c.u === 1 && x.ip !== null && x.mask !== null) { out.push({ port: x.name, ip: x.ip, mask: x.mask }); }
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
      var ad = this.addressing(d), ip = ad[0], m = ad[1], gw = ad[2];
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
      var net = ip2n(r[0]), mk = routeMask(r[1]), via = ip2n(r[2]);
      if (net === null || mk === null || via === null) { return; }
      var p = prefixOf(mk);
      if (and(dst, mk) === and(net, mk) && p > bestP) { best = { kind: 'v', via: via }; bestP = p; }
    });
    (this.ospfRt[d.i] || []).forEach(function (r) {
      var p = prefixOf(r.mask);
      if (and(dst, r.mask) === r.net && p > bestP) { best = { kind: 'v', via: r.via }; bestP = p; }
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
    if (src === null) {
      var lz = this.leases[fromId];
      return { ok: false, why: typeof lz === 'string' ? d.n + ' got no address from DHCP: ' + lz : d.n + ' has no working address to send from', there: null, back: null };
    }
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

  if (typeof module === 'object' && module.exports) { module.exports = { Sim: Sim, ip2n: ip2n, mask2n: mask2n, n2ip: n2ip }; return; }

  /* ---------------------------------------------------------------------
   * The workspace
   * ------------------------------------------------------------------- */
  var L = global.DMLLab;
  var el = L.el, S = L.svg;
  var W = 960, H = 540;

  function mount(root, p) {
    var mode = p.mode === 'repair' || p.mode === 'vlan' || p.mode === 'ospf' ? p.mode : 'build';
    var hasStart = mode !== 'build' && !!p.start;
    var storeKey = 'dml-nb:' + location.pathname + '|' + p.server + '|' + mode;
    var state = load() || (hasStart ? JSON.parse(JSON.stringify(p.start)) : { d: [], l: [] });
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
    } else if (mode === 'vlan') {
      el('p', null, 'In ' + p.site + ', staff and students share one switch. Keep them apart with VLAN ' + p.staffVlan + ' (staff, ' + p.staffNet + ') and VLAN ' + p.studentVlan + ' (students, ' + p.studentNet + '), route between them on R1, and let SRV1 (' + p.server + ') give every PC its address by DHCP.', brief);
    } else if (mode === 'ospf') {
      var sites = p.sites || ['the first site', 'the second', 'the third'];
      el('p', null, sites[0] + ' (' + p.lans[0] + '), ' + sites[1] + ' (' + p.lans[1] + ') and ' + sites[2] + ' (' + p.lans[2] + ', with SRV1 at ' + p.server + ') each have a router, joined in a triangle. Every address is set, but nothing is routed. Turn on OSPF on R1, R2 and R3 so they learn every network, then shut down one link between routers and check that traffic goes the long way round.', brief);
    } else {
      el('p', null, 'People in ' + p.site + ' cannot reach the server SRV1 (' + p.server + '). The network is built, but three things are wrong. Test, find and fix them.', brief);
    }
    var planBox = el('details', { class: 'nb-plan' }, null, brief);
    if (mode !== 'repair') { planBox.setAttribute('open', ''); }
    el('summary', null, 'Addressing plan', planBox);
    var planWrap = el('div', { class: 'table-wrap' }, null, planBox);
    var table = el('table', { class: 'table table-compact nb-plan-table' }, null, planWrap);
    var thead = el('thead', null, null, table);
    var hr = el('tr', null, null, thead);
    (p.planHead || ['Device', 'Interface', 'Address', 'Mask', 'Default gateway']).forEach(function (h) { el('th', { scope: 'col' }, h, hr); });
    var tb = el('tbody', null, null, table);
    p.plan.forEach(function (r) {
      var tr = el('tr', null, null, tb);
      el('th', { scope: 'row' }, r.device, tr);
      el('td', null, r.port, tr);
      var looksLikeAddress = function (v) { return /^\d{1,3}(\.\d{1,3}){3}$/.test(String(v || '')); };
      el('td', looksLikeAddress(r.address) ? { class: 'mono' } : null, r.address, tr);
      el('td', { class: 'mono' }, r.mask || '–', tr);
      el('td', looksLikeAddress(r.gateway) ? { class: 'mono' } : null, r.gateway || '–', tr);
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
    var resetBtn = el('button', { type: 'button', class: 'nb-tool nb-reset' }, hasStart ? 'Start again' : 'Clear all', bar);
    var resetArmed = false;
    resetBtn.addEventListener('click', function () {
      if (!resetArmed) { resetArmed = true; resetBtn.textContent = 'Press again to confirm'; window.setTimeout(function () { resetArmed = false; resetBtn.textContent = hasStart ? 'Start again' : 'Clear all'; }, 4000); return; }
      resetArmed = false;
      state = hasStart ? JSON.parse(JSON.stringify(p.start)) : { d: [], l: [] };
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
    var simNow = null;
    function sim() { if (!simNow) { simNow = new Sim(state); } return simNow; }
    function label(d) {
      if (isHost(d) && d.dh === 1) {
        var ls = sim().leases[d.i];
        return ls && typeof ls === 'object' ? 'DHCP ' + n2ip(ls.ip) : 'DHCP: no address';
      }
      if (isHost(d)) { return d.ip || 'no address'; }
      if (d.t === 'switch') {
        var vs = {};
        PORTS['switch'].forEach(function (pt) { var c = portCfg(d, pt); if (!c.trunk && linkAt(d.i, pt)) { vs[c.vlan] = true; } });
        var nv = Object.keys(vs).length, nc = state.l.filter(function (l) { return l[0] === d.i || l[2] === d.i; }).length;
        return (nv > 1 ? nv + ' VLANs, ' : '') + nc + ' cables';
      }
      if (d.t === 'router') {
        var n = PORTS.router.filter(function (pt) { return ((d['if'] || {})[pt] || {}).ip; }).length + Object.keys(d.si || {}).filter(function (k) { return d.si[k].ip; }).length;
        return n === 0 ? 'not configured' : n + (n === 1 ? ' interface' : ' interfaces');
      }
      return state.l.filter(function (l) { return l[0] === d.i || l[2] === d.i; }).length + ' cables';
    }
    function endTag(d, port) {
      if (d.t === 'switch') { var c = portCfg(d, port); return c.trunk ? ' trunk' : (c.vlan !== 1 ? ' v' + c.vlan : ''); }
      if (d.t === 'router' && subsOf(d).some(function (x) { return x.parent === port; })) { return ' trunk'; }
      return '';
    }
    function drawLinks() {
      gLinks.textContent = '';
      state.l.forEach(function (l) {
        var a = byId(l[0]), b = byId(l[2]);
        if (!a || !b) { return; }
        var up = portUp(a, l[1]) && portUp(b, l[3]);
        var trunk = endTag(a, l[1]) === ' trunk' || endTag(b, l[3]) === ' trunk';
        var g = S('g', { class: 'nb-link' + (up ? '' : ' is-down') + (trunk ? ' is-trunk' : '') }, gLinks);
        S('line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, class: 'nb-wire' }, g);
        [[a, l[1], b], [b, l[3], a]].forEach(function (e) {
          var dx = e[2].x - e[0].x, dy = e[2].y - e[0].y, len = Math.max(1, Math.sqrt(dx * dx + dy * dy));
          S('circle', { cx: e[0].x + dx / len * 40, cy: e[0].y + dy / len * 40, r: 4.5, class: 'nb-light ' + (up ? 'is-up' : 'is-down') }, g);
          // The device's name and summary sit below it (about 55 either side, 30 to 70 down): slide the port name
          // further along the cable until it clears them, but never past the middle of the cable.
          var at = 62;
          while (at < len * 0.45 && Math.abs(dx / len * at) < 78 && dy / len * at - 7 > 18 && dy / len * at - 7 < 82) { at += 4; }
          S('text', { x: e[0].x + dx / len * at, y: e[0].y + dy / len * at - 7, class: 'nb-port', 'text-anchor': 'middle' }, g, e[1] + endTag(e[0], e[1]));
        });
      });
    }
    function draw() {
      simNow = null;
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
      if (t === 'switch') { d.sp = {}; }
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
      var views = isHost(d) ? [['settings', 'IP settings']].concat(d.t === 'server' ? [['dhcp', 'DHCP service']] : []).concat([['cli', 'Command prompt'], ['ports', 'Cables']])
        : d.t === 'router' ? [['settings', 'Interfaces'], ['subs', 'Subinterfaces'], ['routes', 'Routes'], ['ospf', 'OSPF'], ['dhcp', 'DHCP'], ['cli', 'CLI'], ['ports', 'Cables']]
          : [['vlans', 'VLANs'], ['ports', 'Cables'], ['cli', 'CLI'], ['settings', 'Name']];
      if (!views.some(function (v) { return v[0] === tab; })) { tab = views[0][0]; }
      views.forEach(function (v) {
        var b = el('button', { type: 'button', role: 'tab', class: 'nb-tab', 'aria-selected': tab === v[0] ? 'true' : 'false', id: 'nb-tab-' + v[0] }, v[1], tabs);
        b.addEventListener('click', function () { renderInspector(v[0]); var again = document.getElementById('nb-tab-' + v[0]); if (again) { again.focus(); } });
      });
      var panel = el('div', { class: 'nb-panel', role: 'tabpanel', 'aria-labelledby': 'nb-tab-' + tab }, null, inspector);
      if (tab === 'ports') { portsView(panel, d); } else if (tab === 'cli') { cliView(panel, d); } else if (tab === 'routes') { routesView(panel, d); }
      else if (tab === 'ospf') { ospfView(panel, d); }
      else if (tab === 'vlans') { vlansView(panel, d); } else if (tab === 'subs') { subsView(panel, d); } else if (tab === 'dhcp') { dhcpView(panel, d); }
      else if (isHost(d)) { hostView(panel, d); } else if (d.t === 'router') { ifaceView(panel, d); } else { nameView(panel, d); }
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
    function leaseText(d) {
      var ls = sim().leases[d.i];
      if (ls && typeof ls === 'object') { return 'From DHCP (' + sim().dev[ls.server].n + '): ' + n2ip(ls.ip) + ', mask ' + n2ip(ls.mask) + ', gateway ' + (ls.gateway === null ? 'none' : n2ip(ls.gateway)) + '.'; }
      return 'No address from DHCP: ' + (ls || 'save to ask') + '.';
    }
    function hostView(panel, d) {
      var form = el('form', { class: 'nb-form' }, null, panel);
      var name = nameField(form, d);
      var fs = el('fieldset', { class: 'nb-ipmode' }, null, form);
      el('legend', null, 'How it gets its address', fs);
      var rs = el('label', { class: 'choice choice-tight' }, null, fs);
      var rStatic = el('input', { type: 'radio', name: 'nb-ipmode', value: 'static' }, null, rs);
      el('span', null, 'Static (typed in)', rs);
      var rd = el('label', { class: 'choice choice-tight' }, null, fs);
      var rDhcp = el('input', { type: 'radio', name: 'nb-ipmode', value: 'dhcp' }, null, rd);
      el('span', null, 'DHCP (from a server)', rd);
      rStatic.checked = d.dh !== 1; rDhcp.checked = d.dh === 1;
      var ip = field(form, 'nb-ip', 'IP address', d.ip, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var m = field(form, 'nb-mask', 'Subnet mask', d.m, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var g = field(form, 'nb-gw', 'Default gateway', d.g, { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var leaseP = el('p', { class: 'small nb-lease', role: 'status' }, d.dh === 1 ? leaseText(d) : '', form);
      function sync() { [ip, m, g].forEach(function (x) { x.disabled = rDhcp.checked; }); leaseP.hidden = !rDhcp.checked || d.dh !== 1; }
      rStatic.addEventListener('change', sync); rDhcp.addEventListener('change', sync);
      sync();
      el('button', { type: 'submit', class: 'button button-small' }, 'Save settings', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var dhcp = rDhcp.checked;
        var err = rename(d, name.value, true)
          || (!dhcp && ip.value.trim() !== '' && ip2n(ip.value) === null ? 'The IP address should look like 192.168.1.10.' : null)
          || (!dhcp && m.value.trim() !== '' && mask2n(m.value) === null ? 'The mask should look like 255.255.255.0 (or /24).' : null)
          || (!dhcp && g.value.trim() !== '' && ip2n(g.value) === null ? 'The gateway should be an address like 192.168.1.1.' : null);
        if (err) { problem(form, err); return; }
        rename(d, name.value);
        if (dhcp) { d.dh = 1; } else {
          delete d.dh;
          d.ip = ip.value.trim(); d.g = g.value.trim();
          var mk = mask2n(m.value);
          d.m = m.value.trim() === '' ? '' : n2ip(mk);
        }
        changed(d.n + ' settings saved.' + (dhcp ? ' ' + leaseText(d) : ''));
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
          m: field(fs, 'nb-' + id + '-m', 'Mask', c.m, { inputmode: 'decimal', class: 'mono', maxlength: '15' }),
          h: field(fs, 'nb-' + id + '-h', 'Relay DHCP to (optional)', c.h || '', { inputmode: 'decimal', class: 'mono', maxlength: '15' })
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
          else if (inputs[pt].h.value.trim() !== '' && ip2n(inputs[pt].h.value) === null) { err = pt + ': the relay address should be the DHCP server’s address.'; }
        });
        if (err) { problem(form, err); return; }
        rename(d, name.value);
        PORTS.router.forEach(function (pt) {
          var mk = mask2n(inputs[pt].m.value);
          d['if'][pt] = { ip: inputs[pt].ip.value.trim(), m: inputs[pt].m.value.trim() === '' ? '' : n2ip(mk), u: inputs[pt].u.checked ? 1 : 0 };
          if (inputs[pt].h.value.trim() !== '') { d['if'][pt].h = n2ip(ip2n(inputs[pt].h.value)); }
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
      var n = ip2n(net), mk = routeMask(m), v = ip2n(via);
      if (n === null) { return 'The network should be an address like 10.1.2.0.'; }
      if (mk === null) { return 'The mask should look like 255.255.255.0 (or 0.0.0.0 for a default route).'; }
      if (v === null) { return 'The next hop should be an address like 172.16.0.2.'; }
      if ((d.rt || []).length >= 12) { return 'A router here can hold 12 static routes.'; }
      d.rt = d.rt || [];
      d.rt.push([n2ip(and(n, mk)), n2ip(mk), n2ip(v)]);
      return null;
    }
    /** The OSPF tab (ADR 0041): network statements, passive interfaces, what the router learned. */
    function ospfView(panel, d) {
      var on = !!d.os;
      el('p', { class: 'small muted' }, 'OSPF lets routers tell each other which networks they have, so nobody types routes by hand. Each network statement turns OSPF on for the interfaces whose address it matches.', panel);
      var tog = el('label', { class: 'choice choice-tight' }, null, panel);
      var box = el('input', { type: 'checkbox', id: 'nb-os-on' }, null, tog);
      box.checked = on;
      el('span', null, 'Run OSPF on ' + d.n + ' (router ospf 1)', tog);
      box.addEventListener('change', function () {
        if (box.checked) { d.os = d.os || { n: [], pi: [] }; changed('OSPF is on for ' + d.n + '. Add a network statement for each network.'); }
        else { delete d.os; changed('OSPF is off for ' + d.n + '.'); }
      });
      if (!on) { return; }
      el('h4', { class: 'nb-h4' }, 'Network statements', panel);
      var list = el('ul', { class: 'nb-routes' }, null, panel);
      if (!(d.os.n || []).length) { el('li', { class: 'muted' }, 'None yet: no interface runs OSPF.', list); }
      (d.os.n || []).forEach(function (n, i) {
        var li = el('li', null, null, list);
        el('span', { class: 'mono' }, 'network ' + n[0] + ' ' + n[1] + ' area ' + n[2], li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
        x.setAttribute('aria-label', 'Remove network ' + n[0] + ' ' + n[1]);
        x.addEventListener('click', function () { d.os.n.splice(i, 1); changed('Network statement removed.'); });
      });
      var form = el('form', { class: 'nb-form nb-form-row' }, null, panel);
      var net = field(form, 'nb-os-net', 'Network', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var wc = field(form, 'nb-os-wc', 'Wildcard', '', { inputmode: 'decimal', class: 'mono', maxlength: '15', placeholder: '0.0.0.255' });
      var ar = field(form, 'nb-os-area', 'Area', '0', { inputmode: 'numeric', class: 'mono', maxlength: '15' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Add', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var r = addOspfNetwork(d, net.value, wc.value, ar.value);
        if (r) { problem(form, r); return; }
        changed('Network statement added on ' + d.n + '.');
        var again = document.getElementById('nb-os-net');
        if (again) { again.focus(); }
      });
      el('h4', { class: 'nb-h4' }, 'Passive interfaces', panel);
      el('p', { class: 'small muted' }, 'A passive interface still has its network advertised, but sends no OSPF hellos: use it on ports to PCs, where no router listens.', panel);
      var pf = el('div', { class: 'nb-passive', role: 'group', 'aria-label': 'Passive interfaces' }, null, panel);
      PORTS.router.concat(Object.keys(d.si || {}).sort()).forEach(function (pt) {
        var lb = el('label', { class: 'choice choice-tight' }, null, pf);
        var cb = el('input', { type: 'checkbox' }, null, lb);
        cb.checked = (d.os.pi || []).indexOf(pt) >= 0;
        el('span', { class: 'mono' }, pt, lb);
        cb.addEventListener('change', function () {
          d.os.pi = (d.os.pi || []).filter(function (x) { return x !== pt; });
          if (cb.checked) { d.os.pi.push(pt); }
          changed(pt + (cb.checked ? ' is passive.' : ' sends hellos again.'));
        });
      });
      var dl = el('label', { class: 'choice choice-tight' }, null, panel);
      var dcb = el('input', { type: 'checkbox' }, null, dl);
      dcb.checked = d.os.di === 1;
      el('span', null, 'Share this router’s default route (default-information originate)', dl);
      dcb.addEventListener('change', function () { if (dcb.checked) { d.os.di = 1; } else { delete d.os.di; } changed('Saved.'); });
      el('h4', { class: 'nb-h4' }, 'What ' + d.n + ' sees now', panel);
      var sm = sim();
      var nb = sm.nbrs[d.i] || [];
      var ul = el('ul', { class: 'nb-learned' }, null, panel);
      if (!nb.length) { el('li', { class: 'muted' }, 'No neighbours yet.', ul); }
      nb.forEach(function (x) { el('li', { class: 'mono' }, 'Neighbour ' + sm.dev[x.dev].n + ' (' + n2ip(x.ip) + ') on ' + x.port, ul); });
      (sm.ospfRt[d.i] || []).forEach(function (r) { el('li', { class: 'mono' }, 'Learned ' + n2ip(r.net) + '/' + prefixOf(r.mask) + ' via ' + n2ip(r.via) + ' (' + r.port + ', cost ' + r.metric + ')', ul); });
    }
    function addOspfNetwork(d, net, wc, area) {
      var n = ip2n(net), w = ip2n(wc), a = areaOf(String(area || '').trim());
      if (n === null) { return 'The network should be an address like 10.1.2.0.'; }
      if (w === null) { return 'The wildcard is the mask turned inside out: 0.0.0.255 for a /24, 0.0.0.3 for a /30, 0.0.0.0 for one exact address.'; }
      if (a === null) { return 'The area is a number, usually 0.'; }
      d.os = d.os || { n: [], pi: [] };
      d.os.n = d.os.n || [];
      if (d.os.n.length >= 8) { return 'A router here can hold 8 network statements.'; }
      d.os.n.push([n2ip(and(n, 4294967295 - w)), n2ip(w), a]);
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
    /* ---- VLANs, subinterfaces and DHCP (ADR 0038) ---- */
    function vlanName(v) { return 'VLAN' + ('000' + v).slice(-4); }
    /** Sets a switch port; returns a note to show, or null. */
    function setPort(d, port, trunk, vlan) {
      d.sp = d.sp || {};
      var note = null;
      if (!trunk && vlan !== 1 && !(d.vl || {})[vlan]) { d.vl = d.vl || {}; d.vl[vlan] = vlanName(vlan); note = '% Access VLAN does not exist. Creating vlan ' + vlan; }
      if (!trunk && vlan === 1) { delete d.sp[port]; } else { d.sp[port] = [trunk ? 't' : 'a', vlan]; }
      return note;
    }
    function vlansView(panel, d) {
      el('p', { class: 'small muted' }, 'Ports in the same VLAN are one network; ports in different VLANs cannot hear each other. An access port belongs to one VLAN. A trunk carries every VLAN to another switch or a router (its number is the native VLAN, sent untagged).', panel);
      var form = el('form', { class: 'nb-form nb-vlanform' }, null, panel);
      var rows = {};
      var tbl = el('div', { class: 'nb-vlanrows', role: 'group', 'aria-label': 'Port settings' }, null, form);
      PORTS['switch'].forEach(function (pt) {
        var c = portCfg(d, pt), lk = linkAt(d.i, pt), other = lk ? byId(lk[0] === d.i ? lk[2] : lk[0]) : null;
        var row = el('div', { class: 'nb-vlanrow' }, null, tbl);
        var id = 'nb-sp-' + pt.replace('/', '-');
        el('span', { class: 'mono nb-port-name' }, pt, row);
        el('span', { class: 'small muted nb-vlan-to' }, other ? 'to ' + other.n : 'free', row);
        var sel = el('select', { id: id + '-mode', 'aria-label': pt + ' mode' }, null, row);
        el('option', { value: 'a' }, 'Access', sel);
        el('option', { value: 't' }, 'Trunk', sel);
        sel.value = c.trunk ? 't' : 'a';
        var num = el('input', { id: id + '-vlan', type: 'number', min: '1', max: '4094', value: String(c.vlan), class: 'mono', 'aria-label': pt + (c.trunk ? ' native VLAN' : ' VLAN') }, null, row);
        sel.addEventListener('change', function () { num.setAttribute('aria-label', pt + (sel.value === 't' ? ' native VLAN' : ' VLAN')); });
        rows[pt] = { sel: sel, num: num };
      });
      el('button', { type: 'submit', class: 'button button-small' }, 'Save ports', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = null;
        PORTS['switch'].forEach(function (pt) { if (!err && vlanOf(rows[pt].num.value) === null) { err = pt + ': a VLAN is a number from 1 to 4094.'; } });
        if (err) { problem(form, err); return; }
        PORTS['switch'].forEach(function (pt) { setPort(d, pt, rows[pt].sel.value === 't', vlanOf(rows[pt].num.value)); });
        changed(d.n + ' ports saved.');
      });
      el('p', { class: 'nb-form-title' }, 'VLAN names', panel);
      var list = el('ul', { class: 'nb-routes' }, null, panel);
      var names = Object.keys(d.vl || {});
      if (!names.length) { el('li', { class: 'muted' }, 'Only VLAN 1 (default). Name a VLAN to make it easy to recognise.', list); }
      names.forEach(function (v) {
        var li = el('li', null, null, list);
        el('span', { class: 'mono' }, 'VLAN ' + v + ' ' + d.vl[v], li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
        x.setAttribute('aria-label', 'Remove the name of VLAN ' + v);
        x.addEventListener('click', function () { delete d.vl[v]; changed('VLAN ' + v + ' name removed.'); });
      });
      var nf = el('form', { class: 'nb-form nb-form-row' }, null, panel);
      var nv = field(nf, 'nb-vl-id', 'VLAN', '', { type: 'number', min: '2', max: '4094', class: 'mono' });
      var nn = field(nf, 'nb-vl-name', 'Name', '', { maxlength: '20' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Name it', nf);
      nf.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = vlanOf(nv.value), nm = nn.value.trim();
        if (v === null) { problem(nf, 'A VLAN is a number from 1 to 4094.'); return; }
        if (!/^[A-Za-z0-9_-]{1,20}$/.test(nm)) { problem(nf, 'Names use letters, numbers, - and _ (up to 20).'); return; }
        d.vl = d.vl || {};
        if (!d.vl[v] && Object.keys(d.vl).length >= 16) { problem(nf, 'A switch here can name 16 VLANs.'); return; }
        d.vl[v] = nm;
        changed('VLAN ' + v + ' is called ' + nm + '.');
      });
    }
    function subsView(panel, d) {
      el('p', { class: 'small muted' }, 'For a router on a stick: the switch port is a trunk, and ' + d.n + ' has one subinterface per VLAN on the same physical port. Each is the default gateway for its VLAN. The physical port must be up.', panel);
      var list = el('ul', { class: 'nb-routes' }, null, panel);
      var names = Object.keys(d.si || {}).sort();
      if (!names.length) { el('li', { class: 'muted' }, 'No subinterfaces yet.', list); }
      names.forEach(function (nm) {
        var c = d.si[nm];
        var li = el('li', null, null, list);
        el('span', { class: 'mono' }, nm + '  VLAN ' + (vlanOf(c.v) || '?') + '  ' + (c.ip ? c.ip + ' ' + c.m : 'no address') + (c.h ? '  relay ' + c.h : ''), li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
        x.setAttribute('aria-label', 'Remove ' + nm);
        x.addEventListener('click', function () { delete d.si[nm]; changed(nm + ' removed.'); });
      });
      var form = el('form', { class: 'nb-form nb-form-row' }, null, panel);
      var pw = el('div', { class: 'nb-field' }, null, form);
      el('label', { for: 'nb-si-port' }, 'Port', pw);
      var ps = el('select', { id: 'nb-si-port' }, null, pw);
      PORTS.router.forEach(function (pt) { el('option', { value: pt }, pt, ps); });
      var v = field(form, 'nb-si-vlan', 'VLAN', '', { type: 'number', min: '1', max: '4094', class: 'mono' });
      var ip = field(form, 'nb-si-ip', 'IP address', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var m = field(form, 'nb-si-m', 'Mask', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var h = field(form, 'nb-si-h', 'Relay DHCP to (optional)', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Add subinterface', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var vl = vlanOf(v.value), a = ip2n(ip.value), mk = mask2n(m.value), hp = h.value.trim() === '' ? null : ip2n(h.value);
        if (vl === null) { problem(form, 'A VLAN is a number from 1 to 4094.'); return; }
        if (a === null || mk === null) { problem(form, 'Give the subinterface an address and mask, like 172.16.10.1 and 255.255.255.0.'); return; }
        if (h.value.trim() !== '' && hp === null) { problem(form, 'The relay address should be the DHCP server’s address.'); return; }
        var nm = ps.value + '.' + vl;
        d.si = d.si || {};
        if (!d.si[nm] && Object.keys(d.si).length >= 8) { problem(form, 'A router here can have 8 subinterfaces.'); return; }
        d.si[nm] = { ip: n2ip(a), m: n2ip(mk), v: vl };
        if (hp !== null) { d.si[nm].h = n2ip(hp); }
        changed(nm + ' added on ' + d.n + '.' + (((d['if'] || {})[ps.value] || {}).u === 1 ? '' : ' Remember to bring ' + ps.value + ' up.'));
      });
    }
    function poolLine(pl) {
      var x = poolOf(pl);
      return x ? pl.n + ': ' + n2ip(x.start) + ' to ' + n2ip(x.start + x.count - 1) + ', mask ' + n2ip(x.mask) + ', gateway ' + (x.gateway === null ? 'none' : n2ip(x.gateway)) : pl.n + ': not finished (needs a network)';
    }
    function dhcpView(panel, d) {
      el('p', { class: 'small muted' }, d.t === 'server'
        ? 'Turn on DHCP by adding a pool: ' + d.n + ' hands out addresses to computers on its own network that ask, and to other networks through a router’s relay (ip helper-address). Each pool is for one network.'
        : d.n + ' can hand out addresses itself: add a pool for the network of one of its interfaces. Exclude addresses you have given out by hand.', panel);
      var list = el('ul', { class: 'nb-routes' }, null, panel);
      if (!(d.pl || []).length) { el('li', { class: 'muted' }, 'DHCP is off: no pools yet.', list); }
      (d.pl || []).forEach(function (pl, i) {
        var li = el('li', null, null, list);
        el('span', { class: 'mono' }, poolLine(pl), li);
        var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
        x.setAttribute('aria-label', 'Remove the pool ' + pl.n);
        x.addEventListener('click', function () { d.pl.splice(i, 1); changed('Pool removed.'); });
      });
      var form = el('form', { class: 'nb-form nb-form-row' }, null, panel);
      var n = field(form, 'nb-pl-n', 'Pool name', '', { maxlength: '20' });
      var g = field(form, 'nb-pl-g', 'Default gateway', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var st = field(form, 'nb-pl-s', 'Start address', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var m = field(form, 'nb-pl-m', 'Mask', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
      var c = field(form, 'nb-pl-c', 'Maximum users', '50', { type: 'number', min: '1', max: '1000', class: 'mono' });
      el('button', { type: 'submit', class: 'button button-small' }, 'Add pool', form);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var nm = n.value.trim(), a = ip2n(st.value), mk = mask2n(m.value), gw = g.value.trim() === '' ? null : ip2n(g.value), cnt = parseInt(c.value, 10);
        if (!/^[A-Za-z0-9_-]{1,20}$/.test(nm)) { problem(form, 'Give the pool a name: letters, numbers, - and _.'); return; }
        if (a === null || mk === null) { problem(form, 'Give the first address to hand out and the mask, like 172.16.20.100 and 255.255.255.0.'); return; }
        if (g.value.trim() !== '' && gw === null) { problem(form, 'The gateway should be the router’s address on that network.'); return; }
        if (gw !== null && !sameNet(gw, a, mk)) { problem(form, 'The gateway must be on the same network as the addresses.'); return; }
        if (!(cnt >= 1)) { problem(form, 'Maximum users is a number from 1.'); return; }
        d.pl = d.pl || [];
        if (d.pl.length >= 4) { problem(form, 'Here a device can hold 4 pools.'); return; }
        d.pl.push({ n: nm, s: n2ip(a), m: n2ip(mk), g: gw === null ? '' : n2ip(gw), c: Math.min(cnt, 1000) });
        changed('Pool ' + nm + ' added on ' + d.n + '.');
      });
      if (d.t === 'router') {
        el('p', { class: 'nb-form-title' }, 'Excluded addresses', panel);
        var xl = el('ul', { class: 'nb-routes' }, null, panel);
        if (!(d.ex || []).length) { el('li', { class: 'muted' }, 'None.', xl); }
        (d.ex || []).forEach(function (r, i) {
          var li = el('li', null, null, xl);
          el('span', { class: 'mono' }, r[0] + (r[1] && r[1] !== r[0] ? ' to ' + r[1] : ''), li);
          var x = el('button', { type: 'button', class: 'linklike small' }, 'Remove', li);
          x.setAttribute('aria-label', 'Remove the excluded range from ' + r[0]);
          x.addEventListener('click', function () { d.ex.splice(i, 1); changed('Excluded range removed.'); });
        });
        var xf = el('form', { class: 'nb-form nb-form-row' }, null, panel);
        var lo = field(xf, 'nb-ex-lo', 'From', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
        var hi = field(xf, 'nb-ex-hi', 'To (optional)', '', { inputmode: 'decimal', class: 'mono', maxlength: '15' });
        el('button', { type: 'submit', class: 'button button-small' }, 'Exclude', xf);
        xf.addEventListener('submit', function (e) {
          e.preventDefault();
          var r = exclude(d, lo.value, hi.value);
          if (r) { problem(xf, r); return; }
          changed('Addresses excluded on ' + d.n + '.');
        });
      }
      var given = Object.keys(sim().leases).filter(function (id) { var x = sim().leases[id]; return x && typeof x === 'object' && x.server === d.i; });
      el('p', { class: 'nb-form-title' }, 'Addresses handed out', panel);
      var gl = el('ul', { class: 'nb-routes' }, null, panel);
      if (!given.length) { el('li', { class: 'muted' }, 'None yet.', gl); }
      given.forEach(function (id) { el('li', { class: 'mono' }, sim().dev[id].n + '  ' + n2ip(sim().leases[id].ip), gl); });
    }
    function exclude(d, from, to) {
      var a = ip2n(from), b = String(to || '').trim() === '' ? a : ip2n(to);
      if (a === null || b === null || b < a) { return 'Give the first (and last) address to keep out of the pools.'; }
      d.ex = d.ex || [];
      if (d.ex.length >= 4) { return 'Here a router can hold 4 excluded ranges.'; }
      d.ex.push([n2ip(a), n2ip(b)]);
      return null;
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
      var c = cli[d.i] = cli[d.i] || { lines: !isHost(d) ? [d.n + ' con0 is now available', '', 'Press RETURN to get started.', ''] : ['Command prompt on ' + d.n + '. Type help to see what you can run.', ''], mode: 'user', iface: null, ifaces: [], hist: [], at: 0 };
      var term = el('div', { class: 'nt-term nb-term' }, null, panel);
      var screen = el('pre', { class: 'nt-screen nb-screen', role: 'log', 'aria-live': 'polite', tabindex: '0', 'aria-label': d.n + ' output' }, c.lines.join('\n') + '\n', term);
      var form = el('form', { class: 'nt-line' }, null, term);
      var promptLabel = el('label', { for: 'nb-cmd', class: 'nt-prompt' }, prompt(d, c), form);
      var input = el('input', { id: 'nb-cmd', class: 'nt-input', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false' }, null, form);
      el('button', { type: 'submit', class: 'button button-small' }, 'Run', form);
      screen.scrollTop = screen.scrollHeight;
      var quick = el('div', { class: 'nt-quick', role: 'group', 'aria-label': 'Common commands' }, null, panel);
      (d.t === 'router' ? ['enable', 'configure terminal', 'show ip interface brief', 'show ip route'].concat(d.os || p.mode === 'ospf' ? ['show ip ospf neighbor'] : ['show ip dhcp binding']).concat(['show running-config', 'ping ' + p.server])
        : d.t === 'switch' ? ['enable', 'configure terminal', 'show vlan brief', 'show interfaces trunk', 'show running-config']
          : ['ipconfig'].concat(d.dh === 1 ? ['ipconfig /renew'] : []).concat(['ping ' + p.server, 'tracert ' + p.server, 'arp -a'])).forEach(function (q) {
        var b = el('button', { type: 'button', class: 'pc-chip' }, q, quick);
        b.addEventListener('click', function () { run(q); input.focus(); });
      });
      function out(lines) { lines.forEach(function (l) { c.lines.push(l); }); if (c.lines.length > 400) { c.lines = c.lines.slice(-300); } screen.textContent = c.lines.join('\n') + '\n'; screen.scrollTop = screen.scrollHeight; }
      function run(line) {
        if (/^\s*(clear|cls)\s*$/i.test(line)) { c.lines = []; screen.textContent = ''; return; }
        out([prompt(d, c) + line]);
        var before = JSON.stringify(state);
        out(d.t === 'router' ? ios(d, c, line) : d.t === 'switch' ? swios(d, c, line) : shell(d, line));
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
      var ifp = c.mode === 'if' && c.iface && c.iface.indexOf('.') > 0 ? '(config-subif)#' : (c.mode === 'if' && c.ifaces && c.ifaces.length > 1 ? '(config-if-range)#' : '(config-if)#');
      return d.n + ({ user: '>', priv: '#', config: '(config)#', 'if': ifp, vlan: '(config-vlan)#', dhcp: '(dhcp-config)#', router: '(config-router)#' }[c.mode] || '>') + ' ';
    }
    function shell(d, line) {
      var w = line.trim().split(/\s+/), cmd = (w[0] || '').toLowerCase();
      if (cmd === '') { return []; }
      if (cmd === 'help' || cmd === '?') {
        return ['  ipconfig             this computer’s address, mask and gateway', '  ipconfig /renew      ask the DHCP server again', '  ping <address>       send 4 echo requests', '  tracert <address>    list the routers on the way', '  arp -a               addresses this computer has found on its network', '  clear                clear the screen'];
      }
      if (cmd === 'ipconfig') {
        var sw = (w[1] || '').toLowerCase();
        if (sw === '/renew' && d.dh !== 1) { return ['', 'The operation failed: ' + d.n + ' has a static address. Set it to DHCP first.']; }
        var head = sw === '/renew' ? ['', 'Asking for an address (DHCP)…'] : [];
        var cable = '   Cable . . . . . . . . . . . . . : ' + (linkAt(d.i, 'Fa0') ? 'connected' : 'unplugged');
        if (d.dh === 1) {
          var ls = new Sim(state).leases[d.i];
          if (ls && typeof ls === 'object') {
            return head.concat(['', 'Ethernet adapter Ethernet (' + d.n + ' Fa0):', '', '   DHCP Enabled. . . . . . . . . . : Yes', '   IPv4 Address. . . . . . . . . . : ' + n2ip(ls.ip), '   Subnet Mask . . . . . . . . . . : ' + n2ip(ls.mask), '   Default Gateway . . . . . . . . : ' + (ls.gateway === null ? '' : n2ip(ls.gateway)), '   DHCP Server . . . . . . . . . . : ' + new Sim(state).dev[ls.server].n, cable]);
          }
          return head.concat(['', 'Ethernet adapter Ethernet (' + d.n + ' Fa0):', '', '   DHCP Enabled. . . . . . . . . . : Yes', '   Autoconfiguration IPv4 Address. : 169.254.' + (17 + d.i.length) + '.' + (40 + state.d.indexOf(d)), '   Subnet Mask . . . . . . . . . . : 255.255.0.0', '   Default Gateway . . . . . . . . :', cable, '', '(No DHCP answer: ' + (ls || 'no server') + '. A 169.254 address only reaches other computers that also got no answer.)']);
        }
        return head.concat(['', 'Ethernet adapter Ethernet (' + d.n + ' Fa0):', '', '   DHCP Enabled. . . . . . . . . . : No', '   IPv4 Address. . . . . . . . . . : ' + (d.ip || '(not set)'), '   Subnet Mask . . . . . . . . . . : ' + (d.m || '(not set)'), '   Default Gateway . . . . . . . . : ' + (d.g || '(not set)'), cable]);
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
    /** "gi0/0", "g0/1", "gigabitethernet 0/2" (and ".10" for a subinterface) → "Gi0/0" or "Gi0/0.10". */
    function portName(word) {
      var m = /^(g|gi|gig|giga|gigabit|gigabitethernet)(\d\/\d)(\.(\d{1,4}))?$/i.exec(String(word || '').replace(/\s+/g, ''));
      if (!m || PORTS.router.indexOf('Gi' + m[2]) < 0) { return null; }
      if (m[4] !== undefined && parseInt(m[4], 10) < 1) { return null; }
      return 'Gi' + m[2] + (m[4] !== undefined ? '.' + parseInt(m[4], 10) : '');
    }
    /** Switch ports: "fa0/1", "f0/3", "gi0/2" → "Fa0/1"; a range "fa0/1-4" or "fa0/1 - 4" or a list "fa0/1, fa0/5". */
    function swPorts(text) {
      var out = [], bad = false;
      String(text || '').split(',').forEach(function (part) {
        var t = part.replace(/\s+/g, '');
        if (t === '') { return; }
        var m = /^(f|fa|fast|fastethernet|g|gi|gig|gigabitethernet)(\d)\/(\d{1,2})(-(\d{1,2}))?$/i.exec(t);
        if (!m) { bad = true; return; }
        var kind = /^f/i.test(m[1]) ? 'Fa' : 'Gi', from = parseInt(m[3], 10), to = m[5] !== undefined ? parseInt(m[5], 10) : from;
        for (var i = from; i <= to; i++) {
          var pt = kind + m[2] + '/' + i;
          if (PORTS['switch'].indexOf(pt) < 0) { bad = true; return; }
          if (out.indexOf(pt) < 0) { out.push(pt); }
        }
      });
      return bad || !out.length ? null : out;
    }
    function starts(word, full, min) { word = String(word || '').toLowerCase(); return word.length >= (min || 1) && full.indexOf(word) === 0; }
    function longName(pt) { return pt.indexOf('Gi') === 0 ? 'GigabitEthernet' + pt.slice(2) : 'FastEthernet' + pt.slice(2); }
    function ios(d, c, line) {
      var raw = line.trim();
      if (raw === '') { return []; }
      var w = raw.split(/\s+/);
      var k = w.map(function (x) { return x.toLowerCase(); });
      if (k[0] === 'do' && (c.mode === 'config' || c.mode === 'if' || c.mode === 'dhcp' || c.mode === 'router')) { return iosShow(d, k.slice(1), w.slice(1)); }
      if (k[0] === '?' || k[0] === 'help') {
        return {
          user: ['  enable                      go to privileged mode', '  ping <address>              send echo requests', '  show ip interface brief     interfaces and addresses', '  show ip route               the routing table'],
          priv: ['  configure terminal          change the configuration', '  show ip interface brief', '  show ip route', '  show ip ospf neighbor   show ip ospf interface brief   show ip protocols', '  show ip dhcp binding   show ip dhcp pool', '  show running-config', '  ping <address>   traceroute <address>', '  disable / exit'],
          config: ['  hostname <name>', '  interface <Gi0/0|Gi0/1|Gi0/2>', '  interface Gi0/0.<n>         a subinterface (router on a stick)', '  no interface Gi0/0.<n>', '  ip route <network> <mask> <next hop>   (0.0.0.0 0.0.0.0 for a default route)', '  no ip route <network> <mask> <next hop>', '  router ospf <process id>    dynamic routing', '  no router ospf <process id>', '  ip dhcp pool <name>', '  ip dhcp excluded-address <first> [<last>]', '  do show …   end   exit'],
          router: ['  network <network> <wildcard> area <area>   for example network 10.1.1.0 0.0.0.255 area 0', '  no network <network> <wildcard> area <area>', '  passive-interface <interface>   no hellos out of that interface', '  no passive-interface <interface>', '  default-information originate   share this router’s default route', '  router-id <a.b.c.d>', '  exit   end'],
          'if': ['  ip address <address> <mask>', '  no ip address', '  encapsulation dot1Q <vlan>   (subinterfaces)', '  ip helper-address <server>   relay DHCP to a server', '  no ip helper-address', '  no shutdown   (bring the interface up)', '  shutdown', '  exit   end'],
          dhcp: ['  network <network> <mask>', '  default-router <address>', '  exit   end']
        }[c.mode];
      }
      if (starts(k[0], 'end', 3)) { c.mode = c.mode === 'user' ? 'user' : 'priv'; c.iface = null; return []; }
      if (starts(k[0], 'exit', 2)) {
        if (c.mode === 'if' || c.mode === 'dhcp' || c.mode === 'router') { c.mode = 'config'; c.iface = null; c.pool = null; } else if (c.mode === 'config') { c.mode = 'priv'; } else { c.mode = 'user'; }
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
      if (c.mode === 'router') { return iosRouter(d, c, w, k, line); }
      if (c.mode === 'dhcp') {
        var pool = (d.pl || []).filter(function (x) { return x.n === c.pool; })[0];
        if (!pool) { c.mode = 'config'; return ['% The pool was removed.']; }
        if (starts(k[0], 'network', 3)) {
          var na = ip2n(w[1]), nm = mask2n(w[2] || '');
          if (na === null || nm === null || prefixOf(nm) > 30) { return ['% Incomplete or invalid. network <network> <mask>, for example network 172.16.20.0 255.255.255.0']; }
          pool.s = n2ip(and(na, nm) + 1); pool.m = n2ip(nm); delete pool.c;
          return [];
        }
        if (starts(k[0], 'default-router', 9)) {
          var gr = ip2n(w[1]);
          if (gr === null) { return ['% Incomplete command. default-router <address>']; }
          pool.g = n2ip(gr);
          return [];
        }
        if (starts(k[0], 'dns-server', 3) || starts(k[0], 'domain-name', 3) || starts(k[0], 'lease', 3)) { return ['(Accepted. This simulator does not use it.)']; }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      if (c.mode === 'config') {
        if (starts(k[0], 'hostname', 4)) {
          var err = rename(d, w[1]);
          return err ? ['% ' + err] : [];
        }
        if (starts(k[0], 'interface', 3)) {
          var pt = portName(w.slice(1).join(''));
          if (!pt) { return ['% Invalid interface. This router has GigabitEthernet0/0, 0/1 and 0/2 (and subinterfaces like Gi0/0.10).']; }
          if (pt.indexOf('.') > 0) {
            d.si = d.si || {};
            if (!d.si[pt]) {
              if (Object.keys(d.si).length >= 8) { return ['% A router here can have 8 subinterfaces.']; }
              d.si[pt] = { ip: '', m: '', v: 0 };
            }
          }
          c.mode = 'if'; c.iface = pt;
          return [];
        }
        if (k[0] === 'no' && starts(k[1], 'interface', 3)) {
          var sp = portName(w.slice(2).join(''));
          if (!sp || sp.indexOf('.') < 0 || !(d.si || {})[sp]) { return ['% Only a subinterface can be removed, for example no interface Gi0/0.10']; }
          delete d.si[sp];
          return [];
        }
        if (k[0] === 'ip' && starts(k[1], 'route', 3)) {
          if (w.length < 5) { return ['% Incomplete command. ip route <network> <mask> <next hop>']; }
          var e = addRoute(d, w[2], w[3], w[4]);
          return e ? ['% ' + e] : [];
        }
        if (k[0] === 'no' && k[1] === 'ip' && starts(k[2], 'route', 3)) {
          var before = (d.rt || []).length;
          d.rt = (d.rt || []).filter(function (r) { return !(ip2n(r[0]) === and(ip2n(w[3]) || 0, routeMask(w[4]) || 0) && r[1] === n2ip(routeMask(w[4]) || 0) && (!w[5] || r[2] === w[5])); });
          return d.rt.length === before ? ['% No matching route to delete.'] : [];
        }
        if (starts(k[0], 'router', 3)) {
          if (k[1] !== 'ospf') { return ['% This simulator routes with OSPF: router ospf 1']; }
          if (!/^\d{1,5}$/.test(w[2] || '') || parseInt(w[2], 10) < 1) { return ['% Incomplete command. router ospf <process id>, for example router ospf 1']; }
          d.os = d.os || { n: [], pi: [] };
          c.mode = 'router';
          return [];
        }
        if (k[0] === 'no' && starts(k[1], 'router', 3) && k[2] === 'ospf') {
          if (!d.os) { return ['% OSPF is not running.']; }
          delete d.os;
          return [];
        }
        if (k[0] === 'ip' && k[1] === 'dhcp' && starts(k[2], 'pool', 2)) {
          var pn = w[3] || '';
          if (!/^[A-Za-z0-9_-]{1,20}$/.test(pn)) { return ['% Give the pool a name: ip dhcp pool STUDENTS']; }
          d.pl = d.pl || [];
          if (!d.pl.some(function (x) { return x.n === pn; })) {
            if (d.pl.length >= 4) { return ['% Here a router can hold 4 pools.']; }
            d.pl.push({ n: pn, s: '', m: '', g: '' });
          }
          c.mode = 'dhcp'; c.pool = pn;
          return [];
        }
        if (k[0] === 'no' && k[1] === 'ip' && k[2] === 'dhcp' && starts(k[3], 'pool', 2)) {
          var nb = (d.pl || []).length;
          d.pl = (d.pl || []).filter(function (x) { return x.n !== w[4]; });
          return d.pl.length === nb ? ['% No pool called ' + (w[4] || '') + '.'] : [];
        }
        if (k[0] === 'ip' && k[1] === 'dhcp' && starts(k[2], 'excluded-address', 3)) {
          var xe = exclude(d, w[3], w[4]);
          return xe ? ['% ' + xe] : [];
        }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      // interface mode (a physical port or a subinterface)
      var isSub = c.iface.indexOf('.') > 0;
      var cfg = isSub ? (d.si || {})[c.iface] : d['if'][c.iface];
      if (!cfg) { c.mode = 'config'; return ['% That subinterface was removed.']; }
      if (isSub && starts(k[0], 'encapsulation', 5)) {
        var ev = k[1] === 'dot1q' ? vlanOf(w[2]) : null;
        if (ev === null) { return ['% Use encapsulation dot1Q <vlan>, for example encapsulation dot1Q 10']; }
        cfg.v = ev;
        return [];
      }
      if (k[0] === 'ip' && starts(k[1], 'address', 3)) {
        var a = ip2n(w[2]), m = mask2n(w[3]);
        if (a === null || m === null) { return ['% Incomplete or invalid. ip address <address> <mask>, for example ip address 10.0.0.1 255.255.255.0']; }
        if (isSub && vlanOf(cfg.v) === null) { return ['% Configuring IP routing on a LAN subinterface is only allowed if that subinterface is already configured as part of an IEEE 802.1Q vLAN. Type encapsulation dot1Q <vlan> first.']; }
        cfg.ip = n2ip(a); cfg.m = n2ip(m);
        return [];
      }
      if (k[0] === 'no' && k[1] === 'ip' && starts(k[2], 'address', 3)) { cfg.ip = ''; cfg.m = ''; return []; }
      if (k[0] === 'ip' && starts(k[1], 'helper-address', 3)) {
        var hp = ip2n(w[2]);
        if (hp === null) { return ['% ip helper-address <the DHCP server’s address>']; }
        cfg.h = n2ip(hp);
        return [];
      }
      if (k[0] === 'no' && k[1] === 'ip' && starts(k[2], 'helper-address', 3)) { delete cfg.h; return []; }
      if (isSub && (starts(k[0], 'shutdown', 4) || (k[0] === 'no' && starts(k[1], 'shutdown', 4)))) {
        return ['(In this simulator a subinterface is up whenever ' + c.iface.split('.')[0] + ' is up. Use the command on ' + c.iface.split('.')[0] + '.)'];
      }
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
        c.mode = 'config';
        return ios(d, c, line);
      }
      return ['% Invalid input detected. Type ? for the commands.'];
    }
    /** router ospf mode (ADR 0041). */
    function iosRouter(d, c, w, k, line) {
      if (!d.os) { c.mode = 'config'; return ['% OSPF was turned off.']; }
      var neg = k[0] === 'no', a = neg ? 1 : 0;
      if (starts(k[a], 'network', 3)) {
        if (starts(k[a + 3] || '', 'area', 2) === false || w.length < a + 5) { return ['% Incomplete command. network <network> <wildcard> area <area>, for example network 10.1.1.0 0.0.0.255 area 0']; }
        if (!neg) {
          if (mask2n(w[a + 2]) !== null && mask2n(w[a + 2]) !== 4294967295 && w[a + 2] !== '0.0.0.0') {
            return ['% ' + w[a + 2] + ' looks like a mask. OSPF wants a wildcard (the mask turned inside out): for 255.255.255.0 type 0.0.0.255.'];
          }
          var er = addOspfNetwork(d, w[a + 1], w[a + 2], w[a + 4]);
          return er ? ['% ' + er] : [];
        }
        var n = ip2n(w[a + 1]), wc = ip2n(w[a + 2]), before = (d.os.n || []).length;
        d.os.n = (d.os.n || []).filter(function (x) { return !(n !== null && wc !== null && x[0] === n2ip(and(n, 4294967295 - wc)) && x[1] === n2ip(wc) && String(x[2]) === String(areaOf(w[a + 4]))); });
        return d.os.n.length === before ? ['% No matching network statement.'] : [];
      }
      if (starts(k[a], 'passive-interface', 7)) {
        var pt = portName(w.slice(a + 1).join(''));
        if (!pt) { return ['% passive-interface <interface>, for example passive-interface Gi0/0']; }
        d.os.pi = (d.os.pi || []).filter(function (x) { return x !== pt; });
        if (!neg) { d.os.pi.push(pt); }
        return [];
      }
      if (starts(k[a], 'default-information', 9)) {
        if (neg) { delete d.os.di; return []; }
        if (!starts(k[a + 1] || '', 'originate', 4)) { return ['% default-information originate']; }
        d.os.di = 1;
        return (d.rt || []).some(function (r) { return routeMask(r[1]) === 0; }) ? [] : ['(Saved. ' + d.n + ' has no default route yet, so there is nothing to share until you add ip route 0.0.0.0 0.0.0.0 <next hop>.)'];
      }
      if (starts(k[a], 'router-id', 8)) { return ip2n(w[a + 1]) === null && !neg ? ['% router-id <a.b.c.d>'] : ['(Accepted. In this simulator neighbours are found without router IDs.)']; }
      if (starts(k[a], 'log-adjacency-changes', 3) || starts(k[a], 'auto-cost', 4)) { return ['(Accepted. This simulator does not use it.)']; }
      if (starts(k[0], 'interface', 3) || (k[0] === 'ip' && starts(k[1], 'route', 3)) || starts(k[0], 'router', 3) || starts(k[0], 'hostname', 4)) { c.mode = 'config'; return ios(d, c, line); }
      return ['% Invalid input detected. Type ? for the commands.'];
    }
    function ospfLines(d, what) {
      var sm = new Sim(state);
      if (!d.os) { return ['(OSPF is not running on ' + d.n + '. Start it with router ospf 1 in configure terminal.)']; }
      if (/^ip\s+ospf\s+n/.test(what)) {
        var nb = sm.nbrs[d.i] || [];
        var rows = ['Neighbor ID     Pri   State           Dead Time   Address         Interface'];
        nb.forEach(function (x) {
          rows.push((sm.dev[x.dev].n + '                ').slice(0, 16) + '  1   FULL/  -        00:00:35    ' + (n2ip(x.ip) + '                ').slice(0, 16) + 'GigabitEthernet' + x.port.slice(2));
        });
        if (!nb.length) { rows.push('(no neighbours: check that both ends run OSPF on that link, are not passive, and share the network, mask and area)'); }
        return rows;
      }
      if (/^ip\s+ospf\s+int/.test(what)) {
        var ifs = sm.ospfIfs[d.i] || [];
        var out = ['Interface    PID   Area            IP Address/Mask    Cost  State Nbrs F/C'];
        ifs.forEach(function (f) {
          var n = (sm.nbrs[d.i] || []).filter(function (x) { return x.port === f.port; }).length;
          out.push((f.port + '             ').slice(0, 13) + '1     ' + (String(f.area) + '                ').slice(0, 16) + ((n2ip(f.ip) + '/' + prefixOf(f.mask)) + '                   ').slice(0, 19) + '1     ' + (f.passive ? 'PASV ' : (n ? 'DR   ' : 'WAIT ')) + n + '/' + n);
        });
        if (!ifs.length) { out.push('(no interface runs OSPF: no network statement matches a working interface)'); }
        return out;
      }
      var p = ['Routing Protocol is "ospf 1"', '  Routing for Networks:'];
      (d.os.n || []).forEach(function (n) { p.push('    ' + n[0] + ' ' + n[1] + ' area ' + n[2]); });
      if ((d.os.pi || []).length) { p.push('  Passive Interface(s):'); d.os.pi.forEach(function (x) { p.push('    GigabitEthernet' + x.slice(2)); }); }
      p.push('  Distance: (default is 110)');
      return p;
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
          Object.keys(d.si || {}).sort().forEach(function (nm) {
            if (nm.split('.')[0] !== pt) { return; }
            var x = d.si[nm];
            rows.push(('GigabitEthernet' + nm.slice(2) + '                   ').slice(0, 23) + ((x.ip || 'unassigned') + '                ').slice(0, 16) + 'YES manual ' + (status + '                      ').slice(0, 22) + proto);
          });
        });
        return rows;
      }
      if (/^ip\s+(ospf|proto)/.test(what)) { return ospfLines(d, what); }
      if (/^ip\s+ro/.test(what)) {
        var sm = new Sim(state);
        var out = ['Codes: C - connected, L - local, S - static, O - OSPF, E2 - OSPF external type 2', ''];
        var def = (d.rt || []).filter(function (r) { return r[0] === '0.0.0.0' && r[1] === '0.0.0.0'; })[0];
        var odef = (sm.ospfRt[d.i] || []).filter(function (r) { return r.mask === 0; })[0];
        out.push(def ? 'Gateway of last resort is ' + def[2] + ' to network 0.0.0.0' : (odef ? 'Gateway of last resort is ' + n2ip(odef.via) + ' to network 0.0.0.0' : 'Gateway of last resort is not set'), '');
        sm.ifaces(d).forEach(function (f) {
          out.push('C    ' + n2ip(and(f.ip, f.mask)) + '/' + prefixOf(f.mask) + ' is directly connected, GigabitEthernet' + f.port.slice(2));
          out.push('L    ' + n2ip(f.ip) + '/32 is directly connected, GigabitEthernet' + f.port.slice(2));
        });
        (d.rt || []).forEach(function (r) { out.push((r[0] === '0.0.0.0' ? 'S*   ' : 'S    ') + r[0] + '/' + prefixOf(routeMask(r[1]) || 0) + ' [1/0] via ' + r[2]); });
        (sm.ospfRt[d.i] || []).forEach(function (r) {
          var shadow = (d.rt || []).some(function (x) { return ip2n(x[0]) === r.net && routeMask(x[1]) === r.mask; });
          if (!shadow) { out.push((r.external ? 'O*E2 ' : 'O    ') + n2ip(r.net) + '/' + prefixOf(r.mask) + ' [110/' + (r.external ? 1 : r.metric) + '] via ' + n2ip(r.via) + ', 00:01:12, GigabitEthernet' + r.port.slice(2)); }
        });
        if (out.length === 4) { out.push('(empty: no interface is up with an address, and there are no routes)'); }
        return out;
      }
      if (/^ip\s+dhcp\s+b/.test(what)) {
        var sb = new Sim(state), lines = ['IP address       Client          Lease expiration        Type'];
        Object.keys(sb.leases).forEach(function (id) {
          var x = sb.leases[id];
          if (x && typeof x === 'object' && x.server === d.i) { lines.push((n2ip(x.ip) + '                 ').slice(0, 17) + (sb.dev[id].n + '                ').slice(0, 16) + 'Infinite                Automatic'); }
        });
        if (lines.length === 1) { lines.push('(no bindings: no computer has an address from this router)'); }
        return lines;
      }
      if (/^ip\s+dhcp\s+p/.test(what)) {
        if (!(d.pl || []).length) { return ['(no DHCP pools)']; }
        return (d.pl || []).map(function (pl) { return 'Pool ' + poolLine(pl); });
      }
      if (/^run/.test(what) || /^start/.test(what)) {
        var cfg = ['Building configuration...', '', 'hostname ' + d.n, '!'];
        (d.ex || []).forEach(function (r) { cfg.push('ip dhcp excluded-address ' + r[0] + (r[1] && r[1] !== r[0] ? ' ' + r[1] : '')); });
        (d.pl || []).forEach(function (pl) {
          var x = poolOf(pl);
          cfg.push('ip dhcp pool ' + pl.n);
          if (x) { cfg.push(' network ' + n2ip(and(x.start, x.mask)) + ' ' + n2ip(x.mask)); }
          if (pl.g) { cfg.push(' default-router ' + pl.g); }
          cfg.push('!');
        });
        PORTS.router.forEach(function (pt) {
          var c = d['if'][pt] || {};
          cfg.push('interface GigabitEthernet' + pt.slice(2), c.ip ? ' ip address ' + c.ip + ' ' + c.m : ' no ip address');
          if (c.h) { cfg.push(' ip helper-address ' + c.h); }
          cfg.push(c.u === 1 ? ' no shutdown' : ' shutdown', '!');
          Object.keys(d.si || {}).sort().forEach(function (nm) {
            if (nm.split('.')[0] !== pt) { return; }
            var x = d.si[nm];
            cfg.push('interface GigabitEthernet' + nm.slice(2));
            if (vlanOf(x.v) !== null) { cfg.push(' encapsulation dot1Q ' + x.v); }
            cfg.push(x.ip ? ' ip address ' + x.ip + ' ' + x.m : ' no ip address');
            if (x.h) { cfg.push(' ip helper-address ' + x.h); }
            cfg.push('!');
          });
        });
        if (d.os) {
          cfg.push('router ospf 1');
          (d.os.pi || []).forEach(function (x) { cfg.push(' passive-interface GigabitEthernet' + x.slice(2)); });
          (d.os.n || []).forEach(function (n) { cfg.push(' network ' + n[0] + ' ' + n[1] + ' area ' + n[2]); });
          if (d.os.di === 1) { cfg.push(' default-information originate'); }
          cfg.push('!');
        }
        (d.rt || []).forEach(function (r) { cfg.push('ip route ' + r[0] + ' ' + r[1] + ' ' + r[2]); });
        return cfg.concat(['!', 'end']);
      }
      return ['% Try: show ip interface brief, show ip route, show ip ospf neighbor, show ip dhcp binding, show running-config'];
    }
    /** The switch's command line (ADR 0038): VLANs, access ports and trunks. */
    function swios(d, c, line) {
      var raw = line.trim();
      if (raw === '') { return []; }
      var w = raw.split(/\s+/);
      var k = w.map(function (x) { return x.toLowerCase(); });
      if (k[0] === 'do' && c.mode !== 'user' && c.mode !== 'priv') { return swShow(d, k.slice(1)); }
      if (k[0] === '?' || k[0] === 'help') {
        return {
          user: ['  enable                      go to privileged mode', '  show vlan brief', '  show interfaces trunk'],
          priv: ['  configure terminal          change the configuration', '  show vlan brief', '  show interfaces trunk', '  show running-config', '  disable / exit'],
          config: ['  hostname <name>', '  vlan <number>               make (or open) a VLAN', '  no vlan <number>', '  interface <Fa0/1 … Fa0/8 | Gi0/1 | Gi0/2>', '  interface range Fa0/1 - 4', '  do show …   end   exit'],
          'if': ['  switchport mode access', '  switchport access vlan <number>', '  switchport mode trunk', '  switchport trunk native vlan <number>', '  exit   end'],
          vlan: ['  name <name>', '  exit   end']
        }[c.mode];
      }
      if (starts(k[0], 'end', 3)) { c.mode = c.mode === 'user' ? 'user' : 'priv'; c.ifaces = []; return []; }
      if (starts(k[0], 'exit', 2)) {
        if (c.mode === 'if' || c.mode === 'vlan') { c.mode = 'config'; c.ifaces = []; } else if (c.mode === 'config') { c.mode = 'priv'; } else { c.mode = 'user'; }
        return [];
      }
      if (c.mode === 'user' || c.mode === 'priv') {
        if (starts(k[0], 'enable', 2)) { c.mode = 'priv'; return []; }
        if (starts(k[0], 'disable', 4)) { c.mode = 'user'; return []; }
        if (starts(k[0], 'show', 2)) { return swShow(d, k); }
        if (starts(k[0], 'configure', 4)) {
          if (c.mode !== 'priv') { return ['% Invalid input detected. Type enable first.']; }
          c.mode = 'config';
          return ['Enter configuration commands, one per line.  End with CNTL/Z.'];
        }
        if (starts(k[0], 'ping', 2)) { return ['(This switch has no IP address of its own here. Ping from a PC or a router.)']; }
        return ['% Invalid input detected at \'^\' marker. Type ? for the commands.'];
      }
      if (c.mode === 'vlan') {
        if (starts(k[0], 'name', 2)) {
          if (!/^[A-Za-z0-9_-]{1,20}$/.test(w[1] || '')) { return ['% Names use letters, numbers, - and _ (up to 20).']; }
          d.vl = d.vl || {};
          d.vl[c.vlan] = w[1];
          return [];
        }
        if (starts(k[0], 'vlan', 2) || starts(k[0], 'interface', 3)) { c.mode = 'config'; return swios(d, c, line); }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      if (c.mode === 'config') {
        if (starts(k[0], 'hostname', 4)) {
          var err = rename(d, w[1]);
          return err ? ['% ' + err] : [];
        }
        if (starts(k[0], 'vlan', 2)) {
          var v = vlanOf(w[1]);
          if (v === null) { return ['% Use vlan <number>, from 1 to 4094.']; }
          d.vl = d.vl || {};
          if (v !== 1 && !d.vl[v]) {
            if (Object.keys(d.vl).length >= 16) { return ['% A switch here can hold 16 VLANs.']; }
            d.vl[v] = vlanName(v);
          }
          c.mode = 'vlan'; c.vlan = v;
          return [];
        }
        if (k[0] === 'no' && starts(k[1], 'vlan', 2)) {
          var nv = vlanOf(w[2]);
          if (nv === null || nv === 1) { return ['% Default VLAN 1 may not be deleted.']; }
          delete (d.vl || {})[nv];
          var still = PORTS['switch'].filter(function (pt) { var pc = portCfg(d, pt); return !pc.trunk && pc.vlan === nv; });
          return still.length ? ['(Ports ' + still.join(', ') + ' are still set to VLAN ' + nv + '.)'] : [];
        }
        if (starts(k[0], 'interface', 3)) {
          var rest = k[1] && starts(k[1], 'range', 1) && w.length > 2 ? w.slice(2).join(' ') : w.slice(1).join(' ');
          var list = swPorts(rest);
          if (!list) { return ['% Invalid interface. This switch has FastEthernet0/1 to 0/8 and GigabitEthernet0/1 and 0/2.']; }
          c.mode = 'if'; c.ifaces = list;
          return [];
        }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      // interface mode: one port or a range
      if (starts(k[0], 'switchport', 5)) {
        var notes = [];
        if (starts(k[1], 'mode', 2)) {
          if (starts(k[2], 'access', 2)) { c.ifaces.forEach(function (pt) { var pc = portCfg(d, pt); setPort(d, pt, false, pc.trunk ? 1 : pc.vlan); }); return []; }
          if (starts(k[2], 'trunk', 2)) { c.ifaces.forEach(function (pt) { var pc = portCfg(d, pt); setPort(d, pt, true, pc.trunk ? pc.vlan : 1); }); return []; }
          return ['% Use switchport mode access or switchport mode trunk.'];
        }
        if (starts(k[1], 'access', 2) && starts(k[2], 'vlan', 2)) {
          var av = vlanOf(w[3]);
          if (av === null) { return ['% Use switchport access vlan <number>.']; }
          c.ifaces.forEach(function (pt) {
            if (portCfg(d, pt).trunk) { notes.push('(' + pt + ' is a trunk, so it carries every VLAN. To put it in VLAN ' + av + ', first: switchport mode access.)'); return; }
            var note = setPort(d, pt, false, av);
            if (note && notes.indexOf(note) < 0) { notes.push(note); }
          });
          return notes;
        }
        if (starts(k[1], 'trunk', 2) && starts(k[2], 'native', 2) && starts(k[3], 'vlan', 2)) {
          var tv = vlanOf(w[4]);
          if (tv === null) { return ['% Use switchport trunk native vlan <number>.']; }
          c.ifaces.forEach(function (pt) { setPort(d, pt, true, tv); });
          return [];
        }
        if (starts(k[1], 'trunk', 2) && starts(k[2], 'allowed', 2)) { return ['(Accepted. In this simulator a trunk carries every VLAN.)']; }
        if (starts(k[1], 'trunk', 2) && starts(k[2], 'encapsulation', 2)) { return []; }
        return ['% Invalid input detected. Type ? for the commands.'];
      }
      if (k[0] === 'no' && starts(k[1], 'switchport', 5) && starts(k[2], 'access', 2)) { c.ifaces.forEach(function (pt) { var pc = portCfg(d, pt); if (!pc.trunk) { setPort(d, pt, false, 1); } }); return []; }
      if (starts(k[0], 'shutdown', 4) || (k[0] === 'no' && starts(k[1], 'shutdown', 4))) { return ['(Switch ports are always up in this simulator.)']; }
      if (starts(k[0], 'description', 4) || starts(k[0], 'spanning-tree', 4)) { return ['(Accepted. This simulator does not use it.)']; }
      if (starts(k[0], 'interface', 3) || starts(k[0], 'vlan', 2)) { c.mode = 'config'; return swios(d, c, line); }
      return ['% Invalid input detected. Type ? for the commands.'];
    }
    function swShow(d, k) {
      var what = k.slice(1).join(' ');
      if (/^vl/.test(what)) {
        var used = { 1: true };
        Object.keys(d.vl || {}).forEach(function (v) { used[v] = true; });
        PORTS['switch'].forEach(function (pt) { var pc = portCfg(d, pt); if (!pc.trunk) { used[pc.vlan] = true; } });
        var rows = ['VLAN Name                             Status    Ports', '---- -------------------------------- --------- -------------------------------'];
        Object.keys(used).map(Number).sort(function (a, b) { return a - b; }).forEach(function (v) {
          var ports = PORTS['switch'].filter(function (pt) { var pc = portCfg(d, pt); return !pc.trunk && pc.vlan === v; });
          var nm = v === 1 ? 'default' : ((d.vl || {})[v] || vlanName(v));
          rows.push((String(v) + '     ').slice(0, 5) + (nm + '                                 ').slice(0, 33) + 'active    ' + ports.join(', '));
        });
        return rows;
      }
      if (/^int\w*\s+tr/.test(what)) {
        var tr = PORTS['switch'].filter(function (pt) { return portCfg(d, pt).trunk; });
        if (!tr.length) { return ['(no trunk ports: every port is an access port)']; }
        var out = ['Port        Mode         Encapsulation  Status        Native vlan'];
        tr.forEach(function (pt) { out.push((pt + '            ').slice(0, 12) + 'on           802.1q         ' + ((linkAt(d.i, pt) ? 'trunking' : 'not-connect') + '              ').slice(0, 14) + portCfg(d, pt).vlan); });
        out.push('', 'Port        Vlans allowed on trunk');
        tr.forEach(function (pt) { out.push((pt + '            ').slice(0, 12) + '1-4094'); });
        return out;
      }
      if (/^run/.test(what) || /^start/.test(what)) {
        var cfg = ['Building configuration...', '', 'hostname ' + d.n, '!'];
        Object.keys(d.vl || {}).sort(function (a, b) { return a - b; }).forEach(function (v) { cfg.push('vlan ' + v, ' name ' + d.vl[v], '!'); });
        PORTS['switch'].forEach(function (pt) {
          var pc = portCfg(d, pt);
          cfg.push('interface ' + longName(pt));
          if (pc.trunk) { cfg.push(' switchport mode trunk'); if (pc.vlan !== 1) { cfg.push(' switchport trunk native vlan ' + pc.vlan); } }
          else if (pc.vlan !== 1) { cfg.push(' switchport access vlan ' + pc.vlan, ' switchport mode access'); }
          cfg.push('!');
        });
        return cfg.concat(['end']);
      }
      return ['% Try: show vlan brief, show interfaces trunk, show running-config'];
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
  L.register('network-vlan', mount);
  L.register('network-ospf', mount);
})(typeof window !== 'undefined' ? window : this);
