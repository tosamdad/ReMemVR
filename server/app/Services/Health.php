<?php
namespace App\Services;

use App\Core\Settings;

/**
 * 운영 상태 점검(관리자 대시보드의 헬스체크).
 * 외부 API 호출이 있으므로 결과를 settings 'health.cache' 에 5분 동안 보관한다.
 */
class Health
{
    const CACHE_KEY = 'health.cache';
    const CACHE_SECONDS = 300;
    /** 잔여 크레딧 기록(provider_credit_snapshots)을 새로 남기는 최소 간격 */
    const SNAPSHOT_EVERY_SECONDS = 43200;

    /**
     * @return array [
     *   'gemini' => [ok, ms, message, model],
     *   'elevenlabs' => [ok, ms, message, model_story, model_answer, credits => ?[used, limit, remaining, reset_at, tier]],
     *   'storage' => [ok, writable, free_mb, message],
     *   'db' => [ok, ms, message],
     *   'worker' => [pending, running, failed_24h, last_done_at, stale],
     *   'fake' => bool, 'checked_at' => 'Y-m-d H:i:s', 'cached' => bool
     * ]
     */
    public static function status(bool $fresh = false): array
    {
        $sig = self::signature();
        if (!$fresh) {
            $cached = setting(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['ts'], $cached['data']) && is_array($cached['data'])
                && time() - (int) $cached['ts'] < self::CACHE_SECONDS && (isset($cached['sig']) ? $cached['sig'] : '') === $sig) {
                $data = $cached['data'];
                $data['cached'] = true;
                // 작업 대기열은 DB 만 읽으므로 항상 새로 센다.
                $data['worker'] = self::worker();

                return $data;
            }
        }

        $data = [
            'gemini' => self::gemini(),
            'elevenlabs' => self::elevenlabs(),
            'storage' => self::storage(),
            'db' => self::db(),
            'worker' => self::worker(),
            'fake' => (bool) config('providers_fake'),
            'checked_at' => now(),
            'cached' => false,
        ];
        try {
            Settings::set(self::CACHE_KEY, ['ts' => time(), 'sig' => $sig, 'data' => $data]);
        } catch (\Throwable $e) {
            app_log('error', '상태 점검 결과 저장 실패: ' . $e->getMessage());
        }

