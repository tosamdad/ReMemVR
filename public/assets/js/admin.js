/**
 * 관리자 화면 공통 스크립트: 좁은 화면에서 왼쪽 메뉴 열고 닫기, 작업 처리기(worker) 주기 호출.
 * RMAdmin.tickWorker() 는 대기 중인 백그라운드 작업(목소리 생성, 동화 오디오 생성)을 한 단계 진행시킨다.
 * 관리자 화면이 열려 있는 동안 45초마다 자동으로 호출된다.
 */
(function () {
  'use strict';
  var sidebar = document.getElementById('admin-sidebar');
  var backdrop = document.getElementById('admin-backdrop');
  function open() { if (sidebar) { sidebar.classList.remove('-translate-x-full'); backdrop.classList.remove('hidden'); } }
  function close() { if (sidebar) { sidebar.classList.add('-translate-x-full'); backdrop.classList.add('hidden'); } }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-admin-menu-open]')) open();
    if (e.target.closest('[data-admin-menu-close]')) close();
  });

  var ticking = false;
  async function tickWorker() {
    if (ticking || !window.RM) return null;
    ticking = true;
    try {
      return await RM.api('/admin/api/worker/tick', { method: 'POST', body: {} });
    } catch (e) {
      return null;
    } finally {
      ticking = false;
    }
  }
  setInterval(function () { if (!document.hidden) tickWorker(); }, 45000);
  setTimeout(tickWorker, 4000);

  window.RMAdmin = { tickWorker: tickWorker, openMenu: open, closeMenu: close };
})();
