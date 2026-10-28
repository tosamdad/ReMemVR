<?php
namespace App\Services;

use App\Core\RateLimiter;
use App\Core\Storage;

/**
 * 아이 질문(끼어들기) 처리.
 * 앱이 폰 안에서 아이 말을 글로 바꿔 보낸 질문 문장을 받는다(아이 음성은 서버로 오지 않는다).
 * 사용 가능 여부(설정, 질문 한도, 일일 예산, API 키) 확인 → 답변 AI(QaModel) 답 → 같은 가족 목소리로 합성 → 기록.
 * 마이크를 놓은 뒤 qa.timeout_ms(기본 5초) 안에 답 음성을 돌려줄 수 없으면 준비된 음성(qa.timeout_lines)으로 답한다(mode 'timeout').
 * 어떤 오류도 호출한 쪽으로 던지지 않는다. 실패하면 mode 'error' 와 안내 문장을 돌려주고 동화는 이어진다.
 */
class QuestionService
{
    /** 서버가 답을 돌려줘야 하는 시각을 앱의 제한 시간보다 이만큼 앞당긴다(응답 전송 여유). */
    const NETWORK_MARGIN_MS = 500;
    /** 답 음성 합성에 남겨 둘 시간 */
    const TTS_RESERVE_MS = 1000;

    /**
     * $session 은 호출한 쪽에서 본인 것으로 확인한 play_sessions 행이다.
     * @return array ['ok' => true, 'mode', 'question_text', 'answer_text', 'audio_url', 'remaining', 'interaction_id', 'latency_ms']
     */
    public static function ask(array $user, array $session, string $questionText, int $sentenceSeq, int $positionMs): array
    {
        $started = microtime(true);
        $deadlineMs = self::timeoutMs() - self::NETWORK_MARGIN_MS;
        $userId = (int) $user['id'];
        $sessionId = (int) $session['id'];
        $max = (int) setting('qa.max_questions', 3);
        $count = (int) (isset($session['question_count']) ? $session['question_count'] : 0);
        $interactionId = null;
        $reserved = false;
        $profile = null;
        $cleaned = QaPrompt::cleanQuestion($questionText);
        $question = $cleaned['text'];
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

            // 3) 질문 기능이 꺼져 있음(전체 설정 또는 동화별 설정). 질문 문장도 남기지 않는다.
            if ($story && (!qa_available() || (int) $story['barge_in_enabled'] === 0)) {
                $interactionId = self::insert($row);
                $line = self::pickLine(self::fallbackLines($story), $fallbackCount);

                return self::finish($interactionId, 'disabled', null, $line, $profile, [], max(0, $max - $count), $started);
            }

            $row['question_text'] = $question !== '' ? $question : null;
            $interactionId = self::insert($row);
            if (!$story) {
                return self::finish($interactionId, 'error', null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => '동화를 찾을 수 없음',
                ], max(0, $max - $count), $started);
            }

            // 4) 질문 한도: 자리를 먼저 원자적으로 잡는다(동시에 두 질문이 와도 한도를 넘지 않게).
            $reserved = $max > 0 && db_exec(
                'UPDATE play_sessions SET question_count = question_count + 1 WHERE id = ? AND user_id = ? AND question_count < ?',
                [$sessionId, $userId, $max]
            ) === 1;
            if (!$reserved) {
                $line = self::pickLine(self::fallbackLines($story), $fallbackCount);
                db_exec('UPDATE play_sessions SET fallback_count = LEAST(fallback_count + 1, 255) WHERE id = ? AND user_id = ?', [$sessionId, $userId]);

                return self::finish($interactionId, 'quota', $question ?: null, $line, $profile, [], 0, $started);
            }

