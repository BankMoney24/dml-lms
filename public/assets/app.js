// Small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

  // Connection banner: tell people plainly when they are offline.
  var banner = document.getElementById('netstatus');
  var offlineText = banner ? banner.textContent : '';
  function updateNet() {
    document.documentElement.classList.toggle('is-offline', !navigator.onLine);
    if (banner && !navigator.onLine) { banner.textContent = offlineText; }
    if (banner) { banner.hidden = navigator.onLine; }
  }
  window.addEventListener('online', updateNet);
  window.addEventListener('offline', updateNet);
  updateNet();

  // Quiz drafts: keep chosen answers on this device until the server confirms
  // the submission, so a dropped connection or reload does not lose them.
  // Wording is deliberate: "saved on this device" never claims "submitted".
  function storage() {
    try { var k = '__dml'; localStorage.setItem(k, k); localStorage.removeItem(k); return localStorage; }
    catch (e) { return null; }
  }
  var store = storage();
  var forms = document.querySelectorAll('form[data-draft-key]');
  Array.prototype.forEach.call(forms, function (form) {
    var key = 'dml-draft:' + location.host + ':' + form.getAttribute('data-draft-key');
    var status = form.querySelector('[data-draft-status]');
    function say(text) { if (status) { status.textContent = text; } }

    if (!store) { say('Answers cannot be saved on this device; keep this page open until you submit.'); return; }

    try {
      var saved = JSON.parse(store.getItem(key) || '{}');
      Object.keys(saved).forEach(function (name) {
        var input = form.querySelector('input[type=radio][name="' + CSS.escape(name) + '"][value="' + CSS.escape(String(saved[name])) + '"]');
        if (input) { input.checked = true; }
      });
      if (Object.keys(saved).length) { say('Restored answers saved on this device. Not submitted yet.'); }
    } catch (e) { /* ignore corrupt draft */ }

    form.addEventListener('change', function () {
      var data = {};
      Array.prototype.forEach.call(form.querySelectorAll('input[type=radio]:checked'), function (i) { data[i.name] = i.value; });
      try { store.setItem(key, JSON.stringify(data)); say('Saved on this device. Not submitted yet.'); }
      catch (e) { say('Could not save on this device; keep this page open until you submit.'); }
    });

    form.addEventListener('submit', function (event) {
      if (!navigator.onLine) {
        event.preventDefault();
        say('You are offline. Your answers are saved on this device; submit again when you are back online.');
        return;
      }
      var button = form.querySelector('button[type=submit]');
      if (button) { button.disabled = true; button.textContent = 'Sending…'; }
      // The draft is cleared on the result page, after the server has stored the attempt.
      try { sessionStorage.setItem('dml-clear-draft', key); } catch (e) { /* ignore */ }
    });
  });

  // On a result page the server has confirmed the attempt: drop the local draft.
  if (document.querySelector('.result') && store) {
    try {
      var pending = sessionStorage.getItem('dml-clear-draft');
      if (pending) { store.removeItem(pending); sessionStorage.removeItem('dml-clear-draft'); }
    } catch (e) { /* ignore */ }
  }

  // Video lessons: the player (and its data use) loads only after the learner asks.
  Array.prototype.forEach.call(document.querySelectorAll('.video[data-embed]'), function (figure) {
    var link = figure.querySelector('.video-load');
    var frame = figure.querySelector('.video-frame');
    if (!link || !frame) { return; }
    link.addEventListener('click', function (event) {
      var src = figure.getAttribute('data-embed') || '';
      if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(src)) { return; }
      event.preventDefault();
      var iframe = document.createElement('iframe');
      iframe.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
      iframe.title = figure.getAttribute('data-title') || 'Video';
      iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      iframe.setAttribute('allowfullscreen', '');
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
      frame.textContent = '';
      frame.appendChild(iframe);
      iframe.focus();
    });
  });

  // Copy buttons on code blocks.
  if (navigator.clipboard) {
    Array.prototype.forEach.call(document.querySelectorAll('.code-block'), function (block) {
      var code = block.querySelector('code');
      if (!code) { return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'copy-button';
      button.textContent = 'Copy';
      button.setAttribute('aria-label', 'Copy code');
      button.addEventListener('click', function () {
        navigator.clipboard.writeText(code.textContent || '').then(function () {
          button.textContent = 'Copied';
          setTimeout(function () { button.textContent = 'Copy'; }, 2000);
        }, function () { button.textContent = 'Select and copy'; });
      });
      block.appendChild(button);
    });
  }

  // Long outlines: bring the current lesson into view inside the sidebar.
  var side = document.querySelector('.side-trace') || document.querySelector('.lesson-side');
  var current = side && side.querySelector('.trace-node.is-current');
  if (side && current && side.scrollHeight > side.clientHeight) {
    side.scrollTop = Math.max(0, current.getBoundingClientRect().top - side.getBoundingClientRect().top - side.clientHeight / 3);
  }

  // File pickers: say before uploading if a file is too big or there are too many,
  // instead of making someone on slow data wait for the server to refuse it.
  // Video and audio have their own, larger limit (data-media-max-bytes). A form
  // with a problem is not sent: a body over the server's limit would arrive empty.
  var MEDIA = /\.(mp4|webm|mp3|m4a|ogg|opus|wav)$/i;
  Array.prototype.forEach.call(document.querySelectorAll('input[type=file][data-max-bytes]'), function (input) {
    var note = document.createElement('p');
    note.className = 'upload-warning';
    note.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', note);
    input.addEventListener('change', function () {
      var max = parseInt(input.getAttribute('data-max-bytes') || '0', 10);
      var mediaMax = parseInt(input.getAttribute('data-media-max-bytes') || '0', 10) || max;
      var maxFiles = parseInt(input.getAttribute('data-max-files') || '0', 10);
      var problems = [];
      if (maxFiles && input.files.length > maxFiles) { problems.push('Choose at most ' + maxFiles + ' files.'); }
      Array.prototype.forEach.call(input.files, function (f) {
        var limit = MEDIA.test(f.name) ? mediaMax : max;
        if (limit && f.size > limit) { problems.push('“' + f.name + '” is ' + size(f.size) + '; the limit is ' + size(limit) + '.'); }
      });
      note.textContent = problems.join(' ');
    });
    if (input.form) {
      input.form.addEventListener('submit', function (e) {
        if (note.textContent !== '') { e.preventDefault(); e.stopImmediatePropagation(); input.focus(); }
      });
    }
  });

  // New meeting: the day and time only matter for "Later", and the button says what will happen.
  Array.prototype.forEach.call(document.querySelectorAll('[data-meet-form]'), function (form) {
    var later = form.querySelector('[data-when-later]');
    var button = form.querySelector('[data-when-label-now]');
    function sync() {
      var picked = form.querySelector('[data-when]:checked');
      var isLater = picked && picked.value === 'later';
      if (later) { later.hidden = !isLater; }
      if (button) { button.textContent = button.getAttribute(isLater ? 'data-when-label-later' : 'data-when-label-now'); }
    }
    Array.prototype.forEach.call(form.querySelectorAll('[data-when]'), function (r) { r.addEventListener('change', sync); });
    sync();
  });

  // Big uploads (video, audio, recordings) show how far they have got: on a
  // slow connection a 100 MB file can take many minutes and a still page looks
  // stuck. The form is sent as it would be anyway; the page the server answers
  // with replaces this one, so messages and errors appear as usual. Without
  // JavaScript the form simply posts.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-progress]'), function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented || !window.XMLHttpRequest || !window.FormData) { return; }
      var picked = Array.prototype.some.call(form.querySelectorAll('input[type=file]'), function (i) { return i.files && i.files.length > 0; });
      if (!picked) { return; }
      e.preventDefault();
      var button = form.querySelector('button[type=submit], button:not([type])');
      var bar = form.querySelector('[data-progress-bar]');
      var label = form.querySelector('[data-progress-label]');
      var box = form.querySelector('[data-progress-box]');
      var xhr = new XMLHttpRequest();
      if (box) { box.hidden = false; }
      if (button) { button.disabled = true; }
      xhr.open('POST', form.action);
      xhr.upload.addEventListener('progress', function (p) {
        if (!p.lengthComputable) { return; }
        var pct = Math.floor(p.loaded / p.total * 100);
        if (bar) { bar.style.width = pct + '%'; }
        if (label) { label.textContent = pct < 100 ? 'Uploading… ' + pct + '% of ' + size(p.total) : 'Checking the file…'; }
      });
      xhr.addEventListener('load', function () {
        try { window.history.replaceState(null, '', xhr.responseURL || form.action); } catch (err) { /* keep the address */ }
        document.open();
        document.write(xhr.responseText);
        document.close();
      });
      xhr.addEventListener('error', function () {
        if (button) { button.disabled = false; }
        if (label) { label.textContent = 'The upload stopped; the connection may have dropped. Choose Upload to try again.'; }
      });
      xhr.send(new FormData(form));
    });
  });

  function size(bytes) {
    return bytes < 1048576 ? Math.ceil(bytes / 1024) + ' KB' : (bytes / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
  }

  // School setup, "Name and look": the small preview follows the colour and name as they are chosen.
  var preview = document.querySelector('[data-brand-preview]');
  if (preview) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-theme-pick]'), function (radio) {
      radio.addEventListener('change', function () {
        preview.className = preview.className.replace(/\btheme-[a-z]+\b/, 'theme-' + radio.value);
      });
    });
    [['name', '[data-preview-name]', 'Your school'], ['tagline', '[data-preview-tagline]', '']].forEach(function (pair) {
      var input = document.getElementById(pair[0]);
      var target = preview.querySelector(pair[1]);
      if (input && target) {
        input.addEventListener('input', function () { target.textContent = input.value.trim() || pair[2]; });
      }
    });
  }

  // "Go back" on error pages that follow a form (shown only when there is somewhere to go back to).
  Array.prototype.forEach.call(document.querySelectorAll('[data-back]'), function (button) {
    if (history.length > 1) {
      button.hidden = false;
      button.addEventListener('click', function () { history.back(); });
    }
  });

  // Print buttons (certificates).
  Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // Add-a-lesson form: show only the fields the chosen lesson type uses.
  // Without scripts every field shows, each labelled with the type it is for.
  Array.prototype.forEach.call(document.querySelectorAll('[data-type-switch]'), function (form) {
    var parts = form.querySelectorAll('[data-lesson-type]');
    var update = function () {
      var checked = form.querySelector('input[name="type"]:checked');
      var type = checked ? checked.value : '';
      Array.prototype.forEach.call(parts, function (part) {
        var wanted = (part.getAttribute('data-lesson-type') || '').split(' ');
        var show = wanted.indexOf(type) !== -1;
        part.hidden = !show;
        Array.prototype.forEach.call(part.querySelectorAll('input, select, textarea'), function (el) { el.disabled = !show; });
      });
    };
    Array.prototype.forEach.call(form.querySelectorAll('input[name="type"]'), function (radio) {
      radio.addEventListener('change', update);
    });
    update();
  });

  // "Find" boxes that narrow a long list as you type (people, ticked learner lists).
  Array.prototype.forEach.call(document.querySelectorAll('[data-filter]'), function (input) {
    var target = document.getElementById(input.getAttribute('data-filter'));
    var status = document.getElementById(input.getAttribute('data-filter') + '-count');
    if (!target) { return; }
    input.hidden = false;
    var label = input.labels && input.labels[0];
    if (label) { label.hidden = false; }
    var rows = target.querySelectorAll('[data-filter-row]');
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      Array.prototype.forEach.call(rows, function (row) {
        var text = row.getAttribute('data-filter-row') || row.textContent || '';
        var match = q === '' || text.toLowerCase().indexOf(q) !== -1;
        row.hidden = !match;
        if (match) { shown++; }
      });
      if (status) { status.textContent = q === '' ? '' : shown + (shown === 1 ? ' match' : ' matches'); }
    });
    // Arriving from search with ?find=…: narrow the list straight away.
    if (input.value.trim() !== '') { input.dispatchEvent(new Event('input')); }
  });

  // App shell: the sidebar slides in on phones. Without scripts the menu link opens it with :target.
  var sidebar = document.getElementById('sidebar');
  var scrim = document.querySelector('.nav-scrim');
  var openers = document.querySelectorAll('[data-nav-open]');
  var opener = openers[0] || null;
  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    if (scrim) { scrim.hidden = !open; }
    Array.prototype.forEach.call(openers, function (o) { o.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open && sidebar) {
      var first = sidebar.querySelector('a[aria-current="page"]') || sidebar.querySelector('a');
      if (first) { first.focus(); }
    } else if (!open && opener && sidebar && sidebar.contains(document.activeElement)) {
      opener.focus();
    }
  }
  if (sidebar && opener) {
    Array.prototype.forEach.call(openers, function (o) {
      o.setAttribute('role', 'button');
      o.setAttribute('aria-expanded', 'false');
      o.addEventListener('click', function (e) { e.preventDefault(); opener = o; setNav(true); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav-close]'), function (el) {
      el.addEventListener('click', function (e) { e.preventDefault(); setNav(false); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.body.classList.contains('nav-open')) { setNav(false); }
    });
  }

  // Menus built on <details>: close on Escape or a click elsewhere.
  var menus = document.querySelectorAll('details[data-menu]');
  document.addEventListener('click', function (e) {
    Array.prototype.forEach.call(menus, function (m) { if (m.open && !m.contains(e.target)) { m.open = false; } });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    Array.prototype.forEach.call(menus, function (m) {
      if (m.open) { m.open = false; var s = m.querySelector('summary'); if (s) { s.focus(); } }
    });
  });

  // Search as you type. The form still works on its own: Enter goes to the full results page.
  var searchForm = document.querySelector('form[data-search]');
  var searchInput = searchForm && searchForm.querySelector('input[type=search]');
  var pop = searchForm && searchForm.querySelector('.search-pop');
  if (searchForm && searchInput && pop && window.fetch) {
    var sprite = (searchForm.querySelector('use') || { getAttribute: function () { return ''; } }).getAttribute('href').split('#')[0];
    var timer = null, active = -1, options = [], lastQ = '', seq = 0;
    searchInput.setAttribute('role', 'combobox');
    searchInput.setAttribute('aria-autocomplete', 'list');
    searchInput.setAttribute('aria-expanded', 'false');
    searchInput.setAttribute('aria-controls', 'search-pop');
    pop.setAttribute('role', 'listbox');
    pop.setAttribute('aria-label', 'Search results');

    var icon = function (name) {
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon');
      svg.setAttribute('aria-hidden', 'true');
      var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', sprite + '#' + String(name).replace(/[^a-z-]/g, ''));
      svg.appendChild(use);
      return svg;
    };
    var close = function () {
      pop.hidden = true; active = -1; options = [];
      searchInput.setAttribute('aria-expanded', 'false');
      searchInput.removeAttribute('aria-activedescendant');
    };
    var highlight = function (i) {
      options.forEach(function (o, n) { o.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
      active = i;
      if (i >= 0 && options[i]) {
        searchInput.setAttribute('aria-activedescendant', options[i].id);
        options[i].scrollIntoView({ block: 'nearest' });
      } else { searchInput.removeAttribute('aria-activedescendant'); }
    };
    var render = function (data, q) {
      pop.textContent = '';
      options = [];
      var n = 0;
      (data.groups || []).forEach(function (g) {
        if (!g.items || !g.items.length) { return; }
        var head = document.createElement('p');
        head.className = 'search-group';
        head.setAttribute('role', 'presentation');
        head.textContent = g.label;
        pop.appendChild(head);
        g.items.forEach(function (item) {
          if (typeof item.url !== 'string' || item.url.charAt(0) !== '/' || item.url.charAt(1) === '/') { return; }
          var a = document.createElement('a');
          a.className = 'search-option';
          a.href = item.url;
          a.id = 'search-opt-' + (n++);
          a.setAttribute('role', 'option');
          a.setAttribute('aria-selected', 'false');
          a.tabIndex = -1;
          a.appendChild(icon(item.icon || 'arrow'));
          var text = document.createElement('span');
          text.className = 'search-option-text';
          var t = document.createElement('span'); t.className = 'search-option-title'; t.textContent = item.title;
          text.appendChild(t);
          if (item.detail) { var d = document.createElement('span'); d.className = 'search-option-detail'; d.textContent = item.detail; text.appendChild(d); }
          a.appendChild(text);
          pop.appendChild(a);
          options.push(a);
        });
      });
      if (!options.length) {
        var none = document.createElement('p');
        none.className = 'search-empty';
        none.textContent = 'Nothing matches “' + q + '”. Try a shorter word.';
        pop.appendChild(none);
      }
      var all = document.createElement('a');
      all.className = 'search-all';
      all.href = '/search?q=' + encodeURIComponent(q);
      all.textContent = 'See all results for “' + q + '”';
      pop.appendChild(all);
      pop.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
      active = -1;
    };
    var run = function () {
      var q = searchInput.value.trim();
      if (q.length < 2) { close(); lastQ = ''; return; }
      if (q === lastQ && !pop.hidden) { return; }
      lastQ = q;
      var mine = ++seq;
      fetch('/search?format=json&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data && mine === seq && searchInput.value.trim() === q) { render(data, q); } })
        .catch(function () { /* offline: the form still submits */ });
    };
    searchInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 160); });
    searchInput.addEventListener('focus', function () { if (searchInput.value.trim().length >= 2) { run(); } });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && options.length) { e.preventDefault(); highlight(active + 1 >= options.length ? 0 : active + 1); }
      else if (e.key === 'ArrowUp' && options.length) { e.preventDefault(); highlight(active <= 0 ? options.length - 1 : active - 1); }
      else if (e.key === 'Enter' && active >= 0 && options[active]) { e.preventDefault(); location.href = options[active].href; }
      else if (e.key === 'Escape') { if (!pop.hidden) { e.stopPropagation(); close(); } else { searchInput.blur(); } }
    });
    document.addEventListener('click', function (e) { if (!searchForm.contains(e.target)) { close(); } });
    // "/" or Ctrl/Cmd+K jumps to search from anywhere except a text field.
    document.addEventListener('keydown', function (e) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (document.activeElement && document.activeElement.isContentEditable);
      if (((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing)) {
        if (searchInput.offsetParent === null) { return; }
        e.preventDefault();
        searchInput.focus();
        searchInput.select();
      }
    });
  }

  // Copy buttons for links people paste elsewhere (calendar link).
  Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (button) {
    var target = document.getElementById(button.getAttribute('data-copy'));
    if (!target || !navigator.clipboard) { return; }
    button.hidden = false;
    button.addEventListener('click', function () {
      navigator.clipboard.writeText(target.value || target.textContent || '').then(function () {
        button.textContent = 'Copied';
        setTimeout(function () { button.textContent = 'Copy link'; }, 2000);
      });
    });
  });

  // Live classes (ADR 0028): attendance bars get their width from data (no inline styles under the CSP),
  // and the register can mark everyone not yet marked as present in one go.
  Array.prototype.forEach.call(document.querySelectorAll('[data-width]'), function (bar) {
    var w = Math.max(0, Math.min(100, parseInt(bar.getAttribute('data-width'), 10) || 0));
    bar.style.width = w + '%';
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-join]'), function (box) {
    var link = box.querySelector('a');
    var form = box.querySelector('[data-checkin]');
    if (!link || !form) { return; }
    link.addEventListener('click', function () {
      try {
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', keepalive: true, headers: { Accept: 'application/json' } });
      } catch (e) { /* the register can still be taken by the teacher */ }
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-register]'), function (form) {
    var all = form.querySelector('[data-all-present]');
    if (!all) { return; }
    all.hidden = false;
    all.addEventListener('click', function () {
      Array.prototype.forEach.call(form.querySelectorAll('fieldset'), function (fs) {
        if (fs.querySelector('input[type="radio"]:checked')) { return; }
        var present = fs.querySelector('input[type="radio"][value="present"]');
        if (present) { present.checked = true; }
      });
    });
  });

  // The Code studio has its own script (studio.js), loaded only on its pages.

  // Show / hide password, and a warning when Caps Lock is on while typing one.
  Array.prototype.forEach.call(document.querySelectorAll('[data-reveal]'), function (button) {
    var input = document.getElementById(button.getAttribute('data-reveal'));
    if (!input) { return; }
    var word = button.querySelector('[data-reveal-word]') || button;
    button.hidden = false;
    button.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      word.textContent = show ? 'Hide' : 'Show';
      input.focus();
    });
    // Never leave a password readable in the browser's form memory or on screen after sending.
    if (input.form) { input.form.addEventListener('submit', function () { input.type = 'password'; word.textContent = 'Show'; }); }
    var caps = document.createElement('p');
    caps.className = 'caps-note';
    caps.id = input.id + '-caps';
    caps.hidden = true;
    caps.textContent = 'Caps Lock is on.';
    button.parentNode.insertAdjacentElement('afterend', caps);
    function checkCaps(e) {
      if (!e.getModifierState) { return; }
      var on = e.getModifierState('CapsLock');
      if (caps.hidden === on) {
        caps.hidden = !on;
        var ids = (input.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== caps.id; });
        if (on) { ids.push(caps.id); }
        if (ids.length) { input.setAttribute('aria-describedby', ids.join(' ')); } else { input.removeAttribute('aria-describedby'); }
      }
    }
    input.addEventListener('keydown', checkCaps);
    input.addEventListener('keyup', checkCaps);
    input.addEventListener('blur', function () { caps.hidden = true; });
  });

  // ---- Offline (ADR 0033) ----
  // Lessons saved on this device for reading without a connection, and progress made offline
  // kept in an outbox until the connection is back. Everything is per person: another person
  // signing in on the same device, or signing out, removes it.
  var ownerMeta = document.querySelector('meta[name="dml-device-owner"]');
  var owner = ownerMeta ? ownerMeta.getAttribute('content') : '';
  var canKeep = !!(window.caches && store && window.fetch);
  var SAVED_CACHE = 'dml-saved-' + owner;
  var indexKey = 'dml-saved:' + owner;
  var outboxKey = 'dml-outbox:' + owner;
  function readJson(key) { try { return JSON.parse((store && store.getItem(key)) || '[]'); } catch (e) { return []; } }
  function writeJson(key, value) { try { if (store) { store.setItem(key, JSON.stringify(value)); } } catch (e) { /* storage full */ } }
  function forgetDevice(keepOwner) {
    if (window.caches) {
      caches.keys().then(function (keys) {
        keys.forEach(function (k) { if (k.indexOf('dml-saved-') === 0 && k !== 'dml-saved-' + keepOwner) { caches.delete(k); } });
      });
    }
    if (store) {
      for (var i = store.length - 1; i >= 0; i--) {
        var k = store.key(i) || '';
        if ((k.indexOf('dml-saved:') === 0 || k.indexOf('dml-outbox:') === 0) && k !== 'dml-saved:' + keepOwner && k !== 'dml-outbox:' + keepOwner) { store.removeItem(k); }
      }
    }
  }
  if (owner) { forgetDevice(owner); }
  Array.prototype.forEach.call(document.querySelectorAll('form[action="/logout"]'), function (form) {
    form.addEventListener('submit', function () { forgetDevice('-'); });
  });

  function savedList() { return readJson(indexKey); }
  function isSaved(url) { return savedList().some(function (x) { return x.u === url; }); }
  function saveLesson(url, title, course) {
    return fetch(url, { credentials: 'same-origin' }).then(function (response) {
      if (!response.ok || response.redirected) { throw new Error('not saved'); }
      return caches.open(SAVED_CACHE).then(function (cache) { return cache.put(url, response); });
    }).then(function () {
      var list = savedList().filter(function (x) { return x.u !== url; });
      list.unshift({ u: url, t: title, c: course, at: Date.now() });
      writeJson(indexKey, list.slice(0, 300));
    });
  }
  function removeLesson(url) {
    writeJson(indexKey, savedList().filter(function (x) { return x.u !== url; }));
    return caches.open(SAVED_CACHE).then(function (cache) { return cache.delete(url); });
  }

  if (canKeep && owner) {
    // One lesson.
    Array.prototype.forEach.call(document.querySelectorAll('[data-offline-save]'), function (bar) {
      var url = location.pathname;
      var button = bar.querySelector('[data-offline-toggle]');
      var word = bar.querySelector('[data-offline-word]');
      var status = bar.querySelector('[data-offline-status]');
      var note = status ? status.textContent : '';
      function show() {
        var saved = isSaved(url);
        word.textContent = saved ? 'Saved for offline: remove' : 'Save for offline';
        button.setAttribute('aria-pressed', saved ? 'true' : 'false');
        bar.classList.toggle('is-saved', saved);
      }
      bar.hidden = false;
      show();
      button.addEventListener('click', function () {
        if (isSaved(url)) { removeLesson(url).then(function () { show(); status.textContent = 'Removed from this device.'; }); return; }
        if (!navigator.onLine) { status.textContent = 'Connect to save this lesson for offline.'; return; }
        status.textContent = 'Saving…';
        saveLesson(url, bar.getAttribute('data-title') || document.title, bar.getAttribute('data-course') || '').then(function () {
          show(); status.textContent = 'Saved. Open it from this device any time, even with no connection.' + (note ? ' ' + note : '');
        }).catch(function () { status.textContent = 'Could not save it. Check the connection and try again.'; });
      });
    });
    // A whole course.
    Array.prototype.forEach.call(document.querySelectorAll('[data-offline-all]'), function (bar) {
      var lessons;
      try { lessons = JSON.parse(bar.getAttribute('data-offline-all') || '[]'); } catch (e) { lessons = []; }
      if (!lessons.length) { return; }
      var button = bar.querySelector('[data-offline-toggle]');
      var word = bar.querySelector('[data-offline-word]');
      var status = bar.querySelector('[data-offline-status]');
      var course = bar.getAttribute('data-course') || '';
      function count() { return lessons.filter(function (l) { return isSaved(l.u); }).length; }
      function show() {
        var n = count();
        word.textContent = n === lessons.length ? 'Saved for offline: remove' : 'Save the whole course for offline';
        status.textContent = n > 0 && n < lessons.length ? n + ' of ' + lessons.length + ' lessons saved on this device.' : (n === lessons.length ? 'All ' + n + ' lessons are on this device.' : '');
      }
      bar.hidden = false;
      show();
      button.addEventListener('click', function () {
        if (count() === lessons.length) {
          Promise.all(lessons.map(function (l) { return removeLesson(l.u); })).then(show);
          return;
        }
        if (!navigator.onLine) { status.textContent = 'Connect to save the course for offline.'; return; }
        button.setAttribute('aria-disabled', 'true');
        var done = 0;
        var next = function (i) {
          if (i >= lessons.length) { button.removeAttribute('aria-disabled'); show(); return; }
          status.textContent = 'Saving ' + (i + 1) + ' of ' + lessons.length + '…';
          (isSaved(lessons[i].u) ? Promise.resolve() : saveLesson(lessons[i].u, lessons[i].t, course)).then(function () { done++; next(i + 1); })
            .catch(function () { button.removeAttribute('aria-disabled'); status.textContent = 'Stopped after ' + done + ' lessons: the connection dropped. Try again to save the rest.'; });
        };
        next(0);
      });
    });
    // The list of saved lessons (Saved and notes page, and the offline page).
    Array.prototype.forEach.call(document.querySelectorAll('[data-offline-library]'), function (box) {
      var list = savedList();
      var ul = box.querySelector('ul');
      if (!ul || !list.length) { return; }
      list.forEach(function (x) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = x.u;
        a.textContent = x.t;
        li.appendChild(a);
        if (x.c) { var span = document.createElement('span'); span.className = 'small muted'; span.textContent = ' ' + x.c; li.appendChild(span); }
        ul.appendChild(li);
      });
      box.hidden = false;
    });
  }

  // Progress made offline waits in an outbox (forms marked data-offline-queue) and is sent when the connection returns.
  function outbox() { return readJson(outboxKey); }
  function say(text) {
    var live = document.getElementById('netstatus');
    if (!live) { return; }
    live.textContent = text;
    live.hidden = false;
    if (navigator.onLine) { window.setTimeout(function () { live.hidden = true; }, 6000); }
  }
  function flushOutbox() {
    var items = outbox();
    if (!items.length || !navigator.onLine || !window.fetch) { return; }
    var sent = 0;
    var keep = [];
    var signIn = false;
    var chain = Promise.resolve();
    items.forEach(function (item) {
      chain = chain.then(function () {
        var body = new URLSearchParams();
        item.f.forEach(function (pair) { body.append(pair[0], pair[1]); });
        return fetch(item.a, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
          if (new URL(r.url).pathname.indexOf('/login') === 0) { signIn = true; keep.push(item); return; }
          if (r.ok) { sent++; } // other refusals (for example a lesson removed since) are dropped
        }).catch(function () { keep.push(item); });
      });
    });
    chain.then(function () {
      writeJson(outboxKey, keep);
      if (sent) { say(sent === 1 ? 'Back online: 1 thing you did offline was sent.' : 'Back online: ' + sent + ' things you did offline were sent.'); }
      if (signIn) { say('Sign in again to send what you did offline. It is still saved on this device.'); }
    });
  }
  window.addEventListener('online', flushOutbox);
  if (owner) { flushOutbox(); }

  // Sending a form with no connection: queue it when that is safe, otherwise say plainly that it needs a connection.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || navigator.onLine || !(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') { return; }
    e.preventDefault();
    var label = form.getAttribute('data-offline-queue');
    var message;
    if (label !== null && owner && store) {
      var fields = [];
      new FormData(form).forEach(function (value, key) { if (typeof value === 'string') { fields.push([key, value]); } });
      var items = outbox().filter(function (x) { return !(x.a === form.action && x.l === label); });
      items.push({ a: form.action, f: fields, l: label, at: Date.now() });
      writeJson(outboxKey, items.slice(-200));
      message = 'Saved on this device. It will be sent when you are back online.';
      Array.prototype.forEach.call(form.querySelectorAll('button[type=submit], button:not([type])'), function (b) { b.setAttribute('aria-disabled', 'true'); });
    } else {
      message = 'You are offline. This needs a connection: try again when you are back online.';
    }
    var note = form.querySelector('.offline-note');
    if (!note) { note = document.createElement('p'); note.className = 'offline-note small'; note.setAttribute('role', 'status'); form.appendChild(note); }
    note.textContent = message;
  });

  // Sending a form: the button shows it is working and a second tap does not send it twice.
  // Forms that handle their own sending (they cancel the event) and GET forms are left alone.
  function setBusy(form, busy) {
    if (busy) { form.setAttribute('data-sending', ''); } else { form.removeAttribute('data-sending'); }
    Array.prototype.forEach.call(form.querySelectorAll('button[type=submit], button:not([type])'), function (b) {
      if (busy) { b.setAttribute('aria-disabled', 'true'); } else if (!b.hasAttribute('data-keep-aria-disabled')) { b.removeAttribute('aria-disabled'); b.classList.remove('is-busy'); }
    });
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post' || form.hasAttribute('data-no-busy') || form.target) { return; }
    if (form.hasAttribute('data-sending')) { e.preventDefault(); return; }
    setBusy(form, true);
    var b = e.submitter;
    if (b && b.tagName === 'BUTTON') { b.classList.add('is-busy'); }
    // A download, or a page that never arrives: give the button back after a while.
    window.setTimeout(function () { setBusy(form, false); }, 15000);
  });
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { Array.prototype.forEach.call(document.querySelectorAll('form[data-sending]'), function (f) { setBusy(f, false); }); }
  });

  // Unsaved changes: warn before leaving a page with edited writing (forms with a text area, or marked data-unsaved).
  var dirty = [];
  Array.prototype.forEach.call(document.querySelectorAll('form[method=post]'), function (form) {
    if (!form.hasAttribute('data-unsaved') && !form.querySelector('textarea')) { return; }
    if (form.hasAttribute('data-draft-key') || form.hasAttribute('data-no-unsaved')) { return; }
    var mark = function (e) { if (e.target && e.target.type !== 'file' && dirty.indexOf(form) === -1) { dirty.push(form); } };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', function (e) { if (!e.defaultPrevented) { dirty = dirty.filter(function (f) { return f !== form; }); } });
    form.addEventListener('reset', function () { dirty = dirty.filter(function (f) { return f !== form; }); });
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty.length) { e.preventDefault(); e.returnValue = ''; }
  });

  // Lists with filter boxes: a changed choice shows the new list straight away (the button stays for typing).
  Array.prototype.forEach.call(document.querySelectorAll('form[data-autosubmit]'), function (form) {
    Array.prototype.forEach.call(form.querySelectorAll('select'), function (sel) {
      sel.addEventListener('change', function () { if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); } });
    });
  });

  // Tables people compare: headings become sort buttons (the order is only on this page; nothing is saved).
  Array.prototype.forEach.call(document.querySelectorAll('table[data-sortable]'), function (table) {
    var body = table.tBodies[0];
    var head = table.tHead && table.tHead.rows[0];
    if (!body || !head || body.rows.length < 2) { return; }
    var original = Array.prototype.slice.call(body.rows);
    Array.prototype.forEach.call(head.cells, function (th, col) {
      var onlyHidden = th.children.length === 1 && th.children[0].classList.contains('visually-hidden') && th.textContent.trim() === th.children[0].textContent.trim();
      if (th.hasAttribute('data-no-sort') || th.textContent.trim() === '' || onlyHidden || th.querySelector('a, button, input')) { return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'sort-button';
      while (th.firstChild) { button.appendChild(th.firstChild); }
      var mark = document.createElement('span');
      mark.className = 'sort-mark';
      mark.setAttribute('aria-hidden', 'true');
      button.appendChild(mark);
      th.appendChild(button);
      button.addEventListener('click', function () {
        var now = th.getAttribute('aria-sort');
        var dir = now === 'ascending' ? 'descending' : (now === 'descending' ? 'none' : 'ascending');
        Array.prototype.forEach.call(head.cells, function (c) { c.removeAttribute('aria-sort'); });
        var rows = original.slice();
        if (dir !== 'none') {
          th.setAttribute('aria-sort', dir);
          var key = function (row) {
            var cell = row.cells[col];
            if (!cell) { return ''; }
            var raw = cell.getAttribute('data-sort') !== null ? cell.getAttribute('data-sort') : cell.textContent.trim();
            var num = raw.replace(/[₦,%\s]/g, '');
            return num !== '' && !isNaN(num) ? parseFloat(num) : raw.toLowerCase();
          };
          rows.sort(function (a, b) {
            var x = key(a), y = key(b);
            var r = (typeof x === 'number' && typeof y === 'number') ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true });
            return dir === 'ascending' ? r : -r;
          });
        }
        rows.forEach(function (r) { body.appendChild(r); });
      });
    });
  });

  // Wide tables and code that scroll sideways can be scrolled with the keyboard too.
  function scrollRegions() {
    var used = {};
    Array.prototype.forEach.call(document.querySelectorAll('[data-scroll-region]'), function (el) { var l = el.getAttribute('aria-label'); if (l) { used[l] = (used[l] || 0) + 1; } });
    Array.prototype.forEach.call(document.querySelectorAll('.table-wrap, .report-scroll, .code-block pre'), function (el) {
      var scrolls = el.scrollWidth > el.clientWidth + 1;
      if (scrolls && !el.hasAttribute('tabindex')) {
        el.setAttribute('tabindex', '0');
        el.setAttribute('data-scroll-region', el.hasAttribute('aria-label') ? 'kept' : '');
        if (!el.hasAttribute('aria-label')) {
          var t = el.querySelector('caption');
          var h = el.closest('section') && el.closest('section').querySelector('h2, h3');
          el.setAttribute('role', 'region');
          var label = (t || h) ? (t || h).textContent.trim() + (el.tagName === 'PRE' ? ' (code)' : ' (table)') : (el.tagName === 'PRE' ? 'Code' : 'Table');
          // Each region needs its own name (two code samples under one heading become "… 1" and "… 2").
          used[label] = (used[label] || 0) + 1;
          el.setAttribute('aria-label', used[label] > 1 ? label + ' ' + used[label] : label);
        }
      } else if (!scrolls && el.hasAttribute('data-scroll-region')) {
        el.removeAttribute('tabindex');
        if (el.getAttribute('data-scroll-region') !== 'kept') { el.removeAttribute('role'); el.removeAttribute('aria-label'); }
        el.removeAttribute('data-scroll-region');
      }
    });
  }
  scrollRegions();
  var resizeTimer = null;
  window.addEventListener('resize', function () { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(scrollRegions, 200); });

  // Service worker caches only public static assets and an offline page.
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* optional */ });
  }
})();

