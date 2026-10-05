<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Services\DashboardStats;
use App\Services\Jobs;
use App\Services\StoryRequests;
use App\Services\VoiceService;

/**
 * 목소리 생성 관리(디자인 시안 _2). 목록 + 오른쪽 상세 검수 패널, 승인(복제), 반려, 요청 동화 생성 현황,
 * 상태 다시 계산, 합성 파라미터, 테스트 재생, 회원 메모, 처리 콘솔(작업 기록) API.
 * 상태 변경은 모두 VoiceService 를 거치고 감사 로그(admin_audit)를 남긴다.
 */
class VoiceController
{
    const PER_PAGE = 20;
    const STATUSES = ['pending', 'cloning', 'processing', 'completed', 'rejected', 'failed'];

    // ───────────────────────── 화면 ─────────────────────────

    /** GET /admin/voices */
    public function index(): string
    {
        return $this->render(null);
    }

    /** GET /admin/voices/{id} */
    public function show(string $id): string
    {
        return $this->render((int) $id);
    }

    private function render(?int $selectedId): string
    {
        require_admin();
        $filters = self::filters();
        list($where, $params) = self::where($filters);

        $total = (int) db_value('SELECT COUNT(*) FROM voice_profiles vp JOIN users u ON u.id = vp.user_id WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) Request::query('page', 1)));
        $rows = db_all(
            'SELECT vp.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
               FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE ' . $where . "
              ORDER BY FIELD(vp.status, 'pending', 'cloning', 'processing', 'failed', 'completed', 'rejected'),
                       CASE WHEN vp.status = 'pending' THEN vp.requested_at END ASC,
                       vp.requested_at DESC, vp.id DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        $rows = DashboardStats::enrich($rows);
        $reasons = VoiceService::failReasons(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['progress'] = VoiceService::progress((int) $r['id']);
            $r['fail_reason'] = isset($reasons[(int) $r['id']]) ? $reasons[(int) $r['id']] : null;
        }
        unset($r);

        // 상세 검수 대상: 지정한 목소리, 없으면 가장 오래된 대기 요청, 그것도 없으면 목록 첫 줄
        if ($selectedId === null) {
            $pendingId = db_value("SELECT id FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL ORDER BY requested_at ASC, id ASC LIMIT 1");
            $selectedId = $pendingId ? (int) $pendingId : ($rows ? (int) $rows[0]['id'] : null);
        }
        $detail = $selectedId !== null ? self::detail($selectedId) : null;
        if ($selectedId !== null && $detail === null && Request::path() !== '/admin/voices') {
            abort(404, '목소리를 찾을 수 없습니다.');
        }

        return view('admin/voices/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'counts' => DashboardStats::statusCounts(),
            'kpi' => DashboardStats::voiceListKpis(),
            'estimate' => DashboardStats::estimatedStoryCredits(),
            'detail' => $detail,
            'elReady' => provider_ready('elevenlabs'),
        ]);
    }

    /** 상세 검수 패널 데이터. 없거나 삭제된 목소리면 null */
    private static function detail(int $id): ?array
    {
        $row = db_one(
            'SELECT vp.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.admin_memo AS user_memo, u.created_at AS user_created_at
               FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE vp.id = ? AND vp.deleted_at IS NULL',
            [$id]
        );
        if (!$row) {
            return null;
        }
        $voice = DashboardStats::enrich([$row])[0];
        $reasons = VoiceService::failReasons([$id]);
        $voice['fail_reason'] = isset($reasons[$id]) ? $reasons[$id] : null;
        $samples = db_all('SELECT * FROM voice_samples WHERE voice_profile_id = ? ORDER BY id', [$id]);

        // 회원이 이 목소리로 요청한 동화(취소 제외)
        $requests = db_all(StoryRequests::SELECT . " WHERE r.voice_profile_id = ? AND r.status <> 'canceled' ORDER BY r.id DESC LIMIT 100", [$id]);
        foreach ($requests as &$r) {
            $r['state'] = StoryRequests::state($r);
        }
        unset($r);

        $defaults = [
            'stability' => (float) setting('elevenlabs.default_stability', 0.65),
            'similarity_boost' => (float) setting('elevenlabs.default_similarity', 0.80),
            'style' => (float) setting('elevenlabs.default_style', 0.0),
            'speaker_boost' => (bool) setting('elevenlabs.speaker_boost', true),
        ];
        $custom = $row['stability'] !== null || $row['similarity_boost'] !== null || $row['style'] !== null || $row['speaker_boost'] !== null;
        $settings = VoiceService::voiceSettings($row);

        $minSec = (int) setting('voice.min_sample_seconds', 60);
        $recSec = (int) setting('voice.recommended_sample_seconds', 120);
        $totalMs = (int) $voice['samples']['total_ms'];
        if ($totalMs >= $recSec * 1000) {
            $lengthJudge = ['적정', 'text-on-surface'];
        } elseif ($totalMs >= $minSec * 1000) {
            $lengthJudge = ['최소 충족', 'text-secondary'];
        } else {
            $lengthJudge = ['부족', 'text-error'];
        }

        return [
            'voice' => $voice,
            'samples' => $samples,
            'requests' => $requests,
            'progress' => VoiceService::progress($id),
            'params' => [
                'stability' => $settings['stability'],
                'similarity_boost' => $settings['similarity_boost'],
                'style' => $settings['style'],
                'speaker_boost' => $settings['use_speaker_boost'],
                'custom' => $custom,
                'defaults' => $defaults,
            ],
            'length' => ['judge' => $lengthJudge[0], 'class' => $lengthJudge[1], 'min_sec' => $minSec, 'rec_sec' => $recSec],
            'logs' => Jobs::latestLogs('voice_profile', $id, 0, 80),
            'active' => in_array($row['status'], ['cloning', 'processing'], true) || in_array($row['batch_status'], ['queued', 'running'], true),
            'failed_jobs' => (int) db_value(
                "SELECT COUNT(*) FROM jobs WHERE ref_type = 'voice_profile' AND ref_id = ? AND status = 'failed'",
                [$id]
            ),
        ];
    }

    // ───────────────────────── 동작 ─────────────────────────

    /** POST /admin/voices/{id}/clone : 승인하고 ElevenLabs 목소리 생성 작업 등록 */
    public function cloneVoice(string $id)
    {
        $admin = require_admin();
        $voice = self::voice((int) $id);
        $params = self::paramsFromInput();
        try {
            VoiceService::approve((int) $voice['id'], (int) $admin['id'], $params);
        } catch (\RuntimeException $e) {
            return self::respond('error', $e->getMessage(), $voice);
        }
        admin_audit('voice.approve', 'voice_profile', (int) $voice['id'], array_merge(['label' => $voice['label']], $params ? ['params' => $params] : []));

        return self::respond('success', DashboardStats::reqId($voice['id']) . ' 승인: ElevenLabs 목소리 생성 작업을 등록했습니다.', $voice);
    }

    /** POST /admin/voices/{id}/reject : 반려(재녹음 요청) */
    public function reject(string $id)
    {
        $admin = require_admin();
        $voice = self::voice((int) $id);
        $reason = Request::str('reason');
        if ($reason === '') {
            return self::respond('error', '반려 사유를 입력하세요.', $voice);
        }
        try {
            VoiceService::reject((int) $voice['id'], (int) $admin['id'], $reason);
        } catch (\RuntimeException $e) {
            return self::respond('error', $e->getMessage(), $voice);
        }
        admin_audit('voice.reject', 'voice_profile', (int) $voice['id'], ['reason' => mb_substr($reason, 0, 255)]);

        return self::respond('success', DashboardStats::reqId($voice['id']) . ' 반려: 회원에게 재녹음을 요청했습니다.', $voice);
    }

    /** POST /admin/voices/{id}/refresh : 작업과 오디오 상태로 다시 계산 */
    public function refresh(string $id)
    {
        require_admin();
        $voice = self::voice((int) $id);
        try {
            $r = VoiceService::refresh((int) $voice['id']);
        } catch (\RuntimeException $e) {
            return self::respond('error', $e->getMessage(), $voice);
        }
        admin_audit('voice.refresh', 'voice_profile', (int) $voice['id'], ['status' => isset($r['status']) ? $r['status'] : null]);
        $p = isset($r['progress']) ? $r['progress'] : VoiceService::progress((int) $voice['id']);
        $status = isset($r['status']) ? (string) $r['status'] : (string) $voice['status'];

        return self::respond('success', '상태를 다시 계산했습니다: ' . voice_status_label($status) . ' · 동화 ' . (int) $p['completed'] . ' / ' . (int) $p['total'] . '편 완료', $voice);
    }

    /** POST /admin/voices/{id}/params : 합성 파라미터 저장(reset 이면 설정 기본값으로) */
    public function params(string $id)
    {
        require_admin();
        $voice = self::voice((int) $id);
        if (input('reset')) {
            $params = ['stability' => null, 'similarity_boost' => null, 'style' => null, 'speaker_boost' => null];
        } else {
            $params = self::paramsFromInput();
            if (!$params) {
                return self::respond('error', '저장할 값이 없습니다.', $voice);
            }
        }
        try {
            VoiceService::saveParams((int) $voice['id'], $params);
        } catch (\RuntimeException $e) {
            return self::respond('error', $e->getMessage(), $voice);
        }
        admin_audit('voice.params', 'voice_profile', (int) $voice['id'], $params);

        return self::respond('success', input('reset') ? '합성 파라미터를 기본 권장값으로 되돌렸습니다.' : '합성 파라미터를 저장했습니다. 다음 합성부터 적용됩니다.', $voice);
    }

    /** POST /admin/voices/{id}/test : 테스트 재생(동기 합성) → ['ok', 'audio_url', 'duration_ms', 'ms'] */
    public function test(string $id): ?array
    {
        require_admin();
        $voice = self::voice((int) $id);
        $text = Request::str('text');
        try {
            $res = VoiceService::testSpeak((int) $voice['id'], $text);
        } catch (\RuntimeException $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($res['ok'])) {
            json_error(isset($res['error']) ? (string) $res['error'] : '음성 합성에 실패했습니다.', 422);

            return null;
        }
        admin_audit('voice.test', 'voice_profile', (int) $voice['id'], ['chars' => mb_strlen($text)]);

        return [
            'ok' => true,
            'audio_url' => $res['audio_url'],
            'duration_ms' => isset($res['duration_ms']) ? (int) $res['duration_ms'] : null,
            'ms' => isset($res['ms']) ? $res['ms'] : null,
            'message' => '테스트 음성을 만들었습니다.',
        ];
    }

    /** POST /admin/voices/{id}/memo : 회원 메모(users.admin_memo) */
    public function memo(string $id)
    {
        require_admin();
        $voice = self::voice((int) $id);
        $memo = trim((string) input('memo', ''));
        if (mb_strlen($memo) > 2000) {
            return self::respond('error', '메모는 2,000자 이하로 입력하세요.', $voice);
        }
        db_exec('UPDATE users SET admin_memo = ? WHERE id = ?', [$memo === '' ? null : $memo, (int) $voice['user_id']]);
        admin_audit('user.memo', 'user', (int) $voice['user_id'], ['voice_profile_id' => (int) $voice['id'], 'length' => mb_strlen($memo)]);

        return self::respond('success', '회원 메모를 저장했습니다.', $voice);
    }

    /** POST /admin/voices/approve-all : 품질 등급이 좋음, 보통인 대기 목소리를 모두 승인 */
    public function approveAll(): ?array
    {
        $admin = require_admin();
        if (!provider_ready('elevenlabs')) {
            return self::finish('error', 'ElevenLabs API 키가 등록되지 않아 승인할 수 없습니다.', '/admin/dashboard');
        }
        $ids = array_map('intval', array_column(db_all(
            "SELECT id FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL AND quality_grade IN ('good', 'fair')
              ORDER BY requested_at ASC, id ASC"
        ), 'id'));
        if (!$ids) {
            return self::finish('info', '일괄 승인할 대기 목소리가 없습니다. (품질 등급 좋음, 보통 기준)', '/admin/dashboard');
        }
        $ok = [];
        $failed = [];
        foreach ($ids as $vid) {
            try {
                VoiceService::approve($vid, (int) $admin['id']);
                $ok[] = $vid;
                admin_audit('voice.approve', 'voice_profile', $vid, ['bulk' => true]);
            } catch (\RuntimeException $e) {
                $failed[] = DashboardStats::reqId($vid) . ' ' . $e->getMessage();
            }
        }
        admin_audit('voice.approve_all', 'voice_profile', null, ['approved' => $ok, 'failed' => count($failed)]);
        if (!$ok) {
            return self::finish('error', '승인하지 못했습니다: ' . str_limit(implode(' / ', $failed), 200), '/admin/dashboard');
        }
        $msg = count($ok) . '건을 승인하고 목소리 생성을 시작했습니다.' . ($failed ? ' (' . count($failed) . '건 실패: ' . str_limit(implode(' / ', $failed), 120) . ')' : '');

        return self::finish($failed ? 'info' : 'success', $msg, '/admin/dashboard');
    }

    // ───────────────────────── JSON ─────────────────────────

    /** GET /admin/api/voices/{id}/logs?after=마지막id : 처리 콘솔 기록과 현재 상태 */
    public function logs(string $id): array
    {
        require_admin();
        $voice = db_one('SELECT id, status, batch_status, provider_voice_id FROM voice_profiles WHERE id = ? AND deleted_at IS NULL', [(int) $id]);
        if (!$voice) {
            abort(404, '목소리를 찾을 수 없습니다.');
        }
        $after = max(0, (int) Request::query('after', 0));
        $logs = Jobs::latestLogs('voice_profile', (int) $voice['id'], $after, 200);
        $progress = VoiceService::progress((int) $voice['id']);

        return [
            'ok' => true,
            'logs' => array_map(static function ($l) {
                return ['id' => $l['id'], 'level' => $l['level'], 'time' => $l['time'], 'message' => $l['message']];
            }, $logs),
            'status' => (string) $voice['status'],
            'status_label' => voice_status_label((string) $voice['status']),
            'batch_status' => (string) $voice['batch_status'],
            'voice_id' => DashboardStats::maskVoiceId($voice['provider_voice_id']),
            'progress' => $progress,
            'active' => in_array($voice['status'], ['cloning', 'processing'], true) || in_array($voice['batch_status'], ['queued', 'running'], true),
        ];
    }

    /**
     * GET /admin/api/voices/status?ids=1,2,3 : 화면에 떠 있는 목소리들의 지금 상태.
     * 목록과 상세 패널이 생성 중일 때 몇 초마다 불러, 상태가 바뀌면 화면을 새 상태로 바꾼다.
     */
    public function statuses(): array
    {
        require_admin();
        $raw = (string) Request::query('ids', '');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $raw))))), 0, 100);
        $items = [];
        if ($ids) {
            $rows = db_all(
                'SELECT id, status, batch_status, provider_voice_id, deleted_at FROM voice_profiles WHERE id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')',
                $ids
            );
            foreach ($rows as $r) {
                $items[(string) $r['id']] = [
                    'status' => $r['deleted_at'] !== null ? 'deleted' : (string) $r['status'],
                    'batch_status' => (string) $r['batch_status'],
                    'has_voice' => (string) $r['provider_voice_id'] !== '',
                    'active' => $r['deleted_at'] === null
                        && (in_array($r['status'], ['cloning', 'processing'], true) || in_array($r['batch_status'], ['queued', 'running'], true)),
                ];
            }
        }

        return ['ok' => true, 'items' => (object) $items];
    }

    /** GET /admin/api/voices/{id}/audios : 이 목소리로 만든 동화 오디오(캐시 파일) 목록 */
    public function audios(string $id): array
    {
        require_admin();
        $voice = self::voice((int) $id);
        $rows = db_all(
            'SELECT sa.id, sa.story_id, sa.status, sa.file_path, sa.duration_ms, sa.file_size, sa.content_hash, sa.error_message,
                    sa.generated_at, sa.updated_at, s.title, s.code, s.status AS story_status, s.content_hash AS story_hash, s.deleted_at AS story_deleted
               FROM story_audios sa JOIN stories s ON s.id = sa.story_id
              WHERE sa.voice_profile_id = ?
              ORDER BY s.sort_order, s.id',
            [(int) $voice['id']]
        );
        $items = [];
        $bytes = 0;
        foreach ($rows as $r) {
            $fresh = $r['status'] === 'completed' && ($r['story_hash'] === null || $r['content_hash'] === $r['story_hash']);
            $playable = $r['status'] === 'completed' && (string) $r['file_path'] !== '';
            $bytes += (int) $r['file_size'];
            $items[] = [
                'id' => (int) $r['id'],
                'story_id' => (int) $r['story_id'],
                'title' => (string) $r['title'],
                'code' => (string) $r['code'],
                'status' => (string) $r['status'],
                'status_label' => self::audioStatusLabel((string) $r['status']),
                'fresh' => $fresh,
                'published' => $r['story_status'] === 'published' && $r['story_deleted'] === null,
                'duration' => $r['duration_ms'] !== null ? fmt_duration((int) $r['duration_ms']) : '-',
                'size' => $r['file_size'] !== null ? number_format((int) $r['file_size'] / 1048576, 1) . 'MB' : '-',
                'generated_at' => $r['generated_at'] !== null ? date('Y-m-d H:i', strtotime((string) $r['generated_at'])) : null,
                'error' => $r['error_message'] !== null ? (string) $r['error_message'] : null,
                'url' => $playable ? url('/admin/media/story-audio/' . (int) $r['id']) : null,
            ];
        }

        return [
            'ok' => true,
            'name' => $voice['label'],
            'items' => $items,
            'summary' => count($items) . '개 파일 · ' . number_format($bytes / 1048576, 1) . 'MB',
        ];
    }

    // ───────────────────────── 내부 ─────────────────────────

    /** 목록 필터(검색어, 상태, 기간, 품질 등급) */
    public static function filters(): array
    {
        $status = (string) Request::query('status', '');
        $grade = (string) Request::query('grade', '');
        $from = (string) Request::query('from', '');
        $to = (string) Request::query('to', '');
        $isDate = static function ($d) {
            $dt = \DateTime::createFromFormat('Y-m-d', $d);

            return $dt && $dt->format('Y-m-d') === $d;
        };
        $q = Request::query('q', '');

        return [
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'from' => $isDate($from) ? $from : '',
            'to' => $isDate($to) ? $to : '',
            'grade' => in_array($grade, ['good', 'fair', 'poor'], true) ? $grade : '',
            // 방금 처리했거나 화면에 떠 있던 목소리: 상태 탭 조건과 관계없이 목록에 남긴다.
            'keep' => implode(',', keep_ids(is_string(Request::query('keep', '')) ? (string) Request::query('keep', '') : '')),
        ];
    }

    /** 필터를 WHERE 절과 값으로. 별칭 vp(voice_profiles), u(users) */
    public static function where(array $f): array
    {
        $where = ["vp.deleted_at IS NULL", "vp.status <> 'draft'"];
        $params = [];
        if ($f['status'] !== '') {
            $keep = isset($f['keep']) ? keep_ids((string) $f['keep']) : [];
            $where[] = $keep ? '(vp.status = ? OR vp.id IN (' . implode(', ', $keep) . '))' : 'vp.status = ?';
            $params[] = $f['status'];
        }
        if ($f['grade'] !== '') {
            $where[] = 'vp.quality_grade = ?';
            $params[] = $f['grade'];
        }
        if ($f['from'] !== '') {
            $where[] = 'vp.requested_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $where[] = 'vp.requested_at < ?';
            $params[] = date('Y-m-d 00:00:00', strtotime($f['to'] . ' +1 day'));
        }
        $q = $f['q'];
        if ($q !== '') {
            if (preg_match('/^(?:#|REQ-?|#REQ-?)0*(\d+)$/i', $q, $m)) {
                // 신청 번호(#REQ-0012, #12)
                $where[] = 'vp.id = ?';
                $params[] = (int) $m[1];
            } elseif (preg_match('/^[\d\-\s]+$/', $q)) {
                // 숫자만: 신청 번호 또는 연락처
                $digits = preg_replace('/\D/', '', $q);
                $where[] = "(vp.id = ? OR REPLACE(REPLACE(u.phone, '-', ''), ' ', '') LIKE ?)";
                $params[] = (int) $digits;
                $params[] = '%' . $digits . '%';
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(u.name LIKE ? OR u.email LIKE ? OR vp.label LIKE ? OR u.phone LIKE ?
                             OR EXISTS (SELECT 1 FROM children c WHERE c.user_id = u.id AND c.name LIKE ?))';
                array_push($params, $like, $like, $like, $like, $like);
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /** 폼 값에서 합성 파라미터를 꺼낸다(보낸 항목만) */
    private static function paramsFromInput(): array
    {
        $out = [];
        foreach (['stability', 'similarity_boost', 'style'] as $k) {
            $v = input($k);
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $out[$k] = max(0.0, min(1.0, round((float) $v, 2)));
            }
        }
        $sb = input('speaker_boost');
        if ($sb !== null && $sb !== '' && !is_array($sb)) {
            $out['speaker_boost'] = filter_var($sb, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        return $out;
    }

    private static function voice(int $id): array
    {
        $voice = db_one('SELECT * FROM voice_profiles WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$voice) {
            abort(404, '목소리를 찾을 수 없습니다.');
        }

        return $voice;
    }

    /** 동작 결과: fetch 요청이면 JSON, 폼 제출이면 알림을 남기고 직전 화면으로 */
    private static function respond(string $type, string $message, array $voice): ?array
    {
        return self::finish($type, $message, '/admin/voices/' . (int) $voice['id']);
    }

    private static function finish(string $type, string $message, string $fallback): ?array
    {
        if (Request::wantsJson()) {
            if ($type === 'error') {
                json_error($message, 422);

                return null;
            }

            return ['ok' => true, 'type' => $type, 'message' => $message];
        }
        flash($type, $message);
        // 목록에서 처리한 목소리는 상태가 바뀌어도 지금 탭에서 사라지지 않게 남긴다.
        if (preg_match('#^/admin/voices/(\d+)$#', $fallback, $m)) {
            redirect_back_keep([(int) $m[1]], '/admin/voices', $fallback);
        }
        redirect_back($fallback);

        return null;
    }

    private static function audioStatusLabel(string $status): string
    {
        $map = ['pending' => '대기', 'processing' => '생성 중', 'completed' => '완료', 'failed' => '실패'];

        return isset($map[$status]) ? $map[$status] : $status;
    }
}
