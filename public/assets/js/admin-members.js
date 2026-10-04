/**
 * 회원 및 통계 화면: 히트맵 동화 바꾸기, 검색 필터 자동 적용, 대화 로그 창(불러오기, 음성 재생, 검토 완료).
 */
(function () {
  'use strict';
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // 히트맵: 고른 동화만 보이기
  var sel = document.querySelector('[data-heatmap-select]');
  if (sel) sel.addEventListener('change', function () {
    $$('[data-heatmap]').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-heatmap') !== sel.value); });
  });

  // 목소리 유형은 고르면 바로 검색
  $$('[data-auto-submit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });

  // 대화 로그 창
  var modal = document.querySelector('[data-log-modal]');
  var body = document.querySelector('[data-log-body]');
  var player = new Audio();
  var playingBtn = null;
  if (!modal || !body) return;

  function stopAudio() {
    player.pause();
    if (playingBtn) { var i = playingBtn.querySelector('.material-symbols-outlined'); if (i) i.textContent = 'play_circle'; }
    playingBtn = null;
  }
  function open(id) {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<p class="py-12 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>';
    RM.api('/admin/api/members/' + encodeURIComponent(id) + '/log').then(function (res) {
      body.innerHTML = res.html || '';
    }).catch(function (err) {
      body.innerHTML = '<div class="flex flex-col items-center gap-3 py-12"><p class="font-label-md text-label-md text-error">' + RM.escapeHtml(err.message) + '</p>' +
        '<button type="button" class="a-btn-tonal" data-close-log>닫기</button></div>';
    });
  }
  function close() {
    stopAudio();
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
  }
  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-open-log]');
    if (openBtn && !openBtn.disabled) { open(openBtn.getAttribute('data-open-log')); return; }
    if (!modal.contains(e.target)) return;
    if (e.target === modal || e.target.closest('[data-close-log]')) { close(); return; }
    var play = e.target.closest('[data-play-audio]');
    if (play) {
      if (playingBtn === play && !player.paused) { stopAudio(); return; }
      stopAudio();
      player.src = play.getAttribute('data-play-audio');
      player.play().then(function () {
        playingBtn = play;
        var i = play.querySelector('.material-symbols-outlined');
        if (i) i.textContent = 'stop_circle';
      }).catch(function () { RM.toast('음성 파일을 재생하지 못했습니다.', 'error'); });
      return;
    }
    var review = e.target.closest('[data-review]');
    if (review) {
      var ids = [];
      try { ids = JSON.parse(review.getAttribute('data-review') || '[]'); } catch (err) { ids = []; }
      if (!ids.length) return;
      review.disabled = true;
      RM.api('/admin/interactions/review', { method: 'POST', body: { ids: ids } }).then(function (res) {
        RM.toast(res.message || '검토 완료로 표시했습니다.', 'success');
        var dot = document.querySelector('[data-open-log="' + (modal.getAttribute('data-member') || '') + '"] span');
        if (dot) dot.remove();
        close();
      }).catch(function (err) {
        review.disabled = false;
        RM.toast(err.message, 'error');
      });
    }
  });
  player.addEventListener('ended', stopAudio);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
  // 검토 완료 뒤 목록의 미검토 표시를 지우려고 연 회원 id 를 기억한다.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-open-log]');
    if (b) modal.setAttribute('data-member', b.getAttribute('data-open-log'));
  }, true);
})();
