/**
 * 공통 스크립트(회원, 관리자 화면 모두). 전역 객체 RM 을 만든다.
 *   RM.url('/api/x')                    기준 경로를 붙인 주소
 *   await RM.api('/api/x', {method:'POST', body:{...} | FormData})   CSRF 헤더 포함, JSON 응답, 실패 시 Error(message)
 *   RM.toast('저장했습니다', 'success' | 'error' | 'info')
 *   RM.fmtTime(ms)                      m:ss
 *   RM.setDark(true|false)              다크 모드 즉시 적용(회원 화면)
 *   <form data-confirm="정말 삭제할까요?">, <button data-confirm="...">  제출 전 확인
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

  // 확인 창
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (form.hasAttribute('data-ajax')) {
      e.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      if (btn) btn.disabled = true;
      api(form.getAttribute('action') || location.pathname, { method: form.getAttribute('method') || 'POST', body: new FormData(form) })
        .then(function (data) {
          if (data.message) toast(data.message, 'success');
          if (data.redirect) location.href = data.redirect;
          form.dispatchEvent(new CustomEvent('rm:success', { detail: data }));
        })
        .catch(function (err) { toast(err.message, 'error'); })
        .finally(function () { if (btn) btn.disabled = false; });
    }
  }, true);
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && el.tagName !== 'FORM' && !el.form) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
    } else if (el && el.tagName === 'BUTTON' && el.form && !el.form.hasAttribute('data-confirm')) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
    }
  }, true);

  // 서버가 띄운 1회성 알림을 몇 초 뒤 닫는다.
  document.querySelectorAll('[data-flash]').forEach(function (box) {
    setTimeout(function () { box.style.transition = 'opacity .4s'; box.style.opacity = '0'; }, 3500);
    setTimeout(function () { box.remove(); }, 4000);
  });

  window.RM = { url: url, api: api, toast: toast, fmtTime: fmtTime, setDark: setDark, csrf: csrf, escapeHtml: escapeHtml, config: cfg };
})();
