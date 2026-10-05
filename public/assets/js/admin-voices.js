/**
 * 목소리 생성 관리(/admin/voices)와 대시보드 대기열에서 쓰는 스크립트.
 *  - [data-play] 샘플, 동화 오디오 미니 플레이어(한 번에 하나만 재생)
 *  - 재녹음 요청(반려), 캐시 파일 목록, 테스트 재생 창
 *  - 표 행 누르면 상세 검수 패널로 이동, 파라미터 슬라이더 값 표시, 동화 체크리스트 합계
 *  - 샘플 파형 그리기(브라우저에서 오디오를 디코딩해 실제 음량으로 막대를 만든다)
 *  - 처리 콘솔: 생성 중일 때 3초마다 작업 기록을 이어 받고, 10초마다 작업 처리기를 한 단계 돌린다
 */
(function () {
  'use strict';
  if (!window.RM) return;

  var esc = RM.escapeHtml;

  function pad(n) { return String(n).padStart(2, '0'); }
  function mmss(sec) {
    sec = Math.max(0, Math.round(sec || 0));
    return pad(Math.floor(sec / 60)) + ':' + pad(sec % 60);
  }

  // ───────────────────────── 미니 플레이어 ─────────────────────────

  var audio = new Audio();
  audio.preload = 'none';
  var current = null; // 지금 재생 중인 버튼

  function playerOf(btn) { return btn.closest('[data-player]') || btn.parentElement; }

  function paint(btn) {
    var box = playerOf(btn);
    if (!box) return;
    var timeEl = box.querySelector('[data-play-time]');
    var totalMs = timeEl ? parseInt(timeEl.getAttribute('data-total-ms') || '0', 10) : 0;
    var dur = isFinite(audio.duration) && audio.duration > 0 ? audio.duration : totalMs / 1000;
    var playing = btn === current;
    var ratio = playing && dur > 0 ? Math.min(1, audio.currentTime / dur) : 0;
    if (timeEl) {
      var fmt = timeEl.getAttribute('data-format');
      if (playing || fmt === 'pair') timeEl.textContent = mmss(playing ? audio.currentTime : 0) + ' / ' + mmss(dur);
      else timeEl.textContent = mmss(dur);
    }
    var bars = box.querySelectorAll('[data-bar]');
    var lit = Math.round(ratio * bars.length);
    for (var i = 0; i < bars.length; i++) {
      bars[i].classList.toggle('bg-primary', i < lit);
      bars[i].classList.toggle('bg-primary/40', i >= lit);
    }
  }

  function setIcon(btn, playing) {
    var icon = btn.querySelector('.material-symbols-outlined');
    if (icon) icon.textContent = playing ? 'pause' : 'play_arrow';
    btn.setAttribute('aria-label', playing ? '일시 정지' : '듣기');
    if (playing) btn.setAttribute('data-playing', '1'); else btn.removeAttribute('data-playing');
    btn.classList.toggle('ring-4', playing);
    btn.classList.toggle('ring-primary/20', playing);
  }

  function stop() {
    if (!current) return;
    var btn = current;
    current = null;
    audio.pause();
    setIcon(btn, false);
    paint(btn);
  }

  function toggle(btn) {
    var src = btn.getAttribute('data-play');
    if (current === btn) {
      if (audio.paused) { audio.play().catch(function () {}); setIcon(btn, true); }
      else { audio.pause(); setIcon(btn, false); }
      return;
    }
    stop();
    current = btn;
    audio.src = src;
    setIcon(btn, true);
    audio.play().catch(function () {
      if (current === btn) {
        RM.toast('음성 파일을 재생하지 못했습니다.', 'error');
        stop();
      }
    });
  }

  audio.addEventListener('timeupdate', function () { if (current) paint(current); });
  audio.addEventListener('loadedmetadata', function () { if (current) paint(current); });
  audio.addEventListener('ended', function () { stop(); });
  audio.addEventListener('error', function () {
    if (current) { RM.toast('음성 파일을 불러오지 못했습니다.', 'error'); stop(); }
  });

  // ───────────────────────── 창(dialog) ─────────────────────────

  function openDialog(id) {
    var d = document.getElementById(id);
    if (!d) return null;
    if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); }
    else d.setAttribute('open', '');
    return d;
  }
  function closeDialog(d) {
    if (!d) return;
    if (typeof d.close === 'function') d.close(); else d.removeAttribute('open');
  }
  document.querySelectorAll('dialog').forEach(function (d) {
    // 바깥(배경)을 누르면 닫는다.
    d.addEventListener('click', function (e) { if (e.target === d) closeDialog(d); });
    d.addEventListener('close', function () {
      var a = d.querySelector('audio');
      if (a) a.pause();
      if (current && d.contains(current)) stop();
    });
  });

  // 재녹음 요청(반려)
  function openReject(btn) {
    var d = openDialog('reject-dialog');
    if (!d) return;
    var form = d.querySelector('[data-reject-form]');
    form.setAttribute('action', btn.getAttribute('data-reject-url'));
    d.querySelector('[data-reject-target]').textContent = btn.getAttribute('data-reject-name') || '';
    d.querySelector('[data-reject-error]').classList.add('hidden');
    var ta = d.querySelector('textarea[name="reason"]');
    ta.value = '';
    setTimeout(function () { ta.focus(); }, 30);
  }
  // 확인 창(app.js, document 단계)보다 먼저 검사하려고 window 캡처 단계에서 받는다.
  window.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('[data-reject-form]')) return;
    var ta = form.querySelector('textarea[name="reason"]');
    if (!ta.value.trim()) {
      e.preventDefault();
      e.stopImmediatePropagation();
      form.querySelector('[data-reject-error]').classList.remove('hidden');
      ta.focus();
    }
  }, true);

  // 캐시 파일 목록
  function openAudios(btn) {
    var d = openDialog('audios-dialog');
    if (!d) return;
    var body = d.querySelector('[data-audios-body]');
    d.querySelector('[data-audios-target]').textContent = btn.getAttribute('data-audios-name') || '';
    body.innerHTML = '<p class="py-8 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>';
    RM.api(btn.getAttribute('data-audios-url')).then(function (data) {
      if (!data.items || !data.items.length) {
        body.innerHTML = '<div class="flex flex-col items-center gap-2 py-10 text-center">'
          + '<span class="material-symbols-outlined text-[28px] text-on-surface-variant">library_music</span>'
          + '<p class="font-label-md text-label-md text-on-surface">아직 만든 동화 오디오가 없습니다</p>'
          + '<p class="font-label-sm text-label-sm text-on-surface-variant">동화 생성 요청을 시작하면 파일이 여기에 쌓입니다.</p></div>';
        return;
      }
      var rows = data.items.map(function (it, i) {
        var tone = it.status === 'completed' ? (it.fresh ? 'bg-primary-fixed text-on-primary-fixed-variant' : 'bg-error-container text-on-error-container')
          : it.status === 'failed' ? 'bg-error-container text-on-error-container' : 'bg-secondary-fixed text-on-secondary-fixed';
        var label = it.status === 'completed' ? (it.fresh ? '최신' : '옛 본문') : it.status_label;
        var play = it.url
          ? '<div class="flex w-40 items-center gap-2" data-player><button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container" data-play="' + esc(it.url) + '" aria-label="듣기"><span class="material-symbols-outlined text-[18px]">play_arrow</span></button>'
            + '<span class="font-label-sm text-label-sm text-on-surface-variant" data-play-time data-total-ms="0" data-format="total">' + esc(it.duration) + '</span></div>'
          : '<span class="font-label-sm text-label-sm text-on-surface-variant">-</span>';
        return '<tr class="border-t border-surface-container-high">'
          + '<td class="px-3 py-2.5 font-label-sm text-label-sm text-on-surface-variant">' + pad(i + 1) + '</td>'
          + '<td class="px-3 py-2.5"><div class="flex flex-col"><span class="font-label-md text-label-md text-on-surface">' + esc(it.title) + '</span>'
          + '<span class="font-label-sm text-[11px] text-on-surface-variant">' + esc(it.code || '') + (it.generated_at ? ' · ' + esc(it.generated_at) : '') + (it.published ? '' : ' · 게시 중단') + '</span>'
          + (it.error ? '<span class="font-label-sm text-[11px] text-error">' + esc(it.error) + '</span>' : '') + '</div></td>'
          + '<td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-full px-2 py-0.5 font-label-sm text-label-sm ' + tone + '">' + esc(label) + '</span></td>'
          + '<td class="whitespace-nowrap px-3 py-2.5 font-label-sm text-label-sm text-on-surface-variant">' + esc(it.duration) + ' · ' + esc(it.size) + '</td>'
          + '<td class="px-3 py-2.5">' + play + '</td></tr>';
      }).join('');
      body.innerHTML = '<p class="mb-2 font-label-sm text-label-sm text-on-surface-variant">' + esc(data.summary) + ' · 자체 서버 저장, 재생 시 추가 비용 없음</p>'
        + '<table class="w-full text-left"><thead><tr class="bg-surface-container-low font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">'
        + '<th class="px-3 py-2">#</th><th class="px-3 py-2">동화</th><th class="px-3 py-2">상태</th><th class="px-3 py-2">길이 · 크기</th><th class="px-3 py-2">재생</th></tr></thead><tbody>'
        + rows + '</tbody></table>';
    }).catch(function (err) {
      body.innerHTML = '<p class="py-8 text-center font-label-md text-label-md text-error">' + esc(err.message) + '</p>';
    });
  }

  // 테스트 재생
  var testDialog = document.getElementById('test-dialog');
  var testUrl = '';
  function openTest(btn) {
    var d = openDialog('test-dialog');
    if (!d) return;
    testUrl = btn.getAttribute('data-test-url');
    d.querySelector('[data-test-target]').textContent = btn.getAttribute('data-test-name') || '';
    d.querySelector('[data-test-result]').classList.add('hidden');
    d.querySelector('[data-test-result]').classList.remove('flex');
  }
  if (testDialog) {
    testDialog.querySelector('[data-test-form]').addEventListener('submit', function (e) {
      e.preventDefault();
      var form = this;
      var btn = form.querySelector('[type="submit"]');
      var text = form.querySelector('textarea[name="text"]').value.trim();
      if (!text) { RM.toast('테스트할 문장을 입력하세요.', 'error'); return; }
      btn.disabled = true;
      var old = btn.innerHTML;
      btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span>합성 중…';
      RM.api(testUrl, { method: 'POST', body: { text: text } }).then(function (data) {
        var box = form.querySelector('[data-test-result]');
        var a = form.querySelector('[data-test-audio]');
        box.classList.remove('hidden');
        box.classList.add('flex');
        a.src = data.audio_url + (data.audio_url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
        a.play().catch(function () {});
        var meta = [];
        if (data.duration_ms) meta.push('길이 ' + RM.fmtTime(data.duration_ms));
        if (data.ms) meta.push('합성 ' + Number(data.ms).toLocaleString() + 'ms');
        form.querySelector('[data-test-meta]').textContent = meta.join(' · ');
      }).catch(function (err) {
        RM.toast(err.message, 'error');
      }).finally(function () {
        btn.disabled = false;
        btn.innerHTML = old;
      });
    });
  }

  // ───────────────────────── 클릭 위임 ─────────────────────────

  document.addEventListener('click', function (e) {
    var t = e.target;
    var el;
    if ((el = t.closest('[data-play]'))) { e.preventDefault(); toggle(el); return; }
    if ((el = t.closest('[data-dialog-close]'))) { closeDialog(el.closest('dialog')); return; }
    if ((el = t.closest('[data-reason]'))) {
      var ta = el.closest('form').querySelector('textarea[name="reason"]');
      ta.value = el.getAttribute('data-reason');
      ta.focus();
      return;
    }
    if ((el = t.closest('[data-reject-url]'))) { openReject(el); return; }
    if ((el = t.closest('[data-audios-url]'))) { openAudios(el); return; }
    if ((el = t.closest('[data-test-url]'))) { if (!el.disabled) openTest(el); return; }
    // 표의 행을 누르면 상세 검수 패널로(버튼, 링크, 폼 요소는 제외)
    var row = t.closest('tr[data-href]');
    if (row && !t.closest('a, button, input, select, textarea, form, label')) {
      location.href = row.getAttribute('data-href');
    }
  });

  // ───────────────────────── 상세 패널(파라미터, 파형, 처리 콘솔) ─────────────────────────
  // 상세 패널을 새 내용으로 바꾼 뒤에도 다시 부를 수 있게 함수로 묶는다.

  var stopConsole = function () {};

  function initDetail(scope) {
    stopConsole();
    stopConsole = function () {};
    if (!scope) return;

    // ───────────────────────── 파라미터 ─────────────────────────

    scope.querySelectorAll('[data-range]').forEach(function (input) {
      var out = scope.querySelector('[data-range-value="' + input.getAttribute('data-range') + '"]');
      input.addEventListener('input', function () { if (out) out.textContent = Number(input.value).toFixed(2); });
    });

    // ───────────────────────── 샘플 파형 ─────────────────────────

    var wave = scope.querySelector('[data-waveform][data-src]');
    if (wave) {
      var note = wave.querySelector('[data-waveform-note]');
      var Ctx = window.AudioContext || window.webkitAudioContext;
      var fail = function () { if (note) note.textContent = '파형을 그릴 수 없는 형식입니다'; };
      if (!Ctx || !window.fetch) fail();
      else {
        fetch(wave.getAttribute('data-src'), { credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error('load'); return r.arrayBuffer(); })
          .then(function (buf) {
            var ctx = new Ctx();
            return new Promise(function (resolve, reject) {
              var p = ctx.decodeAudioData(buf, resolve, reject);
              if (p && p.then) p.then(resolve, reject);
            }).then(function (decoded) { if (ctx.close) ctx.close(); return decoded; });
          })
          .then(function (decoded) {
            var data = decoded.getChannelData(0);
            var n = 32, step = Math.max(1, Math.floor(data.length / n));
            var peaks = [], max = 0;
            for (var i = 0; i < n; i++) {
              var p = 0;
              for (var j = i * step, end = Math.min(data.length, (i + 1) * step); j < end; j += 4) {
                var a = Math.abs(data[j]);
                if (a > p) p = a;
              }
              peaks.push(p);
              if (p > max) max = p;
            }
            var html = '<div class="absolute inset-x-0 top-1/2 h-px bg-surface-variant"></div>';
            peaks.forEach(function (p) {
              var h = max > 0 ? Math.max(6, Math.round(p / max * 100)) : 6;
              var cls = p >= 0.98 ? 'bg-error' : (p / (max || 1) < 0.15 ? 'bg-primary/40' : 'bg-primary');
              html += '<div class="relative flex-1 rounded-t ' + cls + '" style="height:' + h + '%" title="최대 음량 ' + Math.round(p * 100) + '%"></div>';
            });
            wave.innerHTML = html;
          })
          .catch(fail);
      }
    }

    // ───────────────────────── 처리 콘솔 ─────────────────────────

    var con = scope.querySelector('[data-console]');
    if (con) {
      var after = parseInt(con.getAttribute('data-after') || '0', 10);
      var active = con.getAttribute('data-active') === '1';
      var url = con.getAttribute('data-logs-url');
      var levelCls = { info: 'text-inverse-on-surface/85', warn: 'text-secondary-fixed', error: 'text-error-container' };
      var chipCls = {
        pending: ['대기 중', 'bg-secondary-container text-on-secondary-container'],
        cloning: ['모델 생성 중', 'bg-primary-fixed text-on-primary-fixed-variant'],
        processing: ['오디오 생성 중', 'bg-primary-fixed text-on-primary-fixed-variant'],
        completed: ['전체 완료', 'bg-surface-container-high text-on-surface-variant'],
        rejected: ['반려', 'bg-error-container text-on-error-container'],
        failed: ['실패', 'bg-error-container text-on-error-container']
      };
      var lastStatus = null;
      var pollTimer = null, tickTimer = null;

      var append = function (logs) {
        if (!logs.length) return;
        var empty = con.querySelector('[data-console-empty]');
        if (empty) empty.remove();
        var nearBottom = con.scrollHeight - con.scrollTop - con.clientHeight < 40;
        logs.forEach(function (l) {
          var s = document.createElement('span');
          s.className = levelCls[l.level] || levelCls.info;
          s.textContent = '[' + l.time + '] ' + l.message;
          con.appendChild(s);
          after = Math.max(after, l.id);
        });
        if (nearBottom) con.scrollTop = con.scrollHeight;
      };
      var setLive = function (data) {
        var p = data.progress || {};
        var prog = document.querySelector('[data-live-progress]');
        if (prog) prog.textContent = (p.completed || 0) + ' / ' + (p.total || 0) + '편 완료';
        var pct = document.querySelector('[data-live-percent]');
        if (pct) pct.textContent = (p.percent || 0) + '%';
        var bar = document.querySelector('[data-live-bar]');
        if (bar) bar.style.width = (p.percent || 0) + '%';
        var st = document.querySelector('[data-live-status]');
        var c = chipCls[data.status];
        if (st && c) st.innerHTML = '<span class="whitespace-nowrap rounded-full px-2 py-0.5 font-label-sm text-label-sm ' + c[1] + '">' + c[0] + '</span>';
        con.setAttribute('data-active', data.active ? '1' : '0');
        var dot = document.querySelector('[data-console-dot]');
        if (dot) {
          dot.classList.toggle('animate-pulse', !!data.active);
          dot.classList.toggle('bg-primary', !!data.active);
          dot.classList.toggle('bg-outline-variant', !data.active);
        }
      };
      var poll = function () {
        if (document.hidden) return;
        RM.api(url + '?after=' + after).then(function (data) {
          append(data.logs || []);
          setLive(data);
          if (lastStatus !== null && lastStatus !== data.status) RM.toast('상태가 바뀌었습니다: ' + data.status_label, 'info');
          lastStatus = data.status;
          if (!data.active) {
            stopPolling();
            var note = document.querySelector('[data-console-note]');
            if (note) note.textContent = '작업이 끝났습니다. 화면을 최신 상태로 바꿉니다.';
            softRefresh();
          }
        }).catch(function () {});
      };
      var stopPolling = function () {
        if (pollTimer) clearInterval(pollTimer);
        if (tickTimer) clearInterval(tickTimer);
        pollTimer = tickTimer = null;
      };
      stopConsole = stopPolling;
      con.scrollTop = con.scrollHeight;
      if (active) {
        pollTimer = setInterval(poll, 3000);
        // 생성 중일 때는 작업 처리기를 더 자주 돌려 진행을 앞당긴다(기본 45초 주기에 더해).
        tickTimer = setInterval(function () {
          if (!document.hidden && window.RMAdmin) window.RMAdmin.tickWorker();
        }, 10000);
        if (window.RMAdmin) setTimeout(function () { window.RMAdmin.tickWorker(); }, 1500);
      }
    }
  }
  initDetail(document.querySelector('[data-soft="detail"]') || document);

  // ───────────────────────── 화면 자동 갱신 ─────────────────────────
  // 목록이나 상세 패널에 생성 중인 목소리가 있으면 4초마다 상태만 확인하고,
  // 바뀌면 같은 주소를 다시 받아 KPI, 상태 탭, 표, 상세 패널만 새 내용으로 바꾼다(페이지를 다시 열지 않는다).

  var ACTIVE = /^(cloning|processing)$/;
  var BATCH_ACTIVE = /^(queued|running)$/;
  var softBusy = false;
  var softPending = false;

  function watched() {
    var out = {};
    document.querySelectorAll('[data-voice-row], [data-voice-detail]').forEach(function (el) {
      var id = el.getAttribute('data-voice-row') || el.getAttribute('data-voice-id');
      if (id) out[id] = { status: el.getAttribute('data-status') || '', batch: el.getAttribute('data-batch') || '' };
    });
    return out;
  }
  function anyActive(map) {
    return Object.keys(map).some(function (id) { return ACTIVE.test(map[id].status) || BATCH_ACTIVE.test(map[id].batch); });
  }
  // 샘플을 듣는 중이거나 창, 입력칸을 쓰는 중이면 바꾸지 않고 다음 차례로 미룬다.
  function userBusy() {
    if (current || document.querySelector('dialog[open], [data-rm-dialog]')) return true;
    var a = document.activeElement;
    return !!(a && a !== document.body && a.matches && a.matches('input, textarea, select'));
  }

  function softRefresh() {
    if (softBusy || userBusy()) { softPending = true; return; }
    softBusy = true;
    softPending = false;
    // 화면에 떠 있는 목소리를 keep 으로 넘겨, 상태가 바뀌어 지금 탭 조건에 맞지 않아도 줄이 남게 한다.
    var u = new URL(location.href);
    var shown = Array.prototype.map.call(document.querySelectorAll('[data-voice-row]'), function (tr) { return tr.getAttribute('data-voice-row'); });
    if (u.searchParams.get('status') && shown.length) {
      var keep = (u.searchParams.get('keep') || '').split(',').concat(shown)
        .filter(function (v, i, a) { return /^\d+$/.test(v) && a.indexOf(v) === i; }).slice(-100);
      u.searchParams.set('keep', keep.join(','));
    }
    fetch(u.toString(), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error('load'); return r.text(); })
      .then(function (html) {
        if (userBusy()) { softPending = true; return; }
        var doc = new DOMParser().parseFromString(html, 'text/html');
        document.querySelectorAll('[data-soft]').forEach(function (region) {
          var key = region.getAttribute('data-soft');
          var next = doc.querySelector('[data-soft="' + key + '"]');
          if (!next || next.innerHTML === region.innerHTML) return;
          region.innerHTML = next.innerHTML;
          if (key === 'detail') initDetail(region);
        });
        try { history.replaceState(null, '', u.pathname + u.search); } catch (e) {}
      })
      .catch(function () {})
      .finally(function () { softBusy = false; });
  }

  if (document.querySelector('[data-soft="list"], [data-soft="detail"]')) {
    var lastTick = 0;
    setInterval(function () {
      if (document.hidden) return;
      if (softPending) { softRefresh(); return; }
      var map = watched();
      var ids = Object.keys(map);
      if (!ids.length || !anyActive(map)) return;
      // 생성 중에는 작업 처리기도 조금 더 자주 돌린다(기본 45초 주기에 더해).
      if (window.RMAdmin && Date.now() - lastTick > 12000) { lastTick = Date.now(); window.RMAdmin.tickWorker(); }
      RM.api('/admin/api/voices/status?ids=' + ids.join(',')).then(function (data) {
        var items = data.items || {};
        var changed = ids.some(function (id) {
          var it = items[id];
          return !it || it.status !== map[id].status || it.batch_status !== map[id].batch;
        });
        if (changed) softRefresh();
      }).catch(function () {});
    }, 4000);
  }

  window.RMVoices = {
    isBusy: function (root) {
      return !!(root && current && root.contains(current)) || !!document.querySelector('dialog[open]');
    },
    stop: stop
  };
})();
