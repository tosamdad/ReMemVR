<?php
namespace App\Controllers\Admin;

use App\Services\DashboardStats;

/**
 * 서비스 오퍼레이션 대시보드(/admin/dashboard).
 * 화면을 처음 그릴 때와 30초마다 부르는 /admin/api/dashboard 가 같은 집계(DashboardStats)와 같은 서식(stats)을 쓴다.
 */
class DashboardController
{
    /** GET /admin/dashboard */
    public function index(): string
    {
        require_admin();
        $d = DashboardStats::collect();

        return view('admin/dashboard/index', [
            'd' => $d,
            's' => self::stats($d),
            'w' => self::widths($d),
        ]);
    }

    /**
     * GET /admin/api/dashboard
     * stats: data-stat 요소에 넣을 글자, widths: data-stat-width 막대 너비(%), html: 목록 영역을 다시 그린 조각, data: 원래 숫자
     */
    public function api(): array
    {
        require_admin();
        // 목록 조각 안의 폼에 CSRF 토큰이 들어가야 하므로 세션은 열어 둔다.
        $d = DashboardStats::collect();

        return [
            'ok' => true,
            'stats' => self::stats($d),
            'widths' => self::widths($d),
            'html' => [
                'queue' => partial('admin/dashboard/_queue', ['d' => $d]),
                'latency' => partial('admin/dashboard/_latency', ['d' => $d]),
                'batch' => partial('admin/dashboard/_batch', ['d' => $d]),
                'health' => partial('admin/dashboard/_health', ['d' => $d]),
            ],
            'data' => [
                'voices' => $d['voices'],
                'plays' => $d['plays'],
                'interactions' => $d['interactions'],
                'cost' => $d['cost'],
                'pending_total' => $d['queue']['total'],
                'batch_total' => $d['batch']['total'],
                'latency_avg_ms' => $d['latency']['avg_ms'],
                'health_ok' => $d['health']['all_ok'],
                'approvable' => $d['approvable'],
            ],
            'generated_at' => $d['generated_at'],
        ];
    }

    /** 화면에 그대로 넣는 글자(data-stat 키 → 문자열) */
    public static function stats(array $d): array
    {
        $v = $d['voices'];
        $p = $d['plays'];
        $i = $d['interactions'];
        $c = $d['cost'];
        $lat = $d['latency'];

        return [
            'voices.pending' => fmt_number($v['pending']),
            'voices.in_progress' => fmt_number($v['in_progress']) . '건',
            'voices.approved_today' => fmt_number($v['approved_today']) . '건',
            'plays.total' => fmt_number($p['total']),
            'plays.voice_share' => $p['voice_share'] === null ? '재생 기록 없음' : '사전 생성 음성 재생 ' . $p['voice_share'] . '%',
            'interactions.total' => fmt_number($i['total']),
            'interactions.per_story' => $i['per_story'] === null ? '-' : fmt_number($i['per_story'], 1) . '회',
            'interactions.limit' => $i['qa_enabled'] ? '최대 ' . $i['max_questions'] . '회 제한 정상 가동' : '질문 기능 보류 중',
            'cost.today' => fmt_krw($c['today']),
            'cost.percent' => $c['percent'] === null ? '한도 미설정' : $c['percent'] . '% 소진',
            'cost.per_interaction' => $c['per_interaction'] === null ? '-' : '₩' . fmt_number($c['per_interaction'], 1),
            'cost.budget' => $c['budget'] > 0 ? '(' . fmt_krw($c['budget']) . ')' : '(미설정)',
            'queue.total' => fmt_number($d['queue']['total']) . '건 검토 필요',
            'latency.avg' => $lat['avg_ms'] === null ? '오늘 답변 없음' : DashboardStats::koLatency($lat['avg_ms']),
            'approvable' => fmt_number($d['approvable']),
            'generated_at' => date('H:i:s', strtotime($d['generated_at'])),
        ];
    }

    /** 막대 너비(%) */
    public static function widths(array $d): array
    {
        $pct = $d['cost']['percent'];

        return [
            'cost.percent' => $pct === null ? 0 : max(0, min(100, (int) $pct)),
        ];
    }
}
