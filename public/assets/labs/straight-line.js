/*
 * Straight-line graphs: five points on a grid from −10 to 10. Learners try a
 * line y = mx + c with two sliders to check the equation they worked out.
 */
(function () {
  'use strict';
  var L = window.DMLLab;
  var SIZE = 400;
  var RANGE = 10;

  function px(x) { return SIZE / 2 + x * SIZE / (2 * RANGE); }
  function py(y) { return SIZE / 2 - y * SIZE / (2 * RANGE); }

  function fmt(n) {
    var t = Number(n).toFixed(1);
    return (t.slice(-2) === '.0' ? t.slice(0, -2) : t).replace('-', '−');
  }

  L.register('straight-line', function (root, p) {
    var m = 1;
    var c = 0;

    var bench = L.el('div', { class: 'sim sim-line' }, null, root);
    var draw = L.el('figure', { class: 'sim-figure' }, null, bench);
    var s = L.svg('svg', { viewBox: '-14 -14 ' + (SIZE + 28) + ' ' + (SIZE + 28), role: 'img', 'aria-labelledby': 'line-desc', class: 'sim-svg sim-svg-square' }, draw);
    L.svg('desc', { id: 'line-desc' }, s, 'A grid from −10 to 10 on both axes with ' + p.points.length + ' plotted points.');
    for (var k = -RANGE; k <= RANGE; k++) {
      var major = k === 0;
      L.svg('line', { x1: px(k), y1: 0, x2: px(k), y2: SIZE, class: major ? 'sim-axis' : 'sim-grid' }, s);
      L.svg('line', { x1: 0, y1: py(k), x2: SIZE, y2: py(k), class: major ? 'sim-axis' : 'sim-grid' }, s);
      if (k !== 0 && k % 2 === 0) {
        L.svg('text', { x: px(k), y: py(0) + 14, class: 'sim-tick', 'text-anchor': 'middle' }, s, fmt(k));
        L.svg('text', { x: px(0) - 5, y: py(k) + 4, class: 'sim-tick', 'text-anchor': 'end' }, s, fmt(k));
      }
    }
    L.svg('text', { x: SIZE - 6, y: py(0) - 6, class: 'sim-label', 'text-anchor': 'end' }, s, 'x');
    L.svg('text', { x: px(0) + 8, y: 14, class: 'sim-label' }, s, 'y');
    var line = L.svg('line', { class: 'sim-try-line' }, s);
    p.points.forEach(function (pt) {
      L.svg('circle', { cx: px(pt[0]), cy: py(pt[1]), r: 6, class: 'sim-point' }, s);
    });

    var panel = L.el('div', { class: 'sim-panel' }, null, bench);
    L.el('h3', {}, 'The points', panel);
    var list = L.el('ul', { class: 'sim-coords' }, null, panel);
    p.points.forEach(function (pt) { L.el('li', {}, '(' + fmt(pt[0]) + ', ' + fmt(pt[1]) + ')', list); });

    L.el('h3', {}, 'Try a line', panel);
    function slider(id, label, min, max, step, value, onChange) {
      var box = L.el('div', { class: 'sim-control' }, null, panel);
      L.el('label', { for: id }, label, box);
      var input = L.el('input', { type: 'range', id: id, min: String(min), max: String(max), step: String(step), value: String(value) }, null, box);
      var out = L.el('output', { for: id, class: 'sim-output' }, fmt(value), box);
      input.addEventListener('input', function () { out.textContent = fmt(input.value); onChange(parseFloat(input.value)); });
    }
    slider('line-m', 'Gradient m', -4, 4, 0.5, m, function (v) { m = v; update(); });
    slider('line-c', 'Intercept c', -10, 10, 1, c, function (v) { c = v; update(); });
    var eq = L.el('p', { class: 'sim-equation', 'aria-live': 'polite' }, '', panel);
    L.el('p', { class: 'small muted' }, 'The dashed line is only for checking. Type your answers in the form below.', panel);

    function update() {
      var x1 = -RANGE, x2 = RANGE;
      line.setAttribute('x1', String(px(x1)));
      line.setAttribute('y1', String(py(m * x1 + c)));
      line.setAttribute('x2', String(px(x2)));
      line.setAttribute('y2', String(py(m * x2 + c)));
      eq.textContent = 'Your line: y = ' + fmt(m) + 'x ' + (c < 0 ? '− ' + fmt(-c) : '+ ' + fmt(c));
    }
    update();
  });
})();
