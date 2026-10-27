/*
 * Simple pendulum (ADR 0035): set the length, watch it swing, time 20
 * oscillations (with a little human reaction error), and plot T² against l.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('pendulum', function (root, p) {
    var length = 60; // cm
    var readings = [];
    var raf = null;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function period(cm) { return 2 * Math.PI * Math.sqrt(cm / 100 / p.g); }

    var bench = L.el('div', { class: 'sim sim-pendulum' }, null, root);
    var fig = L.el('figure', { class: 'sim-figure' }, null, bench);
    var s = L.svg('svg', { viewBox: '0 0 320 300', role: 'img', 'aria-labelledby': 'pend-desc', class: 'sim-svg sim-svg-tall' }, fig);
    L.svg('desc', { id: 'pend-desc' }, s, 'A retort stand with a bob hanging on a thread. The thread length can be changed.');
    L.svg('rect', { x: 40, y: 280, width: 240, height: 10, class: 'sim-axis' }, s);
    L.svg('line', { x1: 60, y1: 280, x2: 60, y2: 20, class: 'sim-wire' }, s);
    L.svg('line', { x1: 60, y1: 24, x2: 170, y2: 24, class: 'sim-wire' }, s);
    var thread = L.svg('line', { x1: 160, y1: 24, x2: 160, y2: 150, class: 'pend-thread' }, s);
    var bob = L.svg('circle', { cx: 160, cy: 150, r: 11, class: 'pend-bob' }, s);
    var lenLabel = L.svg('text', { x: 200, y: 90, class: 'sim-label' }, s, '');
    L.el('figcaption', { class: 'small muted' }, 'Lengths are measured from the point of suspension to the centre of the bob. Small swings only (under 10°).', fig);

    var panel = L.el('div', { class: 'sim-panel' }, null, bench);
    var ctl = L.el('div', { class: 'sim-control' }, null, panel);
    L.el('label', { for: 'pend-len' }, 'Length of thread', ctl);
    var range = L.el('input', { type: 'range', id: 'pend-len', min: '20', max: '150', step: '5', value: String(length) }, null, ctl);
    var out = L.el('output', { for: 'pend-len', class: 'sim-output' }, '', ctl);
    var meters = L.el('div', { class: 'sim-meters' }, null, panel);
    var sw = L.el('div', { class: 'sim-readout' }, null, meters);
    L.el('span', { class: 'sim-readout-label' }, 'Stopwatch', sw);
    var watch = L.el('span', { class: 'sim-readout-value', 'aria-live': 'polite' }, '0.00 s', sw);
    var row = L.el('div', { class: 'row-actions sim-buttons' }, null, panel);
    var timeBtn = L.el('button', { type: 'button', class: 'button button-small' }, 'Time 20 oscillations', row);
    var recBtn = L.el('button', { type: 'button', class: 'button button-small button-quiet', disabled: '' }, 'Record this reading', row);
    L.el('p', { class: 'small muted' }, 'The stopwatch runs faster than real time so you do not wait; the time it shows is the real time for 20 swings.', panel);

    var results = L.el('div', { class: 'sim-results' }, null, root);
    var tw = L.el('div', { class: 'sim-table' }, null, results);
    L.el('h3', null, 'Readings', tw);
    var table = L.el('table', { class: 'table table-compact' }, null, tw);
    var hr = L.el('tr', null, null, L.el('thead', null, null, table));
    [['l (cm)', 'num'], ['t for 20 (s)', 'num'], ['T (s)', 'num'], ['T² (s²)', 'num'], ['', '']].forEach(function (h) { L.el('th', { scope: 'col', class: h[1] }, h[0], hr); });
    var body = L.el('tbody', null, null, table);
    var empty = L.el('p', { class: 'small muted' }, 'No readings yet.', tw);
    var gw = L.el('figure', { class: 'sim-graph' }, null, results);
    var g = L.svg('svg', { viewBox: '0 0 300 236', role: 'img', 'aria-label': 'Graph of T squared against length for your readings', class: 'sim-svg' }, gw);
    L.el('figcaption', { class: 'small muted' }, 'T² (s²) against l (m). Draw the best straight line through the origin in your head, or on paper, and find its slope.', gw);

    var last = null;
    function pos(angle) {
      var px = Math.min(240, 40 + length * 1.6);
      var x = 160 + Math.sin(angle) * px, y = 24 + Math.cos(angle) * px;
      thread.setAttribute('x2', x); thread.setAttribute('y2', y);
      bob.setAttribute('cx', x); bob.setAttribute('cy', y);
    }
    function update() {
      out.textContent = length + ' cm';
      lenLabel.textContent = 'l = ' + length + ' cm';
      pos(0);
    }
    function swing(seconds, done) {
      if (raf) { window.cancelAnimationFrame(raf); }
      var T = period(length);
      var start = null;
      var speed = Math.max(1, (T * 20) / 4); // 20 swings in about 4 seconds
      function frame(ts) {
        if (start === null) { start = ts; }
        var t = (ts - start) / 1000 * speed;
        if (!reduce) { pos(0.16 * Math.cos(2 * Math.PI * t / T)); }
        watch.textContent = L.fixed(Math.min(t, seconds), 2) + ' s';
        if (t < seconds) { raf = window.requestAnimationFrame(frame); return; }
        raf = null; pos(0.16); done();
      }
      raf = window.requestAnimationFrame(frame);
    }
    function graph() {
      while (g.firstChild) { g.removeChild(g.firstChild); }
      var x0 = 40, y0 = 190, w = 240, h = 170;
      var maxL = 1.6, maxT2 = Math.max(2, Math.ceil(Math.max.apply(null, readings.map(function (r) { var T = r[1] / 20; return T * T; }).concat([1])) / 2) * 2);
      for (var k = 0; k <= 4; k++) {
        L.svg('line', { x1: x0 + w * k / 4, y1: y0, x2: x0 + w * k / 4, y2: y0 - h, class: 'sim-grid' }, g);
        L.svg('line', { x1: x0, y1: y0 - h * k / 4, x2: x0 + w, y2: y0 - h * k / 4, class: 'sim-grid' }, g);
        L.svg('text', { x: x0 + w * k / 4, y: y0 + 14, class: 'sim-tick', 'text-anchor': 'middle' }, g, L.fixed(maxL * k / 4, 1));
        L.svg('text', { x: x0 - 6, y: y0 - h * k / 4 + 4, class: 'sim-tick', 'text-anchor': 'end' }, g, L.fixed(maxT2 * k / 4, 1));
      }
      L.svg('line', { x1: x0, y1: y0, x2: x0 + w, y2: y0, class: 'sim-axis' }, g);
      L.svg('line', { x1: x0, y1: y0, x2: x0, y2: y0 - h, class: 'sim-axis' }, g);
      L.svg('text', { x: x0 + w / 2, y: y0 + 32, class: 'sim-tick', 'text-anchor': 'middle' }, g, 'l (m)');
      L.svg('text', { x: x0 - 6, y: y0 - h - 8, class: 'sim-tick' }, g, 'T² (s²)');
      readings.forEach(function (r) {
        var T = r[1] / 20;
        L.svg('circle', { cx: x0 + w * (r[0] / 100) / maxL, cy: y0 - h * (T * T) / maxT2, r: 4, class: 'sim-point' }, g);
      });
    }
    function render() {
      body.textContent = '';
      readings.forEach(function (r, i) {
        var T = r[1] / 20;
        var tr = L.el('tr', null, null, body);
        L.el('td', { class: 'num' }, String(r[0]), tr);
        L.el('td', { class: 'num' }, L.fixed(r[1], 2), tr);
        L.el('td', { class: 'num' }, L.fixed(T, 3), tr);
        L.el('td', { class: 'num' }, L.fixed(T * T, 3), tr);
        var del = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Remove reading ' + (i + 1) }, 'Remove', L.el('td', null, null, tr));
        del.addEventListener('click', function () { readings.splice(i, 1); render(); });
      });
      empty.hidden = readings.length > 0;
      L.answer('readings').value = JSON.stringify(readings);
      graph();
    }

    range.addEventListener('input', function () { length = parseInt(range.value, 10); last = null; recBtn.disabled = true; watch.textContent = '0.00 s'; update(); });
    timeBtn.addEventListener('click', function () {
      var t20 = Math.round((20 * period(length) + (Math.random() - 0.5) * 0.3) * 100) / 100;
      timeBtn.disabled = true;
      L.say('Timing 20 oscillations.');
      swing(t20, function () {
        watch.textContent = L.fixed(t20, 2) + ' s';
        last = [length, t20];
        timeBtn.disabled = false; recBtn.disabled = false;
        L.say('20 oscillations took ' + L.fixed(t20, 2) + ' seconds.');
      });
    });
    recBtn.addEventListener('click', function () {
      if (!last) { return; }
      if (readings.length >= 20) { L.say('You have 20 readings; remove one first.'); return; }
      readings.push(last); last = null; recBtn.disabled = true;
      render();
      L.say('Reading ' + readings.length + ' recorded.');
    });
    update();
    render();
  });
})();
