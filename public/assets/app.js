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

  // Service worker caches only public static assets and an offline page.
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('/sw.js').catch(function () { /* optional */ });
  }
})();
