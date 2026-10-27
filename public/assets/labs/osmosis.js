/*
 * Osmosis in potato strips (ADR 0035): weigh a strip, leave it 30 minutes in
 * a sucrose solution, weigh it again; the table and graph give the percentage
 * change in mass against concentration.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('osmosis', function (root, p) {
    var readings = [];
    var conc = 0.3;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function change(c) { var d = p.iso - c; return 30 * d / (1 + 1.5 * Math.abs(d)) + (Math.random() - 0.5) * 0.8; }

    var bench = L.el('div', { class: 'sim sim-osmosis' }, null, root);
    var fig = L.el('figure', { class: 'sim-figure' }, null, bench);
    var s = L.svg('svg', { viewBox: '0 0 320 240', role: 'img', 'aria-labelledby': 'osm-desc', class: 'sim-svg' }, fig);
    L.svg('desc', { id: 'osm-desc' }, s, 'A beaker of sucrose solution with a potato strip in it, and a balance beside it.');
    L.svg('path', { d: 'M40 60 V200 Q40 214 54 214 H146 Q160 214 160 200 V60', class: 'sim-glass' }, s);
    var liquid = L.svg('rect', { x: 42, y: 100, width: 116, height: 112, class: 'osm-liquid' }, s);
    var strip = L.svg('rect', { x: 88, y: 116, width: 24, height: 80, rx: 3, class: 'osm-strip' }, s);
    var concLabel = L.svg('text', { x: 100, y: 50, class: 'sim-label', 'text-anchor': 'middle' }, s, '');
    L.svg('rect', { x: 200, y: 170, width: 100, height: 40, rx: 6, class: 'sim-meter' }, s);
    L.svg('rect', { x: 210, y: 160, width: 80, height: 10, class: 'sim-meter' }, s);
    var scale = L.svg('text', { x: 250, y: 197, class: 'sim-label', 'text-anchor': 'middle' }, s, '0.00 g');
    var clock = L.svg('text', { x: 250, y: 60, class: 'sim-label', 'text-anchor': 'middle' }, s, '');
    L.el('figcaption', { class: 'small muted' }, 'Each strip is cut fresh from the same potato, blotted dry before each weighing.', fig);

    var panel = L.el('div', { class: 'sim-panel' }, null, bench);
    var ctl = L.el('div', { class: 'sim-control' }, null, panel);
    L.el('label', { for: 'osm-conc' }, 'Sucrose solution', ctl);
    var sel = L.el('select', { id: 'osm-conc' }, null, ctl);
    for (var i = 0; i <= 10; i++) { L.el('option', { value: String(i / 10) }, L.fixed(i / 10, 1) + ' M', sel); }
    sel.value = '0.3';
    var runBtn = L.el('button', { type: 'button', class: 'button button-small' }, 'Weigh, soak 30 minutes, weigh again', panel);
    var status = L.el('p', { class: 'sim-status', 'aria-live': 'polite' }, '', panel);

    var results = L.el('div', { class: 'sim-results' }, null, root);
    var tw = L.el('div', { class: 'sim-table' }, null, results);
    L.el('h3', null, 'Readings', tw);
    var table = L.el('table', { class: 'table table-compact' }, null, tw);
    var hr = L.el('tr', null, null, L.el('thead', null, null, table));
    [['Sucrose (M)', 'num'], ['Before (g)', 'num'], ['After (g)', 'num'], ['Change (%)', 'num'], ['', '']].forEach(function (h) { L.el('th', { scope: 'col', class: h[1] }, h[0], hr); });
    var body = L.el('tbody', null, null, table);
    var empty = L.el('p', { class: 'small muted' }, 'No readings yet.', tw);
    var gw = L.el('figure', { class: 'sim-graph' }, null, results);
    var g = L.svg('svg', { viewBox: '0 0 300 236', role: 'img', 'aria-label': 'Graph of percentage change in mass against sucrose concentration', class: 'sim-svg' }, gw);
    L.el('figcaption', { class: 'small muted' }, 'Change in mass (%) against concentration (M). Where would a line through your points cross zero?', gw);

    function graph() {
      while (g.firstChild) { g.removeChild(g.firstChild); }
      var x0 = 40, top = 20, w = 240, h = 170, max = 20;
      var zero = top + h / 2;
      for (var k = 0; k <= 10; k += 2) {
        L.svg('line', { x1: x0 + w * k / 10, y1: top, x2: x0 + w * k / 10, y2: top + h, class: 'sim-grid' }, g);
        L.svg('text', { x: x0 + w * k / 10, y: top + h + 14, class: 'sim-tick', 'text-anchor': 'middle' }, g, L.fixed(k / 10, 1));
      }
      [-20, -10, 0, 10, 20].forEach(function (v) {
        var y = zero - v / max * h / 2;
        L.svg('line', { x1: x0, y1: y, x2: x0 + w, y2: y, class: v === 0 ? 'sim-axis' : 'sim-grid' }, g);
        L.svg('text', { x: x0 - 6, y: y + 4, class: 'sim-tick', 'text-anchor': 'end' }, g, (v > 0 ? '+' : '') + v);
      });
      L.svg('line', { x1: x0, y1: top, x2: x0, y2: top + h, class: 'sim-axis' }, g);
      L.svg('text', { x: x0 + w / 2, y: top + h + 30, class: 'sim-tick', 'text-anchor': 'middle' }, g, 'Sucrose (M)');
      readings.forEach(function (r) {
        var pc = (r[2] - r[1]) / r[1] * 100;
        L.svg('circle', { cx: x0 + w * r[0], cy: zero - Math.max(-max, Math.min(max, pc)) / max * h / 2, r: 4, class: 'sim-point' }, g);
      });
    }
    function render() {
      body.textContent = '';
      readings.forEach(function (r, i) {
        var tr = L.el('tr', null, null, body);
        L.el('td', { class: 'num' }, L.fixed(r[0], 1), tr);
        L.el('td', { class: 'num' }, L.fixed(r[1], 2), tr);
        L.el('td', { class: 'num' }, L.fixed(r[2], 2), tr);
        var pc = (r[2] - r[1]) / r[1] * 100;
        L.el('td', { class: 'num' }, (pc > 0 ? '+' : '') + L.fixed(pc, 1), tr);
        var del = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Remove reading ' + (i + 1) }, 'Remove', L.el('td', null, null, tr));
        del.addEventListener('click', function () { readings.splice(i, 1); render(); });
      });
      empty.hidden = readings.length > 0;
      L.answer('readings').value = JSON.stringify(readings);
      graph();
    }
    function show(c) {
      concLabel.textContent = L.fixed(c, 1) + ' M sucrose';
      liquid.setAttribute('class', 'osm-liquid osm-c' + Math.round(c * 10));
    }
    sel.addEventListener('change', function () { conc = parseFloat(sel.value); show(conc); });
    runBtn.addEventListener('click', function () {
      if (readings.length >= 20) { status.textContent = 'You have 20 readings; remove one first.'; return; }
      var c = conc;
      var before = Math.round((2 + Math.random()) * 100) / 100;
      var after = Math.round(before * (1 + change(c) / 100) * 100) / 100;
      runBtn.disabled = true;
      scale.textContent = L.fixed(before, 2) + ' g';
      status.textContent = 'Strip weighs ' + L.fixed(before, 2) + ' g. Soaking for 30 minutes…';
      var start = null, dur = reduce ? 0 : 2200;
      var grow = after / before;
      function frame(ts) {
        if (start === null) { start = ts; }
        var f = dur === 0 ? 1 : Math.min(1, (ts - start) / dur);
        clock.textContent = Math.round(f * 30) + ' min';
        var k = 1 + (grow - 1) * f * 2.5;
        strip.setAttribute('width', String(24 * k));
        strip.setAttribute('x', String(100 - 12 * k));
        if (f < 1) { window.requestAnimationFrame(frame); return; }
        scale.textContent = L.fixed(after, 2) + ' g';
        readings.push([c, before, after]);
        render();
        runBtn.disabled = false;
        status.textContent = 'After 30 minutes in ' + L.fixed(c, 1) + ' M: ' + L.fixed(after, 2) + ' g. Recorded.';
        L.say(status.textContent);
      }
      window.requestAnimationFrame(frame);
    });
    show(conc);
    render();
  });
})();
