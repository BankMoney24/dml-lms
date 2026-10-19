/*
 * Trace the route: a small routed network simulated in the browser, with a
 * terminal on PC1. Packets are walked hop by hop with longest-prefix
 * matching on each router's table, TTL counted down, and replies only
 * arriving if the way back works too. The learner finds the fault; the
 * answer is marked on the server.
 */
(function (global) {
  'use strict';

  function n(ip) { return ip.split('.').reduce(function (a, o) { return a * 256 + parseInt(o, 10); }, 0); }
  function inNet(ip, cidr) {
    var parts = cidr.split('/');
    var bits = parseInt(parts[1], 10);
    var size = Math.pow(2, 32 - bits);
    return Math.floor(n(ip) / size) === Math.floor(n(parts[0]) / size);
  }
  function prefix(cidr) { return parseInt(cidr.split('/')[1], 10); }

  /** The network model: who owns which address, and how each router forwards. */
  function Net(p) {
    var self = this;
    this.p = p;
    this.owner = {};
    Object.keys(p.interfaces).forEach(function (r) {
      p.interfaces[r].forEach(function (i) { self.owner[i[1]] = r; });
    });
    this.owner[p.pc.ip] = 'PC1';
    this.owner[p.server.ip] = 'SRV';
  }

  /** Longest-prefix match: the route a router uses for dst, or null. */
  Net.prototype.lookup = function (router, dst) {
    var best = null;
    (this.p.routes[router] || []).forEach(function (r) {
      if (inNet(dst, r[0]) && (best === null || prefix(r[0]) > prefix(best[0]))) { best = r; }
    });
    return best;
  };

  /** Which LAN a host sits on, and the address of its gateway there. */
  Net.prototype.hostGateway = function (dev) {
    if (dev === 'PC1') { return this.p.pc.gateway; }
    if (dev === 'SRV') { return this.p.interfaces.R3[1][1]; }
    return null;
  };

  /**
   * Walks one packet from a device towards dst with a starting TTL.
   * Returns { result: delivered | ttl | net | host | nogw, at, from (address that would reply), hops: [router…] }.
   */
  Net.prototype.walk = function (fromDev, dst, ttl) {
    var hops = [];
    var dev = fromDev;
    var ingress = null;
    if (this.owner[dst] === fromDev) { return { result: 'delivered', at: fromDev, from: dst, hops: hops }; }
    if (dev === 'PC1' || dev === 'SRV') {
      var lan = dev === 'PC1' ? this.p.nets.A : this.p.nets.B;
      if (inNet(dst, lan)) {
        return this.owner[dst] ? { result: 'delivered', at: this.owner[dst], from: dst, hops: hops } : { result: 'nogw', at: dev, from: dev === 'PC1' ? this.p.pc.ip : this.p.server.ip, hops: hops };
      }
      var gw = this.hostGateway(dev);
      var gwDev = this.owner[gw];
      if (!gwDev || gwDev === 'PC1' || gwDev === 'SRV' || !inNet(gw, lan)) {
        return { result: 'nogw', at: dev, from: dev === 'PC1' ? this.p.pc.ip : this.p.server.ip, hops: hops };
      }
      dev = gwDev;
      ingress = gw;
    }
    for (var guard = 0; guard < 300; guard++) {
      // A router: the packet arrives (TTL goes down), then is forwarded.
      if (this.owner[dst] === dev) { return { result: 'delivered', at: dev, from: dst, hops: hops }; }
      ttl -= 1;
      hops.push(dev);
      if (ttl <= 0) { return { result: 'ttl', at: dev, from: ingress, hops: hops }; }
      var route = this.lookup(dev, dst);
      if (!route) { return { result: 'net', at: dev, from: ingress, hops: hops }; }
      if (route[1] === 'connected') {
        var target = this.owner[dst];
        if (target === 'SRV' || target === 'PC1') { return { result: 'delivered', at: target, from: dst, hops: hops }; }
        if (target) { return { result: 'delivered', at: target, from: dst, hops: hops }; }
        return { result: 'host', at: dev, from: ingress, hops: hops };
      }
      var next = this.owner[route[1]];
      if (!next || next === 'PC1' || next === 'SRV') { return { result: 'host', at: dev, from: ingress, hops: hops }; }
      dev = next;
      ingress = route[1];
    }
    return { result: 'ttl', at: dev, from: ingress, hops: hops };
  };

  /** Would a reply from this device reach PC1? */
  Net.prototype.reachesPc = function (dev) {
    if (dev === 'PC1') { return true; }
    var w = this.walk(dev, this.p.pc.ip, 64);
    return w.result === 'delivered' && w.at === 'PC1';
  };

  Net.prototype.resolve = function (name) {
    var s = String(name || '').trim().toLowerCase();
    if (s === this.p.server.name || s === 'server' || s === 'results') { return this.p.server.ip; }
    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(s)) { return s; }
    return null;
  };

  Net.prototype.label = function (ip) {
    var d = this.owner[ip];
    if (d === 'SRV') { return this.p.server.name + ' [' + ip + ']'; }
    if (d && d !== 'PC1') { return d.toLowerCase() + '.school.lan [' + ip + ']'; }
    return ip;
  };

  Net.prototype.ping = function (target) {
    var dst = this.resolve(target);
    if (!dst) { return ['Ping request could not find host ' + target + '. Please check the name and try again.']; }
    var out = ['', 'Pinging ' + (dst === target ? dst : target + ' [' + dst + ']') + ' with 32 bytes of data:'];
    var w = this.walk('PC1', dst, 128);
    var ok = 0;
    for (var i = 0; i < 4; i++) {
      if (w.result === 'delivered') {
        if (this.reachesPc(w.at)) { ok++; out.push('Reply from ' + dst + ': bytes=32 time=' + (2 + w.hops.length * 3 + i) + 'ms TTL=' + (64 - w.hops.length)); } else { out.push('Request timed out.'); }
      } else if (w.result === 'nogw') {
        out.push('Reply from ' + this.p.pc.ip + ': Destination host unreachable.');
      } else if (this.reachesPc(w.at)) {
        out.push('Reply from ' + w.from + ': ' + (w.result === 'ttl' ? 'TTL expired in transit.' : (w.result === 'net' ? 'Destination net unreachable.' : 'Destination host unreachable.')));
      } else {
        out.push('Request timed out.');
      }
    }
    out.push('', 'Ping statistics for ' + dst + ':', '    Packets: Sent = 4, Received = ' + (w.result === 'delivered' ? ok : (w.result === 'nogw' || this.reachesPc(w.at) ? 4 : 0)) + ', Lost = ' + (w.result === 'delivered' ? 4 - ok : (w.result === 'nogw' || this.reachesPc(w.at) ? 0 : 4)));
    return out;
  };

  Net.prototype.tracert = function (target) {
    var dst = this.resolve(target);
    if (!dst) { return ['Unable to resolve target system name ' + target + '.']; }
    var MAX = 15;
    var out = ['', 'Tracing route to ' + (dst === target ? dst : target + ' [' + dst + ']'), 'over a maximum of ' + MAX + ' hops:', ''];
    for (var ttl = 1; ttl <= MAX; ttl++) {
      var w = this.walk('PC1', dst, ttl);
      var pad = ('  ' + ttl).slice(-3) + '  ';
      if (w.result === 'nogw') {
        out.push(pad + this.p.pc.ip + ' reports: Destination host unreachable.', '', 'Trace complete.');
        return out;
      }
      var back = this.reachesPc(w.at);
      var ms = function (k) { return ('    ' + (1 + ttl * 2 + k) + ' ms').slice(-7); };
      var times = ms(0) + ' ' + ms(1) + ' ' + ms(0);
      if (!back) {
        out.push(pad + '     *        *        *     Request timed out.');
        continue;
      }
      if (w.result === 'delivered') {
        out.push(pad + times + '  ' + this.label(dst), '', 'Trace complete.');
        return out;
      }
      if (w.result === 'ttl') { out.push(pad + times + '  ' + this.label(w.from)); continue; }
      out.push(pad + times + '  ' + this.label(w.from) + ' reports: Destination ' + (w.result === 'net' ? 'net' : 'host') + ' unreachable.', '', 'Trace complete.');
      return out;
    }
    out.push('', 'Trace complete. (The target did not answer within ' + MAX + ' hops.)');
    return out;
  };

  Net.prototype.showRoute = function (r) {
    var routes = this.p.routes[r];
    if (!routes) { return ['% Unknown device "' + r + '". Try R1, R2, R3 or R4.']; }
    var def = routes.filter(function (x) { return x[0] === '0.0.0.0/0'; })[0];
    var out = [r + '# show ip route', 'Codes: C - connected, S - static, * - candidate default', ''];
    out.push(def ? 'Gateway of last resort is ' + def[1] + ' to network 0.0.0.0' : 'Gateway of last resort is not set', '');
    routes.slice().sort(function (a, b) { return prefix(b[0]) - prefix(a[0]); }).forEach(function (x) {
      if (x[1] === 'connected') { out.push('C     ' + x[0] + ' is directly connected, ' + x[2]); } else { out.push((x[0] === '0.0.0.0/0' ? 'S*    ' : 'S     ') + x[0] + ' [1/0] via ' + x[1] + ', ' + x[2]); }
    });
    return out;
  };

  Net.prototype.showInterfaces = function (r) {
    var list = this.p.interfaces[r];
    if (!list) { return ['% Unknown device "' + r + '". Try R1, R2, R3 or R4.']; }
    var out = [r + '# show ip interface brief', 'Interface   IP-Address        Network              Status'];
    list.forEach(function (i) { out.push((i[0] + '      ').slice(0, 12) + (i[1] + '                ').slice(0, 18) + (i[2] + '                    ').slice(0, 21) + 'up'); });
    return out;
  };

  Net.prototype.ipconfig = function () {
    return ['', 'Windows IP Configuration', '', 'Ethernet adapter Ethernet:', '',
      '   Physical Address. . . . . . . . : ' + this.p.pc.mac.toUpperCase().replace(/:/g, '-'),
      '   IPv4 Address. . . . . . . . . . : ' + this.p.pc.ip,
      '   Subnet Mask . . . . . . . . . . : ' + this.p.pc.mask,
      '   Default Gateway . . . . . . . . : ' + this.p.pc.gateway];
  };

  Net.prototype.arp = function () {
    var gw = this.p.pc.gateway;
    if (this.owner[gw] !== 'R1') { return ['No ARP Entries Found.']; }
    return ['', 'Interface: ' + this.p.pc.ip + ' --- 0x7', '  Internet Address      Physical Address      Type', '  ' + (gw + '                      ').slice(0, 22) + this.p.r1mac.replace(/:/g, '-') + '     dynamic'];
  };

  Net.prototype.run = function (line) {
    var words = String(line).trim().split(/\s+/);
    var cmd = (words[0] || '').toLowerCase();
    var arg = words.slice(1).join(' ');
    var dev = (words[words.length - 1] || '').toUpperCase();
    if (cmd === '') { return []; }
    if (cmd === 'help' || cmd === '?') {
      return ['Commands you can run from PC1:',
        '  ipconfig                    this PC’s address, mask and gateway',
        '  ping <address or name>      e.g. ping ' + this.p.server.name,
        '  tracert <address or name>   the routers a packet passes',
        '  arp -a                      addresses this PC has found on its LAN',
        '  show ip route <R1-R4>       a router’s routing table',
        '  show interfaces <R1-R4>     a router’s interfaces and addresses',
        '  clear                       clear the screen'];
    }
    if (cmd === 'ipconfig') { return this.ipconfig(); }
    if (cmd === 'ping') { return arg ? this.ping(words[1]) : ['Usage: ping <address or name>']; }
    if (cmd === 'tracert' || cmd === 'traceroute') { return arg ? this.tracert(words[1]) : ['Usage: tracert <address or name>']; }
    if (cmd === 'arp') { return this.arp(); }
    if (cmd === 'nslookup') {
      var ip = this.resolve(words[1]);
      return ip && ip !== words[1] ? ['Server:  dns.school.lan', '', 'Name:    ' + this.p.server.name, 'Address:  ' + ip] : ['*** dns.school.lan can’t find ' + (words[1] || '') + ': Non-existent domain'];
    }
    if (cmd === 'show' && /route/i.test(arg)) { return this.showRoute(dev); }
    if (cmd === 'show' && /int/i.test(arg)) { return this.showInterfaces(dev); }
    return ['“' + words[0] + '” is not a command here. Type help to see what you can run.'];
  };

  if (typeof module === 'object' && module.exports) { module.exports = Net; return; }

  var L = global.DMLLab;

  function diagram(svgParent, p) {
    var S = L.svg;
    var svg = S('svg', { viewBox: '0 0 760 280', class: 'nt-map', role: 'img', 'aria-label': 'Network map: PC1 on LAN A connects to R1, then R2. R2 connects to R3, which has the results server on LAN B, and to R4, which has the library LAN C.' }, svgParent);
    var nodes = {
      PC1: [50, 90, 'PC1', p.pc.ip], R1: [200, 90, 'R1', 'school edge'], R2: [370, 90, 'R2', 'core'],
      R3: [540, 90, 'R3', 'data centre'], SRV: [700, 90, 'Server', p.server.ip], R4: [370, 205, 'R4', 'library']
    };
    var links = [['PC1', 'R1', p.nets.A], ['R1', 'R2', p.nets.L12], ['R2', 'R3', p.nets.L23], ['R3', 'SRV', p.nets.B], ['R2', 'R4', p.nets.L24]];
    links.forEach(function (l) {
      var a = nodes[l[0]], b = nodes[l[1]];
      S('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: 'nt-link' }, svg);
      var tx = (a[0] + b[0]) / 2, ty = (a[1] + b[1]) / 2;
      S('text', { x: tx + (a[0] === b[0] ? 8 : 0), y: ty + (a[0] === b[0] ? 4 : -10), class: 'nt-net', 'text-anchor': a[0] === b[0] ? 'start' : 'middle' }, svg, l[2]);
    });
    S('text', { x: 370, y: 272, class: 'nt-net', 'text-anchor': 'middle' }, svg, 'LAN C ' + p.nets.C);
    Object.keys(nodes).forEach(function (k) {
      var v = nodes[k];
      var g = S('g', { class: 'nt-node nt-' + (k.charAt(0) === 'R' ? 'router' : 'host') }, svg);
      if (k.charAt(0) === 'R') { S('circle', { cx: v[0], cy: v[1], r: 22 }, g); } else { S('rect', { x: v[0] - 26, y: v[1] - 18, width: 52, height: 36, rx: 4 }, g); }
      S('text', { x: v[0], y: v[1] + 5, 'text-anchor': 'middle', class: 'nt-name' }, g, v[2]);
      S('text', { x: v[0], y: v[1] + 40, 'text-anchor': 'middle', class: 'nt-sub' }, g, v[3]);
    });
  }

  L.register('network-trace', function (root, p) {
    var net = new Net(p);
    var bench = L.el('div', { class: 'sim sim-trace' }, null, root);
    L.el('p', { class: 'small muted' }, 'PC1 cannot open the results server at ' + p.server.name + '. Investigate from PC1’s command prompt. You can also look at each router’s table.', bench);
    diagram(bench, p);

    var term = L.el('div', { class: 'nt-term' }, null, bench);
    var screen = L.el('pre', { class: 'nt-screen', role: 'log', 'aria-live': 'polite', tabindex: '0', 'aria-label': 'Command output' }, null, term);
    var form = L.el('form', { class: 'nt-line' }, null, term);
    L.el('label', { for: 'nt-cmd', class: 'nt-prompt' }, 'C:\\> ', form);
    var input = L.el('input', { id: 'nt-cmd', class: 'nt-input', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-describedby': 'nt-help' }, null, form);
    L.el('button', { type: 'submit', class: 'button button-small' }, 'Run', form);
    L.el('p', { id: 'nt-help', class: 'small muted' }, 'Type help to see the commands. Up and down arrows bring back earlier commands.', bench);

    var quick = L.el('div', { class: 'nt-quick', role: 'group', 'aria-label': 'Common commands' }, null, bench);
    ['ipconfig', 'ping ' + p.server.name, 'tracert ' + p.server.name, 'arp -a', 'show ip route R1', 'show ip route R2', 'show ip route R3', 'show ip route R4'].forEach(function (c) {
      var b = L.el('button', { type: 'button', class: 'pc-chip' }, c, quick);
      b.addEventListener('click', function () { exec(c); input.focus(); });
    });

    var history = [], at = 0;
    function print(lines) {
      lines.forEach(function (l) { screen.appendChild(document.createTextNode(l + '\n')); });
      screen.scrollTop = screen.scrollHeight;
    }
    function exec(line) {
      if (line.trim().toLowerCase() === 'clear' || line.trim().toLowerCase() === 'cls') { screen.textContent = ''; return; }
      print(['C:\\> ' + line]);
      print(net.run(line));
      if (line.trim() !== '') { history.push(line); at = history.length; }
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      exec(input.value);
      input.value = '';
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' && at > 0) { at--; input.value = history[at]; e.preventDefault(); }
      if (e.key === 'ArrowDown') { at = Math.min(history.length, at + 1); input.value = history[at] || ''; e.preventDefault(); }
    });
    print(['Microsoft Windows [school lab image]', 'Type help to see what you can run.', '']);
  });
})(typeof window !== 'undefined' ? window : this);
