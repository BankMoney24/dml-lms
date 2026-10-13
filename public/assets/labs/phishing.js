/*
 * Spot the phish: an inbox and a reading pane. Marking an email here sets
 * the matching answer in the form (and the other way round). Nothing in the
 * inbox is a real link.
 */
(function () {
  'use strict';
  var L = window.DMLLab;

  L.register('phishing', function (root, p) {
    var bench = L.el('div', { class: 'sim sim-mail' }, null, root);
    var inbox = L.el('ul', { class: 'mail-list', 'aria-label': 'Inbox' }, null, bench);
    var pane = L.el('article', { class: 'mail-pane', 'aria-live': 'polite' }, null, bench);
    var items = [];
    var current = 0;

    function choice(i) { var sel = L.answer('email_' + (i + 1)); return sel ? sel.value : ''; }
    function setChoice(i, value) {
      var sel = L.answer('email_' + (i + 1));
      if (sel) { sel.value = value; }
      refresh();
    }

    function refresh() {
      items.forEach(function (item, i) {
        var c = choice(i);
        item.tag.textContent = c === 'safe' ? 'Safe' : (c === 'phishing' ? 'Phishing' : 'Not marked');
        item.tag.className = 'mail-tag ' + (c === '' ? 'is-open' : 'is-' + c);
        if (i === current) { item.button.setAttribute('aria-current', 'true'); } else { item.button.removeAttribute('aria-current'); }
      });
      var done = items.filter(function (item, i) { return choice(i) !== ''; }).length;
      counter.textContent = done + ' of ' + items.length + ' marked';
    }

    function show(i) {
      current = i;
      var m = p.emails[i];
      pane.textContent = '';
      L.el('h3', { class: 'mail-subject' }, m.subject, pane);
      var from = L.el('p', { class: 'mail-from' }, null, pane);
      L.el('strong', {}, m.from_name, from);
      L.el('span', { class: 'mail-address' }, ' <' + m.from_address + '>', from);
      L.el('div', { class: 'mail-body' }, m.body, pane);
      if (m.link_text) {
        var link = L.el('p', { class: 'mail-link' }, null, pane);
        L.el('span', { class: 'mail-fake-button' }, m.link_text, link);
        L.el('span', { class: 'small muted' }, ' Link goes to: ' + m.link_url, link);
      }
      if (m.attachment) { L.el('p', { class: 'mail-attachment' }, 'Attachment: ' + m.attachment, pane); }
      var actions = L.el('div', { class: 'row-actions mail-actions' }, null, pane);
      var safe = L.el('button', { type: 'button', class: 'button button-small button-quiet' }, 'Looks safe', actions);
      var phish = L.el('button', { type: 'button', class: 'button button-small button-danger' }, 'Report phishing', actions);
      safe.addEventListener('click', function () { setChoice(i, 'safe'); next(); });
      phish.addEventListener('click', function () { setChoice(i, 'phishing'); next(); });
      refresh();
    }

    function next() {
      for (var k = 1; k <= items.length; k++) {
        var j = (current + k) % items.length;
        if (choice(j) === '') { show(j); items[j].button.focus(); return; }
      }
      L.say('Every email is marked. Check your answers when you are ready.');
    }

    p.emails.forEach(function (m, i) {
      var li = L.el('li', {}, null, inbox);
      var b = L.el('button', { type: 'button', class: 'mail-item' }, null, li);
      L.el('span', { class: 'mail-item-from' }, m.from_name, b);
      L.el('span', { class: 'mail-item-subject' }, m.subject, b);
      var tag = L.el('span', { class: 'mail-tag' }, '', b);
      b.addEventListener('click', function () { show(i); });
      items.push({ button: b, tag: tag });
      var sel = L.answer('email_' + (i + 1));
      if (sel) { sel.addEventListener('change', refresh); }
    });
    var counter = L.el('p', { class: 'small muted mail-counter', 'aria-live': 'polite' }, '', root);

    show(0);
  });
})();
