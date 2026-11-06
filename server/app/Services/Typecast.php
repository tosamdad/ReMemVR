<?php
namespace App\Services;

use App\Core\HttpClient;
use App\Core\Storage;

/**
 * Typecast(타입캐스트) TTS API 어댑터 — ElevenLabs 와 같은 모양의 함수를 돌려준다.
 * 문서: https://typecast.ai/docs (2026-11 확인)
 *   목소리 즉시 복제: POST /v1/custom-voices/instant-clone  (WAV/MP3 하나, 5~150초, 25MiB 이하)
 *   음성 합성:        POST /v1/text-to-speech               (바이너리 오디오)
 *   합성+타임스탬프:  POST /v1/text-to-speech/with-timestamps (JSON, base64 오디오 + 글자별 시각)
 *   목소리 삭제:      DELETE /v1/custom-voices/{voice_id}    (uc_ 목소리만, 슬롯을 비운다)
 *   구독:            GET  /v1/users/me/subscription
 * 인증 헤더는 X-API-KEY 다(ElevenLabs 의 xi-api-key 와 다르다).
 * 모든 함수는 예외 대신 ElevenLabs 처럼 ['ok' => false, 'error' => 한글 메시지] 를 돌려준다.
 * TODO: API 키 발급 후 실제 응답과 오류 형식을 확인해 보완할 부분은 코드에 TODO 로 남겼다.
 */
class Typecast
{
    const BASE = 'https://api.typecast.ai';
    /** 한 번에 합성할 최대 글자 수(API 한도 2,000자보다 여유 있게) */
    const MAX_CHUNK_CHARS = 1800;

    public static function ready(): bool
    {
        return provider_ready('typecast');
    }

    private static function fake(): bool
    {
        return (bool) config('providers_fake');
    }

    private static function headers(array $extra = []): array
    {
        return array_merge(['X-API-KEY' => (string) config('typecast.api_key', '')], $extra);
    }

    // ───────────────────────── 목소리 ─────────────────────────

    /**
     * 음성 샘플로 목소리를 복제한다(Instant Cloning).
     * Typecast 는 샘플 파일 하나만 받으므로 $files 중 WAV/MP3 중 가장 큰(길쭉한) 파일 하나를 올린다.
     * TODO: ElevenLabs 처럼 여러 샘플을 모두 반영하려면 샘플을 합치거나 전문 복제(Professional Cloning)로 바꿔야 한다(다음 단계).
     * $usage(선택): ['user_id', 'ref_type', 'ref_id'] 를 주면 사용 기록(voice_clone, 1회)을 남긴다.
     * @return array ['ok', 'voice_id', 'error', 'ms'] + ElevenLabs 과 같은 'permanent', 'voice_limit' 표시
     */
    public static function addVoice(string $name, array $files, string $description = '', array $usage = []): array
    {
        $started = microtime(true);
        // Typecast 목소리 이름은 30자까지다.
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 30) : '르멤버 목소리';
        $result = ['ok' => false, 'voice_id' => null, 'error' => null, 'ms' => 0, 'permanent' => false, 'voice_limit' => false];

        if (!self::ready()) {
            $result['error'] = 'Typecast API 키가 등록되지 않았습니다.';

            return $result;
        }
        $sample = self::pickSample($files);
        if ($sample === null) {
            $result['error'] = 'Typecast 로 보낼 음성 샘플 파일(WAV, MP3)이 없습니다. 녹음 샘플을 확인해 주세요.';
            $result['ms'] = self::elapsed($started);

            return $result;
        }
        // TODO: 150초가 넘는 샘플은 API 가 거절한다. 거절 메시지를 그대로 보여 주므로 관리자가 녹음을 줄여 다시 만들 수 있다.
        if (self::fake()) {
            $result['ok'] = true;
            $result['voice_id'] = 'fake_' . bin2hex(random_bytes(8));
            $result['ms'] = self::elapsed($started);
            self::logClone($usage, $result);

            return $result;
        }

