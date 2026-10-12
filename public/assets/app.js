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
  var side = document.querySelector('.lesson-side');
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
        if (max && f.size > max) { problems.push('“' + f.name + '” is ' + (f.size / 1048576).toFixed(1) + ' MB; the limit is ' + Math.floor(max / 1048576) + ' MB.'); }
      });
      note.textContent = problems.join(' ');
    });
  });

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

  // Service worker caches only public static assets and an offline page.
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* optional */ });
  }
})();
