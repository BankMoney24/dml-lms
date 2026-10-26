/*
 * The online classroom (ADR 0029, 0030), for classes and meetings. Embeds the
 * school's Jitsi Meet with the pass the server made for this person, in one
 * of three data modes:
 *   audio  - no video at all, the least data;
 *   saver  - small video of only the person speaking;
 *   video  - normal video, a few people at a time.
 * In lecture mode the teacher's page turns on "ask to speak" (audio and video
 * moderation) and makes everyone follow the teacher's view.
 * It tells the server when the person joins, that they are still there (once
 * a minute) and when they leave, shows the time left, lets whoever runs the
 * room add time or end it, and offers a way back in when the connection drops.
 */
(function () {
  'use strict';

  // Waiting page: open the room by itself when it is time.
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
  var lecture = root.getAttribute('data-lecture') === '1';
  var subject = root.getAttribute('data-subject') || '';
  var signalUrl = root.getAttribute('data-signal') || '';
  var extendUrl = root.getAttribute('data-extend') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var endsAt = parseInt(root.getAttribute('data-ends') || '0', 10);
  // The end time in the school's time zone, as the server wrote it.
  var endsLabel = root.getAttribute('data-ends-label') || '';
  // The phone's clock may be wrong; count from the server's time.
  var skew = parseInt(root.getAttribute('data-now') || '0', 10) * 1000 - Date.now();
  var stage = root.querySelector('[data-room-stage]');
  var statusEl = root.querySelector('[data-room-status]');
  var clockEl = root.querySelector('[data-room-clock]');
  var modes = root.querySelectorAll('[data-room-modes] input');
  var KEY = 'dml.classroom.mode';

  var api = null;
  var joined = false;
  var leftOnPurpose = false;
  var heartbeat = null;
  var lectureSet = false;

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

  function post(url, fields, useBeacon) {
    var body = new FormData();
    body.append('_token', csrf);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon(url, body);
      return null;
    }
    if (!window.fetch) { return null; }
    return window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
  }

  function signal(type, useBeacon) {
    var p = post(signalUrl, { type: type }, useBeacon);
    if (p) { p.catch(function () { /* the next "still here" will try again */ }); }
  }

  // Time left, from the server's end time.
  function tick() {
    if (!clockEl || !endsAt) { return; }
    var left = Math.round((endsAt * 1000 - (Date.now() + skew)) / 60000);
    var hhmm = endsLabel;
    if (left > 90) {
      clockEl.textContent = 'Ends at ' + hhmm;
    } else if (left > 0) {
      clockEl.textContent = left + (left === 1 ? ' minute' : ' minutes') + ' left, ends at ' + hhmm;
    } else {
      clockEl.textContent = 'Time is up' + (moderator ? '. Add time to keep going.' : '.');
    }
    clockEl.classList.toggle('is-low', left <= 5);
  }
  tick();
  window.setInterval(tick, 20000);

  Array.prototype.forEach.call(root.querySelectorAll('[data-room-extend]'), function (b) {
    b.addEventListener('click', function () {
      if (!extendUrl) { return; }
      b.disabled = true;
      var p = post(extendUrl, { minutes: b.getAttribute('data-room-extend') || '30' });
      if (!p) { b.disabled = false; return; }
      p.then(function (r) { return r.json(); }).then(function (data) {
        b.disabled = false;
        if (data && data.ok) {
          endsAt = data.ends;
          endsLabel = data.ends_label || endsLabel;
          tick();
          status('Time added.', '');
          window.setTimeout(function () { if (joined) { status('', ''); } }, 3000);
        } else {
          status((data && data.error) || 'Time could not be added.', 'warn');
        }
      }).catch(function () { b.disabled = false; status('Time could not be added. Check the connection.', 'warn'); });
    });
  });

  // Ending for everyone: close the call for all, then tell the server.
  var endForm = root.querySelector('[data-room-end]');
  if (endForm) {
    endForm.addEventListener('submit', function (e) {
      if (!api || endForm.getAttribute('data-sent') === '1') { return; }
      e.preventDefault();
      endForm.setAttribute('data-sent', '1');
      try { api.executeCommand('endConference'); } catch (err) { /* older servers: people leave as the page closes */ }
      window.setTimeout(function () { endForm.submit(); }, 600);
    });
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

  // Lecture mode: only the person running the room does this, once it is a moderator.
  function setUpLecture() {
    if (!lecture || !moderator || lectureSet || !api) { return; }
    lectureSet = true;
    try {
      api.executeCommand('toggleModeration', true, 'audio');
      api.executeCommand('toggleModeration', true, 'video');
      api.executeCommand('setFollowMe', true);
    } catch (e) { lectureSet = false; }
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
    lectureSet = false;
    if (typeof window.JitsiMeetExternalAPI !== 'function') {
      status('The classroom server could not be reached. Check your connection, then choose Rejoin.', 'error');
      showRejoin();
      return;
    }
    status('Connecting…', '');
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
      window.setTimeout(setUpLecture, 1500);
    });
    api.addListener('participantRoleChanged', function (e) {
      if (e && e.role === 'moderator') { window.setTimeout(setUpLecture, 500); }
    });
    api.addListener('videoConferenceLeft', function () {
      if (heartbeat) { window.clearInterval(heartbeat); heartbeat = null; }
      if (joined) { signal('leave'); }
      joined = false;
      status(leftOnPurpose ? 'You left.' : 'You are out of the session.', 'warn');
      showRejoin();
    });
    api.addListener('readyToClose', function () {
      leftOnPurpose = true;
      stop();
      stage.textContent = '';
      status('You left. Choose Rejoin to go back in.', 'warn');
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

  // A fresh page brings a fresh pass, so rejoining always works while it is on.
  function rejoin() {
    stop();
    window.location.reload();
  }

  Array.prototype.forEach.call(root.querySelectorAll('[data-room-rejoin]'), function (b) {
    b.addEventListener('click', rejoin);
  });

  // On a phone the help and materials fold away under the video.
  if (window.matchMedia && window.matchMedia('(max-width: 59.99rem)').matches) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-room-panel]'), function (d) { d.open = false; });
  }

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
    status('Your connection dropped. The session carries on when it is back.', 'warn');
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
