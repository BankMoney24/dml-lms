/*
 * DML labs: shared bench code. Each simulation (labs/<key>.js) registers a
 * mount function; this file finds the bench, hands it the session's numbers
 * and runs the countdown. No inline script or style is used anywhere (CSP).
 */
(function () {
  'use strict';

  var registry = {};
  var SVG = 'http://www.w3.org/2000/svg';

  function el(tag, attrs, text, parent) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') { node.className = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
    });
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    if (parent) { parent.appendChild(node); }
    return node;
  }

  function svg(tag, attrs, parent, text) {
    var node = document.createElementNS(SVG, tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, String(attrs[k])); });
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    if (parent) { parent.appendChild(node); }
    return node;
  }

  function fixed(n, d) {
    return Number(n).toFixed(d);
  }

  window.DMLLab = {
    register: function (key, mount) { registry[key] = mount; },
    el: el,
    svg: svg,
    fixed: fixed,
    /** The hidden or visible answer field called name. */
    answer: function (name) { return document.getElementById('answer-' + name); },
    /** Says something to screen readers without moving focus. */
    say: function (message) {
      var live = document.getElementById('lab-live');
      if (live) { live.textContent = ''; window.setTimeout(function () { live.textContent = message; }, 30); }
    }
  };

  function startTimer() {
    var box = document.querySelector('[data-lab-timer]');
    if (!box) { return; }
    var value = box.querySelector('.lab-timer-value');
    var end = Date.now() + parseInt(box.getAttribute('data-seconds') || '0', 10) * 1000;
    var warned = false;
    function tick() {
      var left = Math.max(0, Math.round((end - Date.now()) / 1000));
      if (left === 0) {
        value.textContent = 'Time is up';
        box.classList.add('is-low');
        box.lastChild.textContent = ': check your answers now (you have 2 minutes)';
        window.DMLLab.say('Time is up. Check your answers now.');
        return;
      }
      if (left <= 300) {
        box.classList.add('is-low');
        value.textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
        if (!warned) { warned = true; window.DMLLab.say('Five minutes left.'); }
      } else {
        value.textContent = Math.ceil(left / 60) + ' min';
      }
      window.setTimeout(tick, left <= 300 ? 1000 : 15000);
    }
    tick();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('lab-root');
    if (!root) { return; }
    var key = root.getAttribute('data-lab') || '';
    var params;
    try { params = JSON.parse(root.getAttribute('data-params') || '{}'); } catch (e) { params = null; }
    root.textContent = '';
    el('p', { id: 'lab-live', class: 'visually-hidden', 'aria-live': 'polite' }, '', root);
    var mount = registry[key];
    if (!mount || !params) {
      el('p', { class: 'notice notice-err' }, 'The simulation could not start. Reload the page; if it keeps happening, tell your teacher.', root);
      return;
    }
    try {
      mount(root, params, document.getElementById('lab-answers'));
    } catch (e) {
      el('p', { class: 'notice notice-err' }, 'The simulation stopped with an error. Reload the page to try again.', root);
    }
    startTimer();
  });
})();
