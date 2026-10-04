<?php
namespace App\Services;

use App\Core\RateLimiter;
use App\Core\Storage;

/**
 * 아이 질문(끼어들기) 처리.
 * 질문 음성 저장 → 사용 가능 여부(설정, 질문 한도, 일일 예산, API 키) 확인 → Gemini 답 → 같은 가족 목소리로 합성 → 기록.
 * 어떤 오류도 호출한 쪽으로 던지지 않는다. 실패하면 mode 'error' 와 안내 문장을 돌려주고 동화는 이어진다.
 */
class QuestionService
{
    /** 질문 음성 최대 크기(바이트). 15초 48kHz WAV 도 넉넉히 들어간다. */
    const MAX_AUDIO_BYTES = 8388608;
    const AUDIO_EXTS = ['wav', 'webm', 'ogg', 'oga', 'm4a', 'mp4', 'mp3', 'aac', 'flac'];

    /**
     * $session 은 호출한 쪽에서 본인 것으로 확인한 play_sessions 행이다.
     * @return array ['ok' => true, 'mode', 'question_text', 'answer_text', 'audio_url', 'remaining', 'interaction_id', 'latency_ms']
     */
    public static function ask(array $user, array $session, string $audioTmpPath, string $mime, int $sentenceSeq, int $positionMs): array
    {
        $started = microtime(true);
        $userId = (int) $user['id'];
        $sessionId = (int) $session['id'];
        $max = (int) setting('qa.max_questions', 3);
        $count = (int) (isset($session['question_count']) ? $session['question_count'] : 0);
        $interactionId = null;
        $reserved = false;
        $profile = null;
        $row = [
            'play_session_id' => $sessionId,
            'sentence_seq' => $sentenceSeq > 0 ? $sentenceSeq : null,
            'position_ms' => max(0, $positionMs),
            'mode' => 'error',
        ];

        try {
            // 목소리(없으면 기기 음성). 안내 문장의 미리 만든 음성을 찾을 때도 쓴다.
            if (!empty($session['voice_profile_id'])) {
                $profile = db_one(
                    'SELECT id, label, provider_voice_id, stability, similarity_boost, style, speaker_boost
                       FROM voice_profiles WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
                    [(int) $session['voice_profile_id'], $userId]
                );
            }

            // 1) 짧은 시간에 너무 많은 질문(오작동, 장난)을 막는다.
            if (!RateLimiter::hit('qa:' . $userId, 20, 600)) {
                @unlink($audioTmpPath);
                $line = self::pickLine(self::errorLines(), $count);
                $interactionId = self::insert($row + ['answer_text' => $line, 'error_message' => '질문 요청 한도 초과(10분 20회)']);

                return self::result('error', null, $line, $profile ? self::clipUrl((int) $profile['id'], $line) : null, max(0, $max - $count), $interactionId, $started);
            }

            // 2) 동화(끼어들기 설정)와 최신 질문 횟수
            $story = db_one('SELECT id, title, barge_in_enabled, max_questions, fallback_lines FROM stories WHERE id = ?', [(int) $session['story_id']]);
            $fresh = db_one('SELECT question_count, fallback_count, child_id FROM play_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
            if ($fresh) {
                $session = array_merge($session, $fresh);
                $count = (int) $fresh['question_count'];
            }
            $fallbackCount = (int) (isset($session['fallback_count']) ? $session['fallback_count'] : 0);
            if ($story && $story['max_questions'] !== null && $story['max_questions'] !== '') {
                $max = (int) $story['max_questions'];
            }

            // 3) 질문 기능이 꺼져 있음(전체 설정 또는 동화별 설정). 아이 음성은 저장하지 않고 바로 지운다.
            if ($story && (!qa_available() || (int) $story['barge_in_enabled'] === 0)) {
                @unlink($audioTmpPath);
                $interactionId = self::insert($row);
                $line = self::pickLine(self::fallbackLines($story), $fallbackCount);

                return self::finish($interactionId, 'disabled', null, $line, $profile, [], max(0, $max - $count), $started);
            }

            // 4) 질문 음성 저장(관리자 검토, 재처리용)
            $stored = self::storeQuestionAudio($audioTmpPath, $mime);
            $row['question_audio_path'] = $stored['path'];
            $interactionId = self::insert($row);

            if (!$story) {
                return self::finish($interactionId, 'error', null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => '동화를 찾을 수 없음',
                ], max(0, $max - $count), $started);
            }
            if ($stored['error'] !== null) {
                return self::finish($interactionId, 'error', null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => $stored['error'],
                ], max(0, $max - $count), $started);
            }

            // 5) 질문 한도: 자리를 먼저 원자적으로 잡는다(동시에 두 질문이 와도 한도를 넘지 않게).
            $reserved = $max > 0 && db_exec(
                'UPDATE play_sessions SET question_count = question_count + 1 WHERE id = ? AND user_id = ? AND question_count < ?',
                [$sessionId, $userId, $max]
            ) === 1;
            if (!$reserved) {
                $line = self::pickLine(self::fallbackLines($story), $fallbackCount);
                db_exec('UPDATE play_sessions SET fallback_count = LEAST(fallback_count + 1, 255) WHERE id = ? AND user_id = ?', [$sessionId, $userId]);

                return self::finish($interactionId, 'quota', null, $line, $profile, [], 0, $started);
            }

            // 6) 일일 예산(0 이하면 제한 없음)
            $budget = (float) setting('cost.daily_budget_krw', 0);
            if ($budget > 0 && Usage::todayCostKrw() >= $budget) {
                self::release($sessionId, $userId);
                $reserved = false;

                return self::finish($interactionId, 'budget', null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => '일일 API 예산 도달',
                ], max(0, $max - $count), $started);
            }

