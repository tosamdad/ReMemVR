/**
 * 목소리 연구실 화면 스크립트.
 *   [data-play-src]   눌러서 미리 듣기(목록, 상세, 녹음 화면 공용). 한 번에 하나만 재생한다.
 *   [data-vl-index], [data-vl-show]  생성 중인 목소리가 있으면 10초마다 /api/voice-lab/status 로 상태를 갱신한다.
 *   [data-vl-new]     누구 목소리인지, 아이콘 고르기(미리 보기 갱신)
 *   [data-vl-record]  안내된 녹음: 마이크 확인, 대본별 녹음(RMRecorder), 품질 안내, 저장, 파일로 올리기, 삭제, 제출
 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var WORKING = /^(cloning|processing)$/;

  var GRADES = {
    good: { label: '좋아요', cls: 'bg-secondary-container text-on-secondary-container', icon: 'sentiment_satisfied' },
    fair: { label: '괜찮아요', cls: 'bg-tertiary-container text-on-tertiary-container', icon: 'sentiment_neutral' },
    poor: { label: '다시 녹음을 권해요', cls: 'bg-error-container text-on-error-container', icon: 'sentiment_dissatisfied' }
  };

  /** 초를 "1분 5초" 로 */
  function secText(sec) {
    sec = Math.max(0, Math.round(sec));
    var m = Math.floor(sec / 60), s = sec % 60;
    return m > 0 ? m + '분' + (s > 0 ? ' ' + s + '초' : '') : s + '초';
  }

  // ───────────────────────── 미리 듣기(공용) ─────────────────────────

  var player = null;
  var playingBtn = null;

  function setPlayIcon(btn, playing) {
    var icon = btn.querySelector('[data-play-icon]');
    if (icon) icon.textContent = playing ? 'pause' : 'play_arrow';
    btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
  }

  function stopPlayback() {
    if (player && !player.paused) player.pause();
    if (playingBtn) setPlayIcon(playingBtn, false);
    playingBtn = null;
  }

  function togglePlay(btn) {
    if (!player) {
      player = new Audio();
      player.preload = 'none';
      player.addEventListener('ended', stopPlayback);
      player.addEventListener('error', function () {
        if (!playingBtn) return;
        RM.toast('음성을 불러오지 못했어요. 잠시 후 다시 시도해 주세요.', 'error');
        stopPlayback();
      });
    }
    if (playingBtn === btn) { stopPlayback(); return; }
    stopPlayback();
    playingBtn = btn;
    setPlayIcon(btn, true);
    player.src = btn.getAttribute('data-play-src');
    var p = player.play();
    if (p && p.catch) {
      p.catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (playingBtn === btn) stopPlayback();
      });
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-play-src]');
    if (!btn) return;
    e.preventDefault();
    togglePlay(btn);
  });

  // ───────────────────────── 생성 상태 갱신 ─────────────────────────

  function initPolling() {
    var cards = $$('[data-voice-card]');
    var anyWorking = cards.some(function (c) { return WORKING.test(c.getAttribute('data-status')); });
    if (!anyWorking) return;
    var busy = false;
    var timer = setInterval(poll, 10000);

    async function poll() {
      if (document.hidden || busy) return;
      busy = true;
      var data;
      try { data = await RM.api('/api/voice-lab/status'); } catch (e) { busy = false; return; }
      busy = false;
      var byId = {};
      (data.voices || []).forEach(function (v) { byId[String(v.id)] = v; });
      var changed = false;
      cards.forEach(function (card) {
        var v = byId[card.getAttribute('data-voice-card')];
        if (!v || v.status !== card.getAttribute('data-status')) { changed = true; return; }
        if (!WORKING.test(v.status)) return;
        var st = card.querySelector('[data-voice-status]');
        // 목소리 복제 단계(cloning)에는 동화 진행률이 없으므로 퍼센트 대신 단계 이름을 보여 준다.
        if (st) st.textContent = v.status === 'cloning' ? '목소리 만드는 중...' : '동화 만드는 중... ' + v.percent + '%';
        var pct = card.querySelector('[data-progress-percent]');
        if (pct) pct.textContent = v.percent;
        var bar = card.querySelector('[data-progress-bar]');
        if (bar) bar.style.width = v.percent + '%';
        var done = card.querySelector('[data-progress-done]');
        if (done) done.textContent = v.completed;
        var total = card.querySelector('[data-progress-total]');
        if (total) total.textContent = v.total;
      });
      // 상태가 바뀌면(준비됨, 실패 등) 화면 전체를 다시 그린다.
      if (changed) { clearInterval(timer); location.reload(); }
    }
  }

  // ───────────────────────── 새 목소리 ─────────────────────────

  function initNew(root) {
    var whos = $$('[data-who]', root);
    var icons = $$('[data-icon-pick]', root);
    var wrap = $('[data-custom-wrap]', root);
    var input = $('[data-custom-input]', root);
    var count = $('[data-custom-count]', root);
    var pLabel = $('[data-preview-label]', root);
    var pIcon = $('[data-preview-icon]', root);
    var iconTouched = false;

    function checked(list) {
      for (var i = 0; i < list.length; i++) if (list[i].checked) return list[i];
      return null;
    }

    function update(fromWho) {
      var w = checked(whos);
      var custom = !!w && w.value === 'custom';
      wrap.classList.toggle('hidden', !custom);
      pLabel.textContent = custom ? (input.value.trim() || '우리 가족') : (w ? w.value : '우리 가족');
      if (fromWho && !iconTouched && w) {
        icons.forEach(function (r) { r.checked = r.value === w.getAttribute('data-icon'); });
      }
      var ic = checked(icons);
      pIcon.textContent = ic ? ic.value : 'face_5';
      count.textContent = String(input.value.length);
    }

    whos.forEach(function (r) {
      r.addEventListener('change', function () {
        update(true);
        if (r.value === 'custom') input.focus();
      });
    });
    icons.forEach(function (r) {
      r.addEventListener('change', function () { iconTouched = true; update(false); });
    });
    input.addEventListener('input', function () { update(false); });
    update(false);
  }

  // ───────────────────────── 음량 막대 ─────────────────────────

  /** 최근 음량을 막대 그래프로 보여 준다. push(0~1, 말소리인지) */
  function makeMeter(box, n) {
    var bars = [];
    var levels = [];
    box.innerHTML = '';
    for (var i = 0; i < n; i++) {
      var b = document.createElement('span');
      b.className = 'w-1 shrink-0 rounded-full bg-primary transition-[height] duration-75';
      b.style.height = '8%';
      b.style.opacity = '0.3';
      box.appendChild(b);
      bars.push(b);
      levels.push([0, false]);
    }
    return {
      push: function (level, speech) {
        levels.shift();
        levels.push([level, speech]);
        for (var i = 0; i < n; i++) {
          bars[i].style.height = Math.max(8, Math.round(levels[i][0] * 100)) + '%';
          bars[i].style.opacity = levels[i][1] ? '1' : '0.35';
        }
      },
      reset: function () {
        for (var i = 0; i < n; i++) {
          levels[i] = [0, false];
          bars[i].style.height = '8%';
          bars[i].style.opacity = '0.3';
        }
      }
    };
  }

  /** dBFS(-60~0)를 0~1 로 */
  function dbToLevel(db) {
    return Math.max(0, Math.min(1, (db + 60) / 60));
  }

  /**
   * RMRecorder 가 만든 WAV(머리글 44바이트, PCM)를 maxBytes 이하 조각으로 나눈다.
   * 서버 업로드 한도가 작은 호스팅에서도 긴 녹음을 올리기 위해서다. 반환 [{blob, durationMs}]
   */
  async function splitWav(blob, maxBytes) {
    var buf = await blob.arrayBuffer();
    var view = new DataView(buf);
    var rate = view.getUint32(24, true);
    var byteRate = view.getUint32(28, true);
    var align = view.getUint16(32, true) || 2;
    var bits = view.getUint16(34, true);
    var pcm = new Uint8Array(buf, 44);
    var parts = Math.max(1, Math.ceil(pcm.length / Math.max(align, maxBytes - 44)));
    var per = Math.ceil(pcm.length / parts / align) * align;
    var out = [];
    for (var off = 0; off < pcm.length; off += per) {
      var part = pcm.subarray(off, Math.min(pcm.length, off + per));
      var h = new DataView(new ArrayBuffer(44));
      var str = function (o, t) { for (var i = 0; i < t.length; i++) h.setUint8(o + i, t.charCodeAt(i)); };
      str(0, 'RIFF'); h.setUint32(4, 36 + part.length, true); str(8, 'WAVE');
      str(12, 'fmt '); h.setUint32(16, 16, true); h.setUint16(20, 1, true); h.setUint16(22, 1, true);
      h.setUint32(24, rate, true); h.setUint32(28, byteRate, true); h.setUint16(32, align, true); h.setUint16(34, bits, true);
      str(36, 'data'); h.setUint32(40, part.length, true);
      out.push({ blob: new Blob([h.buffer, part], { type: 'audio/wav' }), durationMs: Math.round(part.length * 1000 / byteRate) });
    }
    return out;
  }

  /**
   * 녹음 품질 판정과 안내 문구. metrics 는 RMRecorder 결과(없으면 null).
   * 반환 { grade: good|fair|poor|null, hint }
   */
  function judge(m, ms, rate) {
    if (ms && ms < 3000) return { grade: 'poor', hint: '너무 짧아요. 3초 이상 녹음해 주세요.' };
    if (!m) return { grade: null, hint: '이 파일은 미리 품질을 확인하지 못했어요. 검토할 때 확인할게요.' };
    var grade = m.grade;
    var speechDb = (m.noiseDb || -100) + (m.snrDb || 0);
    var clipRatio = (m.clipCount || 0) / Math.max(1, ((ms || 1000) / 1000) * (rate || 44100));
    var hint;
    if ((m.speechMs || 0) < 1500) {
      grade = 'poor';
      hint = '목소리가 거의 들리지 않았어요. 마이크 가까이에서 다시 읽어 주세요.';
    } else if (clipRatio >= 0.001) {
      hint = '소리가 너무 커서 일부가 찌그러졌어요. 휴대폰을 입에서 조금 더 멀리 두세요.';
    } else if (speechDb < -40) {
      hint = '목소리가 작게 녹음됐어요. 조금 더 가까이에서 또렷하게 읽어 주세요.';
    } else if ((m.snrDb || 0) < 25) {
      hint = '주변 소음이 들려요. 더 조용한 곳에서 녹음하면 더 닮은 목소리가 돼요.';
    } else {
      hint = grade === 'good' ? '또렷하게 잘 녹음됐어요!' : '괜찮아요. 저장해도 좋아요.';
    }
    return { grade: grade, hint: hint };
  }

  function paintBadge(el, grade) {
    var g = GRADES[grade];
    el.className = 'inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1 font-label-sm text-label-sm ' + (g ? g.cls : 'bg-surface-container text-on-surface-variant');
    el.innerHTML = '<span class="material-symbols-outlined text-[16px]">' + (g ? g.icon : 'help') + '</span>' + RM.escapeHtml(g ? g.label : '확인 전');
  }

  function paintHint(el, grade, text) {
    var icon = grade === 'good' ? 'check_circle' : grade === 'poor' ? 'warning' : 'info';
    var color = grade === 'good' ? 'text-secondary' : grade === 'poor' ? 'text-error' : 'text-outline';
    el.innerHTML = '<span class="material-symbols-outlined text-[18px] ' + color + '">' + icon + '</span><span>' + RM.escapeHtml(text) + '</span>';
  }

  // ───────────────────────── 녹음 화면 ─────────────────────────

  function initRecord(root) {
    var data = JSON.parse($('#vl-record-data').textContent);
    var samples = data.samples || [];
    var totalMs = samples.reduce(function (a, s) { return a + (s.duration_ms || 0); }, 0);
    var unknownCount = samples.filter(function (s) { return !s.duration_ms; }).length;
    var step = data.step;
    var scriptKey = null;
    var ALLOWED = ['wav', 'mp3', 'm4a', 'mp4', 'aac', 'ogg', 'webm', 'flac'];

    var el = {
      tabs: $$('[data-step-tab]', root),
      steps: $$('[data-step]', root),
      samplesWrap: $('[data-samples-wrap]', root),
      samplesList: $('[data-samples]', root),
      samplesEmpty: $('[data-samples-empty]', root),
      samplesCount: $('[data-samples-count]', root),
      nextStep: $('[data-next-step]', root),
      total: $('[data-total]', root),
      totalBar: $('[data-total-bar]', root),
      totalHint: $('[data-total-hint]', root),
      scriptTabs: $$('[data-script-tab]', root),
      scriptPanels: $$('[data-script-panel]', root),
      recBtn: $('[data-rec-btn]', root),
      recIcon: $('[data-rec-icon]', root),
      recRing: $('[data-rec-ring]', root),
      recState: $('[data-rec-state]', root),
      timer: $('[data-timer]', root),
      review: $('[data-review]', root),
      reviewAudio: $('[data-review-audio]', root),
      reviewBadge: $('[data-review-badge]', root),
      reviewHint: $('[data-review-hint]', root),
      reviewDuration: $('[data-review-duration]', root),
      retake: $('[data-retake]', root),
      save: $('[data-save]', root),
      fileInput: $('[data-file-input]', root),
      fileName: $('[data-file-name]', root),
      fileResult: $('[data-file-result]', root),
      fileInfo: $('[data-file-info]', root),
      fileBadge: $('[data-file-badge]', root),
      fileHint: $('[data-file-hint]', root),
      fileUpload: $('[data-file-upload]', root),
      micBtn: $('[data-mic-check]', root),
      micLabel: $('[data-mic-check-label]', root),
      micStatus: $('[data-mic-status]', root),
      consent: $('[data-consent]', root),
      submitForm: $('[data-submit-form]', root),
      submitBtn: $('[data-submit-btn]', root),
      submitHint: $('[data-submit-hint]', root)
    };
    var meter = makeMeter($('[data-meter]', root), 40);
    var micMeter = makeMeter($('[data-mic-meter]', root), 40);

    var rec = null;          // 녹음 중인 RMRecorder
    var recording = false;
    var recScript = null;    // 녹음을 시작할 때의 대본
    var take = null;         // 저장하지 않은 녹음 { blob, durationMs, metrics, sampleRate, scriptKey, source, name }
    var takeUrl = null;
    var fileTake = null;
    var saving = false;

    // ── 단계 ──
    var TAB_ON = ['bg-surface-container-lowest', 'text-primary', 'shadow-sm'];
    var TAB_OFF = ['text-on-surface-variant'];
    var NUM_ON = ['bg-primary', 'text-on-primary'];
    var NUM_OFF = ['bg-surface-container-high', 'text-on-surface-variant'];

    function swap(node, on, onList, offList) {
      onList.forEach(function (c) { node.classList.toggle(c, on); });
      offList.forEach(function (c) { node.classList.toggle(c, !on); });
    }

    function setStep(n, scroll) {
      if (recording) { RM.toast('녹음을 먼저 멈춰 주세요.', 'info'); return; }
      if (n !== 1) stopMicCheck();
      step = n;
      el.steps.forEach(function (s) { s.hidden = Number(s.getAttribute('data-step')) !== n; });
      el.tabs.forEach(function (t) {
        var on = Number(t.getAttribute('data-step-tab')) === n;
        swap(t, on, TAB_ON, TAB_OFF);
        swap($('[data-step-num]', t), on, NUM_ON, NUM_OFF);
        t.setAttribute('aria-current', on ? 'step' : 'false');
      });
      el.samplesWrap.hidden = n === 1;
      updateTotals();
      try {
        var u = new URL(location.href);
        u.searchParams.set('step', String(n));
        history.replaceState(null, '', u.pathname + u.search);
      } catch (e) {}
      if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    el.tabs.forEach(function (t) {
      t.addEventListener('click', function () { setStep(Number(t.getAttribute('data-step-tab')), true); });
    });
    $$('[data-goto]', root).forEach(function (b) {
      b.addEventListener('click', function () { setStep(Number(b.getAttribute('data-goto')), true); });
    });

    // ── 대본 ──
    var SCRIPT_ON = ['border-primary', 'bg-primary-container', 'text-on-primary-container'];
    var SCRIPT_OFF = ['border-surface-variant', 'bg-surface-container-lowest', 'text-on-surface-variant'];

    function doneKeys() {
      var keys = {};
      samples.forEach(function (s) { if (s.script_key) keys[s.script_key] = true; });
      return keys;
    }

    function selectScript(key) {
      if (recording) { RM.toast('녹음을 먼저 멈춰 주세요.', 'info'); return; }
      if (take && take.scriptKey !== key && !window.confirm('저장하지 않은 녹음이 있어요. 지우고 다른 대본으로 넘어갈까요?')) return;
      if (take && take.scriptKey !== key) discardTake();
      scriptKey = key;
      el.scriptPanels.forEach(function (p) { p.hidden = p.getAttribute('data-script-panel') !== key; });
      el.scriptTabs.forEach(function (t) {
        var on = t.getAttribute('data-script-tab') === key;
        swap(t, on, SCRIPT_ON, SCRIPT_OFF);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function markDone() {
      var keys = doneKeys();
      el.scriptTabs.forEach(function (t) {
        $('[data-script-done]', t).classList.toggle('hidden', !keys[t.getAttribute('data-script-tab')]);
      });
    }

    function nextScriptKey() {
      var keys = doneKeys();
      var list = data.scripts.map(function (s) { return s.key; });
      var start = Math.max(0, list.indexOf(scriptKey));
      for (var i = 1; i <= list.length; i++) {
        var k = list[(start + i) % list.length];
        if (!keys[k]) return k;
      }
      return null;
    }

    el.scriptTabs.forEach(function (t) {
      t.addEventListener('click', function () { selectScript(t.getAttribute('data-script-tab')); });
    });

    // ── 합계, 제출 가능 여부 ──
    function canSubmit() {
      return samples.length > 0 && (totalMs >= data.minMs || unknownCount > 0);
    }

    function updateTotals() {
      el.total.textContent = RM.fmtTime(totalMs);
      var pct = Math.min(100, totalMs * 100 / Math.max(1, data.recMs));
      el.totalBar.style.width = pct + '%';
      el.totalBar.classList.toggle('bg-secondary', totalMs >= data.minMs);
      el.totalBar.classList.toggle('bg-primary', totalMs < data.minMs);
      var hint, icon = 'info';
      if (!samples.length) {
        hint = '최소 ' + secText(data.minMs / 1000) + ' 이상 녹음하면 제출할 수 있어요.';
      } else if (totalMs < data.minMs && unknownCount > 0) {
        hint = '길이를 알 수 없는 파일이 ' + unknownCount + '개 있어요. 제출하면 검토할 때 확인할게요.';
      } else if (totalMs < data.minMs) {
        hint = '제출하려면 ' + secText(Math.ceil((data.minMs - totalMs) / 1000)) + ' 더 녹음해 주세요.';
      } else if (totalMs < data.recMs) {
        icon = 'check_circle';
        hint = '제출할 수 있어요! ' + secText(data.recMs / 1000) + '까지 녹음하면 더 닮은 목소리가 돼요.';
      } else {
        icon = 'celebration';
        hint = '충분히 녹음했어요. 이제 제출해 주세요.';
      }
      el.totalHint.innerHTML = '<span class="material-symbols-outlined text-[18px] ' + (icon === 'info' ? 'text-outline' : 'text-secondary') + '">' + icon + '</span><span>' + RM.escapeHtml(hint) + '</span>';
      updateSubmit();
      el.nextStep.hidden = !(step === 2 && canSubmit());
    }

    function updateSubmit() {
      var ok = canSubmit();
      el.submitBtn.disabled = !(ok && el.consent.checked);
      if (!ok) {
        el.submitHint.textContent = samples.length ? '최소 ' + secText(data.minMs / 1000) + '을 채우면 제출할 수 있어요.' : '먼저 대본을 녹음해 주세요.';
      } else {
        el.submitHint.textContent = el.consent.checked ? '' : '위 내용에 동의하면 제출할 수 있어요.';
      }
    }

    el.consent.addEventListener('change', updateSubmit);
    el.submitForm.addEventListener('submit', function (e) {
      if (el.submitBtn.disabled) { e.preventDefault(); return; }
      el.submitBtn.disabled = true;
      el.submitBtn.lastChild.textContent = '제출하는 중...';
    });

    // ── 저장한 녹음 목록 ──
    function renderSamples() {
      el.samplesList.innerHTML = samples.map(function (s) {
        var g = s.grade && GRADES[s.grade];
        return '<li class="flex items-center gap-3 rounded-2xl border border-surface-container bg-surface-container-lowest p-3" data-sample="' + s.id + '">'
          + '<button type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container active:scale-90" data-play-src="' + RM.escapeHtml(s.url) + '" aria-label="' + RM.escapeHtml(s.title) + ' 듣기">'
          + '<span class="material-symbols-outlined icon-fill" data-play-icon>play_arrow</span></button>'
          + '<div class="min-w-0 flex-1"><p class="truncate font-label-lg text-label-lg text-on-surface">' + RM.escapeHtml(s.title) + '</p>'
          + '<p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-label-sm text-on-surface-variant"><span>' + (s.duration_ms ? RM.fmtTime(s.duration_ms) : '길이 알 수 없음') + ' · ' + (s.source === 'record' ? '녹음' : '파일') + '</span>'
          + (g ? '<span class="rounded-full px-2 py-0.5 text-[11px] leading-4 ' + g.cls + '">' + g.label + '</span>' : '') + '</p></div>'
          + '<button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-error-container hover:text-on-error-container" data-delete-sample="' + s.id + '" aria-label="녹음 지우기">'
          + '<span class="material-symbols-outlined">delete</span></button></li>';
      }).join('');
      el.samplesEmpty.hidden = samples.length > 0;
      el.samplesCount.textContent = samples.length ? samples.length + '개' : '';
      markDone();
    }

    el.samplesList.addEventListener('click', async function (e) {
      var btn = e.target.closest('[data-delete-sample]');
      if (!btn) return;
      if (!window.confirm('이 녹음을 지울까요? 지운 녹음은 되돌릴 수 없어요.')) return;
      var id = Number(btn.getAttribute('data-delete-sample'));
      btn.disabled = true;
      try {
        var res = await RM.api('/api/voice-lab/' + data.voiceId + '/samples/' + id + '/delete', { method: 'POST' });
        stopPlayback();
        samples = samples.filter(function (s) { return s.id !== id; });
        totalMs = res.total_ms;
        unknownCount = res.unknown_count;
        renderSamples();
        updateTotals();
        RM.toast(res.message || '녹음을 지웠어요.', 'success');
      } catch (err) {
        btn.disabled = false;
        RM.toast(err.message, 'error');
      }
    });

    // ── 업로드(녹음 저장, 파일 올리기 공용) ──
    async function sendOne(blob, name, durationMs, t) {
      var fd = new FormData();
      fd.append('audio', blob, name);
      fd.append('duration_ms', durationMs ? String(durationMs) : '');
      fd.append('metrics', t.metrics ? JSON.stringify(t.metrics) : '');
      fd.append('source', t.source);
      fd.append('script_key', t.scriptKey || '');
      fd.append('_token', RM.csrf());
      var res = await RM.api(data.uploadUrl, { method: 'POST', body: fd });
      samples.push(res.sample);
      totalMs = res.total_ms;
      unknownCount = res.unknown_count;
      renderSamples();
      updateTotals();
      return res;
    }

    async function upload(t) {
      if (t.blob.size <= data.maxUpload) return sendOne(t.blob, t.name, t.durationMs, t);
      if (t.source !== 'record') throw new Error('파일이 너무 커요. ' + mbText(data.maxUpload) + ' 이하 파일만 올릴 수 있어요.');
      // 서버 한도보다 긴 녹음은 조각으로 나눠 차례로 올린다(조각마다 하나의 녹음으로 저장된다).
      var parts = await splitWav(t.blob, data.maxUpload);
      var res = null;
      for (var i = 0; i < parts.length; i++) {
        res = await sendOne(parts[i].blob, t.name.replace(/\.wav$/, '') + '-' + (i + 1) + '.wav', parts[i].durationMs, t);
      }
      return res;
    }

    function mbText(bytes) {
      return (Math.round(bytes / 104857.6) / 10) + 'MB';
    }

    // ── 녹음 ──
    function setRecUi(state) {
      var on = state === 'recording';
      el.recRing.classList.toggle('hidden', !on);
      el.recIcon.textContent = on ? 'stop' : 'mic';
      el.recBtn.classList.toggle('bg-error', on);
      el.recBtn.classList.toggle('text-on-error', on);
      el.recBtn.classList.toggle('bg-primary', !on);
      el.recBtn.classList.toggle('text-on-primary', !on);
      el.recBtn.setAttribute('aria-label', on ? '녹음 멈추기' : '녹음 시작');
      el.recBtn.disabled = state === 'starting' || state === 'stopping';
      var text = {
        idle: '버튼을 누르고 위 대본을 소리 내어 읽어 주세요.',
        starting: '마이크를 켜고 있어요...',
        recording: '녹음 중이에요. 다 읽으면 버튼을 눌러 멈춰 주세요.',
        stopping: '녹음을 정리하고 있어요...',
        review: '녹음을 들어 보고 저장해 주세요.'
      }[state];
      if (text) el.recState.textContent = text;
      el.recState.classList.toggle('text-primary', on);
    }

    async function startRec() {
      if (!window.RMRecorder || !RMRecorder.supported()) {
        RM.toast('이 브라우저에서는 녹음을 할 수 없어요. 아래 "파일로 올리기"를 이용해 주세요.', 'error');
        return;
      }
      if (take && !window.confirm('저장하지 않은 녹음이 있어요. 지우고 새로 녹음할까요?')) return;
      discardTake();
      stopPlayback();
      stopMicCheck();
      meter.reset();
      el.timer.textContent = '00:00';
      recScript = scriptKey;
      rec = new RMRecorder({
        sampleRate: 24000,
        maxMs: data.maxTakeMs,
        echoCancellation: false,
        noiseSuppression: false,
        autoGainControl: false,
        onLevel: function (db, speech) { meter.push(dbToLevel(db), speech); },
        onTick: function (ms) { el.timer.textContent = RM.fmtTime(ms); },
        onAutoStop: function (reason) {
          if (reason === 'max') {
            RM.toast('최대 ' + secText(data.maxTakeMs / 1000) + '이 되어 녹음을 멈췄어요.', 'info');
            finishRec();
          }
        }
      });
      setRecUi('starting');
      try {
        await rec.start();
      } catch (err) {
        rec = null;
        setRecUi('idle');
        el.recState.textContent = err.message;
        RM.toast(err.message, 'error');
        return;
      }
      recording = true;
      setRecUi('recording');
    }

    async function finishRec() {
      if (!rec || !recording) return;
      recording = false;
      setRecUi('stopping');
      var r;
      try {
        r = await rec.stop();
      } catch (err) {
        rec = null;
        setRecUi('idle');
        RM.toast('녹음을 정리하지 못했어요. 다시 녹음해 주세요.', 'error');
        return;
      }
      rec = null;
      take = {
        blob: r.blob,
        durationMs: r.durationMs,
        metrics: r.metrics,
        sampleRate: r.sampleRate,
        scriptKey: recScript,
        source: 'record',
        name: (recScript || 'take') + '.wav'
      };
      setRecUi('review');
      showReview();
    }

    function showReview() {
      var q = judge(take.metrics, take.durationMs, take.sampleRate);
      if (take.metrics && q.grade) take.metrics = Object.assign({}, take.metrics, { grade: q.grade });
      if (takeUrl) URL.revokeObjectURL(takeUrl);
      takeUrl = URL.createObjectURL(take.blob);
      el.reviewAudio.src = takeUrl;
      el.reviewDuration.textContent = '(' + RM.fmtTime(take.durationMs) + ')';
      paintBadge(el.reviewBadge, q.grade);
      paintHint(el.reviewHint, q.grade, q.hint);
      el.save.disabled = take.durationMs < 3000;
      el.review.hidden = false;
      el.review.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function discardTake() {
      take = null;
      if (takeUrl) { URL.revokeObjectURL(takeUrl); takeUrl = null; }
      try { el.reviewAudio.pause(); } catch (e) {}
      el.reviewAudio.removeAttribute('src');
      el.review.hidden = true;
      if (!recording) {
        setRecUi('idle');
        meter.reset();
        el.timer.textContent = '00:00';
      }
    }

    el.recBtn.addEventListener('click', function () {
      if (recording) finishRec(); else startRec();
    });
    el.retake.addEventListener('click', function () {
      discardTake();
      startRec();
    });
    el.save.addEventListener('click', async function () {
      if (!take || saving) return;
      saving = true;
      el.save.disabled = true;
      var label = el.save.lastChild;
      label.textContent = '저장 중...';
      try {
        await upload(take);
        RM.toast('녹음을 저장했어요.', 'success');
        discardTake();
        var next = nextScriptKey();
        if (next) {
          selectScript(next);
          var panel = $('[data-script-panel="' + next + '"]', root);
          if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (canSubmit()) {
          RM.toast('대본을 모두 녹음했어요! 이제 확인 & 제출로 넘어가 볼까요?', 'info');
          el.nextStep.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      } catch (err) {
        el.save.disabled = false;
        RM.toast(err.message, 'error');
      } finally {
        saving = false;
        label.textContent = '저장';
      }
    });

    // ── 파일로 올리기 ──
    function resetFile() {
      fileTake = null;
      el.fileInput.value = '';
      el.fileName.textContent = '음성 파일 고르기';
      el.fileResult.hidden = true;
    }

    el.fileInput.addEventListener('change', async function () {
      var f = el.fileInput.files && el.fileInput.files[0];
      if (!f) return;
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (ALLOWED.indexOf(ext) < 0) {
        RM.toast('지원하지 않는 파일 형식이에요. (wav, mp3, m4a, aac, ogg, webm, flac)', 'error');
        resetFile();
        return;
      }
      if (f.size > data.maxUpload) {
        RM.toast('파일이 너무 커요. ' + mbText(data.maxUpload) + ' 이하 파일만 올릴 수 있어요.', 'error');
        resetFile();
        return;
      }
      el.fileName.textContent = f.name;
      el.fileResult.hidden = false;
      el.fileInfo.textContent = '파일을 확인하고 있어요...';
      el.fileBadge.className = 'hidden';
      el.fileHint.textContent = '';
      el.fileUpload.disabled = true;
      var a = window.RMRecorder ? await RMRecorder.analyzeFile(f) : { durationMs: null, metrics: null };
      if (el.fileInput.files[0] !== f) return;
      var q = judge(a.metrics, a.durationMs, 44100);
      var metrics = a.metrics && q.grade ? Object.assign({}, a.metrics, { grade: q.grade }) : a.metrics;
      fileTake = { blob: f, durationMs: a.durationMs, metrics: metrics, scriptKey: null, source: 'upload', name: f.name };
      el.fileInfo.textContent = a.durationMs ? '길이 ' + RM.fmtTime(a.durationMs) : '길이를 확인하지 못했어요';
      paintBadge(el.fileBadge, q.grade);
      paintHint(el.fileHint, q.grade, q.hint);
      el.fileUpload.disabled = !!(a.durationMs && a.durationMs < 3000);
    });

    el.fileUpload.addEventListener('click', async function () {
      if (!fileTake || saving) return;
      saving = true;
      el.fileUpload.disabled = true;
      var label = el.fileUpload.lastChild;
      label.textContent = '올리는 중...';
      try {
        await upload(fileTake);
        RM.toast('파일을 올렸어요.', 'success');
        resetFile();
      } catch (err) {
        el.fileUpload.disabled = false;
        RM.toast(err.message, 'error');
      } finally {
        saving = false;
        label.textContent = '이 파일 올리기';
      }
    });

    // ── 마이크 확인(1단계) ──
    var mic = null;
    var micHeard = false;
    var micMax = -100;

    function micStatus(kind, text) {
      var map = {
        info: ['info', 'text-on-surface-variant'],
        listen: ['graphic_eq', 'text-primary'],
        good: ['check_circle', 'text-secondary'],
        warn: ['warning', 'text-error']
      };
      var m = map[kind] || map.info;
      el.micStatus.className = 'flex items-center gap-1.5 text-[14px] font-semibold leading-5 ' + m[1];
      el.micStatus.innerHTML = '<span class="material-symbols-outlined text-[18px]">' + m[0] + '</span><span>' + RM.escapeHtml(text) + '</span>';
    }

    function stopMicCheck() {
      if (!mic) return;
      mic.cancel();
      mic = null;
      micMeter.reset();
      el.micLabel.textContent = '다시 확인';
      if (micMax > -3) micStatus('warn', '소리가 너무 커요. 휴대폰을 입에서 조금 더 멀리 두세요.');
      else if (micHeard) micStatus('good', '마이크가 잘 들려요! 녹음을 시작해도 좋아요.');
      else micStatus('warn', '소리가 잘 들리지 않아요. 마이크가 가려지지 않았는지 확인해 주세요.');
    }

    el.micBtn.addEventListener('click', async function () {
      if (mic) { stopMicCheck(); return; }
      if (!window.RMRecorder || !RMRecorder.supported()) {
        micStatus('warn', '이 브라우저에서는 녹음을 할 수 없어요. 최신 Chrome 이나 Safari 를 사용해 주세요.');
        return;
      }
      micHeard = false;
      micMax = -100;
      mic = new RMRecorder({
        sampleRate: 16000,
        maxMs: 8000,
        echoCancellation: false,
        noiseSuppression: false,
        autoGainControl: false,
        onLevel: function (db, speech) {
          micMeter.push(dbToLevel(db), speech);
          if (speech && db > -45) {
            if (!micHeard) micStatus('good', '잘 들려요! 조금 더 말해 보세요.');
            micHeard = true;
          }
          if (db > micMax) micMax = db;
        },
        onAutoStop: function () { setTimeout(stopMicCheck, 0); }
      });
      el.micLabel.textContent = '멈추기';
      micStatus('listen', '듣고 있어요. 대본을 읽듯 말해 보세요.');
      try {
        await mic.start();
      } catch (err) {
        mic = null;
        el.micLabel.textContent = '다시 확인';
        micStatus('warn', err.message);
      }
    });

    // 녹음 중이거나 저장하지 않은 녹음이 있으면 나가기 전에 묻는다.
    window.addEventListener('beforeunload', function (e) {
      if (recording || take || saving) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    // ── 시작 ──
    renderSamples();
    var first = data.scripts.filter(function (s) { return !doneKeys()[s.key]; })[0] || data.scripts[0];
    selectScript(first.key);
    setStep(step, false);
  }

  // ───────────────────────── 시작 ─────────────────────────

  var recordRoot = $('[data-vl-record]');
  if (recordRoot) initRecord(recordRoot);
  var newRoot = $('[data-vl-new]');
  if (newRoot) initNew(newRoot);
  if ($('[data-vl-index]') || $('[data-vl-show]')) initPolling();
})();
