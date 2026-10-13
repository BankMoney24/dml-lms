/*
 * Ohm's law: a cell with a little internal resistance, an ammeter in
 * series and a voltmeter across a hidden resistor. Learners set the supply,
 * read both meters, record readings and see them on a V–I graph.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('ohms-law', function (root, p) {
    var supply = 6;
    var readings = [];

    var bench = L.el('div', { class: 'sim sim-ohm' }, null, root);

    // Circuit drawing.
    var draw = L.el('figure', { class: 'sim-figure' }, null, bench);
    var s = L.svg('svg', { viewBox: '0 0 420 240', role: 'img', 'aria-labelledby': 'ohm-desc', class: 'sim-svg' }, draw);
    L.svg('desc', { id: 'ohm-desc' }, s, 'Circuit: a variable cell, an ammeter in series and a voltmeter across a resistor hidden in a box.');
    var wire = { class: 'sim-wire' };
    L.svg('path', Object.assign({ d: 'M60 120 V40 H330 V200 H60 V150' }, wire), s);
    // Cell
    L.svg('line', { x1: 40, y1: 132, x2: 80, y2: 132, class: 'sim-cell-long' }, s);
    L.svg('line', { x1: 50, y1: 146, x2: 70, y2: 146, class: 'sim-cell-short' }, s);
    var supplyLabel = L.svg('text', { x: 92, y: 146, class: 'sim-label' }, s, '');
    // Ammeter
    L.svg('circle', { cx: 190, cy: 40, r: 22, class: 'sim-meter' }, s);
    L.svg('text', { x: 190, y: 47, class: 'sim-meter-letter', 'text-anchor': 'middle' }, s, 'A');
    // Hidden resistor
    L.svg('rect', { x: 300, y: 92, width: 60, height: 70, rx: 6, class: 'sim-box' }, s);
    L.svg('text', { x: 330, y: 135, class: 'sim-box-text', 'text-anchor': 'middle' }, s, 'R ?');
    // Voltmeter across the resistor
    L.svg('path', Object.assign({ d: 'M330 72 H395 V105 M395 155 V188 H330' }, wire), s);
    L.svg('circle', { cx: 395, cy: 130, r: 22, class: 'sim-meter' }, s);
    L.svg('text', { x: 395, y: 137, class: 'sim-meter-letter', 'text-anchor': 'middle' }, s, 'V');
    L.el('figcaption', { class: 'small muted' }, 'The resistor is sealed in the box. The cell has a small internal resistance, so read the voltmeter: it shows the voltage across the resistor itself.', draw);

    // Controls and meters.
    var panel = L.el('div', { class: 'sim-panel' }, null, bench);
    var ctl = L.el('div', { class: 'sim-control' }, null, panel);
    L.el('label', { for: 'ohm-supply' }, 'Cell setting', ctl);
    var range = L.el('input', { type: 'range', id: 'ohm-supply', min: '0', max: String(p.max_volts), step: '0.5', value: String(supply) }, null, ctl);
    var rangeOut = L.el('output', { for: 'ohm-supply', class: 'sim-output' }, '', ctl);

    var meters = L.el('div', { class: 'sim-meters' }, null, panel);
    function meter(label) {
      var m = L.el('div', { class: 'sim-readout' }, null, meters);
      L.el('span', { class: 'sim-readout-label' }, label, m);
      return L.el('span', { class: 'sim-readout-value', 'aria-live': 'polite' }, '', m);
    }
    var voltOut = meter('Voltmeter');
    var ampOut = meter('Ammeter');

    var record = L.el('button', { type: 'button', class: 'button button-small' }, 'Record this reading', panel);

    // Readings table and graph.
    var results = L.el('div', { class: 'sim-results' }, null, root);
    var tableWrap = L.el('div', { class: 'sim-table' }, null, results);
    L.el('h3', {}, 'Readings', tableWrap);
    var table = L.el('table', { class: 'table table-compact' }, null, tableWrap);
    var head = L.el('tr', {}, null, L.el('thead', {}, null, table));
    [['', ''], ['V across R (V)', 'num'], ['I (mA)', 'num'], ['', '']].forEach(function (h) { L.el('th', { scope: 'col', class: h[1] }, h[0], head); });
    var body = L.el('tbody', {}, null, table);
    var empty = L.el('p', { class: 'small muted' }, 'No readings yet. Set the cell, then press “Record this reading”.', tableWrap);

    var graphWrap = L.el('figure', { class: 'sim-graph' }, null, results);
    var g = L.svg('svg', { viewBox: '0 0 300 236', role: 'img', 'aria-label': 'Graph of current against voltage for your readings', class: 'sim-svg' }, graphWrap);
    L.el('figcaption', { class: 'small muted' }, 'Your readings: current (mA) against voltage (V).', graphWrap);

    function current() { return supply / (p.rx + p.r_int); }

    function update() {
      var i = current();
      var v = i * p.rx;
      rangeOut.textContent = L.fixed(supply, 1) + ' V';
      supplyLabel.textContent = 'Cell ' + L.fixed(supply, 1) + ' V';
      voltOut.textContent = L.fixed(v, 2) + ' V';
      ampOut.textContent = L.fixed(i * 1000, 1) + ' mA';
      s.classList.toggle('is-live', supply > 0);
    }

    function drawGraph() {
      while (g.firstChild) { g.removeChild(g.firstChild); }
      var maxV = Math.max(1, Math.ceil(Math.max.apply(null, readings.map(function (r) { return r[0]; }).concat([1]))));
      var maxI = Math.max(1, Math.ceil(Math.max.apply(null, readings.map(function (r) { return r[1]; }).concat([1])) / 10) * 10);
      var x0 = 40, y0 = 190, w = 240, h = 170;
      L.svg('line', { x1: x0, y1: y0, x2: x0 + w, y2: y0, class: 'sim-axis' }, g);
      L.svg('line', { x1: x0, y1: y0, x2: x0, y2: y0 - h, class: 'sim-axis' }, g);
      L.svg('text', { x: x0 + w / 2, y: y0 + 32, class: 'sim-tick', 'text-anchor': 'middle' }, g, 'V across R (V)');
      L.svg('text', { x: x0 - 6, y: y0 - h - 6, class: 'sim-tick' }, g, 'I (mA)');
      [0, 0.5, 1].forEach(function (f) {
        L.svg('text', { x: x0 + w * f, y: y0 + 14, class: 'sim-tick', 'text-anchor': 'middle' }, g, L.fixed(maxV * f, 1));
        L.svg('text', { x: x0 - 6, y: y0 - h * f + 4, class: 'sim-tick', 'text-anchor': 'end' }, g, Math.round(maxI * f));
      });
      readings.forEach(function (r) {
        L.svg('circle', { cx: x0 + w * r[0] / maxV, cy: y0 - h * r[1] / maxI, r: 4, class: 'sim-point' }, g);
      });
    }

    function render() {
      while (body.firstChild) { body.removeChild(body.firstChild); }
      readings.forEach(function (r, n) {
        var tr = L.el('tr', {}, null, body);
        L.el('td', {}, n + 1, tr);
        L.el('td', { class: 'num' }, L.fixed(r[0], 2), tr);
        L.el('td', { class: 'num' }, L.fixed(r[1], 1), tr);
        var del = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Remove reading ' + (n + 1) }, 'Remove', L.el('td', {}, null, tr));
        del.addEventListener('click', function () { readings.splice(n, 1); render(); });
      });
      empty.hidden = readings.length > 0;
      L.answer('readings').value = JSON.stringify(readings);
      drawGraph();
    }

    range.addEventListener('input', function () { supply = parseFloat(range.value); update(); });
    record.addEventListener('click', function () {
      if (readings.length >= 20) { L.say('You have 20 readings; remove one first.'); return; }
      var i = current();
      readings.push([Math.round(i * p.rx * 100) / 100, Math.round(i * 10000) / 10]);
      render();
      L.say('Reading ' + readings.length + ' recorded.');
    });

    update();
    render();
  });
})();