        return $data;
    }

    /** 점검 결과를 바꾸는 설정(키 등록 여부, 개발 모드, 모델)이 달라지면 보관한 결과를 쓰지 않는다. 키 값 자체는 넣지 않는다. */
    private static function signature(): string
    {
        return md5(json_encode([
            (bool) config('providers_fake'), qa_available(), Gemini::ready(), ElevenLabs::ready(), Gemini::model(),
            (string) config('gemini.base_url', ''), (string) config('elevenlabs.base_url', ''),
        ]));
    }

    /** 전체가 정상인지(대시보드 상단 표시용) */
    public static function allOk(array $status): bool
    {
        foreach (['gemini', 'elevenlabs', 'storage', 'db'] as $k) {
            if (empty($status[$k]['ok'])) {
                return false;
            }
        }

        return true;
    }

    private static function gemini(): array
    {
        if (!qa_available()) {
            // 아이 질문 기능 보류 중에는 Gemini 를 부르지 않는다(약관상 18세 미만 대상 서비스 사용 금지).
            return ['ok' => true, 'skipped' => true, 'ms' => null, 'message' => '질문 기능 보류 중이라 쓰지 않음', 'model' => Gemini::model()];
        }
        $out = ['ok' => false, 'ms' => null, 'message' => 'API 키 미등록', 'model' => Gemini::model()];
        if (!Gemini::ready()) {
            return $out;
        }
        try {
            $r = Gemini::ping();
            $out['ok'] = (bool) $r['ok'];
            $out['ms'] = (int) $r['ms'];
            $out['message'] = $r['ok'] ? (config('providers_fake') ? '개발 모드(가짜 응답)' : '정상') : (string) $r['error'];
        } catch (\Throwable $e) {
            $out['message'] = '점검 실패: ' . str_limit($e->getMessage(), 80);
        }

        return $out;
    }

    private static function elevenlabs(): array
    {
        $out = [
            'ok' => false, 'ms' => null, 'message' => 'API 키 미등록',
            'model_story' => (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2'),
            'model_answer' => (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5'),
            'credits' => null,
        ];
        if (!ElevenLabs::ready()) {
            return $out;
        }
        try {
            // 마지막 크레딧 기록이 오래되었으면 이번 점검에서 기록도 남긴다(cron 이 없으므로).
            $last = db_value("SELECT MAX(checked_at) FROM provider_credit_snapshots WHERE provider = 'elevenlabs'");
            $needSnapshot = !$last || time() - strtotime((string) $last) >= self::SNAPSHOT_EVERY_SECONDS;
            $r = $needSnapshot ? ElevenLabs::syncCredits() : ElevenLabs::subscription();
            $out['ok'] = (bool) $r['ok'];
            $out['ms'] = (int) $r['ms'];
            if ($r['ok']) {
                $out['message'] = config('providers_fake') ? '개발 모드(가짜 응답)' : '정상';
                $out['credits'] = [
                    'used' => (int) $r['used'],
                    'limit' => (int) $r['limit'],
                    'remaining' => max(0, (int) $r['limit'] - (int) $r['used']),
                    'reset_at' => $r['reset_at'],
                    'tier' => $r['tier'],
                ];
            } else {
                $out['message'] = (string) $r['error'];
            }
        } catch (\Throwable $e) {
            $out['message'] = '점검 실패: ' . str_limit($e->getMessage(), 80);
        }

        return $out;
    }

    private static function storage(): array
    {
        $out = ['ok' => false, 'writable' => false, 'free_mb' => null, 'message' => ''];
        try {
            $dir = storage_path();
            $probe = $dir . '/.health-' . bin2hex(random_bytes(4));
            $out['writable'] = is_dir($dir) && @file_put_contents($probe, 'ok') === 2;
            @unlink($probe);
            if (function_exists('disk_free_space')) {
                $free = @disk_free_space($dir);
                if ($free !== false) {
                    $out['free_mb'] = (int) floor($free / 1048576);
                }
            }
            $out['ok'] = $out['writable'] && ($out['free_mb'] === null || $out['free_mb'] >= 200);
            if (!$out['writable']) {
                $out['message'] = '저장 폴더에 쓸 수 없습니다.';
            } elseif ($out['free_mb'] !== null && $out['free_mb'] < 200) {
                $out['message'] = '남은 공간이 부족합니다(' . number_format($out['free_mb']) . 'MB).';
            } else {
                $out['message'] = '정상';
            }
        } catch (\Throwable $e) {
            $out['message'] = '점검 실패: ' . str_limit($e->getMessage(), 80);
        }

        return $out;
    }

    private static function db(): array
    {
        $started = microtime(true);
        try {
            db_value('SELECT 1');

            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000), 'message' => '정상'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => null, 'message' => 'DB 연결 실패'];
        }
    }

    /** 작업 대기열(jobs 테이블을 직접 읽는다) */
    private static function worker(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'failed_24h' => 0, 'last_done_at' => null, 'stale' => 0];
        try {
            $row = db_one(
                "SELECT SUM(status = 'pending') AS pending,
                        SUM(status = 'running') AS running,
                        SUM(status = 'failed' AND updated_at >= ?) AS failed_24h,
                        SUM(status = 'running' AND locked_at < ?) AS stale,
                        MAX(CASE WHEN status = 'done' THEN finished_at END) AS last_done_at
                   FROM jobs",
                [date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 600)]
            );
            if ($row) {
                $out['pending'] = (int) $row['pending'];
                $out['running'] = (int) $row['running'];
                $out['failed_24h'] = (int) $row['failed_24h'];
                $out['stale'] = (int) $row['stale'];
                $out['last_done_at'] = $row['last_done_at'] ?: null;
            }
        } catch (\Throwable $e) {
            app_log('error', '작업 대기열 점검 실패: ' . $e->getMessage());
        }

        return $out;
    }
}
