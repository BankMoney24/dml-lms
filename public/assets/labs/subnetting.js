/*
 * Subnetting: the address and the mask in binary, with the network part
 * and the host part shaded, plus a small decimal-to-binary converter. The
 * learner does the AND and the counting.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  function octets(ip) { return ip.split('.').map(function (o) { return parseInt(o, 10); }); }
  function bits(n) { return ('00000000' + n.toString(2)).slice(-8); }
  function maskOctets(prefix) {
    var out = [];
    for (var i = 0; i < 4; i++) {
      var take = Math.max(0, Math.min(8, prefix - i * 8));
      out.push(take === 0 ? 0 : (0xFF << (8 - take)) & 0xFF);
    }
    return out;
  }

  L.register('subnetting', function (root, p) {
    var mask = maskOctets(p.prefix);
    var bench = L.el('div', { class: 'sim sim-subnet' }, null, root);

    var big = L.el('div', { class: 'sim-ip' }, null, bench);
    L.el('p', { class: 'sim-ip-main' }, p.ip + '/' + p.prefix, big);
    L.el('p', { class: 'small muted' }, 'Mask: ' + mask.join('.') + '. The first ' + p.prefix + ' bits are the network part; the other ' + (32 - p.prefix) + ' bits are for hosts.', big);

    var tableWrap = L.el('div', { class: 'table-wrap sim-bits-wrap' }, null, bench);
    var table = L.el('table', { class: 'sim-bits' }, null, tableWrap);
    L.el('caption', { class: 'visually-hidden' }, 'Addresses in binary; shaded bits are the network part', table);
    var head = L.el('tr', {}, null, L.el('thead', {}, null, table));
    L.el('th', { scope: 'col' }, '', head);
    for (var o = 1; o <= 4; o++) { L.el('th', { scope: 'col' }, 'Octet ' + o, head); }
    var body = L.el('tbody', {}, null, table);
    function row(label, values) {
      var tr = L.el('tr', {}, null, body);
      L.el('th', { scope: 'row' }, label, tr);
      values.forEach(function (v, i) {
        var td = L.el('td', {}, null, tr);
        var b = bits(v);
        for (var j = 0; j < 8; j++) {
          var position = i * 8 + j;
          L.el('span', { class: 'bit ' + (position < p.prefix ? 'bit-net' : 'bit-host') }, b[j], td);
        }
        L.el('span', { class: 'bit-dec' }, '= ' + v, td);
      });
    }
    row('Address', octets(p.ip));
    row('Mask', mask);
    row('Second device', octets(p.other));
    L.el('p', { class: 'small muted sim-legend' }, 'Shaded: network bits. Plain: host bits. Network address = address AND mask (keep network bits, set host bits to 0). Broadcast = network bits kept, host bits all 1.', bench);

    var conv = L.el('div', { class: 'sim-control sim-converter' }, null, bench);
    L.el('label', { for: 'sub-dec' }, 'Convert a number (0 to 255) to binary', conv);
    var dec = L.el('input', { type: 'number', id: 'sub-dec', min: '0', max: '255', inputmode: 'numeric' }, null, conv);
    var out = L.el('output', { for: 'sub-dec', class: 'sim-output' }, '—', conv);
    dec.addEventListener('input', function () {
      var n = parseInt(dec.value, 10);
      out.textContent = n >= 0 && n <= 255 ? bits(n) : '—';
    });
  });
})();
