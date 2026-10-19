/*
 * Packet capture: a small Wireshark-style viewer. A filter, the packet list,
 * the decoded layers of the chosen packet and its bytes. Everything comes
 * from the session's numbers (built on the server, byte by byte); nothing
 * here is sent anywhere. The learner reads it and answers below.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  var KINDS = { ARP: 'pc-arp', DNS: 'pc-dns', TCP: 'pc-tcp', HTTP: 'pc-http' };

  function matches(p, filter) {
    if (!filter) { return true; }
    var f = filter.toLowerCase().trim();
    var port = /^(?:tcp\.|udp\.)?port\s*==\s*(\d+)$/.exec(f);
    if (port) { return p.info.indexOf(port[1]) !== -1 || JSON.stringify(p.layers).indexOf('"' + port[1] + '"') !== -1; }
    var addr = /^ip\.addr\s*==\s*([\d.]+)$/.exec(f);
    if (addr) { return p.src === addr[1] || p.dst === addr[1]; }
    if (f === 'tcp') { return p.proto === 'TCP' || p.proto === 'HTTP'; }
    if (f === 'udp') { return p.proto === 'DNS'; }
    var hay = (p.no + ' ' + p.src + ' ' + p.dst + ' ' + p.proto + ' ' + p.info).toLowerCase();
    return hay.indexOf(f) !== -1;
  }

  function hexDump(hex, pre) {
    pre.textContent = '';
    for (var off = 0; off < hex.length / 2; off += 16) {
      var bytes = [];
      var ascii = '';
      for (var i = off; i < Math.min(off + 16, hex.length / 2); i++) {
        var b = hex.substr(i * 2, 2);
        bytes.push(b);
        var code = parseInt(b, 16);
        ascii += code >= 32 && code < 127 ? String.fromCharCode(code) : '.';
      }
      var line = ('0000' + off.toString(16)).slice(-4) + '  ' + (bytes.slice(0, 8).join(' ') + '  ' + bytes.slice(8).join(' ')).padEnd(49, ' ') + ' ' + ascii + '\n';
      pre.appendChild(document.createTextNode(line));
    }
  }

  L.register('packet-capture', function (root, p) {
    var bench = L.el('div', { class: 'sim sim-capture' }, null, root);
    L.el('p', { class: 'small muted' }, 'Captured on the laptop ' + p.client + ' while someone opened ' + p.host + ' in a browser. Choose a packet to see inside it.', bench);

    var bar = L.el('div', { class: 'pc-bar' }, null, bench);
    L.el('label', { for: 'pc-filter', class: 'pc-filter-label' }, 'Display filter', bar);
    var filter = L.el('input', { id: 'pc-filter', type: 'search', class: 'pc-filter', placeholder: 'dns, tcp, http, arp, ip.addr==…, tcp.port==80', autocomplete: 'off', spellcheck: 'false' }, null, bar);
    var chips = L.el('div', { class: 'pc-chips', role: 'group', 'aria-label': 'Quick filters' }, null, bar);
    ['arp', 'dns', 'tcp', 'http'].forEach(function (f) {
      var b = L.el('button', { type: 'button', class: 'pc-chip' }, f.toUpperCase(), chips);
      b.addEventListener('click', function () { filter.value = filter.value === f ? '' : f; draw(); });
    });
    var count = L.el('span', { class: 'pc-count small', 'aria-live': 'polite' }, '', bar);

    var wrap = L.el('div', { class: 'pc-list-wrap' }, null, bench);
    var table = L.el('table', { class: 'pc-list' }, null, wrap);
    L.el('caption', { class: 'visually-hidden' }, 'Captured packets. Choose one to see its details.', table);
    var hr = L.el('tr', {}, null, L.el('thead', {}, null, table));
    ['No.', 'Time', 'Source', 'Destination', 'Protocol', 'Length', 'Info'].forEach(function (h) { L.el('th', { scope: 'col' }, h, hr); });
    var body = L.el('tbody', {}, null, table);

    var panes = L.el('div', { class: 'pc-panes' }, null, bench);
    var detail = L.el('div', { class: 'pc-detail', 'aria-live': 'polite' }, null, panes);
    var bytes = L.el('pre', { class: 'pc-bytes', 'aria-label': 'Packet bytes in hexadecimal' }, null, panes);

    var selected = null;
    function show(pkt) {
      selected = pkt.no;
      Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
        var on = tr.getAttribute('data-no') === String(pkt.no);
        tr.classList.toggle('is-selected', on);
        tr.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      detail.textContent = '';
      L.el('p', { class: 'pc-frame' }, 'Frame ' + pkt.no + ': ' + pkt.len + ' bytes captured', detail);
      pkt.layers.forEach(function (layer, i) {
        var d = L.el('details', { class: 'pc-layer' }, null, detail);
        if (i === pkt.layers.length - 1) { d.open = true; }
        L.el('summary', {}, layer.name, d);
        var dl = L.el('dl', {}, null, d);
        layer.fields.forEach(function (f) {
          if (f[0]) { L.el('dt', {}, f[0], dl); } else { L.el('dt', { class: 'visually-hidden' }, 'Line', dl); }
          L.el('dd', { class: f[0] ? '' : 'pc-raw' }, f[1], dl);
        });
      });
      hexDump(pkt.hex, bytes);
    }

    function draw() {
      body.textContent = '';
      var shown = 0;
      p.packets.forEach(function (pkt) {
        if (!matches(pkt, filter.value)) { return; }
        shown++;
        var flags = /\[(SYN|FIN|RST)/.test(pkt.info) ? ' pc-flag' : '';
        var tr = L.el('tr', { class: (KINDS[pkt.proto] || '') + flags, tabindex: '0', 'data-no': String(pkt.no), 'aria-selected': 'false' }, null, body);
        [pkt.no, pkt.time, pkt.src, pkt.dst, pkt.proto, pkt.len, pkt.info].forEach(function (c, i) {
          L.el('td', { class: i === 6 ? 'pc-info' : (i === 0 || i === 5 ? 'num' : '') }, c, tr);
        });
        tr.addEventListener('click', function () { show(pkt); });
        tr.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(pkt); }
          if (e.key === 'ArrowDown' && tr.nextElementSibling) { e.preventDefault(); tr.nextElementSibling.focus(); tr.nextElementSibling.click(); }
          if (e.key === 'ArrowUp' && tr.previousElementSibling) { e.preventDefault(); tr.previousElementSibling.focus(); tr.previousElementSibling.click(); }
        });
        if (pkt.no === selected) { tr.classList.add('is-selected'); tr.setAttribute('aria-selected', 'true'); }
      });
      count.textContent = 'Showing ' + shown + ' of ' + p.packets.length;
      Array.prototype.forEach.call(chips.querySelectorAll('button'), function (b) {
        b.setAttribute('aria-pressed', filter.value.toLowerCase() === b.textContent.toLowerCase() ? 'true' : 'false');
      });
    }
    filter.addEventListener('input', draw);
    draw();
    show(p.packets[0]);
    L.el('p', { class: 'small muted sim-legend' }, 'Tip: in the TCP rows, SYN opens a connection, ACK acknowledges, PSH carries data and FIN closes. DNS uses UDP port 53; plain web traffic uses TCP port 80.', bench);
  });
})();
