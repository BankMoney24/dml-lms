/*
 * Titration: a 50 cm³ burette of 0.100 mol/dm³ NaOH over a flask holding
 * 25.0 cm³ of HCl with phenolphthalein. Pink appears once the alkali is in
 * excess. Learners record titres; the burette's start reading changes each
 * run so they practise subtracting readings.
 */
(function () {
  'use strict';
  var L = window.DMLLab;
  var STARTS = [0.00, 0.40, 1.10, 0.25, 0.85];

  L.register('titration', function (root, p) {
    var endPoint = p.acid * p.acid_volume / p.base;
    var run = 0;
    var start = STARTS[0];
    var added = 0;
    var titres = [];

    var bench = L.el('div', { class: 'sim sim-titration' }, null, root);
    var draw = L.el('figure', { class: 'sim-figure' }, null, bench);
    var s = L.svg('svg', { viewBox: '0 0 260 320', role: 'img', 'aria-labelledby': 'tit-desc', class: 'sim-svg sim-svg-tall' }, draw);
    L.svg('desc', { id: 'tit-desc' }, s, 'A burette above a conical flask of acid with phenolphthalein indicator.');
    // Burette: 0 at the top, 50 at the bottom.
    var top = 20, height = 200;
    L.svg('rect', { x: 115, y: top, width: 30, height: height, class: 'sim-glass' }, s);
    var liquid = L.svg('rect', { x: 117, y: top, width: 26, height: height, class: 'sim-alkali' }, s);
    for (var m = 0; m <= 50; m += 5) {
      var y = top + height * m / 50;
      L.svg('line', { x1: 145, y1: y, x2: m % 10 === 0 ? 156 : 151, y2: y, class: 'sim-axis' }, s);
      if (m % 10 === 0) { L.svg('text', { x: 160, y: y + 4, class: 'sim-tick' }, s, m); }
    }
    L.svg('path', { d: 'M125 220 H135 V238 H125 Z', class: 'sim-glass' }, s);
    L.svg('line', { x1: 130, y1: 238, x2: 130, y2: 250, class: 'sim-wire' }, s);
    var flask = L.svg('path', { d: 'M118 252 H142 V266 L188 306 H72 L118 266 Z', class: 'sim-flask' }, s);
    L.el('figcaption', { class: 'small muted' }, 'Read the burette from the top: the scale goes down from 0.00 to 50.00 cm³.', draw);

    var panel = L.el('div', { class: 'sim-panel' }, null, bench);
    var meters = L.el('div', { class: 'sim-meters' }, null, panel);
    function meter(label) {
      var box = L.el('div', { class: 'sim-readout' }, null, meters);
      L.el('span', { class: 'sim-readout-label' }, label, box);
      return L.el('span', { class: 'sim-readout-value' }, '', box);
    }
    var startOut = meter('Start reading');
    var nowOut = meter('Burette now');
    var colourOut = meter('Flask');
    colourOut.setAttribute('aria-live', 'polite');

    var buttons = L.el('div', { class: 'row-actions sim-buttons' }, null, panel);
    [[1.00, 'Add 1.00 cm³'], [0.10, 'Add 0.10 cm³'], [0.05, 'Add one drop (0.05 cm³)']].forEach(function (b) {
      var btn = L.el('button', { type: 'button', class: 'button button-small button-quiet' }, b[1], buttons);
      btn.addEventListener('click', function () {
        if (start + added + b[0] > 50.0001) { L.say('The burette is nearly empty. Record this titre and refill.'); return; }
        added = Math.round((added + b[0]) * 100) / 100;
        update();
      });
    });
    var recordBtn = L.el('button', { type: 'button', class: 'button button-small' }, 'Record titre, then empty the flask and refill', panel);
    L.el('p', { class: 'small muted' }, 'Titre = burette now − start reading. Stop at the first permanent faint pink.', panel);

    var results = L.el('div', { class: 'sim-results' }, null, root);
    var wrap = L.el('div', { class: 'sim-table' }, null, results);
    L.el('h3', {}, 'Titres', wrap);
    var table = L.el('table', { class: 'table table-compact' }, null, wrap);
    var head = L.el('tr', {}, null, L.el('thead', {}, null, table));
    [['Run', ''], ['Start (cm³)', 'num'], ['End (cm³)', 'num'], ['Titre (cm³)', 'num'], ['', '']].forEach(function (h) { L.el('th', { scope: 'col', class: h[1] }, h[0], head); });
    var body = L.el('tbody', {}, null, table);
    var empty = L.el('p', { class: 'small muted' }, 'No titres yet. Your first run can be a quick rough one.', wrap);
    var rows = [];

    function colour() {
      var excess = added - endPoint;
      if (excess < -0.001) { return ['is-clear', 'Colourless']; }
      if (excess < 0.10) { return ['is-faint', 'Faint pink that stays: the end point']; }
      if (excess < 1.0) { return ['is-pink', 'Pink: just past the end point']; }
      return ['is-deep', 'Deep pink: well past the end point'];
    }

    function update() {
      var c = colour();
      flask.setAttribute('class', 'sim-flask ' + c[0]);
      colourOut.textContent = c[1];
      startOut.textContent = L.fixed(start, 2) + ' cm³';
      nowOut.textContent = L.fixed(start + added, 2) + ' cm³';
      var level = top + height * (start + added) / 50;
      liquid.setAttribute('y', String(level));
      liquid.setAttribute('height', String(Math.max(0, top + height - level)));
    }

    function render() {
      while (body.firstChild) { body.removeChild(body.firstChild); }
      rows.forEach(function (r, n) {
        var tr = L.el('tr', {}, null, body);
        L.el('td', {}, n === 0 ? 'Rough' : n, tr);
        L.el('td', { class: 'num' }, L.fixed(r[0], 2), tr);
        L.el('td', { class: 'num' }, L.fixed(r[1], 2), tr);
        L.el('td', { class: 'num' }, L.fixed(r[1] - r[0], 2), tr);
        var del = L.el('button', { type: 'button', class: 'linklike small', 'aria-label': 'Remove titre ' + (n + 1) }, 'Remove', L.el('td', {}, null, tr));
        del.addEventListener('click', function () { rows.splice(n, 1); render(); });
      });
      empty.hidden = rows.length > 0;
      titres = rows.map(function (r) { return Math.round((r[1] - r[0]) * 100) / 100; });
      L.answer('titres').value = JSON.stringify(titres);
    }

    recordBtn.addEventListener('click', function () {
      if (added <= 0) { L.say('Add some sodium hydroxide first.'); return; }
      if (rows.length >= 10) { L.say('That is plenty of titres. Remove one to record another.'); return; }
      rows.push([start, Math.round((start + added) * 100) / 100]);
      run += 1;
      start = STARTS[run % STARTS.length];
      added = 0;
      render();
      update();
      L.say('Titre recorded. The flask has fresh acid and the burette is refilled.');
    });

    update();
    render();
  });
})();
