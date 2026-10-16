// Small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

  // Connection banner: tell people plainly when they are offline.
  var banner = document.getElementById('netstatus');
  function updateNet() { if (banner) { banner.hidden = navigator.onLine; } }
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
  Array.prototype.forEach.call(document.querySelectorAll('input[type=file][data-max-bytes]'), function (input) {
    var note = document.createElement('p');
    note.className = 'upload-warning';
    note.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', note);
    input.addEventListener('change', function () {
      var max = parseInt(input.getAttribute('data-max-bytes') || '0', 10);
      var maxFiles = parseInt(input.getAttribute('data-max-files') || '0', 10);
      var problems = [];
      if (maxFiles && input.files.length > maxFiles) { problems.push('Choose at most ' + maxFiles + ' files.'); }
      Array.prototype.forEach.call(input.files, function (f) {
        if (max && f.size > max) { problems.push('“' + f.name + '” is ' + size(f.size) + '; the limit is ' + size(max) + '.'); }
      });
      note.textContent = problems.join(' ');
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
  var opener = document.querySelector('[data-nav-open]');
  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    if (scrim) { scrim.hidden = !open; }
    if (opener) { opener.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (open && sidebar) {
      var first = sidebar.querySelector('a[aria-current="page"]') || sidebar.querySelector('a');
      if (first) { first.focus(); }
    } else if (!open && opener && sidebar && sidebar.contains(document.activeElement)) {
      opener.focus();
    }
  }
  if (sidebar && opener) {
    opener.setAttribute('role', 'button');
    opener.setAttribute('aria-expanded', 'false');
    opener.addEventListener('click', function (e) { e.preventDefault(); setNav(true); });
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

  // Code playground: send the code to the sandboxed runner (an opaque-origin iframe) and show what it prints.
  Array.prototype.forEach.call(document.querySelectorAll('[data-playground]'), function (pg) {
    var code = pg.querySelector('[data-pg-code]');
    var out = pg.querySelector('[data-pg-output]');
    var status = pg.querySelector('[data-pg-status]');
    var runBtn = pg.querySelector('[data-pg-run]');
    var clearBtn = pg.querySelector('[data-pg-clear]');
    var frame = pg.querySelector('[data-pg-runner]');
    var example = pg.querySelector('[data-pg-example]');
    var gutter = pg.querySelector('[data-pg-gutter]');
    if (!code || !out || !runBtn || !frame) { return; }
    runBtn.hidden = false;
    if (clearBtn) { clearBtn.hidden = false; }
    if (example) { example.hidden = false; }
    var ready = false, queued = false, seq = 0, waiting = null;

    var numbers = function () {
      if (!gutter) { return; }
      var n = code.value.split('\n').length;
      var text = '';
      for (var i = 1; i <= n; i++) { text += i + '\n'; }
      gutter.textContent = text;
      gutter.scrollTop = code.scrollTop;
    };
    code.addEventListener('input', numbers);
    code.addEventListener('scroll', function () { if (gutter) { gutter.scrollTop = code.scrollTop; } });
    numbers();

    var line = function (text, cls) {
      var span = document.createElement('span');
      span.className = cls || 'pg-line';
      span.textContent = text;
      out.appendChild(span);
    };
    var say = function (text) { if (status) { status.textContent = text; } };

    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) { return; }
      var d = event.data || {};
      if (d.ready) {
        if (!ready) { ready = true; if (queued) { queued = false; run(); } }
        return;
      }
      if (!waiting || d.id !== waiting) { return; }
      waiting = null;
      runBtn.disabled = false;
      out.textContent = '';
      (Array.isArray(d.lines) ? d.lines : []).forEach(function (l) { line(String(l)); });
      if (d.cut) { line('… more lines were printed than can be shown (300 at most).', 'pg-note'); }
      if (typeof d.error === 'string' && d.error) { line(d.error, 'pg-error'); }
      if (!(d.lines && d.lines.length) && !d.error) { line('Your code ran, but printed nothing. Use console.log(…) to show a value.', 'pg-note'); }
      say(d.error ? 'Stopped with an error' : 'Ran in ' + (d.ms || 0) + ' ms');
    });

    var run = function () {
      if (!ready) {
        // The runner posts "ready" when it loads; ask again in case that came before this script.
        queued = true;
        say('Starting…');
        try { frame.contentWindow.postMessage({ mode: 'ping' }, '*'); } catch (e) { /* not loaded yet */ }
        return;
      }
      seq += 1;
      waiting = 'pg' + seq;
      runBtn.disabled = true;
      say('Running…');
      frame.contentWindow.postMessage({ mode: 'script', id: waiting, code: code.value }, '*');
      setTimeout(function () {
        if (waiting === 'pg' + seq) { waiting = null; runBtn.disabled = false; say('The runner did not answer. Reload the page and try again.'); }
      }, 6000);
    };
    runBtn.addEventListener('click', run);
    if (clearBtn) {
      clearBtn.addEventListener('click', function () { out.textContent = ''; say(''); });
    }
    if (example) {
      example.addEventListener('change', function () {
        if (example.value === '') { return; }
        code.value = example.value;
        code.dataset.dirty = '0';
        example.value = '';
        numbers();
        code.focus();
      });
    }
    code.addEventListener('input', function () { code.dataset.dirty = '1'; });
    code.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); run(); return; }
      // Tab indents by two spaces; Escape then Tab leaves the editor, so keyboard users are never trapped.
      if (e.key === 'Tab' && !e.shiftKey && !code.dataset.escaped) {
        e.preventDefault();
        var start = code.selectionStart, end = code.selectionEnd;
        code.value = code.value.slice(0, start) + '  ' + code.value.slice(end);
        code.selectionStart = code.selectionEnd = start + 2;
        numbers();
      }
      if (e.key === 'Escape') { code.dataset.escaped = '1'; }
      else if (e.key !== 'Tab') { delete code.dataset.escaped; }
    });
  });

  // Show / hide password on sign-up.
  Array.prototype.forEach.call(document.querySelectorAll('[data-reveal]'), function (button) {
    var input = document.getElementById(button.getAttribute('data-reveal'));
    if (!input) { return; }
    button.hidden = false;
    button.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.textContent = show ? 'Hide' : 'Show';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });

  // Service worker caches only public static assets and an offline page.
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
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