            // 7) Gemini 키
            if (!Gemini::ready()) {
                self::release($sessionId, $userId);
                $reserved = false;

                return self::finish($interactionId, 'error', null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => 'Gemini API 키 미등록',
                ], max(0, $max - $count), $started);
            }

            // 8) 답 만들기
            $ctx = self::context($user, $session, $story, $profile, $sentenceSeq);
            $llm = Gemini::answerQuestion($ctx, $stored['bytes'], $stored['mime']);
            $llmCostUsd = Usage::geminiCostUsd($llm['input_tokens'], $llm['output_tokens'], $llm['audio_tokens']);
            Usage::log([
                'provider' => 'gemini',
                'purpose' => 'answer_llm',
                'model' => Gemini::model(),
                'user_id' => $userId,
                'ref_type' => 'interaction',
                'ref_id' => $interactionId,
                'unit_type' => 'tokens',
                'units' => $llm['input_tokens'] + $llm['output_tokens'],
                'cost_usd' => $llmCostUsd,
                'latency_ms' => $llm['ms'],
                'success' => $llm['ok'],
            ]);
            $extra = [
                'llm_input_tokens' => $llm['input_tokens'],
                'llm_output_tokens' => $llm['output_tokens'],
                'llm_ms' => $llm['ms'],
                'cost_krw' => Usage::krw($llmCostUsd),
            ];
            if (!$llm['ok']) {
                self::release($sessionId, $userId);
                $reserved = false;

                return self::finish($interactionId, 'error', null, self::pickLine(self::errorLines(), $count), $profile, $extra + [
                    'error_message' => (string) $llm['error'],
                ], max(0, $max - $count), $started);
            }

            $question = $llm['question'];
            $answer = $llm['answer'];
            $extra['emotion'] = $llm['emotion'];
            $notes = [];
            if ($llm['unsafe']) {
                $notes[] = '안전 주제 전환';
            }
            if (self::hasBlockedWord($answer) || self::hasBlockedWord($question)) {
                $answer = Gemini::safeRedirect();
                $notes[] = '금지어 감지로 답 교체';
            }
            $answer = self::trimAnswer($answer, (int) setting('qa.max_answer_chars', 120));

            // 분명한 말이 없어 되물은 경우는 질문 횟수에서 빼 준다.
            if ($question === '') {
                self::release($sessionId, $userId);
                $reserved = false;
                $notes[] = '질문 인식 안 됨(되묻기)';
            }

            // 9) 같은 가족 목소리로 답 읽기(목소리가 없으면 화면이 기기 음성으로 읽는다)
            $audioUrl = null;
            if ($profile && !empty($profile['provider_voice_id']) && ElevenLabs::ready()) {
                $modelId = (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5');
                $tts = ElevenLabs::synthesize((string) $profile['provider_voice_id'], $answer, [
                    'model_id' => $modelId,
                    'voice_settings' => [
                        'stability' => $profile['stability'],
                        'similarity_boost' => $profile['similarity_boost'],
                        'style' => $profile['style'],
                        'use_speaker_boost' => $profile['speaker_boost'],
                    ],
                    'usage' => ['purpose' => 'answer_tts', 'user_id' => $userId, 'ref_type' => 'interaction', 'ref_id' => $interactionId],
                    // 아이가 기다리는 중이라 오래 걸리면 기기 음성으로 넘긴다.
                    'timeout' => 30,
                ]);
                $extra['tts_ms'] = $tts['ms'];
                if ($tts['ok']) {
                    $rel = 'answers/' . date('Ym') . '/' . Storage::randomName($tts['ext']);
                    Storage::put($rel, $tts['audio']);
                    $extra['answer_audio_path'] = $rel;
                    $extra['tts_chars'] = $tts['chars'];
                    $extra['cost_krw'] += Usage::krw(Usage::elevenlabsCostUsd($tts['chars'], $modelId));
                    $audioUrl = url('/media/answer/' . $interactionId);
                } else {
                    $notes[] = '답 음성 합성 실패: ' . $tts['error'];
                }
            }
            if ($notes) {
                $extra['error_message'] = implode(' / ', $notes);
            }

            $after = (int) db_value('SELECT question_count FROM play_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
            $reserved = false;
            $extra['question_text'] = $question !== '' ? $question : null;
            self::update($interactionId, array_merge($extra, [
                'mode' => 'answer',
                'answer_text' => $answer,
                'latency_ms' => self::elapsed($started),
            ]));

            return self::result('answer', $question !== '' ? $question : null, $answer, $audioUrl, max(0, $max - $after), $interactionId, $started);
        } catch (\Throwable $e) {
            app_log('error', '질문 처리 실패: ' . $e->getMessage(), ['session' => $sessionId, 'file' => basename($e->getFile()) . ':' . $e->getLine()]);
            try {
                if ($reserved) {
                    self::release($sessionId, $userId);
                }
                $line = self::pickLine(self::errorLines(), $count);
                if ($interactionId) {
                    self::update($interactionId, [
                        'mode' => 'error',
                        'answer_text' => $line,
                        'error_message' => mb_substr('처리 오류: ' . $e->getMessage(), 0, 500),
                        'latency_ms' => self::elapsed($started),
                    ]);
                } else {
                    $interactionId = self::insert($row + ['answer_text' => $line, 'error_message' => mb_substr('처리 오류: ' . $e->getMessage(), 0, 500)]);
                }
                $remaining = max(0, $max - (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$sessionId]));

                return self::result('error', null, $line, $profile ? self::clipUrl((int) $profile['id'], $line) : null, $remaining, $interactionId, $started);
            } catch (\Throwable $e2) {
                $line = self::errorLines()[0];

                return self::result('error', null, $line, null, max(0, $max - $count), $interactionId, $started);
            }
        }
    }

    // ───────────────────────── 문장 ─────────────────────────

    /** 질문 한도 초과 대체 문장: 동화별 설정이 있으면 그것, 없으면 전체 설정 */
    public static function fallbackLines(?array $story = null): array
    {
        $lines = $story ? self::cleanLines(json_decode_array(isset($story['fallback_lines']) ? $story['fallback_lines'] : null)) : [];
        if (!$lines) {
            $lines = self::cleanLines((array) setting('qa.fallback_lines', []));
        }

        return $lines ?: ['나머지 이야기는 다 듣고 또 얘기하자, 조금만 더 들어볼까?'];
    }

    /** 오류 안내 문장 */
    public static function errorLines(): array
    {
        $lines = self::cleanLines((array) setting('qa.error_lines', []));

        return $lines ?: ['음, 잘 못 들었어. 이야기를 계속 들어 볼까?'];
    }

    /** 빈 줄을 빼고 공백을 한 칸으로 정리한다(미리 만든 목소리 음성의 text_hash 와 같은 기준). */
    private static function cleanLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (!is_string($l)) {
                continue;
            }
            $l = trim((string) preg_replace('/\s+/u', ' ', $l));
            if ($l !== '') {
                $out[] = $l;
            }
        }

        return $out;
    }

    private static function pickLine(array $lines, int $index): string
    {
        $lines = array_values($lines);

        return $lines[max(0, $index) % count($lines)];
    }

    /** 설정 금지어(배열 또는 쉼표 문자열)가 들어 있는지 */
    public static function hasBlockedWord(string $text): bool
    {
        $words = setting('qa.blocked_words', []);
        if (is_string($words)) {
            $words = preg_split('/[,\n]+/u', $words);
        }
        if (!is_array($words) || $text === '') {
            return false;
        }
        $hay = mb_strtolower($text);
        foreach ($words as $w) {
            $w = trim((string) $w);
            if ($w !== '' && strpos($hay, mb_strtolower($w)) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 답을 글자 수 안으로 줄인다. 가능하면 문장 끝에서 자른다. */
    public static function trimAnswer(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $max = max(10, $max);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        if (preg_match_all('/[.!?~…]+/u', $cut, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $end = mb_strlen(substr($cut, 0, $last[1] + strlen($last[0])));
            if ($end >= (int) ($max * 0.4)) {
                return trim(mb_substr($cut, 0, $end));
            }
        }
        // 낱말 중간에서 끊기지 않게 마지막 공백 앞에서 자른다.
        if (preg_match('/^(.*) /su', $cut, $sm) && mb_strlen($sm[1]) >= (int) ($max * 0.5)) {
            $cut = $sm[1];
        }

        return rtrim($cut, " ,") . '…';
    }

    // ───────────────────────── 내부 ─────────────────────────

    /**
     * 질문 음성을 저장 폴더로 옮긴다. 확장자, 크기를 확인한다.
     * @return array ['path' => ?string, 'bytes' => string, 'mime' => string, 'error' => ?string]
     */
    private static function storeQuestionAudio(string $tmpPath, string $mime): array
    {
        $out = ['path' => null, 'bytes' => '', 'mime' => $mime, 'error' => null];
        if ($tmpPath === '' || !is_file($tmpPath)) {
            $out['error'] = '질문 음성 파일이 없음';

            return $out;
        }
        $size = (int) filesize($tmpPath);
        if ($size < 64 || $size > self::MAX_AUDIO_BYTES) {
            @unlink($tmpPath);
            $out['error'] = '질문 음성 크기가 올바르지 않음(' . $size . ' bytes)';

            return $out;
        }
        // 브라우저가 알려 준 MIME 보다 파일 머리의 실제 형식을 믿는다(sendBeacon, Safari 녹음 대비).
        $ext = self::sniffAudioExt($tmpPath);
        if ($ext === null) {
            $ext = Storage::extForMime($mime, 'bin');
        }
        if (!in_array($ext, self::AUDIO_EXTS, true)) {
            @unlink($tmpPath);
            $out['error'] = '지원하지 않는 음성 형식(' . str_limit($mime, 40) . ')';

            return $out;
        }
        $rel = 'questions/' . date('Ym') . '/' . Storage::randomName($ext);
        Storage::putUploaded($tmpPath, $rel);
        $out['path'] = $rel;
        $out['bytes'] = Storage::get($rel);
        $out['mime'] = Storage::mimeFor($rel);

        return $out;
    }

    /** 파일 머리 바이트로 음성 형식을 알아낸다. 모르면 null */
    public static function sniffAudioExt(string $path): ?string
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return null;
        }
        $head = (string) fread($fp, 16);
        fclose($fp);
        if (strlen($head) < 12) {
            return null;
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
            return 'webm';
        }
        if (substr($head, 0, 4) === 'OggS') {
            return 'ogg';
        }
        if (substr($head, 0, 4) === 'fLaC') {
            return 'flac';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        if (substr($head, 0, 3) === 'ID3' || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE6) === 0xE2)) {
            return 'mp3';
        }
        if (ord($head[0]) === 0xFF && (ord($head[1]) & 0xF6) === 0xF0) {
            return 'aac';
        }

        return null;
    }

    /** Gemini 에 넘길 동화 맥락 */
    private static function context(array $user, array $session, array $story, ?array $profile, int $seq): array
    {
        $storyId = (int) $story['id'];
        if ($seq < 1) {
            $seq = 1;
        }
        $before = db_all(
            'SELECT seq, content FROM story_sentences WHERE story_id = ? AND seq <= ? ORDER BY seq DESC LIMIT 8',
            [$storyId, $seq]
        );
        $before = array_reverse($before);
        $current = '';
        if ($before) {
            $last = end($before);
            $current = (string) $last['content'];
        }
        $after = db_all('SELECT content FROM story_sentences WHERE story_id = ? AND seq > ? ORDER BY seq LIMIT 2', [$storyId, $seq]);

        $child = null;
        if (!empty($session['child_id'])) {
            $child = db_one('SELECT name, birth_year, birth_date FROM children WHERE id = ? AND user_id = ?', [(int) $session['child_id'], (int) $user['id']]);
        }

        return [
            'story_title' => (string) $story['title'],
            'persona' => $profile ? (string) $profile['label'] : '보호자',
            'child_name' => $child ? (string) $child['name'] : '',
            'child_age' => $child ? child_age($child) : null,
            'context_before' => implode(' ', array_map(static function ($r) {
                return (string) $r['content'];
            }, $before)),
            'current_sentence' => $current,
            'context_after' => implode(' ', array_map(static function ($r) {
                return (string) $r['content'];
            }, $after)),
            'max_chars' => (int) setting('qa.max_answer_chars', 120),
        ];
    }

    /** 미리 만들어 둔 목소리 음성(voice_clips)이 있으면 그 주소 */
    public static function clipUrl(int $profileId, string $text): ?string
    {
        try {
            $id = db_value(
                "SELECT id FROM voice_clips WHERE voice_profile_id = ? AND text_hash = ? AND status = 'completed' AND file_path IS NOT NULL LIMIT 1",
                [$profileId, hash('sha256', $text)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $id ? url('/media/clip/' . (int) $id) : null;
    }

    /** 답 이외의 결과(대체 문장, 안내 문장)를 기록하고 돌려준다. */
    private static function finish(?int $interactionId, string $mode, ?string $question, string $line, ?array $profile, array $extra, int $remaining, float $started): array
    {
        $audioUrl = $profile ? self::clipUrl((int) $profile['id'], $line) : null;
        if ($interactionId) {
            self::update($interactionId, array_merge($extra, [
                'mode' => $mode,
                'question_text' => $question,
                'answer_text' => $line,
                'latency_ms' => self::elapsed($started),
            ]));
        }

        return self::result($mode, $question, $line, $audioUrl, $remaining, $interactionId, $started);
    }

    private static function result(string $mode, ?string $question, string $answer, ?string $audioUrl, int $remaining, ?int $interactionId, float $started): array
    {
        return [
            'ok' => true,
            'mode' => $mode,
            'question_text' => $question,
            'answer_text' => $answer,
            'audio_url' => $audioUrl,
            'remaining' => max(0, $remaining),
            'interaction_id' => $interactionId,
            'latency_ms' => self::elapsed($started),
        ];
    }

    private static function release(int $sessionId, int $userId): void
    {
        db_exec('UPDATE play_sessions SET question_count = question_count - 1 WHERE id = ? AND user_id = ? AND question_count > 0', [$sessionId, $userId]);
    }

    private static function insert(array $row): ?int
    {
        try {
            if (isset($row['error_message'])) {
                $row['error_message'] = mb_substr((string) $row['error_message'], 0, 500);
            }

            return db_insert('interactions', $row);
        } catch (\Throwable $e) {
            app_log('error', '질문 기록 실패: ' . $e->getMessage());

            return null;
        }
    }

    private static function update(int $id, array $data): void
    {
        if (isset($data['error_message'])) {
            $data['error_message'] = mb_substr((string) $data['error_message'], 0, 500);
        }
        if (isset($data['cost_krw'])) {
            $data['cost_krw'] = round((float) $data['cost_krw'], 2);
        }
        db_update('interactions', $data, 'id = ?', [$id]);
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
