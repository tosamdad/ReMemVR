/**
 * 운영 설정 화면: 왼쪽 구역 메뉴 강조(스크롤 위치), 저장 뒤 돌아올 구역 기억, 저장하지 않은 변경 경고, 긴급 차단 스위치 표시.
 */
(function () {
  'use strict';
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var form = document.querySelector('[data-settings-form]');
  var sectionInput = document.querySelector('[data-section-input]');
  var sections = $$('[data-section]');
  var navs = $$('[data-nav]');
  var ACTIVE = ['bg-primary-fixed', 'text-primary'];

  function setActive(id) {
    navs.forEach(function (a) {
      var on = a.getAttribute('data-nav') === id;
      ACTIVE.forEach(function (c) { a.classList.toggle(c, on); });
      a.classList.toggle('text-on-surface-variant', !on);
      if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
    if (sectionInput && id && id !== 'api') sectionInput.value = id;
  }

  // 화면 위쪽 1/3 지점을 지나는 구역을 지금 구역으로 본다.
  function current() {
    var line = window.innerHeight / 3;
    var id = sections.length ? sections[0].id : '';
    sections.forEach(function (s) { if (s.getBoundingClientRect().top <= line) id = s.id; });
    return id;
  }
  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(function () { ticking = false; setActive(current()); });
  }, { passive: true });
  navs.forEach(function (a) {
    a.addEventListener('click', function () { setActive(a.getAttribute('data-nav')); });
  });
  var start = (location.hash || '').replace('#', '');
  setActive(start && document.getElementById(start) ? start : current());

  // 긴급 차단 스위치: 끄면 붉은 배경과 경고
  var kill = document.querySelector('[data-kill]');
  var killBox = document.querySelector('[data-killswitch]');
  function paintKill() {
    if (!kill || !killBox) return;
    killBox.classList.toggle('bg-error-container', !kill.checked);
    killBox.classList.toggle('bg-surface-container-low', kill.checked);
  }
  if (kill) kill.addEventListener('change', function () {
    paintKill();
    if (!kill.checked) RM.toast('저장하면 모든 동화에서 질문 기능이 꺼집니다.', 'error');
  });
  paintKill();

  // 저장하지 않은 변경 경고
  if (!form) return;
  var dirty = false;
  var dirtyText = document.querySelector('[data-dirty-text]');
  function markDirty() {
    if (dirty) return;
    dirty = true;
    if (dirtyText) dirtyText.textContent = '저장하지 않은 변경사항이 있습니다.';
  }
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', function () {
    dirty = false;
    if (sectionInput && !sectionInput.value) sectionInput.value = current();
  });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
  // 대체 음성 다시 만들기는 별도 폼이라 저장 안 된 값이 사라진다는 것을 알린다.
  var clips = document.getElementById('clips-form');
  if (clips) clips.addEventListener('submit', function (e) {
    if (dirty && !window.confirm('저장하지 않은 설정 변경이 사라집니다. 계속할까요?')) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    dirty = false;
  }, true);
})();
