// The offline page (ADR 0033): lists lessons saved on this device and offers to try again.
(function () {
  'use strict';
  var box = document.querySelector('[data-offline-library]');
  try {
    var list = [];
    for (var i = 0; i < localStorage.length; i++) {
      var key = localStorage.key(i) || '';
      if (key.indexOf('dml-saved:') === 0) { list = list.concat(JSON.parse(localStorage.getItem(key) || '[]')); }
    }
    if (box && list.length) {
      var ul = box.querySelector('ul');
      list.forEach(function (x) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = x.u;
        a.textContent = x.t;
        li.appendChild(a);
        if (x.c) { var s = document.createElement('span'); s.className = 'small muted'; s.textContent = ' ' + x.c; li.appendChild(s); }
        ul.appendChild(li);
      });
      box.hidden = false;
    }
  } catch (e) { /* storage unavailable */ }
  var again = document.querySelector('[data-reload]');
  if (again) { again.addEventListener('click', function () { location.reload(); }); }
})();
