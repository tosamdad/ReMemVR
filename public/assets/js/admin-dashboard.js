/**
 * 운영 대시보드(/admin/dashboard): 30초마다 /admin/api/dashboard 를 불러 화면을 새로 고치지 않고 숫자를 바꾼다.
 *  - [data-stat="키"]       글자 바꾸기
 *  - [data-stat-width="키"] 막대 너비(%) 바꾸기
 *  - [data-region="이름"]   대기열, 응답 지연, 사전 생성 큐, 헬스체크 영역을 서버가 다시 그린 조각으로 바꾼다
 *    (그 영역에서 샘플을 듣는 중이거나 창이 열려 있으면 이번 차례는 건너뛴다)
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-dashboard]');
  if (!root || !window.RM) return;
  var api = root.getAttribute('data-api');
  var INTERVAL = 30000;
  var busy = false;
  var lastAt = Date.now();

  function apply(data) {
    var stats = data.stats || {};
    Object.keys(stats).forEach(function (k) {
      root.querySelectorAll('[data-stat="' + k + '"]').forEach(function (el) {
        if (el.textContent !== stats[k]) {
          el.textContent = stats[k];
          el.classList.add('transition-colors', 'bg-primary-fixed/60', 'rounded');
          setTimeout(function () { el.classList.remove('bg-primary-fixed/60'); }, 1200);
        }
      });
    });
    var widths = data.widths || {};
    Object.keys(widths).forEach(function (k) {
      root.querySelectorAll('[data-stat-width="' + k + '"]').forEach(function (el) {
        var w = Math.max(0, Math.min(100, Number(widths[k]) || 0));
        el.style.width = w + '%';
        el.classList.toggle('bg-error', w >= 90);
        el.classList.toggle('bg-secondary-container', w < 90);
        var bar = el.parentElement;
        if (bar && bar.getAttribute('role') === 'progressbar') bar.setAttribute('aria-valuenow', String(w));
      });
    });
    var html = data.html || {};
    Object.keys(html).forEach(function (k) {
      var region = root.querySelector('[data-region="' + k + '"]');
      if (!region) return;
      if (window.RMVoices && window.RMVoices.isBusy(region)) return;
      if (region.contains(document.activeElement) && document.activeElement !== document.body) return;
      region.innerHTML = html[k];
    });
  }

  function refresh() {
    if (busy || document.hidden) return;
    busy = true;
    RM.api(api).then(function (data) {
      apply(data);
      lastAt = Date.now();
    }).catch(function (err) {
      if (err && err.status === 401) location.reload();
    }).finally(function () { busy = false; });
  }

  setInterval(refresh, INTERVAL);
  // 다른 탭에 있다가 돌아오면 오래된 숫자를 바로 바꾼다.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && Date.now() - lastAt > INTERVAL) refresh();
  });
})();
