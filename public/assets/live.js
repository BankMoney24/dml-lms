/*
 * Live quizzes (ADR 0042): the class screen ([data-stage]) and a player's
 * phone ([data-play]). Both ask the server for the game's state about once a
 * second; the server keeps the time and the scores, this only draws them.
 * Countdowns run from the moment each answer arrived, never from the device's
 * clock. No inline script or style (CSP): widths come from element.style.
 */
(function () {
  'use strict';

  var LETTERS = ['A', 'B', 'C', 'D'];

  function el(tag, attrs, text, parent) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== null && text !== undefined) { e.textContent = text; }
    if (parent) { parent.appendChild(e); }
    return e;
  }
  function ordinal(n) {
    var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  }
  function fmt(n) { return Number(n || 0).toLocaleString('en-NG'); }
  function show(root, name) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-view]'), function (v) { v.hidden = v.getAttribute('data-view') !== name; });
  }
  function post(url, csrf, fields) {
    var body = new FormData();
    body.append('_token', csrf);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) { throw new Error('http ' + r.status); }
      return r.json();
    });
  }
  function getJson(url) {
    return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' }).then(function (r) {
      if (!r.ok) { throw new Error('http ' + r.status); }
      return r.json();
    });
  }

  /* =====================================================================
   * The class screen
   * =================================================================== */
  var stage = document.querySelector('[data-stage]');
  if (stage) {
    var S = {
      state: stage.getAttribute('data-state'), advance: stage.getAttribute('data-advance'), remove: stage.getAttribute('data-remove'),
      csrf: stage.getAttribute('data-csrf') || '', host: stage.getAttribute('data-host') === '1'
    };
    var last = null, drawnKey = '', endsAt = 0, busy = false;
    var net = stage.querySelector('[data-stage-net]');

    var drawLobby = function (st) {
      var count = stage.querySelector('[data-stage-count]');
      count.textContent = st.players === 0 ? 'Waiting for players…' : st.players + (st.players === 1 ? ' player' : ' players');
      var list = stage.querySelector('[data-stage-names]');
      var names = st.names || [];
      if (list.getAttribute('data-n') !== String(names.length)) {
        list.setAttribute('data-n', String(names.length));
        list.textContent = '';
        names.forEach(function (p) {
          var li = el('li', { class: 'stage-name' }, null, list);
          el('span', null, p.name, li);
          if (S.host) {
            var x = el('button', { type: 'button', class: 'stage-kick', 'aria-label': 'Remove ' + p.name }, '×', li);
            x.addEventListener('click', function () { post(S.remove, S.csrf, { player: String(p.id) }).then(render).catch(function () { /* next poll */ }); });
          }
        });
      }
      var go = stage.querySelector('[data-view="lobby"] [data-act="start"]');
      if (go) { go.disabled = st.players === 0; }
    };
    var drawOptions = function (box, st, reveal) {
      box.textContent = '';
      var total = 0;
      (st.counts || []).forEach(function (n) { total += n; });
      st.question.options.forEach(function (text, i) {
        var li = el('li', { class: 'stage-opt stage-opt-' + i + (reveal && i === st.correct ? ' is-key' : '') + (reveal && i !== st.correct ? ' is-dim' : '') }, null, box);
        el('span', { class: 'stage-letter', 'aria-hidden': 'true' }, LETTERS[i], li);
        el('span', { class: 'stage-opt-text' }, text, li);
        if (reveal) {
          var n = (st.counts || [])[i] || 0;
          var bar = el('span', { class: 'stage-bar', 'aria-hidden': 'true' }, null, li);
          var fill = el('span', null, null, bar);
          fill.style.width = (total ? Math.round(n / total * 100) : 0) + '%';
          el('span', { class: 'stage-n' }, String(n), li);
          if (i === st.correct) { el('span', { class: 'visually-hidden' }, ' (right answer)', li); }
        }
      });
    };
    var drawLeaders = function (box, leaders, from) {
      box.textContent = '';
      leaders.forEach(function (p, i) {
        var li = el('li', { class: 'stage-leader' }, null, box);
        el('span', { class: 'stage-rank', 'aria-hidden': 'true' }, String(from + i), li);
        el('span', { class: 'stage-leader-name' }, p.name, li);
        if (p.streak >= 3) { el('span', { class: 'stage-streak' }, p.streak + ' in a row', li); }
        el('span', { class: 'stage-leader-score' }, fmt(p.score), li);
      });
    };
    var render = function (st) {
      last = st;
      net.hidden = true;
      var key = st.status + ':' + st.position;
      var qnum = st.position !== null && st.position !== undefined ? 'Question ' + (st.position + 1) + ' of ' + st.total : '';
      if (st.status === 'lobby') { show(stage, 'lobby'); drawLobby(st); }
      if (st.status === 'question') {
        show(stage, 'question');
        var v = stage.querySelector('[data-view="question"]');
        if (drawnKey !== key) {
          v.querySelector('[data-stage-qnum]').textContent = qnum + (st.question.points === 'double' ? ', double points' : '');
          v.querySelector('[data-stage-prompt]').textContent = st.question.prompt;
          drawOptions(v.querySelector('[data-stage-options]'), st, false);
        }
        v.querySelector('[data-stage-answered]').textContent = st.answered + ' of ' + st.players + ' answered';
        var target = performance.now() + (st.ms_left || 0);
        if (!endsAt || Math.abs(target - endsAt) > 400 || drawnKey !== key) { endsAt = target; }
      }
      if (st.status === 'reveal') {
        show(stage, 'reveal');
        var r = stage.querySelector('[data-view="reveal"]');
        if (drawnKey !== key) {
          r.querySelector('[data-stage-qnum]').textContent = qnum + ': ' + st.answered + ' answered';
          r.querySelector('[data-stage-prompt]').textContent = st.question.prompt;
          drawOptions(r.querySelector('[data-stage-options]'), st, true);
          var nb = r.querySelector('[data-next-label]');
          if (nb) { nb.textContent = st.last ? 'Show the podium' : 'Next'; }
        }
      }
      if (st.status === 'scores' && drawnKey !== key) {
        show(stage, 'scores');
        drawLeaders(stage.querySelector('[data-stage-leaders]'), st.leaders || [], 1);
      }
      if (st.status === 'finished' && drawnKey !== key) {
        show(stage, 'finished');
        var podium = stage.querySelector('[data-stage-podium]');
        podium.textContent = '';
        var top = (st.leaders || []).slice(0, 3);
        [1, 0, 2].forEach(function (i) {
          var p = top[i];
          if (!p) { return; }
          var li = el('li', { class: 'podium-step podium-' + (i + 1), value: String(i + 1) }, null, podium);
          el('span', { class: 'podium-place' }, ordinal(i + 1), li);
          el('span', { class: 'podium-name' }, p.name, li);
          el('span', { class: 'podium-score' }, fmt(p.score) + ' points, ' + p.correct + ' right', li);
        });
        drawLeaders(stage.querySelector('[data-stage-rest]'), (st.leaders || []).slice(3), 4);
        var end = stage.querySelector('.stage-end');
        if (end) { end.hidden = true; }
      }
      drawnKey = key;
    };
    var poll = function () {
      getJson(S.state).then(render).catch(function () { net.hidden = false; }).then(function () {
        window.setTimeout(poll, last && last.status === 'finished' ? 15000 : 1000);
      });
    };
    window.setInterval(function () {
      if (!last || last.status !== 'question') { return; }
      var left = Math.max(0, Math.ceil((endsAt - performance.now()) / 1000));
      var t = stage.querySelector('[data-stage-timer]');
      t.textContent = String(left);
      t.classList.toggle('is-low', left <= 5);
    }, 200);
    Array.prototype.forEach.call(stage.querySelectorAll('[data-act]'), function (b) {
      b.addEventListener('click', function () {
        if (busy) { return; }
        var action = b.getAttribute('data-act');
        if (action === 'end' && b.getAttribute('data-sure') !== '1') {
          b.setAttribute('data-sure', '1');
          b.textContent = 'Press again to end the game for everyone';
          window.setTimeout(function () { b.removeAttribute('data-sure'); b.textContent = 'End the game'; }, 4000);
          return;
        }
        busy = true;
        post(S.advance, S.csrf, { action: action }).then(function (st) { busy = false; render(st); }).catch(function () { busy = false; net.hidden = false; });
      });
    });
    document.addEventListener('keydown', function (e) {
      if (!S.host || !last || e.target.tagName === 'INPUT' || e.ctrlKey || e.metaKey) { return; }
      if (e.key === ' ' || e.key === 'Enter' || e.key === 'ArrowRight') {
        var view = stage.querySelector('[data-view="' + last.status + '"]');
        var b = view && view.querySelector('.stage-go:not([disabled])');
        if (b && document.activeElement !== b) { e.preventDefault(); b.click(); }
      }
    });
    poll();
  }

  /* =====================================================================
   * A player's phone
   * =================================================================== */
  var play = document.querySelector('[data-play]');
  if (play) {
    var P = { state: play.getAttribute('data-state'), answer: play.getAttribute('data-answer'), csrf: play.getAttribute('data-csrf') || '' };
    var version = 0, cur = null, sentFor = null, pEnds = 0, pLimit = 0;
    var pnet = play.querySelector('[data-play-net]');
    var alertEl = play.querySelector('[data-play-alert]');
    var scoreEl = play.querySelector('[data-play-score]');
    var say = function (t) { alertEl.textContent = ''; window.setTimeout(function () { alertEl.textContent = t; }, 50); };

    var drawQuestion = function (st) {
      show(play, 'question');
      play.querySelector('[data-play-qnum]').textContent = 'Question ' + (st.position + 1) + ' of ' + st.total;
      play.querySelector('[data-play-prompt]').textContent = st.question.prompt;
      var box = play.querySelector('[data-play-answers]');
      box.textContent = '';
      box.className = 'play-answers play-answers-' + st.question.options.length;
      st.question.options.forEach(function (text, i) {
        var b = el('button', { type: 'button', class: 'play-answer play-answer-' + i }, null, box);
        el('span', { class: 'play-letter', 'aria-hidden': 'true' }, LETTERS[i], b);
        el('span', { class: 'play-answer-text' }, text, b);
        b.addEventListener('click', function () {
          if (sentFor === st.position) { return; }
          sentFor = st.position;
          Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.disabled = true; });
          b.classList.add('is-picked');
          post(P.answer, P.csrf, { position: String(st.position), choice: String(i) }).then(function (res) {
            if (res.ok || res.error === 'already') {
              show(play, 'sent');
              play.querySelector('[data-play-sent-text]').textContent = 'You chose ' + LETTERS[i] + '. Waiting for the others…';
              say('Answer ' + LETTERS[i] + ' sent.');
            } else {
              show(play, 'sent');
              play.querySelector('[data-play-sent-text]').textContent = 'Too late for this one. The answer comes up next.';
            }
          }).catch(function () {
            sentFor = null;
            Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.disabled = false; });
            b.classList.remove('is-picked');
            pnet.hidden = false;
          });
        });
      });
      pLimit = st.question.seconds * 1000;
    };
    var renderP = function (st) {
      pnet.hidden = true;
      if (st.same) {
        if (cur && cur.status === 'question') { pEnds = performance.now() + (st.ms_left || 0); }
        return;
      }
      version = st.version;
      cur = st;
      if (typeof st.score === 'number') { scoreEl.textContent = fmt(st.score) + ' points'; }
      if (st.status === 'removed') { show(play, 'removed'); return; }
      if (st.status === 'lobby') { show(play, 'lobby'); return; }
      if (st.status === 'question') {
        pEnds = performance.now() + (st.ms_left || 0);
        if (st.answered) {
          sentFor = st.position;
          show(play, 'sent');
          play.querySelector('[data-play-sent-text]').textContent = 'You chose ' + LETTERS[st.choice] + '. Waiting for the others…';
        } else {
          drawQuestion(st);
          say('Question ' + (st.position + 1) + '. ' + st.question.prompt);
        }
        return;
      }
      if (st.status === 'reveal') {
        show(play, 'reveal');
        var verdict = play.querySelector('[data-play-verdict]');
        var answered = st.choice !== null && st.choice !== undefined;
        verdict.textContent = st.right ? 'Correct!' : (answered ? 'Not this time' : 'Time’s up');
        play.querySelector('[data-view="reveal"]').className = 'play-view play-result ' + (st.right ? 'is-right' : 'is-wrong');
        play.querySelector('[data-play-points]').textContent = st.right ? '+' + fmt(st.points) + ' points' : '';
        play.querySelector('[data-play-correct]').textContent = st.right ? '' : 'The answer was ' + LETTERS[st.correct] + ': ' + st.question.options[st.correct];
        play.querySelector('[data-play-streak]').textContent = st.streak >= 2 ? st.streak + ' right in a row' + (st.streak >= 3 ? ', streak bonus!' : '') : '';
        say(verdict.textContent);
        if (st.right && navigator.vibrate) { try { navigator.vibrate(60); } catch (e) { /* fine */ } }
        return;
      }
      if (st.status === 'scores') {
        show(play, 'scores');
        play.querySelector('[data-play-rank]').textContent = 'You are ' + ordinal(st.rank) + ' of ' + st.players;
        play.querySelector('[data-play-behind]').textContent = st.behind ? fmt(st.behind.by) + ' points behind ' + st.behind.name : (st.rank === 1 ? 'You are in the lead.' : '');
        return;
      }
      if (st.status === 'finished') {
        show(play, 'finished');
        play.querySelector('[data-play-final]').textContent = st.rank ? 'You finished ' + ordinal(st.rank) + ' of ' + st.players : 'The game is over';
        play.querySelector('[data-play-final-detail]').textContent = st.rank ? fmt(st.score) + ' points, ' + st.correct_count + ' of ' + st.total + ' right.' : '';
      }
    };
    var pollP = function () {
      getJson(P.state + '?v=' + version).then(renderP).catch(function () { pnet.hidden = false; }).then(function () {
        var done = cur && (cur.status === 'finished' || cur.status === 'removed');
        if (!done) { window.setTimeout(pollP, cur && cur.status === 'lobby' ? 1500 : 1000); }
      });
    };
    window.setInterval(function () {
      if (!cur || cur.status !== 'question') { return; }
      var bar = play.querySelector('[data-play-timebar]');
      var left = Math.max(0, pEnds - performance.now());
      if (bar && pLimit) { bar.style.width = Math.round(left / pLimit * 100) + '%'; }
    }, 150);
    pollP();
  }
}());
