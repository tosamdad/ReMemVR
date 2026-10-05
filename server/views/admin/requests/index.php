<?php
/**
 * 동화 생성 요청 목록. 변수: rows, tab, counts, filters(q, voice), keepIds(남겨 둘 요청 번호), voice, page, pages, total, elReady
 * 확인 대기, 생성 실패, 반려 요청은 골라서 한 번에 생성을 시작할 수 있다.
 * 생성 시작, 반려는 화면에서 바로 보내고, 목록은 줄을 그대로 둔 채 상태만 바꾼다(data-soft 영역을 새 내용으로 교체).
 */
use App\Controllers\Admin\RequestController;
use App\Services\MemberStats;
use App\Services\StoryRequests;

layout('admin/layout', ['title' => '동화 생성 요청', 'active' => 'requests']);
$keep = array_filter(['q' => $filters['q'], 'voice' => $filters['voice'] ?: ''], 'strlen');
$query = array_filter(['status' => $tab === 'requested' ? '' : $tab] + $keep, 'strlen');
$filtered = $filters['q'] !== '' || $filters['voice'] > 0;
$from = $total ? ($page - 1) * RequestController::PER_PAGE + 1 : 0;
$to = min($total, $page * RequestController::PER_PAGE);
$tabIcons = [
    'requested' => 'pending_actions', 'making' => 'graphic_eq', 'failed' => 'error', 'done' => 'task_alt',
    'rejected' => 'block', 'canceled' => 'undo', 'all' => 'inbox',
];
$selectable = ['requested', 'failed', 'rejected'];
$anySelectable = false;
foreach ($rows as $r) {
    if (in_array($r['state'], $selectable, true)) {
        $anySelectable = true;
        break;
    }
}
?>
<div class="flex w-full flex-col gap-6" data-requests>
  <div class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-sm" data-soft="head">
    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-3">
          <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Story Requests</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant">회원이 동화와 가족 목소리를 골라 보낸 요청입니다</span>
        </div>
        <h1 class="font-headline-md text-headline-md text-on-surface">동화 생성 요청</h1>
        <p class="max-w-2xl font-label-sm text-label-sm text-on-surface-variant">요청을 확인하고 생성을 시작하면 ElevenLabs 로 동화 오디오를 만듭니다. 완성되면 회원의 내 동화에 나타나고 완성 메일이 갑니다. 동화 한 편당 본문 글자 수만큼 크레딧이 쓰입니다.</p>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">확인 대기</p>
          <p class="font-headline-md text-headline-md <?= $counts['requested'] > 0 ? 'text-secondary' : 'text-on-surface' ?>"><?= (int) $counts['requested'] ?><span class="ml-0.5 font-label-md text-label-md text-on-surface-variant">건</span></p>
        </div>
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">생성 중</p>
          <p class="font-headline-md text-headline-md text-on-surface"><?= (int) $counts['making'] ?><span class="ml-0.5 font-label-md text-label-md text-on-surface-variant">건</span></p>
        </div>
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">생성 실패</p>
          <p class="font-headline-md text-headline-md <?= $counts['failed'] > 0 ? 'text-error' : 'text-on-surface' ?>"><?= (int) $counts['failed'] ?><span class="ml-0.5 font-label-md text-label-md text-on-surface-variant">건</span></p>
        </div>
      </div>
    </div>
    <nav class="flex gap-6 overflow-x-auto border-b border-surface-container-high no-scrollbar" aria-label="요청 상태">
      <?php foreach (RequestController::TABS as $key => $label): $on = $key === $tab; ?>
      <a href="<?= e(url('/admin/requests', array_filter(['status' => $key === 'requested' ? '' : $key] + $keep, 'strlen'))) ?>" class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-1 pb-3 font-label-md text-label-md transition-colors <?= $on ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined text-[18px]"><?= $tabIcons[$key] ?></span><?= e($label) ?>
        <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $on ? 'bg-primary-fixed text-primary' : (($key === 'requested' || $key === 'failed') && $counts[$key] > 0 ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant') ?>"><?= (int) $counts[$key] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if (!$elReady): ?>
  <div class="flex items-start gap-3 rounded-xl bg-error-container px-5 py-4 text-on-error-container">
    <span class="material-symbols-outlined">key_off</span>
    <p class="font-label-md text-label-md">ElevenLabs API 키가 등록되지 않아 생성을 시작할 수 없습니다. GitHub Secrets 에 ELEVENLABS_API_KEY 를 등록하고 다시 배포하세요.</p>
  </div>
  <?php endif; ?>

  <section class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <form method="get" action="<?= e(url('/admin/requests')) ?>" class="flex flex-wrap items-center gap-3">
        <?php if ($tab !== 'requested'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
        <?php if ($filters['voice'] > 0): ?><input type="hidden" name="voice" value="<?= (int) $filters['voice'] ?>"><?php endif; ?>
        <label class="flex w-80 max-w-full items-center rounded-xl bg-surface-container-low px-3 py-2">
          <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
          <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="동화 제목, 회원 이름, 이메일, 목소리, #RM-ID" class="w-full border-0 bg-transparent p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0">
        </label>
        <button type="submit" class="a-btn-tonal shrink-0 whitespace-nowrap">검색</button>
        <?php if ($voice): ?><span class="a-chip bg-primary-fixed text-primary">목소리: <?= e($voice['label']) ?> #<?= (int) $voice['id'] ?></span><?php endif; ?>
        <?php if ($filtered): ?><a href="<?= e(url('/admin/requests', $tab === 'requested' ? [] : ['status' => $tab])) ?>" class="font-label-sm text-label-sm text-primary hover:underline">조건 지우기</a><?php endif; ?>
      </form>
      <div data-soft="bulk">
      <?php if ($anySelectable): ?>
      <form method="post" action="<?= e(url('/admin/requests/approve')) ?>" id="req-bulk" class="flex items-center gap-3" data-confirm="고른 요청의 동화 생성을 시작할까요? ElevenLabs 크레딧이 사용됩니다." data-confirm-ok="생성 시작" data-ajax data-req-form>
        <?= csrf_field() ?>
        <span class="font-label-sm text-label-sm text-on-surface-variant" data-bulk-info>고른 요청 없음</span>
        <button type="submit" class="a-btn-primary" data-bulk-btn disabled<?= $elReady ? '' : ' title="ElevenLabs API 키가 필요합니다"' ?>><span class="material-symbols-outlined text-[18px]">play_circle</span>고른 요청 생성 시작</button>
      </form>
      <?php endif; ?>
      </div>
    </div>

    <div class="relative overflow-x-auto">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
            <th class="w-10 rounded-l-lg px-3 py-3" data-soft="checkall">
              <?php if ($anySelectable): ?><input type="checkbox" class="rounded border-outline-variant text-primary focus:ring-primary/30" data-check-all aria-label="전체 고르기"><?php endif; ?>
            </th>
            <th class="whitespace-nowrap px-3 py-3">번호</th>
            <th class="px-3 py-3">동화</th>
            <th class="whitespace-nowrap px-3 py-3">목소리</th>
            <th class="whitespace-nowrap px-3 py-3">회원</th>
            <th class="whitespace-nowrap px-3 py-3">요청</th>
            <th class="whitespace-nowrap px-3 py-3">상태</th>
            <th class="whitespace-nowrap rounded-r-lg px-3 py-3 text-right">처리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface" data-soft="rows">
          <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-3 py-14 text-center">
            <span class="material-symbols-outlined text-[36px] text-outline"><?= $tab === 'requested' && !$filtered ? 'task_alt' : 'inbox' ?></span>
            <?php if ($filtered): ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">조건에 맞는 요청이 없습니다.</p>
            <?php elseif ($tab === 'requested'): ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">확인을 기다리는 요청이 없습니다.</p>
            <p class="font-label-sm text-label-sm text-on-surface-variant">회원이 동화 책장에서 동화와 목소리를 골라 요청하면 이곳에 나타납니다.</p>
            <?php else: ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface"><?= e(RequestController::TABS[$tab]) ?> 요청이 없습니다.</p>
            <?php endif; ?>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              $state = $r['state'];
              $rid = (int) $r['id'];
              $canStart = in_array($state, $selectable, true);
              $voiceOk = !$r['voice_deleted_at'] && (int) $r['voice_has_provider'] && $r['voice_status'] === 'completed';
              $chip = isset(RequestController::CHIPS[$state]) ? RequestController::CHIPS[$state] : 'bg-surface-container-high text-on-surface-variant';
          ?>
          <tr class="align-top transition-colors hover:bg-surface-container-low/70" data-req-row="<?= $rid ?>" data-state="<?= e($state) ?>">
            <td class="px-3 py-4">
              <?php if ($canStart): ?>
              <input type="checkbox" name="ids[]" value="<?= $rid ?>" form="req-bulk" class="rounded border-outline-variant text-primary focus:ring-primary/30" data-req-check data-chars="<?= (int) $r['story_chars'] ?>" aria-label="<?= e(StoryRequests::reqId($rid)) ?> 고르기"<?= $voiceOk ? '' : ' disabled' ?>>
              <?php endif; ?>
            </td>
            <td class="whitespace-nowrap px-3 py-4 font-label-md text-label-md text-on-surface-variant"><?= e(StoryRequests::reqId($rid)) ?></td>
            <td class="px-3 py-4">
              <span class="block font-semibold"><?= e($r['story_title']) ?></span>
              <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e(fmt_number((int) $r['story_chars'])) ?>자<?= $r['story_status'] !== 'published' || $r['story_deleted_at'] ? ' · 비공개 동화' : '' ?></span>
            </td>
            <td class="whitespace-nowrap px-3 py-4">
              <a href="<?= e(url('/admin/voices/' . (int) $r['voice_profile_id'])) ?>" class="flex items-center gap-2 hover:text-primary">
                <span class="material-symbols-outlined text-[18px] text-primary"><?= e(voice_icon(['icon' => $r['voice_icon'], 'label' => $r['voice_label']])) ?></span><?= e($r['voice_label']) ?>
              </a>
              <span class="block font-label-sm text-label-sm <?= $voiceOk ? 'text-on-surface-variant' : 'text-error' ?>"><?= $r['voice_deleted_at'] ? '삭제된 목소리' : e(voice_status_label((string) $r['voice_status'])) ?></span>
            </td>
            <td class="px-3 py-4">
              <a href="<?= e(url('/admin/members/' . (int) $r['user_id'])) ?>" class="block whitespace-nowrap hover:text-primary"><?= e($r['user_name']) ?></a>
              <span class="block whitespace-nowrap font-label-sm text-label-sm text-on-surface-variant"><?= e(MemberStats::memberCode((int) $r['user_id'])) ?></span>
            </td>
            <td class="whitespace-nowrap px-3 py-4">
              <span class="block"><?= e(date('Y.m.d H:i', strtotime((string) $r['created_at']))) ?></span>
              <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e(time_ago($r['created_at'])) ?></span>
            </td>
            <td class="px-3 py-4">
              <span class="a-chip whitespace-nowrap <?= $chip ?>"><?= e(StoryRequests::adminStateLabel($state)) ?></span>
              <?php if ($state === 'rejected' && (string) $r['reject_reason'] !== ''): ?>
              <span class="mt-1 block max-w-[220px] font-label-sm text-label-sm text-on-surface-variant"><?= e($r['reject_reason']) ?></span>
              <?php elseif ($state === 'failed' && (string) $r['audio_error'] !== ''): ?>
              <span class="mt-1 block max-w-[220px] font-label-sm text-label-sm text-error"><?= e(str_limit((string) $r['audio_error'], 90)) ?></span>
              <?php elseif ($state === 'done' && $r['completed_at']): ?>
              <span class="mt-1 block whitespace-nowrap font-label-sm text-label-sm text-on-surface-variant"><?= e(date('m.d H:i', strtotime((string) $r['completed_at']))) ?> 완성<?= $r['audio_duration_ms'] ? ' · ' . e(fmt_duration((int) $r['audio_duration_ms'])) : '' ?></span>
              <?php endif; ?>
              <?php if ($r['admin_name'] !== null && $state !== 'requested'): ?>
              <span class="mt-1 block whitespace-nowrap font-label-sm text-label-sm text-outline"><?= e($r['admin_name']) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-4 text-right">
              <div class="flex flex-wrap items-start justify-end gap-2">
                <?php if ($canStart): ?>
                <form method="post" action="<?= e(url('/admin/requests/approve')) ?>" data-confirm="'<?= e($r['story_title']) ?>'을(를) <?= e($r['voice_label']) ?> 목소리로 만들까요? 약 <?= e(fmt_number((int) $r['story_chars'])) ?>자 분량의 크레딧이 쓰입니다." data-confirm-ok="<?= $state === 'requested' ? '생성 시작' : '다시 생성' ?>" data-ajax data-req-form>
                  <?= csrf_field() ?>
                  <input type="hidden" name="ids[]" value="<?= $rid ?>">
                  <button type="submit" class="a-btn-primary !px-3 !py-1.5"<?= $voiceOk && $elReady ? '' : ' disabled' ?>><?= $state === 'requested' ? '생성 시작' : '다시 생성' ?></button>
                </form>
                <?php endif; ?>
                <?php if (in_array($state, ['requested', 'failed'], true)): ?>
                <details class="relative">
                  <summary class="a-btn-tonal !px-3 !py-1.5 cursor-pointer list-none [&::-webkit-details-marker]:hidden">반려</summary>
                  <form method="post" action="<?= e(url('/admin/requests/' . $rid . '/reject')) ?>" class="absolute right-0 z-10 mt-2 flex w-72 flex-col gap-2 rounded-xl bg-surface-container-lowest p-4 text-left shadow-lg ring-1 ring-surface-container-high" data-ajax data-req-form>
                    <?= csrf_field() ?>
                    <label class="font-label-sm text-label-sm text-on-surface-variant" for="reason-<?= $rid ?>">반려 사유 (회원에게 보입니다)</label>
                    <textarea id="reason-<?= $rid ?>" name="reason" rows="3" maxlength="255" required class="a-input" placeholder="예: 목소리 상태 확인이 필요해 잠시 보류합니다."></textarea>
                    <button type="submit" class="a-btn-danger">반려하기</button>
                  </form>
                </details>
                <?php endif; ?>
                <?php if ($state === 'done' && $r['audio_id']): ?>
                <a href="<?= e(url('/admin/media/story-audio/' . (int) $r['audio_id'])) ?>" target="_blank" rel="noopener" class="a-btn-tonal !px-3 !py-1.5"><span class="material-symbols-outlined text-[18px]">headphones</span>들어 보기</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row" data-soft="foot">
      <span class="font-label-md text-label-md text-on-surface-variant"><?= $total ? e(fmt_number($total)) . '건 중 ' . $from . ' - ' . $to . ' 표시 중' : '표시할 요청 없음' ?></span>
      <?php if ($pages > 1): ?>
      <?= partial('admin/members/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/requests', 'query' => $query]) ?>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php section('scripts'); ?>
<script>
(function () {
  var root = document.querySelector('[data-requests]');
  if (!root || !window.RM) return;
  var ready = <?= $elReady ? 'true' : 'false' ?>;

  // ── 고르기(목록을 바꿔도 동작하도록 위임) ──
  function checks() { return Array.prototype.slice.call(root.querySelectorAll('[data-req-check]')); }
  function update() {
    var all = root.querySelector('[data-check-all]');
    var info = root.querySelector('[data-bulk-info]');
    var btn = root.querySelector('[data-bulk-btn]');
    var list = checks();
    var picked = list.filter(function (c) { return c.checked; });
    var chars = picked.reduce(function (a, c) { return a + Number(c.getAttribute('data-chars') || 0); }, 0);
    if (info) info.textContent = picked.length ? picked.length + '건 · 약 ' + chars.toLocaleString('ko-KR') + '자' : '고른 요청 없음';
    if (btn) btn.disabled = !ready || picked.length === 0;
    if (all) {
      var usable = list.filter(function (c) { return !c.disabled; });
      all.checked = usable.length > 0 && picked.length === usable.length;
    }
  }
  root.addEventListener('change', function (e) {
    if (e.target.matches('[data-check-all]')) {
      checks().forEach(function (c) { if (!c.disabled) c.checked = e.target.checked; });
    }
    if (e.target.matches('[data-check-all], [data-req-check]')) update();
  });
  update();

  // ── 목록을 제자리에서 새로 고치기 ──
  // 화면에 떠 있는 요청 번호를 keep 으로 넘겨, 상태가 바뀌어 지금 탭 조건에 맞지 않아도 줄이 남게 한다.
  var busy = false, pending = false;
  function shownIds() {
    return Array.prototype.map.call(root.querySelectorAll('[data-req-row]'), function (tr) { return tr.getAttribute('data-req-row'); });
  }
  function keepUrl(extra) {
    var u = new URL(location.href);
    var ids = shownIds().concat(extra || []);
    var old = (u.searchParams.get('keep') || '').split(',');
    var all = old.concat(ids).map(String).filter(function (v, i, a) { return /^\d+$/.test(v) && a.indexOf(v) === i; }).slice(-100);
    if (all.length) u.searchParams.set('keep', all.join(',')); else u.searchParams.delete('keep');
    return u;
  }
  function userBusy() {
    if (document.querySelector('[data-rm-dialog]') || root.querySelector('details[open]')) return true;
    var a = document.activeElement;
    return !!(a && a !== document.body && root.contains(a) && a.matches('input[type="search"], textarea'));
  }
  function refresh(extra) {
    if (busy || userBusy()) { pending = true; return; }
    busy = true;
    pending = false;
    var u = keepUrl(extra);
    // 선택해 둔 체크 상태는 그대로 살린다.
    var picked = checks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    fetch(u.toString(), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error('load'); return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        root.querySelectorAll('[data-soft]').forEach(function (region) {
          var next = doc.querySelector('[data-soft="' + region.getAttribute('data-soft') + '"]');
          if (next && next.innerHTML !== region.innerHTML) region.innerHTML = next.innerHTML;
        });
        checks().forEach(function (c) { if (picked.indexOf(c.value) >= 0 && !c.disabled) c.checked = true; });
        update();
        try { history.replaceState(null, '', u.pathname + u.search); } catch (e) {}
      })
      .catch(function () {})
      .finally(function () { busy = false; });
  }

  // 생성 시작, 반려를 보낸 뒤에는 그 줄의 상태만 바꾼다.
  root.addEventListener('rm:success', function (e) {
    var form = e.target;
    var d = form.closest('details');
    if (d) d.open = false;
    refresh((e.detail && e.detail.ids) || []);
  });

  // 생성 중인 요청이 있으면 5초마다 상태를 확인한다(작업 처리기도 함께 돌린다).
  var lastTick = 0;
  setInterval(function () {
    if (document.hidden) return;
    if (pending) { refresh(); return; }
    if (!root.querySelector('[data-req-row][data-state="making"]')) return;
    if (window.RMAdmin && Date.now() - lastTick > 12000) { lastTick = Date.now(); window.RMAdmin.tickWorker(); }
    refresh();
  }, 5000);
})();
</script>
<?php endsection(); ?>
