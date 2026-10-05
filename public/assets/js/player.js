/**
 * 동화 플레이어(/player/{id}).
 * - 가족 목소리 오디오: HTMLAudioElement + requestAnimationFrame 으로 sentence_timings 를 따라 문장, 낱말을 강조한다.
 * - 기기 음성: speechSynthesis 로 문장씩 읽는다(낱말 경계 이벤트가 오면 낱말 강조, 소리를 못 내는 기기는 글자만 따라 읽기).
 * - 재생 기록: 첫 재생 때 만들고 15초마다, 멈출 때, 페이지를 떠날 때(sendBeacon) 위치와 들은 시간을 보낸다.
 * - 질문(끼어들기): 동화를 멈추고 RMRecorder 로 녹음 → 서버(Gemini, ElevenLabs) → 답 듣기 → 그 문장 처음부터 다시 재생.
 * - 잠자기 타이머, 다음 이야기 자동 재생, 미디어 세션(잠금 화면 조작)을 지원한다.
 * - 플레이리스트에서 열면(M.playlist) 한 편이 끝날 때 환경 설정과 관계없이 다음 편으로 넘어가고(반복, 랜덤은 서버가 정한 M.next),
 *   다음 편은 열리자마자 재생한다(M.autostart). 잠자기 타이머 마감 시각은 주소(sl)로 다음 편에 넘긴다.
 * 환경 설정의 hands_free(말하면 자동 끼어들기)는 동화 소리와 아이 목소리를 안정적으로 구분하기 어려워 아직 쓰지 않는다.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('player-data');
  if (!dataEl || !window.RM) return;
  var M = JSON.parse(dataEl.textContent || '{}');
  var S = M.sentences || [];
  if (!S.length) return;

  var prefs = M.prefs || {};
  var rate = parseFloat(prefs.playback_speed) || 1;
  if (rate < 0.5 || rate > 2) rate = 1;
  var highlight = prefs.highlight !== false;
  var mode = M.audio ? 'audio' : 'device';
  var PROGRESS_EVERY = 15000;

  function $(id) { return document.getElementById(id); }
  var el = {
    prev: $('pl-prev'), text: $('pl-text'), subtitle: $('pl-subtitle'),
    bar: $('pl-bar'), fill: $('pl-fill'), cur: $('pl-cur'), total: $('pl-total'),
    play: $('pl-play'), playIcon: $('pl-play-icon'), back: $('pl-back'), fwd: $('pl-fwd'),
    ask: $('pl-ask'), askSub: $('pl-ask-sub'),
    overlay: $('pl-ask-overlay'), rec: $('pl-rec'), levels: $('pl-levels'), cancel: $('pl-ask-cancel'),
    q: $('pl-q'), qRow: $('pl-q-row'), a: $('pl-a'), aIcon: $('pl-a-icon'),
    end: $('pl-end'), endXp: $('pl-end-xp'), next: $('pl-next'), nextCount: $('pl-next-count'),
    nextCancel: $('pl-next-cancel'), again: $('pl-again'), sleepText: $('pl-sleep-text')
  };

  var state = {
    index: Math.min(Math.max(0, (M.start && M.start.index) || 0), S.length - 1),
    word: -2,
    rendered: -1,
    playing: false,
    sessionId: null,
    sessionPromise: null,
    remaining: Math.max(0, (M.quota.max || 0) - (M.quota.used || 0)),
    listenedMs: 0,
    lastTick: 0,
    busy: false,
    ended: false,
    completing: false
  };

  var wordEls = [];
  var seqIndex = {};
  S.forEach(function (s, i) { seqIndex[s.seq] = i; });

  // ───────────────────────── 타임라인 ─────────────────────────

  function charLen(s) { return Array.from(String(s || '')).length; }

  /** 문장 안 낱말 시각을 글자 수 비율로 나눈다. */
  function estimateWords(words, start, end) {
    var lens = words.map(function (w) { return charLen(w) + 1; });
    var total = lens.reduce(function (a, b) { return a + b; }, 0) || 1;
    var out = [], t = start, span = Math.max(0, end - start);
    lens.forEach(function (l) {
      var d = span * l / total;
      out.push([Math.round(t), Math.round(t + d * 0.9)]);
      t += d;
    });
    return out;
  }

  /** 문장 전체를 글자 수 비율로 나눈 타임라인(타이밍이 없을 때, 기기 음성) */
  function estimateTimeline(totalMs) {
    var lens = S.map(function (s) { return charLen(s.content) + 1; });
    var sum = lens.reduce(function (a, b) { return a + b; }, 0) || 1;
    var t = 0, tl = [];
    S.forEach(function (s, i) {
      var d = totalMs * lens[i] / sum;
      tl.push({ i: i, start: Math.round(t), end: Math.round(t + d), words: estimateWords(s.words, t, t + d) });
      t += d;
    });
    return tl;
  }

  /** 오디오 타이밍(문장 seq 기준). 옛 본문으로 만든 오디오는 같은 seq 문장에 맞춰 보고, 낱말 수가 다르면 비율로 나눈다. */
  function buildAudioTimeline(durationMs) {
    var T = M.audio && M.audio.timings && M.audio.timings.sentences;
    var tl = [];
    if (T && T.length) {
      T.forEach(function (t) {
        var i = seqIndex[t.seq];
        if (i === undefined) return;
        var start = +t.start || 0, end = +t.end || start;
        var words = (t.words && t.words.length === S[i].words.length) ? t.words : estimateWords(S[i].words, start, end);
        tl.push({ i: i, start: start, end: end, words: words });
      });
      tl.sort(function (a, b) { return a.start - b.start; });
    }
    return tl.length ? tl : estimateTimeline(durationMs || M.est_ms || S.length * 4000);
  }

  /** 기기 음성은 글자 수로 길이를 어림한다(한 글자 약 160ms, 배속 반영). 서버의 show.php 와 같은 식 */
  function deviceTotalMs() {
    var chars = S.reduce(function (a, s) { return a + charLen(s.content) + 1; }, 0);
    return Math.round(chars * 160 / rate);
  }

  var audio = null;
  var durationMs = mode === 'audio' ? (M.audio.duration_ms || 0) : deviceTotalMs();
  var timeline = mode === 'audio' ? buildAudioTimeline(durationMs) : estimateTimeline(durationMs);
  var entryOfIndex = {};
  timeline.forEach(function (t) { if (entryOfIndex[t.i] === undefined) entryOfIndex[t.i] = t; });

  function entryAt(ms) {
    var lo = 0, hi = timeline.length - 1, found = timeline[0];
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (timeline[mid].start <= ms) { found = timeline[mid]; lo = mid + 1; } else { hi = mid - 1; }
    }
    return found;
  }

  function sentenceStartMs(i) {
    var e = entryOfIndex[i];
    return e ? e.start : 0;
  }

  // ───────────────────────── 화면 ─────────────────────────

  function renderSentence(i) {
    if (state.rendered === i) return;
    state.rendered = i;
    var s = S[i];
    if (i > 0) {
      el.prev.textContent = S[i - 1].content;
      el.prev.classList.remove('invisible');
    } else {
      el.prev.textContent = ' ';
      el.prev.classList.add('invisible');
    }
    el.text.textContent = '';
    wordEls = s.words.map(function (w, k) {
      var sp = document.createElement('span');
      sp.className = 'word';
      sp.textContent = w;
      el.text.appendChild(sp);
      if (k < s.words.length - 1) el.text.appendChild(document.createTextNode(' '));
      return sp;
    });
    el.subtitle.textContent = '문장 ' + (i + 1) + ' / ' + S.length;
    state.word = -2;
  }

  /** k 번째 낱말을 지금 읽는 낱말로. k 가 낱말 수 이상이면 모두 읽음, -1 이면 강조 없음 */
  function setWord(k) {
    if (!highlight || k === state.word) return;
    state.word = k;
    for (var j = 0; j < wordEls.length; j++) {
      wordEls[j].classList.toggle('is-read', j < k);
      wordEls[j].classList.toggle('is-current', j === k);
    }
  }

  function setIndex(i) {
    state.index = i;
    renderSentence(i);
  }

  function positionMs() {
    if (mode === 'audio') return audio ? Math.round(audio.currentTime * 1000) : 0;
    return sentenceStartMs(state.index) + (dev.speaking ? Math.min(performance.now() - dev.startedAt, 60000) : 0);
  }

  function updateProgress(ms) {
    var total = durationMs || 1;
    var pct = Math.max(0, Math.min(100, ms * 100 / total));
    el.fill.style.width = pct + '%';
    el.bar.setAttribute('aria-valuenow', String(Math.round(pct)));
    el.cur.textContent = RM.fmtTime(ms);
    el.total.textContent = RM.fmtTime(total);
  }

  function setPlaying(on) {
    accumulate();
    state.playing = on;
    el.playIcon.textContent = on ? 'pause' : 'play_arrow';
    el.play.setAttribute('aria-label', on ? '일시 정지' : '재생');
    if ('mediaSession' in navigator) {
      try { navigator.mediaSession.playbackState = on ? 'playing' : 'paused'; } catch (e) {}
    }
  }

  function updateAskLabel() {
    if (!M.qa.enabled) return;
    el.askSub.textContent = state.remaining > 0 ? '질문 ' + state.remaining + '번 남았어요' : '이야기 끝나고 또 물어보자';
  }

  // 들은 시간은 실제로 재생 중인 동안의 벽시계 시간으로 잰다(배속이어도 아이가 들은 시간).
  // 화면이 꺼지면 애니메이션 프레임이 멈추고 타이머도 1분 간격까지 느려지므로 한 번에 70초까지 인정한다.
  function accumulate() {
    var now = performance.now();
    if (state.playing && state.lastTick) state.listenedMs += Math.min(70000, Math.max(0, now - state.lastTick));
    state.lastTick = now;
  }

  // ───────────────────────── 재생 기록 ─────────────────────────

  function ensureSession() {
    if (state.sessionId) return Promise.resolve(state.sessionId);
    if (state.sessionPromise) return state.sessionPromise;
    state.sessionPromise = RM.api('/api/play-sessions', { method: 'POST', body: { story_id: M.story.id, voice: M.voice } })
      .then(function (res) {
        state.sessionId = res.session_id;
        state.remaining = res.remaining;
        updateAskLabel();
        return res.session_id;
      })
      .catch(function (err) {
        state.sessionPromise = null;
        throw err;
      });
    return state.sessionPromise;
  }

  function payload() {
    accumulate();
    var delta = Math.min(60000, Math.round(state.listenedMs));
    return { position_ms: Math.max(0, Math.round(positionMs())), sentence_seq: S[state.index].seq, listened_delta_ms: delta };
  }

  function flushProgress(useBeacon) {
    if (!state.sessionId || state.ended) return;
    var p = payload();
    state.listenedMs = Math.max(0, state.listenedMs - p.listened_delta_ms);
    var path = '/api/play-sessions/' + state.sessionId + '/progress';
    if (useBeacon && navigator.sendBeacon) {
      var fd = new FormData();
      fd.append('_token', RM.csrf());
      Object.keys(p).forEach(function (k) { fd.append(k, String(p[k])); });
      if (navigator.sendBeacon(RM.url(path), fd)) return;
    }
    RM.api(path, { method: 'POST', body: p }).catch(function () {
      state.listenedMs += p.listened_delta_ms;
    });
  }

  setInterval(function () { if (state.playing) flushProgress(false); }, PROGRESS_EVERY);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushProgress(true); });
  window.addEventListener('pagehide', function () {
    flushProgress(true);
    if (window.speechSynthesis) { try { window.speechSynthesis.cancel(); } catch (e) {} }
  });

  // ───────────────────────── 가족 목소리 오디오 ─────────────────────────

  var raf = 0;
  function syncAudio() {
    var ms = audio.currentTime * 1000;
    var e = entryAt(ms);
    if (e) {
      if (e.i !== state.index || state.rendered !== e.i) setIndex(e.i);
      var k = -1;
      for (var j = 0; j < e.words.length; j++) {
        if (ms >= e.words[j][0]) k = j; else break;
      }
      if (k === e.words.length - 1 && ms > e.words[k][1] + 150) k = e.words.length;
      setWord(k);
    }
    updateProgress(ms);
  }

  function loop() {
    raf = 0;
    if (!state.playing || mode !== 'audio') return;
    accumulate();
    syncAudio();
    raf = requestAnimationFrame(loop);
  }

  function initAudio() {
    audio = new Audio();
    audio.preload = 'auto';
    audio.src = M.audio.url;
    audio.playbackRate = rate;
    try { audio.preservesPitch = true; } catch (e) {}
    var startSet = false;
    function setStart() {
      if (startSet) return;
      startSet = true;
      var d = isFinite(audio.duration) ? audio.duration * 1000 : 0;
      if (d > 0 && Math.abs(d - durationMs) > 500 && !(M.audio.timings && M.audio.timings.sentences)) {
        durationMs = d;
        timeline = buildAudioTimeline(d);
        entryOfIndex = {};
        timeline.forEach(function (t) { if (entryOfIndex[t.i] === undefined) entryOfIndex[t.i] = t; });
      } else if (d > 0) {
        durationMs = d;
      }
      var st = (M.start.s > 0 && seqIndex[M.start.s] !== undefined) ? sentenceStartMs(seqIndex[M.start.s]) : (M.start.t || 0);
      if (st > 0 && st < durationMs - 1500) audio.currentTime = st / 1000;
      syncAudio();
    }
    audio.addEventListener('loadedmetadata', setStart);
    audio.addEventListener('play', function () { if (!state.playing) setPlaying(true); if (!raf) raf = requestAnimationFrame(loop); });
    audio.addEventListener('pause', function () {
      if (state.playing && !state.busy && !audio.ended) { setPlaying(false); flushProgress(false); }
    });
    audio.addEventListener('ended', function () { onEnded(); });
    audio.addEventListener('error', function () {
      if (!audio.error) return;
      setPlaying(false);
      RM.toast('오디오를 불러오지 못했어요. 잠시 뒤 다시 시도해 주세요.', 'error');
    });
    // 시작 위치를 미리 보여 준다.
    var st0 = (M.start.s > 0 && seqIndex[M.start.s] !== undefined) ? sentenceStartMs(seqIndex[M.start.s]) : (M.start.t || 0);
    updateProgress(st0);
  }

  function seekAudio(ms) {
    if (!audio) return;
    ms = Math.max(0, Math.min(ms, (durationMs || 0) - 200));
    try { audio.currentTime = ms / 1000; } catch (e) {}
    syncAudio();
  }

  // ───────────────────────── 기기 음성 ─────────────────────────

  var synth = window.speechSynthesis || null;
  var dev = { token: 0, speaking: false, startedAt: 0, timers: [], silent: !synth || typeof window.SpeechSynthesisUtterance === 'undefined', warned: false, voice: null };

  function pickVoice() {
    if (!synth) return;
    var vs = synth.getVoices() || [];
    var ko = vs.filter(function (v) { return /^ko(-|_|$)/i.test(v.lang); });
    dev.voice = ko.filter(function (v) { return v.localService; })[0] || ko[0] || null;
  }
  if (synth) {
    pickVoice();
    if (synth.addEventListener) synth.addEventListener('voiceschanged', pickVoice);
  }

  function clearDevTimers() {
    dev.timers.forEach(function (t) { clearTimeout(t); clearInterval(t); });
    dev.timers = [];
  }

  function stopDevice() {
    dev.token++;
    dev.speaking = false;
    clearDevTimers();
    if (synth) { try { synth.cancel(); } catch (e) {} }
  }

  function wordAtChar(s, charIndex) {
    var pos = 0;
    for (var k = 0; k < s.words.length; k++) {
      var idx = s.content.indexOf(s.words[k], pos);
      if (idx < 0) idx = pos;
      var end = idx + s.words[k].length;
      if (charIndex < end) return k;
      pos = end;
    }
    return s.words.length - 1;
  }

  function sentenceDurationMs(i) {
    var e = entryOfIndex[i];
    return e ? Math.max(800, e.end - e.start) : 3000;
  }

  /** 소리를 낼 수 없을 때: 예상 시간에 맞춰 글자만 따라 강조한다. */
  function silentRead(i, token) {
    var dur = sentenceDurationMs(i);
    var e = entryOfIndex[i];
    var base = e ? e.start : 0;
    dev.speaking = true;
    dev.startedAt = performance.now();
    var iv = setInterval(function () {
      if (token !== dev.token) { clearInterval(iv); return; }
      var t = performance.now() - dev.startedAt;
      if (e && highlight) {
        var ms = base + t, k = -1;
        for (var j = 0; j < e.words.length; j++) { if (ms >= e.words[j][0]) k = j; }
        setWord(k);
      }
      updateProgress(base + Math.min(t, dur));
      if (t >= dur) {
        clearInterval(iv);
        setWord(S[i].words.length);
        afterDeviceSentence(i, token);
      }
    }, 80);
    dev.timers.push(iv);
  }

  function speakDevice(i) {
    stopDevice();
    var token = dev.token;
    setIndex(i);
    setWord(-1);
    updateProgress(sentenceStartMs(i));
    if (dev.silent) { silentRead(i, token); return; }
    var s = S[i];
    var u = new SpeechSynthesisUtterance(s.content);
    u.lang = 'ko-KR';
    if (dev.voice) u.voice = dev.voice;
    u.rate = rate;
    var started = false;
    u.onstart = function () {
      if (token !== dev.token) return;
      started = true;
      dev.speaking = true;
      dev.startedAt = performance.now();
      var iv = setInterval(function () {
        if (token !== dev.token) { clearInterval(iv); return; }
        updateProgress(sentenceStartMs(i) + Math.min(performance.now() - dev.startedAt, sentenceDurationMs(i)));
      }, 250);
      dev.timers.push(iv);
    };
    u.onboundary = function (ev) {
      if (token !== dev.token) return;
      if (ev.name && ev.name !== 'word') return;
      setWord(wordAtChar(s, ev.charIndex || 0));
    };
    u.onend = function () {
      if (token !== dev.token) return;
      setWord(s.words.length);
      afterDeviceSentence(i, token);
    };
    u.onerror = function (ev) {
      if (token !== dev.token) return;
      if (ev && (ev.error === 'interrupted' || ev.error === 'canceled')) return;
      fallbackSilent(i, token);
    };
    try { synth.cancel(); synth.speak(u); } catch (e) { fallbackSilent(i, token); return; }
    // 소리가 시작되지 않는 기기(음성 엔진 없음)는 글자만 따라 읽는다.
    dev.timers.push(setTimeout(function () {
      if (token === dev.token && !started) fallbackSilent(i, token);
    }, 2500));
  }

  function fallbackSilent(i, token) {
    if (token !== dev.token) return;
    dev.silent = true;
    try { synth && synth.cancel(); } catch (e) {}
    clearDevTimers();
    if (!dev.warned) {
      dev.warned = true;
      RM.toast('이 기기에서는 소리 내어 읽기가 어려워요. 글자를 따라 읽어 줄게요.', 'info');
    }
    silentRead(i, token);
  }

  function afterDeviceSentence(i, token) {
    if (token !== dev.token) return;
    dev.speaking = false;
    clearDevTimers();
    if (i + 1 < S.length) {
      // 문장 사이 짧은 쉼
      dev.timers.push(setTimeout(function () {
        if (token === dev.token && state.playing) speakDevice(i + 1);
      }, 250));
    } else {
      onEnded();
    }
  }

  // ───────────────────────── 재생 조작 ─────────────────────────

  function play() {
    if (state.busy) return;
    unlockAnswerAudio();
    if (state.ended) {
      state.ended = false;
      state.sessionId = null;
      state.sessionPromise = null;
      if (mode === 'audio') seekAudio(0); else setIndex(0);
    }
    ensureSession().catch(function (err) { RM.toast(err.message, 'error'); });
    startSleepTimer();
    if (mode === 'audio') {
      audio.playbackRate = rate;
      setPlaying(true);
      var p = audio.play();
      if (p && p.then) {
        p.then(function () { state.autoTry = false; }, function (err) {
          if (err && err.name === 'AbortError') return;
          setPlaying(false);
          if (state.autoTry) RM.toast('재생 버튼을 누르면 이어서 들려줘요.', 'info');
          else RM.toast('재생을 시작하지 못했어요. 재생 버튼을 다시 눌러 주세요.', 'error');
          state.autoTry = false;
        });
      }
      if (!raf) raf = requestAnimationFrame(loop);
    } else {
      setPlaying(true);
      speakDevice(state.index);
    }
  }

  /** silent: 질문 중처럼 잠깐 멈출 때(기록 전송 없이) */
  function pause(silent) {
    accumulate();
    setPlaying(false);
    if (mode === 'audio') { if (audio) audio.pause(); } else stopDevice();
    if (!silent) flushProgress(false);
  }

  function toggle() { if (state.playing) pause(false); else play(); }

  function skip(dir) {
    if (state.busy) return;
    if (mode === 'audio') {
      seekAudio(audio.currentTime * 1000 + dir * 10000);
    } else {
      var i = Math.max(0, Math.min(S.length - 1, state.index + dir));
      if (state.playing) speakDevice(i); else { setIndex(i); setWord(-1); updateProgress(sentenceStartMs(i)); }
    }
  }

  /** 지금 문장 처음으로 되돌린다(질문 뒤 이어 듣기) */
  function rewindSentence() {
    if (mode === 'audio') seekAudio(sentenceStartMs(state.index));
    else { setWord(-1); updateProgress(sentenceStartMs(state.index)); }
  }

  el.play.addEventListener('click', toggle);
  el.back.addEventListener('click', function () { skip(-1); });
  el.fwd.addEventListener('click', function () { skip(1); });

  // 진행 막대 누르기, 끌기
  function seekToFraction(f) {
    f = Math.max(0, Math.min(1, f));
    if (mode === 'audio') { seekAudio(f * durationMs); return; }
    var e = entryAt(f * durationMs);
    var i = e ? e.i : 0;
    if (state.playing) speakDevice(i); else { setIndex(i); setWord(-1); updateProgress(sentenceStartMs(i)); }
  }
  var dragging = false;
  function fractionOf(ev) {
    var r = el.bar.getBoundingClientRect();
    return (ev.clientX - r.left) / (r.width || 1);
  }
  el.bar.addEventListener('pointerdown', function (ev) {
    if (state.busy) return;
    dragging = true;
    try { el.bar.setPointerCapture(ev.pointerId); } catch (e) {}
    if (mode === 'audio') seekToFraction(fractionOf(ev));
  });
  el.bar.addEventListener('pointermove', function (ev) { if (dragging && mode === 'audio') seekToFraction(fractionOf(ev)); });
  el.bar.addEventListener('pointerup', function (ev) {
    if (!dragging) return;
    dragging = false;
    seekToFraction(fractionOf(ev));
  });
  el.bar.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowLeft') { ev.preventDefault(); skip(-1); }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); skip(1); }
  });

  // 목소리 바꾸기: 지금 문장에서 이어 듣도록 문장 번호와 위치를 넘긴다.
  document.querySelectorAll('[data-voice]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var v = btn.getAttribute('data-voice');
      if (btn.disabled || v === String(M.voice)) return;
      var pos = Math.round(positionMs());
      flushProgress(true);
      pause(true);
      var q = { voice: v, s: String(S[state.index].seq), t: String(pos) };
      // 플레이리스트에 같은 동화가 이 목소리로도 담겨 있으면 플레이리스트 재생을 이어 간다.
      if (M.playlist) { q.pl = String(M.playlist.id); q.seed = String(M.playlist.seed); }
      location.href = M.player_url + '?' + new URLSearchParams(q).toString();
    });
  });

  // ───────────────────────── 끝, 다음 이야기 ─────────────────────────

  var countdown = null;
  function onEnded() {
    if (state.ended) return;
    accumulate();
    state.ended = true;
    setPlaying(false);
    if (mode === 'device') stopDevice();
    setWord(S[state.index].words.length);
    updateProgress(durationMs);
    el.endXp.hidden = true;
    showEnd();
    ensureSession().then(function (sid) {
      var p = { position_ms: Math.round(durationMs), sentence_seq: S[S.length - 1].seq, listened_delta_ms: Math.min(60000, Math.round(state.listenedMs)) };
      state.listenedMs = 0;
      return RM.api('/api/play-sessions/' + sid + '/complete', { method: 'POST', body: p });
    }).then(function (res) {
      if (res && res.xp_gained > 0) {
        el.endXp.textContent = '+' + res.xp_gained + ' XP' + (res.level_up ? ' · 레벨 업! 레벨 ' + res.level.level + ' ' + res.level.title : '');
        el.endXp.hidden = false;
      }
    }).catch(function () {});
  }

  function showEnd() {
    el.end.hidden = false;
    // 플레이리스트는 이어 듣기가 목적이라 환경 설정의 다음 이야기 자동 재생과 관계없이 넘어간다.
    if ((M.playlist || prefs.autoplay_next) && el.next && M.next) {
      var left = M.playlist ? 3 : 5;
      el.nextCount.hidden = false;
      el.nextCancel.hidden = false;
      var tick = function () {
        el.nextCount.textContent = left + '초 뒤에 이어서 들려줄게요';
        if (left <= 0) { clearInterval(countdown); countdown = null; location.href = nextUrl(); }
        left--;
      };
      tick();
      countdown = setInterval(tick, 1000);
    }
  }

  function cancelCountdown() {
    if (countdown) { clearInterval(countdown); countdown = null; }
    if (el.nextCount) el.nextCount.hidden = true;
    if (el.nextCancel) el.nextCancel.hidden = true;
  }
  if (el.nextCancel) el.nextCancel.addEventListener('click', cancelCountdown);
  el.again.addEventListener('click', function () {
    cancelCountdown();
    el.end.hidden = true;
    play();
  });

  // ───────────────────────── 잠자기 타이머 ─────────────────────────

  var sleepMin = parseInt(prefs.sleep_timer_min, 10) || 0;
  var sleepAt = 0;
  var sleepIv = null;
  // 플레이리스트 다음 편으로 넘어온 경우 앞 편에서 시작한 잠자기 타이머를 이어 쓴다.
  if (sleepMin > 0 && M.playlist) {
    var carried = parseInt(new URLSearchParams(location.search).get('sl') || '0', 10);
    if (carried > 0 && carried <= Date.now() + sleepMin * 60000) sleepAt = carried;
  }
  function sleepTick() {
    var left = sleepAt - Date.now();
    if (el.sleepText) el.sleepText.textContent = left > 0 ? '잠자기 ' + Math.max(1, Math.ceil(left / 60000)) + '분 남음' : '잠자기 타이머 끝';
    if (left <= 0) {
      if (sleepIv) { clearInterval(sleepIv); sleepIv = null; }
      goodnight();
    }
  }
  function startSleepTimer() {
    if (sleepMin <= 0 || sleepIv) return;
    if (!sleepAt) sleepAt = Date.now() + sleepMin * 60000;
    sleepIv = setInterval(sleepTick, 5000);
  }
  function sleepExpired() { return sleepAt > 0 && sleepAt <= Date.now(); }
  /** 다음 편 주소(플레이리스트면 잠자기 타이머 마감 시각을 함께 넘긴다) */
  function nextUrl() {
    if (!M.next) return '';
    if (!M.playlist || !sleepAt) return M.next.url;
    var u = new URL(M.next.url, location.href);
    u.searchParams.set('sl', String(sleepAt));
    return u.pathname + u.search;
  }
  function goodnight() {
    if (!state.playing) return;
    var name = M.child ? M.child + ', ' : '';
    if (mode === 'audio' && audio) {
      // 소리를 천천히 줄인 뒤 멈춘다.
      var v = audio.volume, step = setInterval(function () {
        v -= 0.1;
        if (v <= 0.05 || !state.playing) { clearInterval(step); pause(false); audio.volume = 1; } else audio.volume = v;
      }, 400);
    } else {
      pause(false);
    }
    RM.toast(name + '잘 자요. 잠자기 타이머로 이야기를 멈췄어요. 🌙', 'info');
  }

  // ───────────────────────── 질문하기 ─────────────────────────

  var rec = null, recording = false, uploadCtl = null, answerAudio = null;
  var levelBars = [];
  if (el.levels) {
    for (var b = 0; b < 14; b++) {
      var bar = document.createElement('span');
      bar.className = 'w-1.5 rounded-full bg-secondary transition-all duration-75';
      bar.style.height = '4px';
      el.levels.appendChild(bar);
      levelBars.push(bar);
    }
  }
  var levelHist = [];
  function drawLevel(db, isSpeech) {
    var v = Math.max(0, Math.min(1, (db + 60) / 50));
    levelHist.push(isSpeech ? v : v * 0.5);
    if (levelHist.length > levelBars.length) levelHist.shift();
    for (var i = 0; i < levelBars.length; i++) {
      var h = levelHist[i] || 0;
      levelBars[i].style.height = Math.round(4 + h * 28) + 'px';
    }
  }

  function step(name) {
    el.overlay.querySelectorAll('[data-step]').forEach(function (s) { s.hidden = s.getAttribute('data-step') !== name; });
  }

  // iOS 는 사용자 조작 없이 새 오디오를 재생하지 못하므로 버튼을 누를 때 답 오디오를 미리 깨워 둔다.
  function unlockAnswerAudio() {
    if (answerAudio) return;
    answerAudio = new Audio();
    try {
      // 0.1초 무음 WAV
      answerAudio.src = URL.createObjectURL(RMRecorder.encodeWav(new Float32Array(800), 8000));
      var p = answerAudio.play();
      if (p && p.catch) p.catch(function () {});
    } catch (e) {}
  }

  function startQuestion() {
    if (state.busy) return;
    if (!M.qa.enabled) { RM.toast(M.qa.message || '지금은 질문할 수 없어요.', 'info'); return; }
    if (!window.RMRecorder || !RMRecorder.supported()) {
      RM.toast('이 브라우저에서는 마이크를 쓸 수 없어요.', 'error');
      return;
    }
    unlockAnswerAudio();
    cancelCountdown();
    el.end.hidden = true;
    state.busy = true;
    pause(true);
    levelHist = [];
    drawLevel(-100, false);
    step('listening');
    el.overlay.hidden = false;

    var vad = M.qa.vad || {};
    rec = new RMRecorder({
      sampleRate: 16000,
      maxMs: M.qa.max_ms || 15000,
      vad: { minSpeechMs: vad.minSpeechMs || 1000, silenceStopMs: vad.silenceStopMs || 1200, noSpeechTimeoutMs: vad.noSpeechTimeoutMs || 6000 },
      echoCancellation: true,
      noiseSuppression: !!M.qa.noise_suppression,
      onLevel: drawLevel,
      onAutoStop: function (reason) {
        if (reason === 'no_speech') noSpeech();
        else finishRecording();
      }
    });
    // 마이크는 버튼을 누른 그 순간에 켠다(브라우저가 사용자 조작 안에서만 오디오를 허용하는 경우 대비). 재생 기록은 함께 준비한다.
    var r = rec;
    r.start()
      .then(function () { if (rec === r) recording = true; else r.cancel(); })
      .catch(function (err) {
        if (rec === r) { r.cancel(); rec = null; }
        endQuestion(err && err.message ? err.message : '마이크를 시작하지 못했어요.', 'error');
      });
    ensureSession().catch(function () {});
  }

  function noSpeech() {
    if (!recording) return;
    recording = false;
    if (rec) rec.cancel();
    rec = null;
    endQuestion('목소리가 들리지 않았어요. 마이크 버튼을 누르고 다시 물어봐요.', 'info');
  }

  function finishRecording() {
    if (!recording || !rec) return;
    recording = false;
    var r = rec;
    rec = null;
    r.stop().then(function (result) {
      if (!result.heardSpeech || result.durationMs < 300) {
        endQuestion('목소리가 들리지 않았어요. 마이크 버튼을 누르고 다시 물어봐요.', 'info');
        return;
      }
      upload(result);
    }).catch(function () { endQuestion('녹음을 마치지 못했어요.', 'error'); });
  }

  function upload(result) {
    step('thinking');
    var fd = new FormData();
    fd.append('audio', result.blob, 'question.wav');
    fd.append('sentence_seq', String(S[state.index].seq));
    fd.append('position_ms', String(Math.round(positionMs())));
    uploadCtl = window.AbortController ? new AbortController() : null;
    var signal = uploadCtl ? uploadCtl.signal : undefined;
    ensureSession()
      .then(function (sid) {
        return RM.api('/api/play-sessions/' + sid + '/question', { method: 'POST', body: fd, signal: signal });
      })
      .then(function (res) {
        uploadCtl = null;
        if (!state.busy) return;
        if (typeof res.remaining === 'number') { state.remaining = res.remaining; updateAskLabel(); }
        showAnswer(res);
        return playAnswer(res).then(function () {
          if (state.busy) setTimeout(function () { endQuestion(); }, 700);
        });
      })
      .catch(function (err) {
        uploadCtl = null;
        if (err && err.name === 'AbortError') return;
        endQuestion(err && err.message ? err.message : '네트워크 연결을 확인해 주세요.', 'error');
      });
  }

  function showAnswer(res) {
    var q = res.question_text || '';
    el.q.textContent = q;
    el.qRow.hidden = q === '';
    el.a.textContent = res.answer_text || '';
    var active = document.querySelector('[data-voice][aria-pressed="true"] .material-symbols-outlined');
    if (el.aIcon && active) el.aIcon.textContent = active.textContent;
    step('answer');
  }

  function playAnswer(res) {
    return new Promise(function (resolve) {
      var done = false;
      function finish() { if (!done) { done = true; resolve(); } }
      // 너무 오래 걸리면 이야기로 돌아간다.
      var guard = setTimeout(finish, 30000);
      function speakText() {
        var text = res.answer_text || '';
        if (!text || dev.silent || !synth) {
          setTimeout(function () { clearTimeout(guard); finish(); }, Math.min(8000, 1500 + charLen(text) * 90));
          return;
        }
        var u = new SpeechSynthesisUtterance(text);
        u.lang = 'ko-KR';
        if (dev.voice) u.voice = dev.voice;
        u.rate = 1;
        var started = false;
        u.onstart = function () { started = true; };
        u.onend = function () { clearTimeout(guard); finish(); };
        u.onerror = function () { clearTimeout(guard); finish(); };
        try { synth.cancel(); synth.speak(u); } catch (e) { clearTimeout(guard); finish(); }
        setTimeout(function () { if (!started) { try { synth.cancel(); } catch (e) {} clearTimeout(guard); setTimeout(finish, 2500); } }, 2500);
      }
      if (res.audio_url) {
        var a = answerAudio || new Audio();
        answerAudio = a;
        a.onended = function () { clearTimeout(guard); finish(); };
        a.onerror = function () { a.onerror = null; speakText(); };
        a.src = res.audio_url;
        var p = a.play();
        if (p && p.catch) p.catch(function () { speakText(); });
      } else {
        speakText();
      }
    });
  }

  function stopAnswer() {
    if (answerAudio) { try { answerAudio.pause(); } catch (e) {} answerAudio.onended = null; answerAudio.onerror = null; }
    if (synth) { try { synth.cancel(); } catch (e) {} }
  }

  /** 질문 화면을 닫고 지금 문장 처음부터 이야기를 이어 간다. */
  function endQuestion(message, type) {
    if (!state.busy) return;
    if (rec) { rec.cancel(); rec = null; }
    recording = false;
    if (uploadCtl) { try { uploadCtl.abort(); } catch (e) {} uploadCtl = null; }
    stopAnswer();
    el.overlay.hidden = true;
    state.busy = false;
    if (message) RM.toast(message, type || 'info');
    if (state.ended) { el.end.hidden = false; return; }
    rewindSentence();
    play();
  }

  el.ask.addEventListener('click', startQuestion);
  el.rec.addEventListener('click', function () { if (recording) finishRecording(); });
  el.cancel.addEventListener('click', function () { endQuestion(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && state.busy) endQuestion();
  });

  // ───────────────────────── 미디어 세션(잠금 화면) ─────────────────────────

  if ('mediaSession' in navigator && window.MediaMetadata) {
    try {
      var activeLabel = document.querySelector('[data-voice][aria-pressed="true"] .text-label-lg');
      var cover = new URL(M.story.cover, location.href).href;
      navigator.mediaSession.metadata = new MediaMetadata({
        title: M.story.title,
        artist: activeLabel ? activeLabel.textContent : (M.brand || ''),
        album: M.brand || '',
        artwork: [{ src: cover, sizes: '512x512', type: /\.svg(\?|$)/.test(cover) ? 'image/svg+xml' : 'image/png' }]
      });
      var handlers = {
        play: function () { play(); },
        pause: function () { pause(false); },
        seekbackward: function () { skip(-1); },
        seekforward: function () { skip(1); },
        previoustrack: function () { skip(-1); },
        seekto: function (d) { if (mode === 'audio' && d && typeof d.seekTime === 'number') seekAudio(d.seekTime * 1000); }
      };
      Object.keys(handlers).forEach(function (k) {
        try { navigator.mediaSession.setActionHandler(k, handlers[k]); } catch (e) {}
      });
      if (M.next) {
        try { navigator.mediaSession.setActionHandler('nexttrack', function () { location.href = nextUrl(); }); } catch (e) {}
      }
    } catch (e) {}
  }

  // ───────────────────────── 시작 ─────────────────────────

  setIndex(state.index);
  setWord(-1);
  if (mode === 'audio') initAudio(); else updateProgress(sentenceStartMs(state.index));
  updateAskLabel();
  if (!M.qa.enabled && el.askSub) el.askSub.textContent = M.qa.message;
  if (sleepAt) sleepTick();

  // 플레이리스트 다음 편: 열리자마자 재생한다. 브라우저가 막으면 재생 버튼을 누르도록 알린다.
  if (M.autostart && mode === 'audio') {
    if (sleepExpired()) {
      RM.toast('잠자기 타이머가 끝나 다음 편은 멈춰 두었어요. 🌙', 'info');
    } else {
      state.autoTry = true;
      play();
    }
  }

  // 테스트, 디버그용 읽기 전용 상태
  window.RMPlayer = {
    state: state,
    mode: mode,
    position: positionMs,
    play: play,
    pause: function () { pause(false); },
    ask: startQuestion
  };
})();
