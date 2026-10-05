<?php
/**
 * 일회용 목소리 생성 점검 스크립트(voice-check.yml 이 서버 _ops/ 에 올렸다가 바로 지운다).
 * 1) 서버 환경, ElevenLabs 키와 구독 상태, 최근 목소리, 샘플 파일, voice_clone 작업과 기록을 보여 준다.
 * 2) mode=clone_test 면 실제 샘플로 ElevenLabs 목소리를 하나 만들고, 아주 짧은 문장을 읽힌 뒤, 만든 목소리를 지운다.
 *    DB 는 바꾸지 않는다. 키, 이메일, 이름 같은 값은 출력하지 않는다.
 */
require __DIR__ . '/bootstrap.php';

use App\Core\HttpClient;
use App\Core\Storage;
use App\Services\ElevenLabs;

ops_require_token();
ignore_user_abort(true);
@set_time_limit(170);
header('Content-Type: application/json; charset=utf-8');

$cut = static function ($s, int $n = 600) {
    $s = (string) $s;

    return strlen($s) > $n ? substr($s, 0, $n) . '...' : $s;
};
$base = 'https://api.elevenlabs.io/v1';
$key = trim((string) config('elevenlabs.api_key', ''));
$hdr = static function (array $extra = []) use ($key) {
    return array_merge(['xi-api-key' => $key, 'Accept' => 'application/json'], $extra);
};
$mode = isset($_POST['mode']) ? (string) $_POST['mode'] : 'inspect';
$out = ['mode' => $mode];

