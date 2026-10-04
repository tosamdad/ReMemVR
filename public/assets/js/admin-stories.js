/**
 * 동화 콘텐츠 관리(CMS) 편집기.
 * 탭 전환, 문장 행 추가/삭제/번호 다시 매기기, 키워드 칩, 본문 붙여넣기 문장 나누기,
 * 끼어들기 설정(대체 문장, VAD, AEC), 배포 상태 점검 창, 전체 청취 시뮬레이션을 맡는다.
 * 폼은 sentences[i][content|keywords|start|end] 배열로 그대로 제출된다(행 순서 = 저장 순서).
 */
(function () {
  'use strict';
  var page = document.querySelector('[data-story-page]');
  if (!page) return;
  var form = document.querySelector('[data-story-form]');
  var dataEl = document.getElementById('story-data');
  var DATA = { audios: [], saved: [], globalFallback: [] };
  try { DATA = JSON.parse(dataEl ? dataEl.textContent : '{}') || DATA; } catch (e) { /* 기본값 사용 */ }
  var dirty = false;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function pad(n) { return String(n).padStart(2, '0'); }
  function fmt(ms) { return RM.fmtTime(ms || 0); }

  // ───────── 기기 음성(한국어 TTS) ─────────
  var synth = window.speechSynthesis || null;
  function koVoice() {
    if (!synth) return null;
    var list = synth.getVoices() || [];
    for (var i = 0; i < list.length; i++) { if (/^ko/i.test(list[i].lang)) return list[i]; }
    return null;
  }
  function speak(text, onend) {
    if (!synth || !window.SpeechSynthesisUtterance) {
      RM.toast('이 브라우저는 기기 음성 읽기를 지원하지 않습니다.', 'error');
      if (onend) onend(false);
      return null;
    }
    var u = new SpeechSynthesisUtterance(text);
    u.lang = 'ko-KR';
    var v = koVoice();
    if (v) u.voice = v;
    u.rate = 0.95;
    u.onend = function () { if (onend) onend(true); };
    u.onerror = function () { if (onend) onend(false); };
    synth.speak(u);
    return u;
  }
  function stopSpeak() { if (synth) synth.cancel(); }

  // ───────── 탭 ─────────
  var tabs = $$('[data-tab]');
  var panels = $$('[data-panel]');
  var tabInput = $('[data-tab-input]');
  function showTab(name, push) {
    if (!tabs.some(function (t) { return t.getAttribute('data-tab') === name; })) return;
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-tab') === name;
      t.classList.toggle('border-primary', on);
      t.classList.toggle('text-primary', on);
      t.classList.toggle('font-bold', on);
      t.classList.toggle('border-transparent', !on);
      t.classList.toggle('text-on-surface-variant', !on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (p) {
      var names = (p.getAttribute('data-panel') || '').split(/\s+/);
      p.hidden = names.indexOf(name) === -1;
    });
    if (tabInput) tabInput.value = name;
    if (push && history.replaceState) history.replaceState(null, '', location.pathname + location.search + '#' + name);
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { showTab(t.getAttribute('data-tab'), true); }); });
  var initial = (location.hash || '').replace('#', '');
  if (initial) showTab(initial, false);
  // 검증 오류가 있는 탭을 먼저 보여 준다.
  var firstErr = form ? form.querySelector('.text-error') : null;
  if (firstErr) {
    var panel = firstErr.closest('[data-panel]');
    if (panel) showTab(panel.getAttribute('data-panel').split(/\s+/)[0], false);
  }

  // ───────── 문장 행 ─────────
  var tbody = $('[data-rows]');
  var rowTpl = document.getElementById('sentence-row-template');
  var emptyNote = $('[data-rows-empty]');
  var summary = $('[data-summary]');

  function autoGrow(ta) {
    ta.style.height = 'auto';
    ta.style.height = Math.max(ta.scrollHeight, 40) + 'px';
  }
  function renderChips(row) {
    var input = $('[data-keywords]', row);
    var box = $('[data-chips]', row);
    if (!input || !box) return;
    var seen = {};
    var words = input.value.split(/[,#\s]+/).map(function (w) { return w.trim(); }).filter(function (w) {
      if (!w || seen[w]) return false;
      seen[w] = true;
      return true;
    });
    box.innerHTML = words.map(function (w) {
      return '<span class="rounded-full bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm text-on-primary-fixed-variant">' + RM.escapeHtml(w) + '</span>';
    }).join('');
  }
  function renumber() {
    if (!tbody) return;
    var rows = $$('[data-row]', tbody);
    var chars = 0, count = 0;
    rows.forEach(function (row, i) {
      var id = $('[data-row-id]', row);
      if (id) id.textContent = 'ST-' + pad(i + 1);
      $$('[name^="sentences["]', row).forEach(function (el) {
        el.name = el.name.replace(/^sentences\[[^\]]*\]/, 'sentences[' + i + ']');
      });
      var text = ($('[data-content]', row) || {}).value || '';
      text = text.replace(/\s+/g, ' ').trim();
      if (text) { count++; chars += text.length; }
    });
    // 저장 시 문장 사이를 공백 하나로 잇는다(StoryController 와 같은 계산).
    if (count > 1) chars += count - 1;
    if (emptyNote) emptyNote.classList.toggle('hidden', rows.length > 0);
    if (summary) {
      var sec = Math.ceil(chars / 5.5);
      summary.textContent = '문장 ' + count + '개 · 글자 ' + chars.toLocaleString() + '자 · 예상 낭독 ' + Math.floor(sec / 60) + '분 ' + pad(sec % 60) + '초';
    }
  }
  function bindRow(row) {
    var ta = $('[data-content]', row);
    if (ta) { autoGrow(ta); ta.addEventListener('input', function () { autoGrow(ta); renumber(); }); }
    var kw = $('[data-keywords]', row);
    if (kw) kw.addEventListener('input', function () { renderChips(row); });
    $$('[data-time]', row).forEach(function (inp) { inp.addEventListener('blur', function () { normalizeTime(inp); }); });
  }
  function newRow(values) {
    var html = rowTpl.innerHTML.replace(/__i__/g, '0');
    var tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    var row = tmp.firstElementChild;
    if (values) {
      $('[data-content]', row).value = values.content || '';
      $('[data-keywords]', row).value = values.keywords || '';
      var times = $$('[data-time]', row);
      if (times[0]) times[0].value = values.start || '';
      if (times[1]) times[1].value = values.end || '';
    }
    return row;
  }
  function addRow(values, after) {
    var row = newRow(values);
    if (after && after.parentNode) after.parentNode.insertBefore(row, after.nextSibling);
    else tbody.appendChild(row);
    bindRow(row);
    renderChips(row);
    renumber();
    markDirty();
    return row;
  }
  // 초 또는 m:ss 입력을 MM:SS 로 맞춘다.
  function normalizeTime(inp) {
    var v = inp.value.trim();
    if (v === '') return;
    var m;
    if (/^\d+$/.test(v)) {
      var s = parseInt(v, 10);
      inp.value = pad(Math.floor(s / 60)) + ':' + pad(s % 60);
    } else if ((m = v.match(/^(\d{1,3}):(\d{1,2})(\.\d{1,3})?$/))) {
      inp.value = pad(parseInt(m[1], 10)) + ':' + pad(parseInt(m[2], 10)) + (m[3] || '');
    }
    var ok = /^(\d{1,2}:)?\d{1,3}:\d{2}(\.\d{1,3})?$/.test(inp.value);
    inp.classList.toggle('ring-2', !ok);
    inp.classList.toggle('ring-error', !ok);
  }

  if (tbody) {
    $$('[data-row]', tbody).forEach(bindRow);
    renumber();
    tbody.addEventListener('click', function (e) {
      var row = e.target.closest('[data-row]');
      if (!row) return;
      if (e.target.closest('[data-row-delete]')) {
        var text = ($('[data-content]', row) || {}).value || '';
        if (text.trim() && !window.confirm('이 문장 행을 삭제할까요? 저장해야 반영됩니다.')) return;
        row.remove();
        renumber();
        markDirty();
      } else if (e.target.closest('[data-row-insert]')) {
        var r = addRow(null, row);
        $('[data-content]', r).focus();
      } else if (e.target.closest('[data-row-play]')) {
        var t = (($('[data-content]', row) || {}).value || '').trim();
        if (!t) { RM.toast('문장이 비어 있습니다.', 'info'); return; }
        stopSpeak();
        speak(t);
      }
    });
  }
  $$('[data-add-row]').forEach(function (b) {
    b.addEventListener('click', function () {
      var r = addRow(null);
      $('[data-content]', r).focus();
    });
  });

  // ───────── 본문 붙여넣기로 문장 나누기 ─────────
  var splitPanel = $('[data-split-panel]');
  $$('[data-toggle-split]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!splitPanel) return;
      var open = splitPanel.classList.contains('hidden');
      splitPanel.classList.toggle('hidden', !open);
      splitPanel.classList.toggle('flex', open);
      if (open) $('[data-split-body]', splitPanel).focus();
    });
  });
  $$('[data-split-run]').forEach(function (b) {
    b.addEventListener('click', async function () {
      var mode = b.getAttribute('data-split-run');
      var body = ($('[data-split-body]') || {}).value || '';
      if (!body.trim()) { RM.toast('나눌 본문을 붙여 넣어 주세요.', 'info'); return; }
      var existing = $$('[data-row]', tbody).filter(function (r) { return (($('[data-content]', r) || {}).value || '').trim() !== ''; });
      if (mode === 'replace' && existing.length && !window.confirm('지금 표의 문장 ' + existing.length + '개를 나눈 결과로 바꿀까요? 키워드와 타임코드도 지워집니다.')) return;
      b.disabled = true;
      try {
        var res = await RM.api('/admin/stories/split', { method: 'POST', body: { body: body } });
        if (!res.rows || !res.rows.length) { RM.toast('나눌 문장을 찾지 못했습니다.', 'info'); return; }
        if (mode === 'replace') {
          tbody.innerHTML = '';
        } else {
          // 비어 있는 행은 정리하고 뒤에 붙인다.
          $$('[data-row]', tbody).forEach(function (r) {
            var empty = !(($('[data-content]', r) || {}).value || '').trim() && !(($('[data-keywords]', r) || {}).value || '').trim();
            if (empty) r.remove();
          });
        }
        res.rows.forEach(function (r) { addRow(r); });
        RM.toast('문장 ' + res.rows.length + '개로 나눴습니다. 저장해야 반영됩니다.', 'success');
        $('[data-split-body]').value = '';
        splitPanel.classList.add('hidden');
        splitPanel.classList.remove('flex');
      } catch (err) {
        RM.toast(err.message, 'error');
      } finally {
        b.disabled = false;
      }
    });
  });

  // ───────── 끼어들기 설정 ─────────
  var maxInput = $('[data-max-input]');
  function syncMax() {
    if (!maxInput) return;
    var v = maxInput.value.trim();
    var n = v === '' ? maxInput.getAttribute('data-global') : String(parseInt(v, 10));
    $$('[data-max-text]').forEach(function (el) { el.textContent = n; });
  }
  if (maxInput) maxInput.addEventListener('input', syncMax);

  var fbList = $('[data-fb-list]');
  var fbTpl = document.getElementById('fallback-template');
  var fbCustom = $('[data-fb-custom]');
  var fbBadge = $('[data-fb-badge]');
  var fbReset = $('[data-fb-reset]');
  function setFbCustom(on) {
    if (!fbCustom) return;
    fbCustom.value = on ? '1' : '0';
    if (fbBadge) {
      fbBadge.textContent = on ? '이 동화 전용 문장' : '전체 설정 문장 사용 중';
      fbBadge.className = 'rounded-full px-2.5 py-0.5 font-label-sm text-label-sm ' + (on ? 'bg-primary-fixed text-on-primary-fixed-variant' : 'bg-surface-container-high text-on-surface-variant');
    }
    if (fbReset) fbReset.classList.toggle('hidden', !on);
  }
  function fbRenumber() {
    $$('[data-fb-item]', fbList).forEach(function (item, i) {
      var t = $('[data-fb-title]', item);
      if (t) t.textContent = '대체 샘플 ' + (i + 1) + ' (순차 로테이션)';
      var line = ($('[data-fb-line]', item) || {}).value || '';
      var d = $('[data-fb-dur]', item);
      if (d) d.textContent = '00:' + pad(Math.min(59, Math.ceil(line.trim().length / 5.5)));
    });
  }
  function addFb(text) {
    var tmp = document.createElement('div');
    tmp.innerHTML = fbTpl.innerHTML.replace(/__n__/g, '0').trim();
    var item = tmp.firstElementChild;
    $('[data-fb-line]', item).value = text || '';
    fbList.appendChild(item);
    fbRenumber();
    return item;
  }
  if (fbList) {
    fbList.addEventListener('input', function (e) {
      if (e.target.matches('[data-fb-line]')) { setFbCustom(true); fbRenumber(); }
    });
    fbList.addEventListener('click', function (e) {
      var item = e.target.closest('[data-fb-item]');
      if (!item) return;
      if (e.target.closest('[data-fb-remove]')) {
        item.remove();
        setFbCustom(true);
        fbRenumber();
        markDirty();
      } else if (e.target.closest('[data-fb-play]')) {
        var t = (($('[data-fb-line]', item) || {}).value || '').trim();
        if (!t) { RM.toast('문장이 비어 있습니다.', 'info'); return; }
        stopSpeak();
        speak(t);
      }
    });
  }
  var fbAdd = $('[data-fb-add]');
  if (fbAdd) fbAdd.addEventListener('click', function () {
    if ($$('[data-fb-item]', fbList).length >= 10) { RM.toast('대체 문장은 10개까지 등록할 수 있습니다.', 'info'); return; }
    setFbCustom(true);
    var item = addFb('');
    $('[data-fb-line]', item).focus();
    markDirty();
  });
  if (fbReset) fbReset.addEventListener('click', function () {
    fbList.innerHTML = '';
    (DATA.globalFallback || []).forEach(function (l) { addFb(l); });
    setFbCustom(false);
    markDirty();
  });

  var vad = $('[data-vad]');
  var vadCustom = $('[data-vad-custom]');
  var vadReset = $('[data-vad-reset]');
  function syncVad() {
    if (!vad) return;
    var v = parseFloat(vad.value).toFixed(1);
    var custom = vadCustom && vadCustom.value === '1';
    $('[data-vad-label]').textContent = v + '초' + (v === '1.0' ? ' (권장)' : '') + (custom ? '' : ' · 전체 설정');
    $('[data-vad-sec]').textContent = v;
    if (vadReset) vadReset.classList.toggle('hidden', !custom);
  }
  if (vad) {
    vad.addEventListener('input', function () { if (vadCustom) vadCustom.value = '1'; syncVad(); });
    syncVad();
  }
  if (vadReset) vadReset.addEventListener('click', function () {
    vadCustom.value = '0';
    vad.value = vadCustom.getAttribute('data-global') || '1.0';
    syncVad();
    markDirty();
  });

  var aecInput = $('[data-aec-input]');
  var aecBtns = $$('[data-aec]');
  function showAec(level, custom) {
    aecBtns.forEach(function (b) {
      var on = b.getAttribute('data-aec') === level;
      b.className = 'flex-1 rounded-lg py-2 font-label-sm text-label-sm transition-colors ' + (on ? 'bg-primary font-semibold text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high');
      if (on) {
        $('[data-aec-label]').textContent = b.getAttribute('data-title') + (custom ? '' : ' · 전체 설정');
        $('[data-aec-help]').textContent = b.getAttribute('data-help');
      }
    });
  }
  aecBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      var level = b.getAttribute('data-aec');
      // 전체 설정과 같은 값을 고르면 전체 설정을 따르게 둔다.
      aecInput.value = level === aecInput.getAttribute('data-global') ? '' : level;
      showAec(level, aecInput.value !== '');
      markDirty();
    });
  });

  // ───────── 표지 파일 ─────────
  var coverInput = $('[data-cover-input]');
  if (coverInput) coverInput.addEventListener('change', function () {
    var f = coverInput.files && coverInput.files[0];
    var label = $('[data-cover-name]');
    if (!f) { label.textContent = '선택한 파일 없음'; return; }
    if (f.size > 2 * 1024 * 1024) {
      RM.toast('표지 이미지는 2MB 이하여야 합니다.', 'error');
      coverInput.value = '';
      label.textContent = '선택한 파일 없음';
      return;
    }
    label.textContent = f.name + ' (' + Math.round(f.size / 1024) + 'KB) · 저장하면 이 이미지가 표지가 됩니다.';
  });

  // ───────── 변경 감지 ─────────
  function markDirty() { dirty = true; }
  if (form) {
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function () { dirty = false; stopSpeak(); });
  }
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
  var tcBtn = $('[data-timecode-btn]');
  if (tcBtn) tcBtn.addEventListener('click', function (e) {
    if (dirty && !window.confirm('저장하지 않은 변경사항이 있습니다. 타임코드를 가져오면 지금 편집한 내용은 사라집니다. 계속할까요?')) {
      e.preventDefault();
      return;
    }
    dirty = false;
  });
  var delForm = document.getElementById('story-delete-form');
  if (delForm) delForm.addEventListener('submit', function () { dirty = false; });

  // 목록 상태 필터는 고르면 바로 적용
  $$('[data-auto-submit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });

  // ───────── 창(모달) ─────────
  function openModal(name) {
    var m = $('[data-modal="' + name + '"]');
    if (!m) return null;
    m.classList.remove('hidden');
    m.classList.add('flex');
    document.body.style.overflow = 'hidden';
    return m;
  }
  function closeModal(m) {
    m.classList.add('hidden');
    m.classList.remove('flex');
    document.body.style.overflow = '';
    if (m.getAttribute('data-modal') === 'simulate') simStop();
  }
  $$('[data-modal]').forEach(function (m) {
    m.addEventListener('click', function (e) {
      if (e.target === m || e.target.closest('[data-close-modal]')) closeModal(m);
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') $$('[data-modal]').forEach(function (m) { if (!m.classList.contains('hidden')) closeModal(m); });
  });

  // 배포 상태 점검
  var deployBody = $('[data-deploy-body]');
  async function loadDeploy() {
    deployBody.innerHTML = '<p class="py-12 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>';
    try {
      var res = await RM.api('/admin/api/stories/deploy-status');
      deployBody.innerHTML = res.html || '';
      $$('[data-deploy-action]', deployBody).forEach(function (f) {
        f.addEventListener('rm:success', function () { setTimeout(loadDeploy, 400); });
      });
    } catch (err) {
      deployBody.innerHTML = '<p class="py-12 text-center font-label-md text-label-md text-error">' + RM.escapeHtml(err.message) + '</p>';
    }
  }
  $$('[data-open-deploy]').forEach(function (b) {
    b.addEventListener('click', function () { openModal('deploy'); loadDeploy(); });
  });
  if (/[?&]deploy=1/.test(location.search)) { openModal('deploy'); loadDeploy(); }

  // ───────── 전체 청취 시뮬레이션 ─────────
  var simList = $('[data-sim-list]');
  var simSource = $('[data-sim-source]');
  var simAudio = $('[data-sim-audio]');
  var simStatus = $('[data-sim-status]');
  var simTime = $('[data-sim-time]');
  var sim = { playing: false, index: 0, items: [], audio: null, ttsStart: 0, timer: null };

  function currentRows() {
    return $$('[data-row]', tbody || document).map(function (r, i) {
      return { seq: i + 1, content: ((($('[data-content]', r) || {}).value) || '').replace(/\s+/g, ' ').trim() };
    }).filter(function (r) { return r.content !== ''; });
  }
  function audioById(id) {
    for (var i = 0; i < DATA.audios.length; i++) if (String(DATA.audios[i].id) === String(id)) return DATA.audios[i];
    return null;
  }
  function buildList() {
    var src = simSource.value;
    var a = src === 'tts' ? null : audioById(src);
    sim.items = a ? DATA.saved.slice() : currentRows();
    sim.audio = a;
    simList.innerHTML = sim.items.length ? sim.items.map(function (s, i) {
      return '<p class="flex gap-3 rounded-lg px-3 py-2 font-body-md text-body-md text-on-surface transition-colors" data-sim-seq="' + i + '">' +
        '<span class="w-12 shrink-0 pt-0.5 font-label-sm text-label-sm font-bold text-primary">ST-' + pad(s.seq) + '</span><span>' + RM.escapeHtml(s.content) + '</span></p>';
    }).join('') : '<p class="py-8 text-center font-label-md text-label-md text-on-surface-variant">들을 문장이 없습니다.</p>';
    var note = a && !a.fresh ? ' · 옛 본문으로 만든 오디오라 강조 위치가 맞지 않을 수 있습니다' : '';
    simStatus.textContent = (a ? a.label + ' 목소리 오디오' : '기기 음성') + ' · 문장 ' + sim.items.length + '개' + note;
    simTime.textContent = '00:00';
  }
  function highlight(i) {
    $$('[data-sim-seq]', simList).forEach(function (p) {
      var on = String(i) === p.getAttribute('data-sim-seq');
      p.classList.toggle('bg-primary-fixed', on);
      if (on && p.scrollIntoView) p.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }
  function setPlayUi(on) {
    sim.playing = on;
    $('[data-sim-icon]').textContent = on ? 'pause' : 'play_arrow';
    $('[data-sim-play-text]').textContent = on ? '일시정지' : '재생';
  }
  function ttsNext() {
    if (!sim.playing) return;
    if (sim.index >= sim.items.length) { simStop(); simStatus.textContent = '끝까지 들었습니다.'; return; }
    highlight(sim.index);
    speak(sim.items[sim.index].content, function () {
      if (!sim.playing) return;
      sim.index++;
      ttsNext();
    });
  }
  function simStop() {
    stopSpeak();
    if (simAudio) { simAudio.pause(); }
    clearInterval(sim.timer);
    sim.index = 0;
    setPlayUi(false);
    highlight(-1);
  }
  function simToggle() {
    if (sim.audio) {
      if (simAudio.getAttribute('data-src') !== sim.audio.url) {
        simAudio.src = sim.audio.url;
        simAudio.setAttribute('data-src', sim.audio.url);
      }
      if (simAudio.paused) {
        simAudio.play().then(function () { setPlayUi(true); }).catch(function () { RM.toast('오디오를 재생하지 못했습니다.', 'error'); });
      } else {
        simAudio.pause();
        setPlayUi(false);
      }
      return;
    }
    if (sim.playing) {
      // 기기 음성은 일시정지 대신 현재 문장에서 멈추고, 다시 누르면 그 문장부터 읽는다.
      stopSpeak();
      setPlayUi(false);
      clearInterval(sim.timer);
      return;
    }
    if (!sim.items.length) return;
    setPlayUi(true);
    sim.ttsStart = Date.now();
    clearInterval(sim.timer);
    sim.timer = setInterval(function () { simTime.textContent = fmt(Date.now() - sim.ttsStart); }, 500);
    ttsNext();
  }
  if (simAudio) {
    simAudio.addEventListener('timeupdate', function () {
      if (!sim.audio) return;
      var ms = simAudio.currentTime * 1000;
      simTime.textContent = fmt(ms);
      var t = sim.audio.timings || [];
      var idx = -1;
      for (var i = 0; i < t.length; i++) { if (ms >= t[i].start) idx = i; else break; }
      if (idx >= 0) {
        var seq = t[idx].seq;
        for (var k = 0; k < sim.items.length; k++) { if (sim.items[k].seq === seq) { highlight(k); break; } }
      }
    });
    simAudio.addEventListener('ended', function () { setPlayUi(false); simStatus.textContent = '끝까지 들었습니다.'; });
    simAudio.addEventListener('pause', function () { if (sim.audio) setPlayUi(false); });
  }
  $$('[data-open-simulate]').forEach(function (b) {
    b.addEventListener('click', function () {
      openModal('simulate');
      simStop();
      buildList();
    });
  });
  if (simSource) simSource.addEventListener('change', function () { simStop(); if (simAudio) { simAudio.removeAttribute('src'); simAudio.removeAttribute('data-src'); } buildList(); });
  var simPlay = $('[data-sim-play]');
  if (simPlay) simPlay.addEventListener('click', simToggle);
  var simStopBtn = $('[data-sim-stop]');
  if (simStopBtn) simStopBtn.addEventListener('click', function () { simStop(); if (simAudio) simAudio.currentTime = 0; simTime.textContent = '00:00'; });
  if (synth && synth.onvoiceschanged !== undefined) synth.onvoiceschanged = function () { /* 목소리 목록 갱신 */ };

  syncMax();
})();
