/**
 * 로그인, 회원가입, 첫 자녀 등록, 설정 화면 스크립트(app.js 의 RM 을 쓴다).
 *   [data-toggle-password="입력 id"]  비밀번호 보기/숨기기
 *   [data-social-off]                 키가 없는 간편 로그인 버튼 → "준비 중" 안내
 *   [data-agree-all], [data-agree-item]  약관 전체 동의
 *   [data-birth-mode], [data-birth-panel]  생년월일 / 만 나이 입력 방식 전환
 *   [data-pref-switch="notify|dark_mode"]  환경 설정 즉시 저장(POST /api/settings/prefs)
 *   [data-cache-clear], [data-cache-size]  캐시 크기 표시와 삭제
 *   [data-confirm-phrase]             탈퇴 확인 문구를 정확히 입력해야 버튼이 켜진다
 */
(function () {
  'use strict';
  var RM = window.RM;

  // 비밀번호 보기/숨기기
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-password'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      var icon = btn.querySelector('.material-symbols-outlined');
      if (icon) icon.textContent = show ? 'visibility_off' : 'visibility';
      btn.setAttribute('aria-label', show ? '비밀번호 숨기기' : '비밀번호 보기');
      input.focus();
    });
  });

  // 간편 로그인 준비 중
  document.querySelectorAll('[data-social-off]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      RM.toast('간편 로그인은 준비 중이에요', 'info');
    });
  });

  // 약관 전체 동의
  var agreeAll = document.querySelector('[data-agree-all]');
  if (agreeAll) {
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-agree-item]'));
    var sync = function () { agreeAll.checked = items.every(function (i) { return i.checked; }); };
    agreeAll.addEventListener('change', function () {
      items.forEach(function (i) { i.checked = agreeAll.checked; });
    });
    items.forEach(function (i) { i.addEventListener('change', sync); });
    sync();
  }

  // 생년월일 / 만 나이 입력 방식
  var modes = document.querySelectorAll('[data-birth-mode]');
  if (modes.length) {
    var applyMode = function () {
      var checked = document.querySelector('[data-birth-mode]:checked');
      var mode = checked ? checked.value : 'date';
      document.querySelectorAll('[data-birth-panel]').forEach(function (p) {
        p.hidden = p.getAttribute('data-birth-panel') !== mode;
      });
    };
    modes.forEach(function (m) { m.addEventListener('change', applyMode); });
    applyMode();
  }

  // 환경 설정 스위치(바로 저장, 실패하면 되돌린다)
  document.querySelectorAll('[data-pref-switch]').forEach(function (input) {
    input.addEventListener('change', function () {
      var key = input.getAttribute('data-pref-switch');
      var on = input.checked;
      var body = {};
      if (key === 'notify') {
        body.notify_voice_ready = on;
        body.notify_notice = on;
      } else {
        body[key] = on;
      }
      if (key === 'dark_mode') RM.setDark(on);
      input.disabled = true;
      RM.api('/api/settings/prefs', { method: 'POST', body: body })
        .then(function () {
          if (key === 'notify') RM.toast(on ? '알림을 켰어요' : '알림을 껐어요', 'success');
          if (key === 'dark_mode') RM.toast(on ? '다크 모드를 켰어요' : '다크 모드를 껐어요', 'success');
        })
        .catch(function (err) {
          input.checked = !on;
          if (key === 'dark_mode') RM.setDark(!on);
          RM.toast(err.message, 'error');
        })
        .finally(function () { input.disabled = false; });
    });
  });

  // 캐시 크기와 삭제
  var sizeEl = document.querySelector('[data-cache-size]');
  var clearBtn = document.querySelector('[data-cache-clear]');
  function fmtBytes(n) {
    if (!n || n < 1024) return '0KB';
    if (n < 1024 * 1024) return Math.round(n / 1024) + 'KB';
    if (n < 1024 * 1024 * 1024) return (n / 1024 / 1024).toFixed(n < 10 * 1024 * 1024 ? 1 : 0) + 'MB';
    return (n / 1024 / 1024 / 1024).toFixed(1) + 'GB';
  }
  function showSize() {
    if (!sizeEl) return Promise.resolve();
    if (!navigator.storage || !navigator.storage.estimate) {
      sizeEl.textContent = '';
      return Promise.resolve();
    }
    return navigator.storage.estimate()
      .then(function (est) { sizeEl.textContent = fmtBytes(est && est.usage ? est.usage : 0); })
      .catch(function () { sizeEl.textContent = ''; });
  }
  showSize();
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      clearBtn.disabled = true;
      var jobs = [];
      if (window.caches && caches.keys) {
        jobs.push(caches.keys().then(function (keys) {
          return Promise.all(keys.map(function (k) { return caches.delete(k); }));
        }).catch(function () {}));
      }
      try { sessionStorage.clear(); } catch (e) {}
      try {
        var drop = [];
        for (var i = 0; i < localStorage.length; i++) {
          var k = localStorage.key(i);
          // 다크 모드 기억(rm-dark)은 남긴다.
          if (k && k.indexOf('rm-') === 0 && k !== 'rm-dark') drop.push(k);
        }
        drop.forEach(function (k) { localStorage.removeItem(k); });
      } catch (e) {}
      Promise.all(jobs).then(showSize).then(function () {
        RM.toast('캐시를 삭제했어요. 동화는 다음에 들을 때 다시 받아와요.', 'success');
      }).finally(function () { clearBtn.disabled = false; });
    });
  }

  // 탈퇴 확인 문구
  var phrase = document.querySelector('[data-confirm-phrase]');
  if (phrase) {
    var target = phrase.getAttribute('data-confirm-phrase');
    var submit = document.querySelector('[data-withdraw-submit]');
    var check = function () { if (submit) submit.disabled = phrase.value.trim() !== target; };
    phrase.addEventListener('input', check);
    check();
  }
})();
