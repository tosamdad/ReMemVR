<?php
namespace App\Services;

use App\Core\HttpClient;
use App\Core\Text;

/**
 * ElevenLabs API(목소리 복제, 음성 합성, 구독 크레딧).
 * 모든 함수는 예외 대신 ['ok' => false, 'error' => 한글 메시지] 를 돌려준다.
 * config('providers_fake') 가 true 면 네트워크 없이 가짜 응답을 쓴다(FakeAudio).
 */
class ElevenLabs
{
    const BASE = 'https://api.elevenlabs.io/v1';
    /** 한 번에 합성할 최대 글자 수(API 한도 5,000자보다 여유 있게) */
    const MAX_CHUNK_CHARS = 4500;

    public static function ready(): bool
    {
        return provider_ready('elevenlabs');
    }

    private static function fake(): bool
    {
        return (bool) config('providers_fake');
    }

    /** API 주소. config 의 elevenlabs.base_url 로 바꿀 수 있다(로컬 모의 서버 점검용, 보통은 비워 둔다). */
    private static function base(): string
    {
        $url = (string) config('elevenlabs.base_url', '');

        return $url !== '' ? rtrim($url, '/') : self::BASE;
    }

    private static function headers(array $extra = []): array
    {
        return array_merge(['xi-api-key' => (string) config('elevenlabs.api_key', '')], $extra);
    }

    // ───────────────────────── 목소리 ─────────────────────────

    /**
     * 음성 샘플로 목소리를 복제한다(Instant Voice Cloning).
     * $files: [['path' => 절대 경로, 'name' => 파일 이름, 'mime' => MIME], ...]
     * $usage(선택): ['user_id', 'ref_type', 'ref_id'] 를 주면 사용 기록(voice_clone, 1회)을 남긴다.
     * @return array ['ok', 'voice_id', 'error', 'ms']
     */
    public static function addVoice(string $name, array $files, string $description = '', array $usage = []): array
    {
        $started = microtime(true);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 100) : '르멤버 목소리';
        $result = ['ok' => false, 'voice_id' => null, 'error' => null, 'ms' => 0];

        if (!self::ready()) {
            $result['error'] = 'ElevenLabs API 키가 등록되지 않았습니다.';

            return $result;
        }
        $parts = [['name' => 'name', 'contents' => $name]];
        $count = 0;
        foreach ($files as $f) {
            $path = isset($f['path']) ? (string) $f['path'] : '';
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $parts[] = [
                'name' => 'files',
                'filename' => isset($f['name']) && $f['name'] !== '' ? basename((string) $f['name']) : basename($path),
                'contents' => $bytes,
                'type' => isset($f['mime']) && $f['mime'] !== '' ? (string) $f['mime'] : 'application/octet-stream',
            ];
            $count++;
        }
        if ($count === 0) {
            $result['error'] = '목소리 생성에 쓸 음성 샘플 파일이 없습니다.';
            $result['ms'] = self::elapsed($started);

            return $result;
        }

        if (self::fake()) {
            $result['ok'] = true;
            $result['voice_id'] = 'fake_' . bin2hex(random_bytes(8));
            $result['ms'] = self::elapsed($started);
            self::logClone($usage, $result);

            return $result;
        }

        if ($description !== '') {
            $parts[] = ['name' => 'description', 'contents' => mb_substr($description, 0, 500)];
        }
        $parts[] = ['name' => 'remove_background_noise', 'contents' => setting('elevenlabs.remove_background_noise', true) ? 'true' : 'false'];