        $res = HttpClient::request('POST', self::BASE . '/v1/custom-voices/instant-clone', [
            'headers' => self::headers(['Accept' => 'application/json']),
            'multipart' => [
                ['name' => 'file', 'filename' => $sample['name'], 'contents' => $sample['bytes'], 'type' => $sample['mime']],
                ['name' => 'name', 'contents' => $name],
                ['name' => 'model', 'contents' => self::model()],
            ],
            'timeout' => 120,
        ]);
        $result['ms'] = self::elapsed($started);
        $data = HttpClient::json($res);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['voice_id'])) {
            $result['ok'] = true;
            $result['voice_id'] = (string) $data['voice_id'];
        } else {
            $result['error'] = self::errorMessage($res);
            $result['permanent'] = self::isPermanent($res);
            $result['voice_limit'] = self::isVoiceLimit($res);
        }
        self::logClone($usage, $result);

        return $result;
    }

    /** 복제에 쓸 샘플 하나를 고른다. WAV/MP3 만 받으므로 그 가운데 가장 큰 파일을 고른다. */
    public static function pickSample(array $files): ?array
    {
        $best = null;
        foreach ($files as $f) {
            $path = isset($f['path']) ? (string) $f['path'] : '';
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $mime = isset($f['mime']) && $f['mime'] !== '' ? strtolower((string) $f['mime']) : Storage::mimeFor((string) $f['path']);
            if (!in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mpeg', 'audio/mp3'], true)) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $cand = [
                'bytes' => $bytes,
                'mime' => $mime,
                'name' => isset($f['name']) && (string) $f['name'] !== '' ? basename((string) $f['name']) : basename($path),
            ];
            if ($best === null || strlen($cand['bytes']) > strlen($best['bytes'])) {
                $best = $cand;
            }
        }

        return $best;
    }

    private static function logClone(array $usage, array $result): void
    {
        if (!$usage) {
            return;
        }
        Usage::log([
            'provider' => 'typecast',
            'purpose' => 'voice_clone',
            'model' => self::model(),
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
     * 복제한 목소리(uc_ 로 시작)를 Typecast 에서 지운다. 이미 없으면(404) 성공으로 본다.
     * 기본 제공 목소리(tc_)는 지울 수 없으므로 성공으로 본다.
     * @return array ['ok', 'error']
     */
    public static function deleteVoice(string $voiceId): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'error' => 'Typecast API 키가 등록되지 않았습니다.'];
        }
        if (self::fake() || strpos($voiceId, 'fake_') === 0) {
            return ['ok' => true, 'error' => null];
        }
        if (!self::validVoiceId($voiceId)) {
            return ['ok' => false, 'error' => '목소리 ID 형식이 올바르지 않습니다.'];
        }
        if (strpos($voiceId, 'uc_') !== 0) {
            return ['ok' => true, 'error' => null];
        }
        $res = HttpClient::request('DELETE', self::BASE . '/v1/custom-voices/' . rawurlencode($voiceId), [
            'headers' => self::headers(['Accept' => 'application/json']),
            'timeout' => 30,
        ]);
        if (($res['status'] >= 200 && $res['status'] < 300) || $res['status'] === 404) {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => self::errorMessage($res)];
    }

    // ───────────────────────── 음성 합성 ─────────────────────────

    /**
     * 문장을 목소리로 읽는다. ElevenLabs::synthesize 와 같은 반환 형식을 쓴다.
     * $opts: with_timestamps(bool), usage, chunk_chars, timeout, timeout_ms 만 받는다.
     *        model_id, voice_settings, output_format 은 ElevenLabs 전용이라 무시하고
     *        모델과 출력 형식은 typecast.model, typecast.audio_format 설정을 따른다.
     * TODO: 감정(prompt.emotion)과 속도(output.audio_tempo) 연동은 다음 단계에서 검토한다.
     * @return array ['ok', 'audio', 'ext', 'mime', 'alignment' => ?['chars', 'starts', 'ends'](초), 'duration_ms', 'chars', 'error', 'ms']
     */
    public static function synthesize(string $voiceId, string $text, array $opts = []): array
    {
        $started = microtime(true);
        $text = trim($text);
        $withTs = !empty($opts['with_timestamps']);
        $chars = mb_strlen($text);
        $result = [
            'ok' => false, 'audio' => '', 'ext' => 'mp3', 'mime' => 'audio/mpeg', 'alignment' => null,
            'duration_ms' => null, 'chars' => $chars, 'error' => null, 'ms' => 0,
        ];

        if (!self::ready()) {
            $result['error'] = 'Typecast API 키가 등록되지 않았습니다.';

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

        $max = isset($opts['chunk_chars']) ? max(50, (int) $opts['chunk_chars']) : self::MAX_CHUNK_CHARS;
        $timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 180;
        $timeoutMs = isset($opts['timeout_ms']) && (int) $opts['timeout_ms'] > 0 ? max(300, (int) $opts['timeout_ms']) : null;
        $chunks = ElevenLabs::splitText($text, $max);

        $audios = [];
        $alignment = $withTs ? ['chars' => [], 'starts' => [], 'ends' => []] : null;
        $offsetSec = 0.0;
        $ext = 'mp3';
        $error = null;
        foreach ($chunks as $ci => $chunk) {
            if (self::fake()) {
                $part = self::fakeChunk($chunk);
            } else {
                $part = self::requestChunk($voiceId, $chunk, $withTs, $timeout, $timeoutMs);
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
                    $alignment = null;
                }
            }
            $offsetSec += $partSec !== null ? $partSec : 0.0;
        }

        $result['ms'] = self::elapsed($started);
        if ($error === null) {
            $audio = $ext === 'wav' ? FakeAudio::concatWav($audios) : ElevenLabs::concatMp3($audios);
            $duration = $ext === 'wav' ? FakeAudio::wavDurationMs($audio) : ElevenLabs::mp3DurationMs($audio);
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
                'provider' => 'typecast',
                'purpose' => isset($u['purpose']) ? $u['purpose'] : 'story_tts',
                'model' => self::model(),
                'user_id' => isset($u['user_id']) ? $u['user_id'] : null,
                'ref_type' => isset($u['ref_type']) ? $u['ref_type'] : null,
                'ref_id' => isset($u['ref_id']) ? $u['ref_id'] : null,
                'unit_type' => 'chars',
                'units' => $result['ok'] ? $chars : 0,
                // TODO: Typecast 크레딧 단가(달러)가 확인되면 ElevenLabs 처럼 비용을 계산해 넣는다.
                'cost_usd' => 0,
                'latency_ms' => $result['ms'],
                'success' => $result['ok'],
            ]);
        }

        return $result;
    }

    /** API 한 번 호출(최대 MAX_CHUNK_CHARS 글자) */
    private static function requestChunk(string $voiceId, string $text, bool $withTs, int $timeout = 180, ?int $timeoutMs = null): array
    {
        $out = ['ok' => false, 'audio' => '', 'ext' => 'mp3', 'alignment' => null, 'duration_ms' => null, 'error' => null];
        $body = [
            'voice_id' => $voiceId,
            'text' => $text,
            'model' => self::model(),
            'output' => ['audio_format' => self::audioFormat()],
        ];
        $url = self::BASE . ($withTs ? '/v1/text-to-speech/with-timestamps' : '/v1/text-to-speech');
        $res = HttpClient::request('POST', $url, [
            'headers' => self::headers(['Accept' => $withTs ? 'application/json' : '*/*']),
            'json' => $body,
            'timeout' => $timeout,
            'timeout_ms' => $timeoutMs,
        ]);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $out['error'] = self::errorMessage($res);

            return $out;
        }
        if ($withTs) {
            $data = HttpClient::json($res);
            $audio = isset($data['audio']) ? base64_decode((string) $data['audio'], true) : false;
            if ($audio === false || $audio === '') {
                $out['error'] = 'Typecast 응답에 음성이 없습니다.';

                return $out;
            }
            $out['alignment'] = self::mapAlignment(isset($data['characters']) && is_array($data['characters']) ? $data['characters'] : null);
            if ($out['alignment'] === null && isset($data['words']) && is_array($data['words'])) {
                $out['alignment'] = self::mapAlignment($data['words']);
            }
            $ext = strtolower(trim((string) (isset($data['audio_format']) ? $data['audio_format'] : '')));
            if ($ext === 'wav' || $ext === 'mp3') {
                $out['ext'] = $ext;
            }
            if (isset($data['audio_duration']) && is_numeric($data['audio_duration'])) {
                $out['duration_ms'] = (int) round((float) $data['audio_duration'] * 1000);
            }
        } else {
            $audio = (string) $res['body'];
            if ($audio === '') {
                $out['error'] = 'Typecast 응답에 음성이 없습니다.';

                return $out;
            }
        }
        if ($out['ext'] === 'wav') {
            $out['duration_ms'] = $out['duration_ms'] !== null ? $out['duration_ms'] : FakeAudio::wavDurationMs($audio);
        } else {
            $out['duration_ms'] = $out['duration_ms'] !== null ? $out['duration_ms'] : ElevenLabs::mp3DurationMs($audio);
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

    /**
     * with-timestamps 응답의 [[text, start, end], ...] 를 ElevenLabs 정렬 형식으로 바꾼다.
     * @param array|null $items [{'text' => ..., 'start' => 초, 'end' => 초}, ...]
     * @return array|null ['chars', 'starts', 'ends']
     */
    public static function mapAlignment(?array $items): ?array
    {
        if (!$items || !is_array($items)) {
            return null;
        }
        $out = ['chars' => [], 'starts' => [], 'ends' => []];
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['start'], $it['end']) || !is_numeric($it['start']) || !is_numeric($it['end'])) {
                return null;
            }
            $out['chars'][] = isset($it['text']) ? (string) $it['text'] : '';
            $out['starts'][] = (float) $it['start'];
            $out['ends'][] = (float) $it['end'];
        }

        return $out['chars'] ? $out : null;
    }

    // ───────────────────────── 구독 ─────────────────────────

    /**
     * 플랜과 크레딧. ElevenLabs::subscription 과 같은 모양으로 맞춘다(Health, 관리자 화면에서 같은 필드를 읽는다).
     * @return array ['ok', 'used', 'limit', 'reset_at', 'tier', 'can_clone', 'error', 'ms']
     */
    public static function subscription(): array
    {
        $started = microtime(true);
        $out = ['ok' => false, 'used' => 0, 'limit' => 0, 'reset_at' => null, 'tier' => null, 'can_clone' => null, 'error' => null, 'ms' => 0];
        if (!self::ready()) {
            $out['error'] = 'Typecast API 키가 등록되지 않았습니다.';

            return $out;
        }
        if (self::fake()) {
            $out['ok'] = true;
            $out['used'] = 30000;
            $out['limit'] = 200000;
            $out['tier'] = 'fake';
            $out['can_clone'] = true;
            $out['ms'] = self::elapsed($started);

            return $out;
        }
        $res = HttpClient::request('GET', self::BASE . '/v1/users/me/subscription', [
            'headers' => self::headers(['Accept' => 'application/json']),
            'timeout' => 20,
        ]);
        $out['ms'] = self::elapsed($started);
        $data = HttpClient::json($res);
        if ($res['status'] !== 200 || !isset($data['credits']) || !is_array($data['credits'])) {
            $out['error'] = self::errorMessage($res);

            return $out;
        }
        $out['ok'] = true;
        $out['used'] = (int) (isset($data['credits']['used_credits']) ? $data['credits']['used_credits'] : 0);
        $out['limit'] = (int) (isset($data['credits']['plan_credits']) ? $data['credits']['plan_credits'] : 0);
        $out['tier'] = isset($data['plan']) ? (string) $data['plan'] : null;

        return $out;
    }

    // ───────────────────────── 설정 ─────────────────────────

    /** 합성 모델(관리자 설정 typecast.model) */
    public static function model(): string
    {
        $m = (string) setting('typecast.model', 'ssfm-v31');

        return in_array($m, ['ssfm-v31', 'ssfm-v30', 'ssfm-v21'], true) ? $m : 'ssfm-v31';
    }

    /** 출력 형식(관리자 설정 typecast.audio_format): wav | mp3 */
    public static function audioFormat(): string
    {
        return strtolower((string) setting('typecast.audio_format', 'mp3')) === 'wav' ? 'wav' : 'mp3';
    }

    // ───────────────────────── 오류 ─────────────────────────

    private static function detailStatus(array $data): ?string
    {
        if (isset($data['detail']) && is_array($data['detail']) && isset($data['detail']['status'])) {
            return (string) $data['detail']['status'];
        }

        return null;
    }

    /** 다시 시도해도 같은 결과인 오류인가(키 권한, 요금제, 결제, 잘못된 요청). ElevenLabs::isPermanent 와 같은 기준. */
    public static function isPermanent(array $res): bool
    {
        $status = (int) $res['status'];
        if ($status === 429) {
            return false;
        }

        return in_array($status, [400, 401, 402, 403, 413, 422], true);
    }

    /** 플랜의 클로닝 목소리 슬롯(custom_voice_slot) 한도에 걸린 오류인가. */
    public static function isVoiceLimit(array $res): bool
    {
        $data = HttpClient::json($res);
        $code = (string) self::detailStatus($data);
        if (strpos($code, 'slot') !== false || strpos($code, 'voice_limit') !== false) {
            return true;
        }
        $message = isset($data['detail']['message']) && is_string($data['detail']['message']) ? $data['detail']['message']
            : (isset($data['detail']) && is_string($data['detail']) ? $data['detail'] : '');

        // TODO: 실제 오류 코드·문구는 키 발급 후 확인한다.
        return $message !== '' && (bool) preg_match('/custom_voice_slot|maximum number of (custom )?voices|voice slot/i', $message);
    }

    /** API 오류를 관리자가 이해할 수 있는 한글 메시지로 바꾼다(API 키는 절대 넣지 않는다). */
    public static function errorMessage(array $res): string
    {
        $status = (int) $res['status'];
        if ($status === 0) {
            return 'Typecast 서버에 연결하지 못했습니다(네트워크 오류' . (!empty($res['error']) ? ': ' . str_limit((string) $res['error'], 80) : '') . ').';
        }
        $data = HttpClient::json($res);
        $detail = '';
        if (isset($data['detail'])) {
            if (is_array($data['detail']) && isset($data['detail']['message'])) {
                $detail = (string) $data['detail']['message'];
            } elseif (is_string($data['detail'])) {
                $detail = $data['detail'];
            } elseif (is_array($data['detail']) && isset($data['detail'][0]['msg'])) {
                $detail = (string) $data['detail'][0]['msg'];
            }
        } elseif (isset($data['message']) && is_string($data['message'])) {
            $detail = $data['message'];
        }
        $detail = str_limit(trim($detail), 120);
        if ($status === 401) {
            return 'Typecast API 키가 올바르지 않습니다.';
        }
        if ($status === 402 || $status === 403) {
            return 'Typecast 요금제나 결제 문제로 요청이 거절되었습니다' . ($detail !== '' ? ': ' . $detail : '.');
        }
        if ($status === 429) {
            return 'Typecast 요청 한도 또는 크레딧 한도를 넘었습니다. 잠시 후 다시 시도합니다.';
        }
        if ($status === 404) {
            return 'Typecast 에서 목소리를 찾을 수 없습니다. 목소리를 다시 생성해야 합니다.';
        }
        if ($status === 413) {
            return 'Typecast 로 보낸 파일이 너무 큽니다(25MB 이하). 샘플을 줄여 주세요.';
        }
        if ($status === 400 || $status === 422) {
            // 샘플 길이(5~150초), 지원하지 않는 형식 같은 요청 문제가 여기 온다.
            return 'Typecast 요청이 거절되었습니다' . ($detail !== '' ? ': ' . $detail : '.');
        }
        if ($status >= 500) {
            return 'Typecast 서버 오류(' . $status . ')입니다. 잠시 후 다시 시도합니다.';
        }

        return 'Typecast 오류(' . $status . ')' . ($detail !== '' ? ': ' . $detail : '');
    }

    /** Typecast 목소리 id 형식(tc_ 또는 uc_ 로 시작) */
    public static function validVoiceId(string $voiceId): bool
    {
        return (bool) preg_match('/^(tc|uc)_[A-Za-z0-9]{8,64}$/', $voiceId);
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