            // 5) 일일 예산(0 이하면 제한 없음)
            $budget = (float) setting('cost.daily_budget_krw', 0);
            if ($budget > 0 && Usage::todayCostKrw() >= $budget) {
                self::release($sessionId, $userId);
                $reserved = false;

                return self::finish($interactionId, 'budget', $question ?: null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => '일일 API 예산 도달',
                ], max(0, $max - $count), $started);
            }

            // 6) 답변 AI 키
            if (!QaModel::ready()) {
                self::release($sessionId, $userId);
                $reserved = false;

                return self::finish($interactionId, 'error', $question ?: null, self::pickLine(self::errorLines(), $count), $profile, [
                    'error_message' => QaModel::label() . ' API 키 미등록',
                ], max(0, $max - $count), $started);
            }

            $notes = [];
            if ($cleaned['masked']) {
                $notes[] = '개인정보로 보이는 부분 가림';
            }
            $extra = ['cost_krw' => 0.0];
            $unclear = false;

            // 7) 답 만들기. 금지어가 들어 있으면 답변 AI 에 보내지 않고 화제를 돌린다.
            if (self::hasBlockedWord($question)) {
                $answer = QaPrompt::safeRedirect();
                $extra['emotion'] = '기타';
                $notes[] = '질문 금지어 감지로 답변 AI 를 거치지 않음';
            } else {
                $ctx = self::context($userId, $session, $story, $profile, $sentenceSeq);
                $llmBudget = max(300, $deadlineMs - self::elapsed($started) - self::TTS_RESERVE_MS);
                $llm = QaModel::answer($ctx, $question, $llmBudget);
                $llmCostUsd = QaModel::costUsd($llm['input_tokens'], $llm['output_tokens']);
                Usage::log([
                    'provider' => QaModel::usageProvider(),
                    'purpose' => 'answer_llm',
                    'model' => QaModel::model(),
                    'user_id' => $userId,
                    'ref_type' => 'interaction',
                    'ref_id' => $interactionId,
                    'unit_type' => 'tokens',
                    'units' => $llm['input_tokens'] + $llm['output_tokens'],
                    'cost_usd' => $llmCostUsd,
                    'latency_ms' => $llm['ms'],
                    'success' => $llm['ok'],
                ]);
                $extra['llm_input_tokens'] = $llm['input_tokens'];
                $extra['llm_output_tokens'] = $llm['output_tokens'];
                $extra['llm_ms'] = $llm['ms'];
                $extra['cost_krw'] = Usage::krw($llmCostUsd);

                if (!empty($llm['timed_out'])) {
                    $reserved = false;

                    return self::timeout($interactionId, $sessionId, $userId, $question, $profile, $count, $max, $extra, $notes, null, $started);
                }
                if (!$llm['ok']) {
                    self::release($sessionId, $userId);
                    $reserved = false;

                    return self::finish($interactionId, 'error', $question ?: null, self::pickLine(self::errorLines(), $count), $profile, $extra + [
                        'error_message' => (string) $llm['error'],
                    ], max(0, $max - $count), $started);
                }

                $answer = $llm['answer'];
                $extra['emotion'] = $llm['emotion'];
                $unclear = !empty($llm['unclear']);
                if ($llm['unsafe']) {
                    $notes[] = '안전 주제 전환';
                }
                if (self::hasBlockedWord($answer)) {
                    $answer = QaPrompt::safeRedirect();
                    $notes[] = '답 금지어 감지로 답 교체';
                }
            }
            $answer = self::trimAnswer($answer, (int) setting('qa.max_answer_chars', 120));

            // 분명한 말이 없어 되물은 경우는 질문 횟수에서 빼 준다.
            if ($unclear) {
                self::release($sessionId, $userId);
                $reserved = false;
                $notes[] = '질문 인식 안 됨(되묻기)';
            }

            // 8) 같은 가족 목소리로 답 읽기(목소리가 없으면 화면이 기기 음성으로 읽는다)
            $audioUrl = null;
            if ($profile && !empty($profile['provider_voice_id']) && ElevenLabs::ready()) {
                $left = $deadlineMs - self::elapsed($started);
                if ($left < 300) {
                    if (!$unclear) {
                        $reserved = false;
                    }

                    return self::timeout($interactionId, $sessionId, $userId, $question, $profile, $count, $max, $extra, $notes, $answer, $started, !$unclear);
                }
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
                    // 아이가 기다리는 중이라 마감까지 남은 시간만 쓴다.
                    'timeout_ms' => $left,
                ]);
                $extra['tts_ms'] = $tts['ms'];
                if ($tts['ok']) {
                    VoiceService::touch((int) $profile['id']);
                    $rel = 'answers/' . date('Ym') . '/' . Storage::randomName($tts['ext']);
                    Storage::put($rel, $tts['audio']);
                    $extra['answer_audio_path'] = $rel;
                    $extra['tts_chars'] = $tts['chars'];
                    $extra['cost_krw'] += Usage::krw(Usage::elevenlabsCostUsd($tts['chars'], $modelId));
                    $audioUrl = url('/media/answer/' . $interactionId);
                } elseif (self::elapsed($started) >= $deadlineMs) {
                    $notes[] = '답 음성 합성 시간 초과';
                    if (!$unclear) {
                        $reserved = false;
                    }

                    return self::timeout($interactionId, $sessionId, $userId, $question, $profile, $count, $max, $extra, $notes, $answer, $started, !$unclear);
                } else {
                    $notes[] = '답 음성 합성 실패: ' . $tts['error'];
                }
            }
            if ($notes) {
                $extra['error_message'] = implode(' / ', $notes);
            }

            $after = (int) db_value('SELECT question_count FROM play_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
            $reserved = false;
            self::update($interactionId, array_merge($extra, [
                'mode' => 'answer',
                'question_text' => $question !== '' ? $question : null,
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

    /** 앱이 답을 기다리는 시간(밀리초). 2~15초 */
    public static function timeoutMs(): int
    {
        return max(2000, min(15000, (int) setting('qa.timeout_ms', 5000)));
    }

    /**
     * 마감 안에 답 음성을 못 만든 경우: 준비된 음성으로 답하고 질문 횟수는 돌려준다.
     * 만든 답이 있으면 관리자 기록에 남긴다.
     */
    private static function timeout(?int $interactionId, int $sessionId, int $userId, string $question, ?array $profile, int $count, int $max, array $extra, array $notes, ?string $answer, float $started, bool $release = true): array
    {
        if ($release) {
            self::release($sessionId, $userId);
        }
        $notes[] = '시간 초과(' . self::elapsed($started) . 'ms)';
        if ($answer !== null) {
            $notes[] = '만든 답: ' . $answer;
        }
        $extra['error_message'] = implode(' / ', $notes);
        $line = self::pickLine(self::timeoutLines(), $count);
        $after = (int) db_value('SELECT question_count FROM play_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);

        return self::finish($interactionId, 'timeout', $question !== '' ? $question : null, $line, $profile, $extra, max(0, $max - $after), $started);
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

    /** 마이크를 놓자마자 들려주는 생각하는 소리 */
    public static function thinkingLines(): array
    {
        $lines = self::cleanLines((array) setting('qa.thinking_lines', []));

        return $lines ?: ['음, 그건 말이지…'];
    }

    /** 답이 늦을 때 대신 들려주는 말 */
    public static function timeoutLines(): array
    {
        $lines = self::cleanLines((array) setting('qa.timeout_lines', []));

        return $lines ?: ['우와, 좋은 질문이다! 이야기 다 듣고 같이 생각해 보자.'];
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

    /** 답변 AI 에 넘길 동화 맥락(아이 실명은 넣지 않는다) */
    private static function context(int $userId, array $session, array $story, ?array $profile, int $seq): array
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
            $child = db_one('SELECT birth_year, birth_date FROM children WHERE id = ? AND user_id = ?', [(int) $session['child_id'], $userId]);
        }

        return [
            'story_title' => (string) $story['title'],
            'persona' => $profile ? (string) $profile['label'] : '보호자',
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
