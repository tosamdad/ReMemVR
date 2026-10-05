/**
 * 공통 스크립트(회원, 관리자 화면 모두). 전역 객체 RM 을 만든다.
 *   RM.url('/api/x')                    기준 경로를 붙인 주소
 *   await RM.api('/api/x', {method:'POST', body:{...} | FormData})   CSRF 헤더 포함, JSON 응답, 실패 시 Error(message)
 *   RM.toast('저장했습니다', 'success' | 'error' | 'info')
 *   RM.fmtTime(ms)                      m:ss
 *   RM.setDark(true|false)              다크 모드 즉시 적용(회원 화면)
 *   await RM.confirm('정말 삭제할까요?', {ok: '삭제', danger: true})   화면을 어둡게 덮는 확인 팝업, 확인이면 true
 *   await RM.alert('저장했습니다')        확인 버튼 하나짜리 팝업
 *   <form data-confirm="정말 삭제할까요?">, <button data-confirm="...">  제출 전 확인 팝업
 *     (data-confirm-ok 로 확인 버튼 글자, data-confirm-danger 로 빨간 버튼)
 *   <form data-ajax> 는 fetch 로 제출하고 응답 JSON 의 message 를 토스트로, redirect 가 있으면 이동한다.
 */
(function () {
  'use strict';
  var cfg = window.RM_CONFIG || { base: '', csrf: '' };

  function url(path) {
    if (/^https?:\/\//.test(path)) return path;
    return (cfg.base || '') + '/' + String(path).replace(/^\//, '');
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.getAttribute('content')) || cfg.csrf || '';
  }

  async function api(path, opts) {
    opts = opts || {};
    var headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
    var method = (opts.method || (opts.body ? 'POST' : 'GET')).toUpperCase();
    var body = opts.body;
    if (method !== 'GET') headers['X-CSRF-Token'] = csrf();
    if (body && !(body instanceof FormData) && !(body instanceof Blob) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    var res;
    try {
      res = await fetch(url(path), { method: method, headers: headers, body: method === 'GET' ? undefined : body, credentials: 'same-origin', signal: opts.signal });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      throw new Error('네트워크 연결을 확인해 주세요.');
    }
    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok || (data && data.ok === false)) {
      var err = new Error((data && (data.error || data.message)) || ('요청을 처리하지 못했습니다. (' + res.status + ')'));
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data || {};
  }

  var toastBox = null;
  function toast(message, type) {
    if (!message) return;
    type = type || 'info';
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = cfg.admin
        ? 'pointer-events-none fixed right-4 top-20 z-[70] flex w-[360px] max-w-[calc(100vw-2rem)] flex-col gap-2'
        : 'pointer-events-none fixed inset-x-0 top-3 z-[70] mx-auto flex max-w-[520px] flex-col gap-2 px-4';
      document.body.appendChild(toastBox);
    }
    var styles = cfg.admin
      ? { success: 'bg-primary-fixed text-on-primary-fixed', error: 'bg-error-container text-on-error-container', info: 'bg-secondary-fixed text-on-secondary-fixed' }
      : { success: 'bg-secondary-container text-on-secondary-container', error: 'bg-error-container text-on-error-container', info: 'bg-inverse-surface text-inverse-on-surface' };
    var icons = { success: 'check_circle', error: 'error', info: 'info' };
    var el = document.createElement('div');
    el.className = 'pointer-events-auto flex items-start gap-2 rounded-xl px-4 py-3 shadow-lg transition-all duration-300 ' + (styles[type] || styles.info);
    el.setAttribute('role', 'status');
    var icon = document.createElement('span');
    icon.className = 'material-symbols-outlined text-[20px]';
    icon.textContent = icons[type] || 'info';
    var p = document.createElement('p');
    p.className = 'flex-1 text-[14px] font-bold leading-5';
    p.textContent = message;
    el.appendChild(icon); el.appendChild(p);
    toastBox.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transform = 'translateY(-6px)'; }, 3200);
    setTimeout(function () { el.remove(); }, 3600);
  }

  function fmtTime(ms) {
    ms = Math.max(0, Math.round((ms || 0) / 1000));
    var h = Math.floor(ms / 3600), m = Math.floor((ms % 3600) / 60), s = ms % 60;
    var mm = (h > 0 && m < 10 ? '0' : '') + m;
    return (h > 0 ? h + ':' : '') + (h > 0 ? mm : String(m).padStart(2, '0')) + ':' + String(s).padStart(2, '0');
  }

  function setDark(on) {
    var root = document.documentElement;
    root.classList.toggle('dark', !!on);
    root.classList.toggle('light', !on);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', on ? '#0b1d25' : '#f3faff');
    try { localStorage.setItem('rm-dark', on ? '1' : '0'); } catch (e) {}
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ───────── 확인 팝업(브라우저 기본 알림창 대신 화면을 어둡게 덮는 팝업) ─────────
  var DANGER_WORDS = /삭제|지우|지울|지워|반려|탈퇴|초기화|사라/;
  var dialogQueue = Promise.resolve();

  function showDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var danger = opts.danger != null ? !!opts.danger : DANGER_WORDS.test(String(message));
      var prevFocus = document.activeElement;
      var wrap = document.createElement('div');
      wrap.className = 'fixed inset-0 z-[80] flex items-end justify-center bg-black/50 p-4 opacity-0 transition-opacity duration-150 sm:items-center';
      wrap.setAttribute('data-rm-dialog', '');
      var box = document.createElement('div');
      box.className = 'w-full max-w-[400px] translate-y-2 rounded-3xl bg-surface-container-lowest p-6 shadow-2xl transition-transform duration-150';
      box.setAttribute('role', opts.alert ? 'alertdialog' : 'dialog');
      box.setAttribute('aria-modal', 'true');
      var id = 'rm-dialog-' + Date.now();
      box.setAttribute('aria-labelledby', id);
      if (opts.title) {
        var h = document.createElement('p');
        h.className = 'mb-2 text-[18px] font-bold leading-6 text-on-surface';
        h.textContent = opts.title;
        box.appendChild(h);
      }
      var p = document.createElement('p');
      p.id = id;
      p.className = 'whitespace-pre-line break-keep text-[15px] leading-6 text-on-surface';
      p.textContent = message;
      box.appendChild(p);
      var row = document.createElement('div');
      row.className = 'mt-6 flex justify-end gap-2';
      var btnBase = 'min-w-[88px] rounded-full px-5 py-2.5 text-[14px] font-bold transition-opacity hover:opacity-90 focus:outline-none focus-visible:ring-4 ';
      var cancel = null;
      if (!opts.alert) {
        cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = btnBase + 'bg-surface-container-high text-on-surface focus-visible:ring-surface-container-high/60';
        cancel.textContent = opts.cancel || '취소';
        row.appendChild(cancel);
      }
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = btnBase + (danger ? 'bg-error text-on-error focus-visible:ring-error/30' : 'bg-primary text-on-primary focus-visible:ring-primary/30');
      ok.textContent = opts.ok || '확인';
      row.appendChild(ok);
      box.appendChild(row);
      wrap.appendChild(box);

      var done = false;
      var overflow = document.documentElement.style.overflow;
      function close(result) {
        if (done) return;
        done = true;
        wrap.removeAttribute('data-rm-dialog'); // 닫히는 중인 팝업은 '열린 팝업'으로 세지 않는다.
        document.removeEventListener('keydown', onKey, true);
        document.documentElement.style.overflow = overflow;
        wrap.classList.add('opacity-0');
        setTimeout(function () { wrap.remove(); }, 150);
        if (prevFocus && prevFocus.focus) { try { prevFocus.focus({ preventScroll: true }); } catch (e) {} }
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(!!opts.alert); }
        else if (e.key === 'Tab') {
          // 팝업 안에서만 초점이 돈다.
          var items = cancel ? [cancel, ok] : [ok];
          var i = items.indexOf(document.activeElement);
          e.preventDefault();
          items[(i + (e.shiftKey ? items.length - 1 : 1)) % items.length].focus();
        }
      }
      ok.addEventListener('click', function () { close(true); });
      if (cancel) cancel.addEventListener('click', function () { close(false); });
      wrap.addEventListener('click', function (e) { if (e.target === wrap) close(!!opts.alert); });
      document.addEventListener('keydown', onKey, true);
      document.documentElement.style.overflow = 'hidden';
      document.body.appendChild(wrap);
      requestAnimationFrame(function () {
        wrap.classList.remove('opacity-0');
        box.classList.remove('translate-y-2');
      });
      ok.focus();
    });
  }

  // 팝업이 겹치지 않게 차례로 띄운다.
  function queued(message, opts) {
    var next = dialogQueue.then(function () { return showDialog(message, opts); });
    dialogQueue = next.catch(function () {});
    return next;
  }
  function confirmBox(message, opts) { return queued(message, opts); }
  function alertBox(message, opts) {
    return queued(message, Object.assign({}, opts || {}, { alert: true })).then(function () {});
  }

  function confirmOpts(el) {
    var o = {};
    if (el.hasAttribute('data-confirm-ok')) o.ok = el.getAttribute('data-confirm-ok');
    if (el.hasAttribute('data-confirm-danger')) o.danger = el.getAttribute('data-confirm-danger') !== '0';
    return o;
  }

  // 확인을 마친 폼을 다시 제출한다(누른 버튼의 formaction 등을 그대로 살린다).
  function resubmit(form, submitter) {
    form._rmConfirmed = true;
    if (typeof form.requestSubmit === 'function') {
      try { form.requestSubmit(submitter || undefined); return; } catch (e) {}
    }
    if (submitter) submitter.click(); else form.submit();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (form._rmConfirmed) {
      form._rmConfirmed = false;
    } else if (msg) {
      e.preventDefault();
      e.stopPropagation();
      var submitter = e.submitter || null;
      confirmBox(msg, confirmOpts(form)).then(function (ok) { if (ok) resubmit(form, submitter); });
      return;
    }
    if (form.hasAttribute('data-ajax')) {
      e.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      if (btn) btn.disabled = true;
      api(form.getAttribute('action') || location.pathname, { method: form.getAttribute('method') || 'POST', body: new FormData(form) })
        .then(function (data) {
          if (data.message) toast(data.message, data.type === 'error' ? 'error' : (data.type === 'info' ? 'info' : 'success'));
          if (data.redirect) location.href = data.redirect;
          form.dispatchEvent(new CustomEvent('rm:success', { detail: data, bubbles: true }));
        })
        .catch(function (err) {
          toast(err.message, 'error');
          form.dispatchEvent(new CustomEvent('rm:error', { detail: err, bubbles: true }));
        })
        .finally(function () { if (btn) btn.disabled = false; });
    }
  }, true);
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    // 폼 안의 버튼인데 폼에도 확인 문구가 있으면 제출 때 한 번만 묻는다.
    if (el.form && el.form.hasAttribute('data-confirm')) return;
    if (el._rmConfirmed) { el._rmConfirmed = false; return; }
    e.preventDefault();
    e.stopPropagation();
    confirmBox(el.getAttribute('data-confirm'), confirmOpts(el)).then(function (ok) {
      if (!ok) return;
      el._rmConfirmed = true;
      el.click();
    });
  }, true);

  // 서버가 띄운 1회성 알림을 몇 초 뒤 닫는다.
  document.querySelectorAll('[data-flash]').forEach(function (box) {
    setTimeout(function () { box.style.transition = 'opacity .4s'; box.style.opacity = '0'; }, 3500);
    setTimeout(function () { box.remove(); }, 4000);
  });

  // 동화 생성 요청 지켜보기(회원 동화 상세, 내 동화): [data-req-id][data-req-state] 중 확인 대기나 만드는 중이 있으면
  // /api/requests/status 로 진행 단계를 확인하고, 하나라도 바뀌면(만드는 중 → 완성 등) 화면을 다시 그린다.
  // 만드는 중이면 5초, 확인 대기면 20초마다 확인하고, 1시간이 지나면 멈춘다. 고르던 목소리, 열린 팝업이 있으면 기다렸다가 다시 그린다.
  (function watchRequests() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-req-id][data-req-state]'));
    var seen = {};
    rows.forEach(function (el) { seen[String(el.getAttribute('data-req-id'))] = el.getAttribute('data-req-state'); });
    var ids = Object.keys(seen);
    function open() { return ids.filter(function (id) { return seen[id] === 'making' || seen[id] === 'requested'; }); }
    if (!open().length) return;
    var started = Date.now();
    var changed = false;
    function busy() {
      return !!document.querySelector('[data-rm-dialog], [data-voice-pick]:checked, #add-sheet:not([hidden])');
    }
    function next() {
      if (Date.now() - started > 3600000) return;
      var making = open().some(function (id) { return seen[id] === 'making'; });
      setTimeout(poll, making ? 5000 : 20000);
    }
    async function poll() {
      if (changed) {
        if (busy()) { setTimeout(poll, 3000); return; }
        location.reload();
        return;
      }
      if (document.hidden) { next(); return; }
      try {
        var data = await api('/api/requests/status?ids=' + open().join(','));
        var states = (data && data.states) || {};
        Object.keys(states).forEach(function (id) {
          if (seen[id] !== undefined && states[id] !== seen[id]) changed = true;
        });
      } catch (e) {
        // 잠깐 연결이 끊겨도 다음 차례에 다시 확인한다.
      }
      if (changed) { poll(); return; }
      next();
    }
    next();
  })();

  window.RM = { url: url, api: api, toast: toast, confirm: confirmBox, alert: alertBox, fmtTime: fmtTime, setDark: setDark, csrf: csrf, escapeHtml: escapeHtml, config: cfg };
})();