$out['env'] = [
    'php' => PHP_VERSION,
    'curl' => function_exists('curl_init'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'providers_fake' => (bool) config('providers_fake'),
    'elevenlabs_ready' => ElevenLabs::ready(),
    'key_length' => strlen($key),
    'key_has_whitespace' => $key !== (string) config('elevenlabs.api_key', ''),
];
$out['settings'] = [
    'voice.auto_clone_on_submit' => setting('voice.auto_clone_on_submit', false),
    'voice.min_sample_seconds' => setting('voice.min_sample_seconds', 60),
    'elevenlabs.remove_background_noise' => setting('elevenlabs.remove_background_noise', true),
    'elevenlabs.model_story' => setting('elevenlabs.model_story', 'eleven_multilingual_v2'),
];

// 구독 정보(무료 호출). 목소리 복제 가능 여부와 목소리 개수 한도를 본다.
if ($key !== '') {
    $res = HttpClient::request('GET', $base . '/user/subscription', ['headers' => $hdr(), 'timeout' => 20]);
    $d = HttpClient::json($res);
    $sub = ['http' => $res['status'], 'ms' => $res['ms']];
    foreach (['tier', 'status', 'character_count', 'character_limit', 'can_use_instant_voice_cloning', 'voice_limit',
        'voice_slots_used', 'max_voice_add_edits', 'voice_add_edit_counter', 'professional_voice_limit'] as $k) {
        if (array_key_exists($k, $d)) {
            $sub[$k] = $d[$k];
        }
    }
    if ($res['status'] !== 200) {
        $sub['body'] = $cut($res['body']);
        $sub['curl_error'] = $res['error'];
    }
    $out['subscription'] = $sub;
}

// 최근 목소리와 샘플
$profiles = db_all(
    'SELECT id, label, status, sample_total_ms, quality_grade, provider_voice_id IS NOT NULL AS has_voice,
            requested_at, processed_at, updated_at
       FROM voice_profiles WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 8'
);
foreach ($profiles as &$p) {
    $p['samples'] = [];
    foreach (db_all('SELECT id, file_path, mime_type, file_size, duration_ms, source, created_at FROM voice_samples WHERE voice_profile_id = ? ORDER BY id', [(int) $p['id']]) as $s) {
        $exists = Storage::exists((string) $s['file_path']);
        $abs = $exists ? Storage::path((string) $s['file_path']) : null;
        $head = $abs ? (string) @file_get_contents($abs, false, null, 0, 16) : '';
        $p['samples'][] = [
            'id' => (int) $s['id'],
            'ext' => strtolower(pathinfo((string) $s['file_path'], PATHINFO_EXTENSION)),
            'mime' => $s['mime_type'],
            'db_size' => $s['file_size'],
            'disk_size' => $abs ? @filesize($abs) : null,
            'duration_ms' => $s['duration_ms'],
            'source' => $s['source'],
            'exists' => $exists,
            'magic' => bin2hex(substr($head, 0, 12)),
        ];
    }
}
unset($p);
$out['profiles'] = $profiles;

$out['voice_clone_jobs'] = db_all(
    "SELECT id, status, attempts, max_attempts, ref_id, last_error, created_at, updated_at, finished_at
       FROM jobs WHERE type = 'voice_clone' ORDER BY id DESC LIMIT 8"
);
$out['job_logs'] = db_all(
    "SELECT id, job_id, ref_id, level, message, created_at FROM job_logs
      WHERE ref_type = 'voice_profile' ORDER BY id DESC LIMIT 40"
);
$logFile = storage_path('logs') . '/app-' . date('Ymd') . '.log';
$out['app_log_errors_today'] = [];
if (is_file($logFile)) {
    $lines = array_slice(preg_grep('/\] ERROR /', (array) @file($logFile, FILE_IGNORE_NEW_LINES)), -15);
    foreach ($lines as $l) {
        $out['app_log_errors_today'][] = $cut($l, 400);
    }
}

// 실제 생성 점검: 샘플로 목소리를 만들고, 12자 정도 읽히고, 지운다.
if ($mode === 'clone_test' && $key !== '') {
    $pid = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
    if ($pid <= 0) {
        $pid = (int) db_value(
            "SELECT vp.id FROM voice_profiles vp
              WHERE vp.deleted_at IS NULL AND EXISTS (SELECT 1 FROM voice_samples s WHERE s.voice_profile_id = vp.id)
              ORDER BY FIELD(vp.status, 'failed', 'cloning', 'pending') DESC, vp.id DESC LIMIT 1"
        );
    }
    $test = ['profile_id' => $pid, 'files' => []];
    $parts = [['name' => 'name', 'contents' => '르멤버 점검 ' . date('mdHi') . ' #' . $pid]];
    foreach (db_all('SELECT * FROM voice_samples WHERE voice_profile_id = ? ORDER BY id', [$pid]) as $s) {
        if (!Storage::exists((string) $s['file_path'])) {
            continue;
        }
        $mime = (string) $s['mime_type'];
        if ($mime === '' || strpos($mime, '/') === false) {
            $mime = Storage::mimeFor((string) $s['file_path']);
        }
        $ext = strtolower(pathinfo((string) $s['file_path'], PATHINFO_EXTENSION));
        $bytes = (string) @file_get_contents(Storage::path((string) $s['file_path']));
        $parts[] = ['name' => 'files', 'filename' => 'sample-' . (int) $s['id'] . '.' . $ext, 'contents' => $bytes, 'type' => $mime];
        $test['files'][] = ['id' => (int) $s['id'], 'mime' => $mime, 'bytes' => strlen($bytes)];
    }
    $parts[] = ['name' => 'remove_background_noise', 'contents' => setting('elevenlabs.remove_background_noise', true) ? 'true' : 'false'];
    if (count($parts) > 2) {
        $res = HttpClient::request('POST', $base . '/voices/add', ['headers' => $hdr(), 'multipart' => $parts, 'timeout' => 120]);
        $d = HttpClient::json($res);
        $test['add'] = ['http' => $res['status'], 'ms' => $res['ms'], 'curl_error' => $res['error'], 'body' => $cut($res['body'])];
        $voiceId = isset($d['voice_id']) ? (string) $d['voice_id'] : '';
        if ($voiceId !== '') {
            $test['add']['body'] = 'voice_id 받음';
            $tts = HttpClient::request('POST', $base . '/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128', [
                'headers' => $hdr(['Accept' => 'audio/mpeg']),
                'json' => ['text' => '안녕, 오늘도 사랑해.', 'model_id' => (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2')],
                'timeout' => 60,
            ]);
            $ok = $tts['status'] === 200 && strlen($tts['body']) > 1000;
            $test['tts'] = ['http' => $tts['status'], 'ms' => $tts['ms'], 'audio_bytes' => $ok ? strlen($tts['body']) : 0,
                'chars' => mb_strlen('안녕, 오늘도 사랑해.'), 'body' => $ok ? '' : $cut($tts['body'])];
            $del = HttpClient::request('DELETE', $base . '/voices/' . rawurlencode($voiceId), ['headers' => $hdr(), 'timeout' => 30]);
            $test['delete'] = ['http' => $del['status']];
        }
    } else {
        $test['add'] = '보낼 샘플 파일이 없음';
    }
    $out['clone_test'] = $test;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