// TridaxLabs credit: pointing at (or focusing) the name opens a panel of what
// TridaxLabs builds; a click or tap keeps it open. Without JS it is a plain link.
(function () {
  var pop = document.querySelector('[data-tridax-pop]');
  var triggers = document.querySelectorAll('[data-tridax]');
  if (!pop || !triggers.length) { return; }
  var closeBtn = pop.querySelector('[data-tridax-close]');
  var current = null, mode = null, timer = null;
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  var place = function (trigger) {
    var narrow = window.innerWidth < 640;
    pop.classList.toggle('is-sheet', narrow);
    if (narrow) { pop.style.left = ''; pop.style.top = ''; pop.style.bottom = ''; return; }
    var r = trigger.getBoundingClientRect();
    var w = pop.offsetWidth, h = pop.offsetHeight, gap = 10;
    var left = Math.min(Math.max(12, r.left - 24), window.innerWidth - w - 12);
    var top = r.top - h - gap >= 12 ? r.top - h - gap : Math.min(r.bottom + gap, window.innerHeight - h - 12);
    pop.style.left = Math.round(left) + 'px';
    pop.style.top = Math.round(Math.max(12, top)) + 'px';
  };
  var open = function (trigger, how) {
    clearTimeout(timer);
    if (current && current !== trigger) { current.setAttribute('aria-expanded', 'false'); }
    current = trigger;
    mode = how;
    pop.hidden = false;
    place(trigger);
    trigger.setAttribute('aria-expanded', 'true');
    if (how === 'click' && closeBtn) { closeBtn.focus(); }
  };
  var close = function (returnFocus) {
    clearTimeout(timer);
    if (pop.hidden) { return; }
    pop.hidden = true;
    if (current) {
      current.setAttribute('aria-expanded', 'false');
      if (returnFocus) { current.focus(); }
    }
    current = null;
    mode = null;
  };
  var later = function () {
    clearTimeout(timer);
    timer = setTimeout(function () { if (mode === 'hover') { close(false); } }, 300);
  };

  Array.prototype.forEach.call(triggers, function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      if (!pop.hidden && current === t && mode === 'click') { close(true); } else { open(t, 'click'); }
    });
    if (finePointer) {
      t.addEventListener('mouseenter', function () {
        clearTimeout(timer);
        if (pop.hidden) { timer = setTimeout(function () { open(t, 'hover'); }, 350); }
      });
      t.addEventListener('mouseleave', later);
    }
    t.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(t, 'click'); }
    });
  });
  pop.addEventListener('mouseenter', function () { clearTimeout(timer); });
  pop.addEventListener('mouseleave', later);
  pop.addEventListener('focusin', function () { if (mode === 'hover') { mode = 'click'; } });
  if (closeBtn) { closeBtn.addEventListener('click', function () { close(true); }); }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !pop.hidden) { close(pop.contains(document.activeElement)); }
  });
  document.addEventListener('click', function (e) {
    if (pop.hidden || pop.contains(e.target)) { return; }
    var onTrigger = false;
    Array.prototype.forEach.call(triggers, function (t) { onTrigger = onTrigger || t.contains(e.target); });
    if (!onTrigger) { close(false); }
  });
  window.addEventListener('resize', function () { if (!pop.hidden && current) { place(current); } });
  window.addEventListener('scroll', function () { if (mode === 'hover') { close(false); } else if (!pop.hidden && current) { place(current); } }, { passive: true });
}());