        $res = HttpClient::request('POST', self::base() . '/voices/add', [
            'headers' => self::headers(['Accept' => 'application/json']),
            'multipart' => $parts,
            'timeout' => 120,
        ]);
        $result['ms'] = self::elapsed($started);
        $data = HttpClient::json($res);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['voice_id'])) {
            $result['ok'] = true;
            $result['voice_id'] = (string) $data['voice_id'];
        } else {
            $result['error'] = self::errorMessage($res);
        }
        self::logClone($usage, $result);

        return $result;
    }

    private static function logClone(array $usage, array $result): void
    {
        if (!$usage) {
            return;
        }
        Usage::log([
            'provider' => 'elevenlabs',
            'purpose' => 'voice_clone',
            'model' => 'ivc',
            'user_id' => isset($usage['user_id']) ? $usage['user_id'] : null,
            'ref_type' => isset($usage['ref_type']) ? $usage['ref_type'] : null,
            'ref_id' => isset($usage['ref_id']) ? $usage['ref_id'] : null,
            'unit_type' => 'requests',
            'units' => 1,
            'cost_usd' => 0,
            'latency_ms' => $result['ms'],
            'success' => $result['ok'],
        ]);
    }

    /**
     * 복제한 목소리를 ElevenLabs 에서 지운다. 이미 없으면(404) 성공으로 본다.
     * @return array ['ok', 'error']
     */
    public static function deleteVoice(string $voiceId): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'error' => 'ElevenLabs API 키가 등록되지 않았습니다.'];
        }
        if (self::fake() || strpos($voiceId, 'fake_') === 0) {
            return ['ok' => true, 'error' => null];
        }
        if (!self::validVoiceId($voiceId)) {
            return ['ok' => false, 'error' => '목소리 ID 형식이 올바르지 않습니다.'];
        }
        $res = HttpClient::request('DELETE', self::base() . '/voices/' . rawurlencode($voiceId), [
            'headers' => self::headers(['Accept' => 'application/json']),
            'timeout' => 30,
        ]);
        if (($res['status'] >= 200 && $res['status'] < 300) || $res['status'] === 404) {
            return ['ok' => true, 'error' => null];
        }
        $data = HttpClient::json($res);
        if (self::detailStatus($data) === 'voice_not_found') {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => self::errorMessage($res)];
    }

    // ───────────────────────── 음성 합성 ─────────────────────────

    /**
     * 문장을 목소리로 읽는다.
     * $opts: model_id, voice_settings[stability, similarity_boost, style, use_speaker_boost], with_timestamps(bool),
     *        output_format, usage[purpose, user_id, ref_type, ref_id](주면 사용량 기록), chunk_chars(나눔 기준, 기본 4500),
     *        timeout(조각 하나의 제한 시간 초, 기본 180. 실시간 답변은 짧게)
     * @return array ['ok', 'audio', 'ext', 'mime', 'alignment' => ?['chars', 'starts', 'ends'](초), 'duration_ms', 'chars', 'error', 'ms']
     */
    public static function synthesize(string $voiceId, string $text, array $opts = []): array
    {
        $started = microtime(true);
        $text = trim($text);
        $modelId = !empty($opts['model_id']) ? (string) $opts['model_id'] : (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2');
        $format = self::normalizeFormat(!empty($opts['output_format']) ? (string) $opts['output_format'] : (string) setting('elevenlabs.output_format', 'mp3_44100_128'));
        $withTs = !empty($opts['with_timestamps']);
        $chars = mb_strlen($text);
        $result = [
            'ok' => false, 'audio' => '', 'ext' => 'mp3', 'mime' => 'audio/mpeg', 'alignment' => null,
            'duration_ms' => null, 'chars' => $chars, 'error' => null, 'ms' => 0,
        ];

        if (!self::ready()) {
            $result['error'] = 'ElevenLabs API 키가 등록되지 않았습니다.';

            return $result;
        }
        if ($text === '') {
            $result['error'] = '읽을 문장이 비어 있습니다.';

            return $result;
        }
        if (!self::fake() && !self::validVoiceId($voiceId)) {
            $result['error'] = '목소리 ID 형식이 올바르지 않습니다.';

            return $result;
        }

        $settings = self::voiceSettings(isset($opts['voice_settings']) && is_array($opts['voice_settings']) ? $opts['voice_settings'] : []);
        $max = isset($opts['chunk_chars']) ? max(50, (int) $opts['chunk_chars']) : self::MAX_CHUNK_CHARS;
        $timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 180;
        $chunks = self::splitText($text, $max);

        $audios = [];
        $alignment = $withTs ? ['chars' => [], 'starts' => [], 'ends' => []] : null;
        $offsetSec = 0.0;
        $ext = 'mp3';
        $error = null;
        foreach ($chunks as $ci => $chunk) {
            if (self::fake()) {
                $part = self::fakeChunk($chunk);
            } else {
                $ctx = [
                    'previous' => $ci > 0 ? mb_substr($chunks[$ci - 1], -300) : '',
                    'next' => isset($chunks[$ci + 1]) ? mb_substr($chunks[$ci + 1], 0, 300) : '',
                ];
                $part = self::requestChunk($voiceId, $chunk, $modelId, $format, $settings, $withTs, $ctx, $timeout);
            }
            if (!$part['ok']) {
                $error = $part['error'];
                break;
            }
            $ext = $part['ext'];
            $audios[] = $part['audio'];
            $partSec = $part['duration_ms'] !== null ? $part['duration_ms'] / 1000 : null;

            if ($alignment !== null) {
                $a = $part['alignment'];
                if (is_array($a) && !empty($a['chars'])) {
                    if ($ci > 0) {
                        // 나눈 자리의 공백 한 칸(시각은 이어지는 지점)
                        $alignment['chars'][] = ' ';
                        $alignment['starts'][] = round($offsetSec, 4);
                        $alignment['ends'][] = round($offsetSec, 4);
                    }
                    $n = min(count($a['chars']), count($a['starts']), count($a['ends']));
                    for ($i = 0; $i < $n; $i++) {
                        $alignment['chars'][] = (string) $a['chars'][$i];
                        $alignment['starts'][] = round($offsetSec + (float) $a['starts'][$i], 4);
                        $alignment['ends'][] = round($offsetSec + (float) $a['ends'][$i], 4);
                    }
                    if ($partSec === null && $n > 0) {
                        $partSec = (float) $a['ends'][$n - 1];
                    }
                } else {
                    // 한 조각이라도 정렬이 없으면 전체 정렬을 믿을 수 없다(호출한 쪽에서 추정한다).
                    $alignment = null;
                }
            }
            $offsetSec += $partSec !== null ? $partSec : 0.0;
        }

        $result['ms'] = self::elapsed($started);
        if ($error === null) {
            $audio = $ext === 'wav' ? FakeAudio::concatWav($audios) : self::concatMp3($audios);
            $duration = $ext === 'wav' ? FakeAudio::wavDurationMs($audio) : self::mp3DurationMs($audio);
            if ($duration === null && $alignment !== null && $alignment['ends']) {
                $duration = (int) round(end($alignment['ends']) * 1000);
            }
            if ($audio === '') {
                $error = '합성된 음성이 비어 있습니다.';
            } else {
                $result['ok'] = true;
                $result['audio'] = $audio;
                $result['ext'] = $ext;
                $result['mime'] = $ext === 'wav' ? 'audio/wav' : 'audio/mpeg';
                $result['alignment'] = $alignment;
                $result['duration_ms'] = $duration;
            }
        }
        $result['error'] = $error;

        if (!empty($opts['usage']) && is_array($opts['usage'])) {
            $u = $opts['usage'];
            Usage::log([
                'provider' => 'elevenlabs',
                'purpose' => isset($u['purpose']) ? $u['purpose'] : 'story_tts',
                'model' => $modelId,
                'user_id' => isset($u['user_id']) ? $u['user_id'] : null,
                'ref_type' => isset($u['ref_type']) ? $u['ref_type'] : null,
                'ref_id' => isset($u['ref_id']) ? $u['ref_id'] : null,
                'unit_type' => 'chars',
                // 실패한 합성은 과금되지 않으므로 0 으로 남긴다.
                'units' => $result['ok'] ? $chars : 0,
                'cost_usd' => $result['ok'] ? Usage::elevenlabsCostUsd($chars, $modelId) : 0,
                'latency_ms' => $result['ms'],
                'success' => $result['ok'],
            ]);
        }

        return $result;
    }

    /** API 한 번 호출(최대 MAX_CHUNK_CHARS 글자) */
    private static function requestChunk(string $voiceId, string $text, string $modelId, string $format, array $settings, bool $withTs, array $ctx, int $timeout = 180): array
    {
        $out = ['ok' => false, 'audio' => '', 'ext' => 'mp3', 'alignment' => null, 'duration_ms' => null, 'error' => null];
        $body = ['text' => $text, 'model_id' => $modelId, 'voice_settings' => $settings];
        $m = strtolower($modelId);
        if (strpos($m, 'flash') !== false || strpos($m, 'turbo') !== false || strpos($m, 'v2_5') !== false || strpos($m, 'v3') !== false) {
            $body['language_code'] = 'ko';
        }
        // 나눠 읽을 때 앞뒤 문맥을 주면 이어지는 억양이 자연스럽다(v3 는 지원하지 않음).
        if (strpos($m, 'v3') === false) {
            if ($ctx['previous'] !== '') {
                $body['previous_text'] = $ctx['previous'];
            }
            if ($ctx['next'] !== '') {
                $body['next_text'] = $ctx['next'];
            }
        }
        $url = self::base() . '/text-to-speech/' . rawurlencode($voiceId) . ($withTs ? '/with-timestamps' : '') . '?output_format=' . rawurlencode($format);
        $res = HttpClient::request('POST', $url, [
            'headers' => self::headers(['Accept' => $withTs ? 'application/json' : '*/*']),
            'json' => $body,
            'timeout' => $timeout,
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $out['error'] = self::errorMessage($res);

            return $out;
        }
        if ($withTs) {
            $data = HttpClient::json($res);
            $audio = isset($data['audio_base64']) ? base64_decode((string) $data['audio_base64'], true) : false;
            if ($audio === false || $audio === '') {
                $out['error'] = 'ElevenLabs 응답에 음성이 없습니다.';

                return $out;
            }
            $a = isset($data['alignment']) && is_array($data['alignment']) ? $data['alignment'] : (isset($data['normalized_alignment']) && is_array($data['normalized_alignment']) ? $data['normalized_alignment'] : null);
            if ($a && isset($a['characters'], $a['character_start_times_seconds'], $a['character_end_times_seconds'])) {
                $out['alignment'] = [
                    'chars' => array_values($a['characters']),
                    'starts' => array_map('floatval', array_values($a['character_start_times_seconds'])),
                    'ends' => array_map('floatval', array_values($a['character_end_times_seconds'])),
                ];
            }
        } else {
            $audio = (string) $res['body'];
            if ($audio === '') {
                $out['error'] = 'ElevenLabs 응답에 음성이 없습니다.';

                return $out;
            }
        }
        if (strpos($format, 'pcm_') === 0) {
            $rate = (int) substr($format, 4);
            $audio = FakeAudio::pcmToWav($audio, $rate > 0 ? $rate : 16000);
            $out['ext'] = 'wav';
            $out['duration_ms'] = FakeAudio::wavDurationMs($audio);
        } elseif (strpos($format, 'wav') === 0) {
            $out['ext'] = 'wav';
            $out['duration_ms'] = FakeAudio::wavDurationMs($audio);
        } else {
            $out['duration_ms'] = self::mp3DurationMs($audio);
        }
        $out['ok'] = true;
        $out['audio'] = $audio;

        return $out;
    }

    /** 가짜 합성(개발 모드) */
    private static function fakeChunk(string $text): array
    {
        $s = FakeAudio::speech($text);

        return ['ok' => true, 'audio' => $s['audio'], 'ext' => 'wav', 'alignment' => $s['alignment'], 'duration_ms' => $s['duration_ms'], 'error' => null];
    }

    /** 목소리 파라미터. 빈 값은 설정 기본값으로 채우고 0~1 범위로 자른다. */
    public static function voiceSettings(array $given = []): array
    {
        $pick = static function ($key, $settingKey, $default) use ($given) {
            $v = isset($given[$key]) && $given[$key] !== '' ? $given[$key] : setting($settingKey, $default);

            return round(min(1.0, max(0.0, (float) $v)), 2);
        };
        $boost = array_key_exists('use_speaker_boost', $given) && $given['use_speaker_boost'] !== null && $given['use_speaker_boost'] !== ''
            ? (bool) $given['use_speaker_boost']
            : (bool) setting('elevenlabs.speaker_boost', true);

        return [
            'stability' => $pick('stability', 'elevenlabs.default_stability', 0.65),
            'similarity_boost' => $pick('similarity_boost', 'elevenlabs.default_similarity', 0.80),
            'style' => $pick('style', 'elevenlabs.default_style', 0.0),
            'use_speaker_boost' => $boost,
        ];
    }

    /** 지원하는 출력 형식만 쓴다(mp3_*, pcm_*, wav_*). 나머지는 mp3_44100_128 */
    private static function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (preg_match('/^(mp3_\d+_\d+|pcm_\d+|wav_\d+)$/', $format)) {
            return $format;
        }

        return 'mp3_44100_128';
    }

    /**
     * 긴 본문을 문장 경계에서 $max 글자 이하 조각으로 나눈다.
     * 문장 끝(. ! ? … ~ 와 닫는 따옴표 뒤 공백)을 우선, 없으면 공백, 그래도 없으면 글자 수로 자른다.
     */
    public static function splitText(string $text, int $max = self::MAX_CHUNK_CHARS): array
    {
        $text = trim($text);
        $chunks = [];
        while (mb_strlen($text) > $max) {
            $head = mb_substr($text, 0, $max + 1);
            $cut = 0;
            if (preg_match_all('/[.!?…~。？！]+["\'”’」』)]*(?=\s)/u', $head, $m, PREG_OFFSET_CAPTURE)) {
                $last = end($m[0]);
                $cut = mb_strlen(substr($head, 0, $last[1] + strlen($last[0])));
            }
            if ($cut < (int) ($max / 3)) {
                $space = 0;
                if (preg_match_all('/\s/u', $head, $m2, PREG_OFFSET_CAPTURE)) {
                    $lastSpace = end($m2[0]);
                    $space = mb_strlen(substr($head, 0, $lastSpace[1]));
                }
                $cut = $space >= (int) ($max / 3) ? $space : $max;
            }
            $cut = min($cut, $max);
            $piece = trim(mb_substr($text, 0, $cut));
            if ($piece !== '') {
                $chunks[] = $piece;
            }
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }

    // ───────────────────────── 크레딧 ─────────────────────────

    /**
     * 구독 사용량(글자 크레딧).
     * @return array ['ok', 'used', 'limit', 'reset_at'(Y-m-d H:i:s), 'tier', 'error', 'ms']
     */
    public static function subscription(): array
    {
        $started = microtime(true);
        $out = ['ok' => false, 'used' => 0, 'limit' => 0, 'reset_at' => null, 'tier' => null, 'error' => null, 'ms' => 0];
        if (!self::ready()) {
            $out['error'] = 'ElevenLabs API 키가 등록되지 않았습니다.';

            return $out;
        }
        if (self::fake()) {
            $out['ok'] = true;
            $out['used'] = 12000;
            $out['limit'] = 100000;
            $out['reset_at'] = date('Y-m-01 00:00:00', strtotime('first day of next month'));
            $out['tier'] = 'fake';
            $out['ms'] = self::elapsed($started);

            return $out;
        }
        $res = HttpClient::request('GET', self::base() . '/user/subscription', [
            'headers' => self::headers(['Accept' => 'application/json']),
            'timeout' => 20,
        ]);
        $out['ms'] = self::elapsed($started);
        $data = HttpClient::json($res);
        if ($res['status'] !== 200 || !isset($data['character_limit'])) {
            $out['error'] = self::errorMessage($res);

            return $out;
        }
        $out['ok'] = true;
        $out['used'] = (int) (isset($data['character_count']) ? $data['character_count'] : 0);
        $out['limit'] = (int) $data['character_limit'];
        $out['reset_at'] = !empty($data['next_character_count_reset_unix']) ? date('Y-m-d H:i:s', (int) $data['next_character_count_reset_unix']) : null;
        $out['tier'] = isset($data['tier']) ? (string) $data['tier'] : null;

        return $out;
    }

    /**
     * 구독 사용량을 읽어 잔여 크레딧 기록(provider_credit_snapshots)을 남긴다.
     * internal_estimate: 직전 기록의 잔량에서 그 뒤 우리 DB 에 기록된 사용 크레딧을 뺀 값(비교용).
     * @return array subscription() 결과 + 'remaining', 'internal_estimate'
     */
    public static function syncCredits(): array
    {
        $sub = self::subscription();
        if (!$sub['ok']) {
            return $sub;
        }
        $remaining = max(0, $sub['limit'] - $sub['used']);
        $estimate = null;
        try {
            $prev = db_one("SELECT remaining_units, checked_at FROM provider_credit_snapshots WHERE provider = 'elevenlabs' ORDER BY checked_at DESC, id DESC LIMIT 1");
            if ($prev) {
                // 직전 기록이 지난 충전 주기라면 비교할 수 없다.
                $periodStart = $sub['reset_at'] ? strtotime('-1 month', strtotime($sub['reset_at'])) : null;
                if ($periodStart === null || strtotime($prev['checked_at']) >= $periodStart) {
                    $used = (float) db_value(
                        "SELECT COALESCE(SUM(CASE WHEN model LIKE '%flash%' OR model LIKE '%turbo%' THEN units * ? ELSE units END), 0)
                           FROM api_usage_logs
                          WHERE provider = 'elevenlabs' AND unit_type = 'chars' AND success = 1 AND created_at >= ?",
                        [(string) (float) setting('elevenlabs.flash_credit_ratio', 0.5), $prev['checked_at']]
                    );
                    $estimate = (int) round((int) $prev['remaining_units'] - $used);
                }
            }
            db_insert('provider_credit_snapshots', [
                'provider' => 'elevenlabs',
                'remaining_units' => $remaining,
                'limit_units' => $sub['limit'],
                'internal_estimate' => $estimate,
                'checked_at' => now(),
            ]);
        } catch (\Throwable $e) {
            app_log('error', '크레딧 기록 실패: ' . $e->getMessage());
        }
        $sub['remaining'] = $remaining;
        $sub['internal_estimate'] = $estimate;

        return $sub;
    }

    // ───────────────────────── MP3 도구 ─────────────────────────

    /** MP3 프레임 헤더를 읽어 재생 시간(ms)을 계산한다. MP3 가 아니면 null */
    public static function mp3DurationMs(string $bytes): ?int
    {
        $len = strlen($bytes);
        $pos = self::id3Size($bytes);
        $samples = 0;
        $rate = 0;
        $first = true;
        $brV1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
        $brV2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
        $rates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
        while ($pos + 4 <= $len) {
            $b0 = ord($bytes[$pos]);
            $b1 = ord($bytes[$pos + 1]);
            if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
                $pos++;
                continue;
            }
            $b2 = ord($bytes[$pos + 2]);
            $ver = ($b1 >> 3) & 3;
            $layer = ($b1 >> 1) & 3;
            $brIdx = ($b2 >> 4) & 15;
            $srIdx = ($b2 >> 2) & 3;
            if ($ver === 1 || $layer !== 1 || $brIdx === 0 || $brIdx === 15 || $srIdx === 3) {
                $pos++;
                continue;
            }
            $sr = $rates[$ver][$srIdx];
            $br = ($ver === 3 ? $brV1[$brIdx] : $brV2[$brIdx]) * 1000;
            $spf = $ver === 3 ? 1152 : 576;
            $frameLen = (int) floor($spf / 8 * $br / $sr) + (($b2 >> 1) & 1);
            if ($frameLen < 4) {
                $pos++;
                continue;
            }
            // 첫 프레임이 Xing/Info(길이 정보) 프레임이면 소리가 없으므로 세지 않는다.
            $isInfo = $first && (strpos(substr($bytes, $pos + 4, 40), 'Xing') !== false || strpos(substr($bytes, $pos + 4, 40), 'Info') !== false);
            if (!$isInfo) {
                $samples += $spf;
            }
            $rate = $sr;
            $first = false;
            $pos += $frameLen;
        }

        return $rate > 0 ? (int) round($samples / $rate * 1000) : null;
    }

    /** 앞에 붙은 ID3v2 태그 길이 */
    private static function id3Size(string $bytes): int
    {
        if (strlen($bytes) >= 10 && substr($bytes, 0, 3) === 'ID3') {
            $size = ((ord($bytes[6]) & 0x7f) << 21) | ((ord($bytes[7]) & 0x7f) << 14) | ((ord($bytes[8]) & 0x7f) << 7) | (ord($bytes[9]) & 0x7f);

            return 10 + $size + ((ord($bytes[5]) & 0x10) ? 10 : 0);
        }

        return 0;
    }

    /** MP3 조각을 잇는다. 두 번째 조각부터 앞의 ID3 태그와 Xing/Info 프레임은 뗀다(중간에 소리 없는 프레임이 끼지 않게). */
    public static function concatMp3(array $parts): string
    {
        $out = '';
        foreach (array_values($parts) as $i => $p) {
            $p = (string) $p;
            if ($i > 0) {
                $p = substr($p, self::id3Size($p));
                $p = substr($p, self::infoFrameLength($p));
            }
            $out .= $p;
        }

        return $out;
    }

    /** 맨 앞 프레임이 Xing/Info 프레임이면 그 길이, 아니면 0 */
    private static function infoFrameLength(string $bytes): int
    {
        if (strlen($bytes) < 48 || ord($bytes[0]) !== 0xFF || (ord($bytes[1]) & 0xE0) !== 0xE0) {
            return 0;
        }
        $head = substr($bytes, 4, 40);
        if (strpos($head, 'Xing') === false && strpos($head, 'Info') === false) {
            return 0;
        }
        $b1 = ord($bytes[1]);
        $b2 = ord($bytes[2]);
        $ver = ($b1 >> 3) & 3;
        $brIdx = ($b2 >> 4) & 15;
        $srIdx = ($b2 >> 2) & 3;
        if ($ver === 1 || (($b1 >> 1) & 3) !== 1 || $brIdx === 0 || $brIdx === 15 || $srIdx === 3) {
            return 0;
        }
        $brV1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
        $brV2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
        $rates = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
        $spf = $ver === 3 ? 1152 : 576;
        $len = (int) floor($spf / 8 * ($ver === 3 ? $brV1[$brIdx] : $brV2[$brIdx]) * 1000 / $rates[$ver][$srIdx]) + (($b2 >> 1) & 1);

        return $len <= strlen($bytes) ? $len : 0;
    }

    // ───────────────────────── 오류 ─────────────────────────

    private static function detailStatus(array $data): ?string
    {
        if (isset($data['detail']) && is_array($data['detail']) && isset($data['detail']['status'])) {
            return (string) $data['detail']['status'];
        }

        return null;
    }

    /** API 오류를 관리자가 이해할 수 있는 한글 메시지로 바꾼다(API 키는 절대 넣지 않는다). */
    public static function errorMessage(array $res): string
    {
        $status = (int) $res['status'];
        if ($status === 0) {
            return 'ElevenLabs 서버에 연결하지 못했습니다(네트워크 오류' . (!empty($res['error']) ? ': ' . str_limit((string) $res['error'], 80) : '') . ').';
        }
        $data = HttpClient::json($res);
        $code = self::detailStatus($data);
        $detail = '';
        if (isset($data['detail'])) {
            if (is_array($data['detail']) && isset($data['detail']['message'])) {
                $detail = (string) $data['detail']['message'];
            } elseif (is_string($data['detail'])) {
                $detail = $data['detail'];
            } elseif (is_array($data['detail']) && isset($data['detail'][0]['msg'])) {
                $detail = (string) $data['detail'][0]['msg'];
            }
        }
        $detail = str_limit(trim($detail), 120);
        $map = [
            'quota_exceeded' => 'ElevenLabs 크레딧이 부족합니다. 요금제나 남은 크레딧을 확인해 주세요.',
            'voice_not_found' => 'ElevenLabs 에서 목소리를 찾을 수 없습니다. 목소리를 다시 생성해야 합니다.',
            'invalid_api_key' => 'ElevenLabs API 키가 올바르지 않습니다.',
            'too_many_concurrent_requests' => 'ElevenLabs 동시 요청 한도를 넘었습니다. 잠시 후 다시 시도합니다.',
            'system_busy' => 'ElevenLabs 서버가 바쁩니다. 잠시 후 다시 시도합니다.',
            'missing_permissions' => 'ElevenLabs API 키에 필요한 권한이 없습니다(키 권한 설정 확인).',
            'voice_limit_reached' => 'ElevenLabs 계정의 목소리 개수 한도에 도달했습니다. 쓰지 않는 목소리를 정리해 주세요.',
            'max_character_limit_exceeded' => '한 번에 합성할 수 있는 글자 수를 넘었습니다.',
            'detected_unusual_activity' => 'ElevenLabs 가 비정상 사용을 감지해 요청을 막았습니다. 계정 상태를 확인해 주세요.',
        ];
        if ($code !== null && isset($map[$code])) {
            return $map[$code];
        }
        if ($status === 401) {
            return 'ElevenLabs API 키가 올바르지 않습니다.';
        }
        if ($status === 429) {
            return 'ElevenLabs 요청 한도 또는 크레딧 한도를 넘었습니다. 잠시 후 다시 시도합니다.';
        }
        if ($status === 404) {
            return 'ElevenLabs 에서 목소리를 찾을 수 없습니다.';
        }
        if ($status === 413) {
            return 'ElevenLabs 로 보낸 파일이 너무 큽니다. 샘플 길이나 개수를 줄여 주세요.';
        }
        if ($status === 400 || $status === 422) {
            return 'ElevenLabs 요청이 거절되었습니다' . ($detail !== '' ? ': ' . $detail : '.');
        }
        if ($status >= 500) {
            return 'ElevenLabs 서버 오류(' . $status . ')입니다. 잠시 후 다시 시도합니다.';
        }

        return 'ElevenLabs 오류(' . $status . ')' . ($detail !== '' ? ': ' . $detail : '');
    }

    private static function validVoiceId(string $voiceId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{4,64}$/', $voiceId);
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
