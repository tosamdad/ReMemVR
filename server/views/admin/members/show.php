<?php
/**
 * 회원 상세: 기본 정보, 자녀, 목소리, 최근 재생, 관리자 메모, 이용 정지.
 * 변수: member(MemberStats::decorate 한 행), user, sessions, voices, inquiries, social
 */
use App\Services\MemberStats;

layout('admin/layout', ['title' => '회원 상세', 'active' => 'members']);

$statusChip = [
    'active' => 'bg-emerald-100 text-emerald-800',
    'voice_needed' => 'bg-amber-100 text-amber-800',
    'voice_waiting' => 'bg-primary-fixed text-on-primary-fixed-variant',
    'blocked' => 'bg-error-container text-on-error-container',
    'withdrawn' => 'bg-surface-container-high text-on-surface-variant',
];
$providers = ['email' => '이메일', 'kakao' => '카카오', 'google' => '구글'];
$inqStatus = ['open' => '대기', 'answered' => '답변 완료', 'closed' => '종료'];
$withdrawn = $user['status'] === 'withdrawn' || $user['deleted_at'] !== null;
$memo = old('admin_memo', (string) $user['admin_memo']);
?>
<div class="flex w-full flex-col gap-6">
  <a href="<?= e(url('/admin/members')) ?>" class="flex w-fit items-center gap-1 font-label-md text-label-md text-on-surface-variant hover:text-primary"><span class="material-symbols-outlined text-[18px]">arrow_back</span>회원 목록</a>

  <div class="flex flex-col justify-between gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm lg:flex-row lg:items-center">
    <div class="flex items-center gap-4">
      <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[30px]">person</span></div>
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="font-headline-md text-headline-md text-on-surface"><?= e($user['name']) ?></h1>
          <span class="rounded-full bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= e($member['code']) ?></span>
          <span class="rounded-full px-2.5 py-0.5 font-label-sm text-label-sm <?= $statusChip[$member['status']['key']] ?>"><?= e($member['status']['label']) ?></span>
        </div>
        <p class="mt-1 font-label-md text-label-md text-on-surface-variant"><?= e($user['email']) ?><?= $user['phone'] ? ' · ' . e(mask_phone($user['phone'])) : '' ?> · <?= e($providers[$user['signup_provider']] ?? $user['signup_provider']) ?> 가입 <?= e(date('Y.m.d', strtotime($user['created_at']))) ?><?= $user['last_login_at'] ? ' · 최근 로그인 ' . e(time_ago($user['last_login_at'])) : '' ?></p>
        <?php if ($social): ?>
        <p class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">연결된 간편 로그인: <?= e(implode(', ', array_map(static function ($s) use ($providers) {
            return $providers[$s['provider']] ?? $s['provider'];
        }, $social))) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" class="a-btn-tonal text-primary" data-open-log="<?= (int) $user['id'] ?>"><span class="material-symbols-outlined text-[18px]">forum</span>대화 로그</button>
      <?php if (!$withdrawn): ?>
      <?php if ($user['status'] === 'blocked'): ?>
      <form method="post" action="<?= e(url('/admin/members/' . (int) $user['id'] . '/status')) ?>" data-confirm="이용 정지를 해제할까요?">
        <?= csrf_field() ?><input type="hidden" name="status" value="active">
        <button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">lock_open</span>이용 정지 해제</button>
      </form>
      <?php else: ?>
      <form method="post" action="<?= e(url('/admin/members/' . (int) $user['id'] . '/status')) ?>" class="flex items-center gap-2" data-confirm="이 회원의 이용을 정지할까요? 즉시 로그아웃되고 다시 로그인할 수 없습니다.">
        <?= csrf_field() ?><input type="hidden" name="status" value="blocked">
        <input name="reason" maxlength="200" class="a-input w-48" placeholder="정지 사유(감사 로그에 남음)" aria-label="정지 사유">
        <button type="submit" class="a-btn-danger"><span class="material-symbols-outlined text-[18px]">block</span>이용 정지</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
    <?php foreach ([
        ['play_circle', '재생 수', fmt_number($member['sessions']) . '회'],
        ['task_alt', '완독', fmt_number($member['completed']) . '회'],
        ['schedule', '총 청취 시간', number_format($member['listened_hours'], 1) . '시간'],
        ['record_voice_over', '질문 수', fmt_number($member['questions']) . '회' . ($member['per_story'] !== null ? ' (' . number_format($member['per_story'], 1) . '회/편)' : '')],
        ['bedtime', '주 이용 시간대', $member['hour'] === null ? '기록 없음' : $member['hour_text']],
    ] as $k): ?>
    <div class="rounded-xl bg-surface-container-lowest p-4 shadow-sm">
      <div class="flex items-center gap-1.5 font-label-sm text-label-sm text-on-surface-variant"><span class="material-symbols-outlined text-[16px] text-primary"><?= $k[0] ?></span><?= e($k[1]) ?></div>
      <p class="mt-1 font-label-md text-label-md text-on-surface"><?= e($k[2]) ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-12">
    <div class="flex flex-col gap-6 xl:col-span-5">
      <div class="a-card flex flex-col gap-3">
        <h2 class="font-label-md text-label-md font-bold text-on-surface">자녀</h2>
        <?php if (!$member['children']): ?><p class="font-label-sm text-label-sm text-on-surface-variant">등록한 자녀가 없습니다.</p><?php endif; ?>
        <?php foreach ($member['children'] as $ch): ?>
        <div class="flex items-center gap-3 rounded-xl bg-surface-container-low p-3">
          <?= child_avatar($ch, 'w-10 h-10 text-xl') ?>
          <div>
            <p class="font-label-md text-label-md text-on-surface"><?= e(MemberStats::childText($ch)) ?></p>
            <p class="font-label-sm text-label-sm text-on-surface-variant"><?= $ch['birth_date'] ? '생일 ' . e(date('Y.m.d', strtotime($ch['birth_date']))) : '생일 미입력' ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="a-card flex flex-col gap-3">
        <h2 class="font-label-md text-label-md font-bold text-on-surface">가족 목소리</h2>
        <?php if (!$voices): ?><p class="font-label-sm text-label-sm text-on-surface-variant">등록한 목소리가 없습니다. 기기 음성으로 동화를 듣습니다.</p><?php endif; ?>
        <?php foreach ($voices as $v): ?>
        <a href="<?= e(url('/admin/voices/' . (int) $v['id'])) ?>" class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-low p-3 transition-colors hover:bg-surface-container-high<?= $v['deleted_at'] ? ' opacity-60' : '' ?>">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[20px]"><?= e(voice_icon($v)) ?></span></span>
            <div>
              <p class="font-label-md text-label-md text-on-surface"><?= e($v['label']) ?> <span class="font-label-sm text-label-sm text-on-surface-variant">#REQ-<?= str_pad((string) (int) $v['id'], 4, '0', STR_PAD_LEFT) ?></span></p>
              <p class="font-label-sm text-label-sm text-on-surface-variant">신청 <?= e(date('Y.m.d', strtotime($v['requested_at']))) ?><?= $v['deleted_at'] ? ' · 삭제됨' : '' ?></p>
            </div>
          </div>
          <span class="rounded-full bg-surface-container-highest px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= e(voice_status_label((string) $v['status'])) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <div class="a-card flex flex-col gap-3">
        <h2 class="font-label-md text-label-md font-bold text-on-surface">최근 1:1 문의</h2>
        <?php if (!$inquiries): ?><p class="font-label-sm text-label-sm text-on-surface-variant">문의 내역이 없습니다.</p><?php endif; ?>
        <?php foreach ($inquiries as $q): ?>
        <a href="<?= e(url('/admin/inquiries/' . (int) $q['id'])) ?>" class="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-surface-container-low">
          <span class="truncate font-label-md text-label-md text-on-surface"><?= e($q['title']) ?></span>
          <span class="shrink-0 font-label-sm text-label-sm text-on-surface-variant"><?= e($inqStatus[$q['status']] ?? $q['status']) ?> · <?= e(date('m.d', strtotime($q['created_at']))) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex flex-col gap-6 xl:col-span-7">
      <form method="post" action="<?= e(url('/admin/members/' . (int) $user['id'] . '/memo')) ?>" class="a-card flex flex-col gap-3">
        <?= csrf_field() ?>
        <div class="flex items-center justify-between">
          <label for="admin_memo" class="font-label-md text-label-md font-bold text-on-surface">관리자 메모</label>
          <span class="font-label-sm text-label-sm text-on-surface-variant">회원에게 보이지 않습니다</span>
        </div>
        <textarea id="admin_memo" name="admin_memo" rows="4" maxlength="2000" class="a-input" placeholder="상담 내용, 특이 사항을 남겨 두세요."><?= e($memo) ?></textarea>
        <?php if (errors('admin_memo')): ?><p class="font-label-sm text-label-sm text-error"><?= e(errors('admin_memo')) ?></p><?php endif; ?>
        <div class="flex justify-end"><button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">save</span>메모 저장</button></div>
      </form>

      <div class="a-card flex flex-col gap-3">
        <h2 class="font-label-md text-label-md font-bold text-on-surface">최근 재생 기록</h2>
        <?php if (!$sessions): ?>
        <p class="py-6 text-center font-label-sm text-label-sm text-on-surface-variant">재생 기록이 없습니다.</p>
        <?php else: ?>
        <div class="relative overflow-x-auto">
          <table class="a-table w-full">
            <thead><tr class="bg-surface-container-low"><th class="rounded-l-lg">시작</th><th>동화</th><th>자녀</th><th>목소리</th><th>청취</th><th class="rounded-r-lg">질문</th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
              <tr>
                <td class="whitespace-nowrap"><?= e(date('m.d H:i', strtotime($s['started_at']))) ?></td>
                <td><?= e(str_limit($s['story_title'], 18)) ?><?php if ((int) $s['completed'] === 1): ?> <span class="a-chip bg-emerald-100 text-emerald-800">완독</span><?php endif; ?></td>
                <td class="whitespace-nowrap"><?= e($s['child_name'] ?: '-') ?></td>
                <td class="whitespace-nowrap"><?= e($s['voice_label'] ?: '기기 음성') ?></td>
                <td class="whitespace-nowrap"><?= e(fmt_duration((int) $s['listened_ms'])) ?></td>
                <td class="whitespace-nowrap"><?= (int) $s['asks'] ?>회<?= (int) $s['fallback_count'] > 0 ? ' <span class="font-label-sm text-label-sm text-secondary">(초과 ' . (int) $s['fallback_count'] . ')</span>' : '' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="fixed inset-0 z-50 hidden items-center justify-center bg-inverse-surface/40 p-4 backdrop-blur-sm" data-log-modal role="dialog" aria-modal="true" aria-label="인터랙션 상세 로그">
  <div class="flex max-h-[90vh] w-full max-w-2xl flex-col gap-6 overflow-y-auto rounded-2xl bg-surface-container-lowest p-6 shadow-2xl sm:p-8" data-log-body></div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-members.js')) ?>"></script>
<?php endsection(); ?>
