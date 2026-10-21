/*
 * The online classroom (ADR 0029). Embeds the school's Jitsi Meet with the
 * pass the server made for this person, in one of three data modes:
 *   audio  - no video at all, the least data;
 *   saver  - small video of only the person speaking;
 *   video  - normal video, a few people at a time.
 * It tells the server when the person joins, that they are still there (once
 * a minute) and when they leave, which marks attendance and works out time in
 * class. When the connection drops it says so and offers a way back in.
 */
(function () {
  'use strict';

  // Waiting page: open the classroom by itself when it is time.
  var waiting = document.querySelector('[data-reload-at]');
  if (waiting) {
    var at = parseInt(waiting.getAttribute('data-reload-at') || '0', 10);
    var serverNow = parseInt(waiting.getAttribute('data-now') || '0', 10);
    var wait = Math.max(5, at - serverNow + 3) * 1000;
    if (wait < 6 * 3600 * 1000) {
      window.setTimeout(function () { window.location.reload(); }, wait);
    }
    return;
  }

  var root = document.querySelector('[data-room]');
  if (!root) { return; }

  var domain = root.getAttribute('data-domain') || '';
  var roomName = root.getAttribute('data-room-name') || '';
  var jwt = root.getAttribute('data-jwt') || '';
  var moderator = root.getAttribute('data-moderator') === '1';
  var lowData = root.getAttribute('data-low-data') === '1';
  var subject = root.getAttribute('data-subject') || '';
  var signalUrl = root.getAttribute('data-signal') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var stage = root.querySelector('[data-room-stage]');
  var statusEl = root.querySelector('[data-room-status]');
  var modes = root.querySelectorAll('[data-room-modes] input');
  var KEY = 'dml.classroom.mode';

  var api = null;
  var joined = false;
  var leftOnPurpose = false;
  var heartbeat = null;

  function status(text, tone) {
    statusEl.textContent = text;
    statusEl.className = 'room-status' + (tone ? ' is-' + tone : '');
    statusEl.hidden = text === '';
  }

  function savedMode() {
    try {
      var m = window.localStorage.getItem(KEY);
      if (m === 'audio' || m === 'saver' || m === 'video') { return m; }
    } catch (e) { /* storage may be blocked */ }
    return lowData && !moderator ? 'saver' : 'video';
  }

  function saveMode(m) {
    try { window.localStorage.setItem(KEY, m); } catch (e) { /* not essential */ }
  }

  function signal(type, useBeacon) {
    var body = new FormData();
    body.append('_token', csrf);
    body.append('type', type);
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon(signalUrl, body);
      return;
    }
    if (!window.fetch) { return; }
    window.fetch(signalUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .catch(function () { /* the next "still here" will try again */ });
  }

  function config(mode) {
    var audio = mode === 'audio';
    var saver = mode === 'saver';
    var height = saver ? 180 : 360;
    var learnerTools = ['microphone', 'camera', 'chat', 'raisehand', 'tileview', 'participants-pane', 'settings', 'fullscreen', 'toggle-camera', 'hangup'];
    var teacherTools = ['microphone', 'camera', 'desktop', 'chat', 'raisehand', 'participants-pane', 'mute-everyone', 'mute-video-everyone', 'tileview',
      'settings', 'fullscreen', 'toggle-camera', 'hangup'];
    return {
      subject: subject,
      prejoinConfig: { enabled: false },
      prejoinPageEnabled: false,
      disableDeepLinking: true,
      readOnlyName: true,
      startWithAudioMuted: !moderator,
      startWithVideoMuted: !moderator || audio || saver,
      startAudioOnly: audio,
      channelLastN: audio ? 0 : (saver ? 1 : (moderator ? 6 : 4)),
      resolution: height,
      constraints: { video: { height: { ideal: height, max: height, min: 90 } } },
      enableLayerSuspension: true,
      disableAudioLevels: true,
      disableInviteFunctions: true,
      doNotStoreRoom: true,
      disableThirdPartyRequests: true,
      enableWelcomePage: false,
      toolbarButtons: moderator ? teacherTools : learnerTools
    };
  }

  function stop() {
    if (heartbeat) { window.clearInterval(heartbeat); heartbeat = null; }
    if (api) {
      try { api.dispose(); } catch (e) { /* already gone */ }
      api = null;
    }
    joined = false;
  }

  function start(mode) {
    stop();
    leftOnPurpose = false;
    if (typeof window.JitsiMeetExternalAPI !== 'function') {
      status('The classroom server could not be reached. Check your connection, then choose Rejoin.', 'error');
      return;
    }
    status('Connecting to the classroom…', '');
    stage.textContent = '';
    try {
      api = new window.JitsiMeetExternalAPI(domain, {
        roomName: roomName,
        jwt: jwt,
        parentNode: stage,
        width: '100%',
        height: '100%',
        lang: 'en',
        configOverwrite: config(mode),
        interfaceConfigOverwrite: { MOBILE_APP_PROMO: false, SHOW_JITSI_WATERMARK: false, SHOW_WATERMARK_FOR_GUESTS: false }
      });
    } catch (e) {
      status('The classroom could not start in this browser. Try Chrome or Firefox, or rejoin.', 'error');
      return;
    }
    api.addListener('videoConferenceJoined', function () {
      joined = true;
      status('', '');
      signal('join');
      heartbeat = window.setInterval(function () { signal('here'); }, 60000);
    });
    api.addListener('videoConferenceLeft', function () {
      if (heartbeat) { window.clearInterval(heartbeat); heartbeat = null; }
      if (joined) { signal('leave'); }
      joined = false;
      status(leftOnPurpose ? 'You left the class.' : 'You are out of the class.', 'warn');
      showRejoin();
    });
    api.addListener('readyToClose', function () {
      leftOnPurpose = true;
      stop();
      stage.textContent = '';
      status('You left the class. Choose Rejoin to go back in.', 'warn');
      showRejoin();
    });
  }

  function showRejoin() {
    if (statusEl.querySelector('button')) { return; }
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'button button-small';
    b.textContent = 'Rejoin';
    b.addEventListener('click', rejoin);
    statusEl.appendChild(document.createTextNode(' '));
    statusEl.appendChild(b);
  }

  // A fresh page brings a fresh pass, so rejoining always works while the class is on.
  function rejoin() {
    stop();
    window.location.reload();
  }

  Array.prototype.forEach.call(root.querySelectorAll('[data-room-rejoin]'), function (b) {
    b.addEventListener('click', rejoin);
  });

  var mode = savedMode();
  Array.prototype.forEach.call(modes, function (input) {
    input.checked = input.value === mode;
    input.addEventListener('change', function () {
      if (!input.checked || input.value === mode) { return; }
      mode = input.value;
      saveMode(mode);
      start(mode);
    });
  });

  window.addEventListener('offline', function () {
    status('Your connection dropped. The class will carry on when it is back.', 'warn');
  });
  window.addEventListener('online', function () {
    status(joined ? '' : 'You are back online.', '');
    if (!joined) { showRejoin(); }
  });
  window.addEventListener('pagehide', function () {
    if (joined) { signal('leave', true); }
  });

  start(mode);
}());
