<?php
/**
 * 로컬 개발과 화면 캡처용 데모 데이터 생성기. 실서버에서는 쓰지 않는다.
 *   php server/bin/demo-seed.php                 데모 회원과 자녀, 목소리, 동화 오디오, 재생 기록, 질문, 비용 기록을 만든다
 *   php server/bin/demo-seed.php --reset         이전 데모 데이터를 지우고 다시 만든다
 *   php server/bin/demo-seed.php --users=40      회원 수(기본 24)
 *   php server/bin/demo-seed.php --clean         데모 데이터만 지운다
 *   php server/bin/demo-seed.php --seed=7        난수 씨앗(같은 값이면 같은 구성)
 *   php server/bin/demo-seed.php --force         설정 env 가 production 이어도 실행한다(권하지 않음)
 * 데모 회원 이메일은 모두 @demo.rememvr.local 로 끝나고 비밀번호는 demo1234 이다. 첫 회원은 demo@demo.rememvr.local.
 * 동화 12편(migrations/0003_seed_content.sql)이 먼저 들어 있어야 한다.
 * 음성 파일은 저장 폴더에 아주 작은 WAV(8kHz) 로 만들고, DB 의 길이와 문장 시각은 실제 낭독 길이로 둔다.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Storage;
use App\Core\Text;

final class DemoSeed
{
    const DOMAIN = 'demo.rememvr.local';
    const PASSWORD = 'demo1234';
    const MANIFEST = 'demo-seed.json';
    const STORY_CODES = 12;
    const WAV_RATE = 8000;

    /** @var array 만든 파일(실패하면 지운다) */
    private static $files = [];
    /** @var array 같은 소리를 여러 번 만들지 않도록 */
    private static $wavCache = [];
    /** @var array 출력용 개수 */
    private static $count = [];

    // ───────────────────────── 실행 ─────────────────────────

    public static function main(array $argv): int
    {
        $opts = self::parseArgs($argv);
        if ($opts['error'] !== '') {
            fwrite(STDERR, '오류: ' . $opts['error'] . "\n");
            fwrite(STDERR, "사용법: php server/bin/demo-seed.php [--reset] [--users=24] [--clean] [--seed=1004] [--force]\n");

            return 1;
        }
        if ((string) config('env', 'local') === 'production' && !$opts['force']) {
            fwrite(STDERR, "오류: 실서버 설정(env=production)에서는 데모 데이터를 만들지 않는다. 꼭 필요하면 --force 를 붙인다.\n");

            return 1;
        }

        try {
            db();
        } catch (Throwable $e) {
            fwrite(STDERR, '오류: DB 에 연결하지 못했다. ' . $e->getMessage() . "\n");

            return 1;
        }

        $existing = (int) db_value('SELECT COUNT(*) FROM users WHERE email LIKE ?', ['%@' . self::DOMAIN]);
        if ($opts['clean'] || $opts['reset']) {
            $removed = self::reset();
            echo sprintf("이전 데모 데이터를 지웠다: 회원 %d명, 파일 %d개\n", $removed['users'], $removed['files']);
            if ($opts['clean']) {
                return 0;
            }
        } elseif ($existing > 0) {
            fwrite(STDERR, "데모 회원 {$existing}명이 이미 있다. 다시 만들려면 --reset 을 붙인다.\n");

            return 1;
        }

        $stories = self::loadStories();
        if (count($stories) < self::STORY_CODES) {
            fwrite(STDERR, '오류: 기본 동화 12편(Fairytale-001 ~ 012, 게시 상태)이 필요한데 ' . count($stories) . "편만 있다.\n");
            fwrite(STDERR, "먼저 php server/bin/migrate.php 로 0003_seed_content.sql 을 적용한다(동화를 숨겼다면 관리자 화면에서 다시 게시한다).\n");

            return 1;
        }

        mt_srand($opts['seed']);
        $started = microtime(true);
        try {
            $summary = db_tx(function () use ($opts, $stories) {
                return self::seed($opts['users'], $stories);
            });
        } catch (Throwable $e) {
            foreach (self::$files as $rel) {
                Storage::delete($rel);
            }
            fwrite(STDERR, '오류: 데모 데이터를 만들지 못했다(DB 는 되돌렸다). ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");

            return 1;
        }

        $c = self::$count;
        echo sprintf("데모 데이터를 만들었다 (%.1f초)\n", microtime(true) - $started);
        echo sprintf("  회원 %d명, 자녀 %d명\n", $c['users'], $c['children']);
        $vs = [];
        foreach (['completed', 'processing', 'cloning', 'pending', 'rejected', 'failed'] as $st) {
            if (!empty($c['voice_' . $st])) {
                $vs[] = voice_status_label($st) . ' ' . $c['voice_' . $st];
            }
        }
        echo sprintf("  목소리 %d개(%s), 샘플 %d개, 동화 오디오 %d개, 안내 음성 %d개\n", $c['voices'], implode(', ', $vs), $c['samples'], $c['story_audios'], $c['clips']);
        echo sprintf("  재생 기록 %d회(최근 30일), 질문 %d개(답변 %d, 한도 초과 %d)\n", $c['sessions'], $c['interactions'], $c['answers'], $c['quota']);
        echo sprintf("  API 사용 기록 %d건(추정 비용 %s), 크레딧 기록 %d건, 1:1 문의 %d건, 관리자 기록 %d건\n", $c['usage'], fmt_krw($c['cost_krw']), $c['snapshots'], $c['inquiries'], $c['audits']);
        echo sprintf("  저장 폴더에 만든 파일 %d개: %s\n", count(self::$files), storage_path());
        echo "\n데모 로그인: " . $summary['login'] . ' / ' . self::PASSWORD . "\n";

        return 0;
    }

    public static function parseArgs(array $argv): array
    {
        $o = ['reset' => false, 'clean' => false, 'force' => false, 'users' => 24, 'seed' => 1004, 'error' => ''];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--reset') {
                $o['reset'] = true;
            } elseif ($arg === '--clean') {
                $o['clean'] = true;
            } elseif ($arg === '--force') {
                $o['force'] = true;
            } elseif (preg_match('/^--users=(\d+)$/', $arg, $m)) {
                $o['users'] = (int) $m[1];
            } elseif (preg_match('/^--seed=(\d+)$/', $arg, $m)) {
                $o['seed'] = (int) $m[1];
            } else {
                $o['error'] = '알 수 없는 옵션: ' . $arg;
            }
        }
        if ($o['error'] === '' && ($o['users'] < 1 || $o['users'] > 500)) {
            $o['error'] = '--users 는 1 ~ 500 사이로 준다.';
        }

        return $o;
    }

    // ───────────────────────── 지우기 ─────────────────────────

    /** 데모 회원(이메일 도메인)과 그에 딸린 행, 파일, 기록을 지운다. */
    public static function reset(): array
    {
        $uids = array_map('intval', array_column(db_all('SELECT id FROM users WHERE email LIKE ?', ['%@' . self::DOMAIN]), 'id'));
        $manifest = json_decode_array(@file_get_contents(storage_path(self::MANIFEST)) ?: '');
        $files = 0;
        $pids = [];
        if ($uids) {
            $in = self::in($uids);
            $pids = array_map('intval', array_column(db_all('SELECT id FROM voice_profiles WHERE user_id IN (' . $in . ')', $uids), 'id'));
            $paths = [];
            if ($pids) {
                $pin = self::in($pids);
                foreach (['voice_samples', 'story_audios', 'voice_clips'] as $table) {
                    foreach (db_all('SELECT file_path FROM ' . $table . ' WHERE voice_profile_id IN (' . $pin . ')', $pids) as $r) {
                        $paths[] = $r['file_path'];
                    }
                }
            }
            foreach (db_all(
                'SELECT i.question_audio_path, i.answer_audio_path FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id WHERE ps.user_id IN (' . $in . ')',
                $uids
            ) as $r) {
                $paths[] = $r['question_audio_path'];
                $paths[] = $r['answer_audio_path'];
            }
            foreach ($paths as $p) {
                if (is_string($p) && $p !== '' && Storage::exists($p)) {
                    Storage::delete($p);
                    $files++;
                }
            }
            // 비어 있는 데모 폴더 정리
            foreach ($uids as $uid) {
                self::removeEmptyDirs(storage_path('voice-samples/' . $uid));
            }
            foreach ($pids as $pid) {
                self::removeEmptyDirs(storage_path('story-audio/' . $pid));
                self::removeEmptyDirs(storage_path('clips/' . $pid));
            }

            db_tx(function () use ($uids, $pids, $in) {
                // 회원 삭제 시 SET NULL 로 남는 기록을 먼저 지운다.
                db_exec('DELETE FROM api_usage_logs WHERE user_id IN (' . $in . ')', $uids);
                db_exec('DELETE FROM admin_audit_logs WHERE target_type = ? AND target_id IN (' . $in . ')', array_merge(['user'], $uids));
                if ($pids) {
                    $pin = self::in($pids);
                    db_exec('DELETE FROM job_logs WHERE ref_type = ? AND ref_id IN (' . $pin . ')', array_merge(['voice_profile'], $pids));
                    db_exec('DELETE FROM jobs WHERE ref_type = ? AND ref_id IN (' . $pin . ')', array_merge(['voice_profile'], $pids));
                    db_exec('DELETE FROM admin_audit_logs WHERE target_type = ? AND target_id IN (' . $pin . ')', array_merge(['voice_profile'], $pids));
                }
                // 자녀, 목소리, 샘플, 동화 오디오, 재생 기록, 질문, 문의는 FK(ON DELETE CASCADE)로 함께 지워진다.
                db_exec('DELETE FROM users WHERE id IN (' . $in . ')', $uids);
            });
        }
        foreach (['credit_snapshot_ids' => 'provider_credit_snapshots', 'audit_ids' => 'admin_audit_logs', 'job_log_ids' => 'job_logs'] as $key => $table) {
            $ids = isset($manifest[$key]) && is_array($manifest[$key]) ? array_values(array_filter(array_map('intval', $manifest[$key]))) : [];
            foreach (array_chunk($ids, 500) as $chunk) {
                db_exec('DELETE FROM ' . $table . ' WHERE id IN (' . self::in($chunk) . ')', $chunk);
            }
        }
        @unlink(storage_path(self::MANIFEST));

        return ['users' => count($uids), 'files' => $files];
    }

    private static function removeEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*', GLOB_ONLYDIR) as $sub) {
            self::removeEmptyDirs((string) $sub);
        }
        @rmdir($dir);
    }

    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    // ───────────────────────── 동화 ─────────────────────────

    /** 기본 동화 12편과 문장(게시된 것만) */
    public static function loadStories(): array
    {
        $codes = [];
        for ($i = 1; $i <= self::STORY_CODES; $i++) {
            $codes[] = sprintf('Fairytale-%03d', $i);
        }
        $rows = db_all(
            'SELECT id, code, title, char_count, est_duration_sec, content_hash, fallback_lines FROM stories
              WHERE code IN (' . self::in($codes) . ') AND status = ? AND deleted_at IS NULL ORDER BY sort_order, id',
            array_merge($codes, ['published'])
        );
        $out = [];
        foreach ($rows as $r) {
            $sentences = db_all('SELECT seq, content, keywords FROM story_sentences WHERE story_id = ? ORDER BY seq', [(int) $r['id']]);
            if (!$sentences) {
                continue;
            }
            $texts = array_column($sentences, 'content');
            $r['sentences'] = $sentences;
            $r['char_count'] = (int) $r['char_count'] > 0 ? (int) $r['char_count'] : Text::charCount(implode(' ', $texts));
            $r['est_ms'] = ((int) $r['est_duration_sec'] > 0 ? (int) $r['est_duration_sec'] : (int) ceil($r['char_count'] / 5.5)) * 1000;
            if ((string) $r['content_hash'] === '') {
                $r['content_hash'] = Text::hashSentences($texts);
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * 문장 길이(글자 수)에 비례해 문장과 단어의 시작, 끝 시각(ms)을 나눈다.
     * 형식: {"v":1,"duration":ms,"sentences":[{"seq","start","end","words":[[s,e],...]}]}, words 는 Text::words(문장) 순서
     */
    public static function timings(array $sentences, int $durationMs): array
    {
        $n = count($sentences);
        $durationMs = max(0, $durationMs);
        $lead = 300;
        $tail = 600;
        $gap = 450;
        if ($n === 0) {
            return ['v' => 1, 'duration' => $durationMs, 'sentences' => []];
        }
        $speak = $durationMs - $lead - $tail - $gap * ($n - 1);
        if ($speak < $n * 400) {
            // 너무 짧으면 쉼 없이 나눈다.
            $lead = $tail = $gap = 0;
            $speak = $durationMs;
        }
        $weights = [];
        foreach ($sentences as $s) {
            $weights[] = max(1, mb_strlen(preg_replace('/\s+/u', '', (string) $s['content'])));
        }
        $sum = array_sum($weights);
        $cursor = (float) $lead;
        $out = [];
        foreach (array_values($sentences) as $i => $s) {
            $len = $speak * $weights[$i] / $sum;
            $start = (int) round($cursor);
            $end = (int) round($cursor + $len);
            $out[] = [
                'seq' => (int) $s['seq'],
                'start' => $start,
                'end' => $end,
                'words' => self::wordTimings(Text::words((string) $s['content']), $start, $end),
            ];
            $cursor += $len + $gap;
        }

        return ['v' => 1, 'duration' => $durationMs, 'sentences' => $out];
    }

    private static function wordTimings(array $words, int $start, int $end): array
    {
        $m = count($words);
        if ($m === 0) {
            return [];
        }
        $span = max(0, $end - $start);
        $wgap = $m > 1 ? min(90.0, $span * 0.06 / ($m - 1)) : 0.0;
        $avail = $span - $wgap * ($m - 1);
        $weights = array_map(function ($w) {
            return max(1, mb_strlen($w));
        }, $words);
        $sum = array_sum($weights);
        $c = (float) $start;
        $out = [];
        foreach ($weights as $k => $w) {
            $len = $avail * $w / $sum;
            $ws = (int) round($c);
            $we = $k === $m - 1 ? $end : (int) round($c + $len);
            $out[] = [$ws, max($ws, $we)];
            $c += $len + $wgap;
        }

        return $out;
    }

    /** 재생 위치(ms)에 해당하는 문장 seq */
    public static function seqAt(array $timings, int $positionMs): int
    {
        $seq = 1;
        foreach ($timings['sentences'] as $s) {
            if ($s['start'] <= $positionMs) {
                $seq = (int) $s['seq'];
            } else {
                break;
            }
        }

        return $seq;
    }

    // ───────────────────────── WAV ─────────────────────────

    /** 8kHz 8비트 모노 WAV. 음을 차례로 내고 앞뒤를 부드럽게 줄인다. */
    public static function wav(int $ms, array $freqs, int $rate = self::WAV_RATE): string
    {
        $key = $ms . '|' . implode(',', $freqs) . '|' . $rate;
        if (isset(self::$wavCache[$key])) {
            return self::$wavCache[$key];
        }
        $n = max(1, (int) round($ms * $rate / 1000));
        $parts = max(1, count($freqs));
        $per = (int) ceil($n / $parts);
        $fadeIn = $rate * 0.015;
        $fadeOut = $rate * 0.04;
        $data = '';
        for ($i = 0; $i < $n; $i++) {
            $k = min($parts - 1, intdiv($i, $per));
            $t = $i - $k * $per;
            $len = min($per, $n - $k * $per);
            $env = max(0.0, min(1.0, $t / $fadeIn, ($len - $t) / $fadeOut));
            $v = sin(2 * M_PI * (float) $freqs[$k] * $i / $rate) * 0.35 * $env;
            $data .= chr(128 + (int) round($v * 127));
        }
        $wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8)
            . 'data' . pack('V', strlen($data)) . $data;
        self::$wavCache[$key] = $wav;

        return $wav;
    }

    private static function putWav(string $rel, int $ms, array $freqs): int
    {
        $bytes = self::wav($ms, $freqs);
        Storage::put($rel, $bytes);
        self::$files[] = $rel;

        return strlen($bytes);
    }

    // ───────────────────────── 한국어 ─────────────────────────

    /** 받침에 맞는 조사. josa('달님', '은', '는') → '달님은' */
    public static function josa(string $word, string $withFinal, string $withoutFinal): string
    {
        $last = mb_substr($word, -1);
        $code = self::codepoint($last);
        $hasFinal = $code >= 0xAC00 && $code <= 0xD7A3 && (($code - 0xAC00) % 28) !== 0;

        return $word . ($hasFinal ? $withFinal : $withoutFinal);
    }

    private static function codepoint(string $char): int
    {
        if ($char === '') {
            return 0;
        }
        if (function_exists('mb_ord')) {
            return (int) mb_ord($char, 'UTF-8');
        }
        $b = array_values(unpack('C*', $char));
        if ($b[0] < 0x80) {
            return $b[0];
        }
        if ($b[0] < 0xE0 && isset($b[1])) {
            return (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F);
        }
        if ($b[0] < 0xF0 && isset($b[2])) {
            return (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F);
        }

        return 0;
    }

    /** {kw:은} {kw:이} {kw:을} {kw} {who} {self} 자리를 채운다. */
    public static function fill(string $tpl, string $kw, string $who, string $self): string
    {
        $out = strtr($tpl, [
            '{kw:은}' => self::josa($kw, '은', '는'),
            '{kw:이}' => self::josa($kw, '이', '가'),
            '{kw:을}' => self::josa($kw, '을', '를'),
            '{kw}' => $kw,
            '{who}' => $who !== '' ? $who . ', ' : '',
            '{self}' => $self,
        ]);

        return trim(preg_replace('/\s+/u', ' ', $out));
    }

    /** 동화별 아이 질문과 답(트리거 키워드가 있는 문장에서 묻는다). e: 감정 */
    public static function crafted(): array
    {
        return [
            'Fairytale-001' => [
                ['t' => ['달님', '창밖'], 'q' => '{who}달님은 왜 밤에만 나와?', 'a' => '달님은 낮에도 하늘에 있는데 해님이 너무 밝아서 잘 안 보여. 밤이 되면 반짝 보이는 거야.', 'e' => '호기심'],
                ['t' => ['모양', '보름달', '쟁반', '손톱'], 'q' => '달님은 왜 모양이 자꾸 바뀌어?', 'a' => '달님이 빛을 조금씩 나눠 주느라 그렇대. 우리가 푹 자면 다시 동그래진단다.', 'e' => '호기심'],
                ['t' => ['부엉이'], 'q' => '{who}부엉이는 밤에 안 자?', 'a' => '부엉이는 밤에 깨어 있고 낮에 코 자는 새야. 그래서 달님 심부름을 잘하지.', 'e' => '호기심'],
                ['t' => ['사다리'], 'q' => '나도 은빛 사다리 타 보고 싶어!', 'a' => '우와, 같이 타 보면 정말 신나겠다. 오늘 밤 꿈속에서 손 꼭 잡고 올라가 보자.', 'e' => '기쁨'],
                ['t' => ['고양이'], 'q' => '길 잃은 고양이 무서웠겠다.', 'a' => '맞아, 많이 무서웠을 거야. 그래도 달님이 길을 비춰 줘서 집에 잘 찾아갔대.', 'e' => '공감'],
                ['t' => ['빛가루', '꿈'], 'q' => '{who}빛가루 뿌리면 무서운 꿈 안 꿔?', 'a' => '그럼, 빛가루가 반짝 지켜 줘. 그리고 오늘은 {self}가 옆에 있으니까 걱정하지 마.', 'e' => '걱정'],
            ],
            'Fairytale-002' => [
                ['t' => ['다람쥐', '꼬리'], 'q' => '{who}다람쥐는 왜 꼬리가 커?', 'a' => '커다란 꼬리로 균형도 잡고, 추운 밤엔 이불처럼 덮는대. 정말 똑똑하지?', 'e' => '호기심'],
                ['t' => ['둥지', '울음소리'], 'q' => '아기 새 엄마 찾을 수 있어?', 'a' => '그럼, 친구들이 힘을 모아 도와주고 있잖아. 끝까지 같이 응원해 보자.', 'e' => '걱정'],
                ['t' => ['시냇물', '헤엄', '물가'], 'q' => '{who}물에 빠지면 어떡해?', 'a' => '걱정했구나. 친구가 등을 내어 줘서 안전하게 건넜어. 서로 도우면 괜찮아.', 'e' => '걱정'],
                ['t' => ['산딸기'], 'q' => '나도 산딸기 먹고 싶어!', 'a' => '새콤달콤 맛있겠다. 이번 주말에 산딸기 같이 골라 볼까?', 'e' => '기쁨'],
                ['t' => ['노래', '꼭대기', '노랫소리'], 'q' => '아기 새는 무슨 노래 불러?', 'a' => '엄마를 부르는 맑은 노래야. 우리도 나중에 같이 흉내 내 볼까?', 'e' => '호기심'],
            ],
            'Fairytale-003' => [
                ['t' => ['천둥', '천둥소리', '빗소리'], 'q' => '{who}천둥은 왜 쿵쾅거려?', 'a' => '구름끼리 부딪혀서 커다란 소리가 나는 거야. 깜짝 놀라도 우리 집 안은 안전해.', 'e' => '무서움'],
                ['t' => ['공룡', '고사리'], 'q' => '공룡은 진짜 있었어?', 'a' => '응, 아주 오래전에 살았어. 지금은 박물관에서 공룡 뼈를 볼 수 있단다.', 'e' => '호기심'],
                ['t' => ['겁쟁이', '고민'], 'q' => '아기 공룡은 겁쟁이 아니야!', 'a' => '맞아, 무서워도 친구를 도우려고 한 걸 보면 정말 용감한 거야.', 'e' => '공감'],
                ['t' => ['다리', '개울', '물살'], 'q' => '{who}다리가 무너지면 어떡해?', 'a' => '아기 공룡이 튼튼한 목으로 친구를 지켜 줄 거야. 조금만 더 들어 보자.', 'e' => '걱정'],
                ['t' => ['무지개', '햇살'], 'q' => '와, 무지개 나왔다!', 'a' => '비가 그치고 햇살이 비치면 무지개가 떠. 정말 예쁘지?', 'e' => '기쁨'],
                ['t' => ['도마뱀'], 'q' => '도마뱀은 꼬리가 잘려도 괜찮아?', 'a' => '응, 도마뱀은 꼬리가 다시 자라. 신기하지?', 'e' => '호기심'],
            ],
            'Fairytale-004' => [
                ['t' => ['무지개', '색깔', '빛깔'], 'q' => '{who}무지개는 왜 여러 색이야?', 'a' => '햇빛 안에 여러 색이 숨어 있다가 빗방울을 만나면 나뉘어 보이는 거야.', 'e' => '호기심'],
                ['t' => ['구름', '솜사탕'], 'q' => '구름은 솜사탕 맛이 나?', 'a' => '하하, 구름은 작은 물방울이라서 맛은 없어. 그래도 솜사탕처럼 폭신해 보이지?', 'e' => '호기심'],
                ['t' => ['딸기', '딸기밭', '빨간색'], 'q' => '빨간색은 딸기가 줬구나!', 'a' => '맞아, 딸기가 제일 빨간빛을 나눠 줬어. 너는 무슨 색이 제일 좋아?', 'e' => '기쁨'],
                ['t' => ['남색'], 'q' => '{who}남색은 무슨 색이야?', 'a' => '깊은 밤하늘 같은 진한 파란색이야. 오늘 밤 하늘을 보면 찾을 수 있어.', 'e' => '호기심'],
                ['t' => ['회색'], 'q' => '회색 구름은 슬펐어?', 'a' => '응, 색이 없어서 조금 쓸쓸했대. 그래서 친구들이 색을 나눠 주는 거야.', 'e' => '공감'],
            ],
            'Fairytale-005' => [
                ['t' => ['아기별', '별'], 'q' => '{who}아기별은 왜 빛이 약해?', 'a' => '아직 아기라서 힘이 작대. 숨을 크게 쉬고 춤추면 점점 밝아진단다.', 'e' => '호기심'],
                ['t' => ['춤', '동작'], 'q' => '나도 아기별처럼 춤출래!', 'a' => '좋아, 팔을 크게 벌리고 천천히 빙글 돌아 보자. 그다음엔 코 자는 거야.', 'e' => '기쁨'],
                ['t' => ['눈물방울'], 'q' => '아기별 울었어?', 'a' => '응, 빛이 약해서 속상했대. 그럴 땐 달님처럼 꼭 안아 주면 돼.', 'e' => '공감'],
                ['t' => ['촛불', '풍선'], 'q' => '{who}촛불처럼 후 불면 돼?', 'a' => '맞아, 숨을 천천히 후 내쉬는 거야. 우리도 같이 해 볼까? 후우.', 'e' => '호기심'],
                ['t' => ['별님', '밤하늘'], 'q' => '별은 밤새 안 자?', 'a' => '별님들은 우리가 잘 때 하늘을 지켜 줘. 그래서 아침이 되면 쉬러 간대.', 'e' => '호기심'],
            ],
            'Fairytale-006' => [
                ['t' => ['시계토끼', '시계탑'], 'q' => '{who}시계토끼는 왜 맨날 바빠?', 'a' => '시간을 꼭 지키고 싶어서 그래. 그런데 천천히 가도 괜찮다는 걸 곧 알게 될 거야.', 'e' => '호기심'],
                ['t' => ['안경', '안경다리'], 'q' => '마법 안경 쓰면 뭐가 보여?', 'a' => '친구들의 마음이 보인대. 너도 친구 마음을 잘 살피는 따뜻한 아이야.', 'e' => '호기심'],
                ['t' => ['달팽이'], 'q' => '달팽이는 왜 이렇게 느려?', 'a' => '달팽이는 집을 등에 지고 다녀서 천천히 가. 대신 아주 꼼꼼하게 길을 본대.', 'e' => '호기심'],
                ['t' => ['꽃봉오리', '꽃', '꽃잎'], 'q' => '{who}꽃은 언제 피어?', 'a' => '햇볕을 듬뿍 받고 때가 되면 활짝 펴. 기다리면 예쁜 꽃을 볼 수 있어.', 'e' => '호기심'],
                ['t' => ['시계', '종'], 'q' => '시계 소리 무서워.', 'a' => '째깍째깍 소리가 크게 들렸구나. 괜찮아, 그건 시간이 지나가는 소리일 뿐이야.', 'e' => '무서움'],
            ],
            'Fairytale-007' => [
                ['t' => ['곰돌이', '잠'], 'q' => '{who}곰돌이는 왜 잠이 안 와?', 'a' => '낮잠을 너무 많이 자서 그렇대. 너는 오늘 신나게 놀았으니까 금방 잘 수 있을 거야.', 'e' => '호기심'],
                ['t' => ['꿀물', '모금'], 'q' => '꿀물 마시면 잠이 와?', 'a' => '따뜻한 걸 마시면 몸이 포근해져서 잠이 솔솔 와. 내일 따뜻한 우유 데워 줄까?', 'e' => '호기심'],
                ['t' => ['반딧불이', '불빛'], 'q' => '반딧불이는 어떻게 빛나?', 'a' => '꼬리에서 작은 불빛을 내는 신기한 곤충이야. 여름밤에 볼 수 있어.', 'e' => '호기심'],
                ['t' => ['자장가', '노래'], 'q' => '{self}가 자장가 불러 줘.', 'a' => '그럼, 이야기 다 듣고 자장가 불러 줄게. 눈 감고 기다려 줄래?', 'e' => '기쁨'],
                ['t' => ['동굴', '밤'], 'q' => '{who}동굴은 깜깜해서 무서워.', 'a' => '깜깜하면 무서울 수 있지. 그래도 곰돌이 곁에는 친구들이 있어서 괜찮아.', 'e' => '무서움'],
            ],
            'Fairytale-008' => [
                ['t' => ['별님', '하늘'], 'q' => '{who}별님은 왜 내려왔어?', 'a' => '길을 잃고 잠깐 내려온 거래. 아기곰이 집에 돌아가도록 도와줄 거야.', 'e' => '걱정'],
                ['t' => ['아기곰'], 'q' => '아기곰은 밤에 혼자 안 무서워?', 'a' => '조금 무섭지만 별님 친구가 있어서 용기를 냈대. 너도 그렇게 용감하지?', 'e' => '공감'],
                ['t' => ['담요'], 'q' => '별님도 추워?', 'a' => '밤바람이 차가우니까 추울 수 있지. 그래서 아기곰이 담요를 덮어 준 거야.', 'e' => '공감'],
                ['t' => ['꿀', '숟가락'], 'q' => '{who}별님도 꿀 먹어?', 'a' => '하하, 별님은 처음 먹어 보는 꿀이 정말 달콤했대. 너도 꿀 좋아하지?', 'e' => '기쁨'],
                ['t' => ['눈물', '빈자리'], 'q' => '별님 가면 슬퍼.', 'a' => '헤어지는 건 슬프지만 밤마다 하늘에서 인사해 줄 거야. 오늘 밤 창밖을 같이 보자.', 'e' => '공감'],
            ],
            'Fairytale-009' => [
                ['t' => ['고래', '바다'], 'q' => '{who}고래는 물고기야?', 'a' => '고래는 물고기가 아니라 우리처럼 숨을 쉬는 동물이야. 그래서 물 위로 올라와 숨을 쉬지.', 'e' => '호기심'],
                ['t' => ['안개'], 'q' => '안개 속은 무섭지 않아?', 'a' => '앞이 안 보이면 무서울 수 있어. 그래도 작은 고래는 노래로 가족을 찾을 거야.', 'e' => '무서움'],
                ['t' => ['노래', '목소리'], 'q' => '고래도 노래해?', 'a' => '응, 고래는 멀리까지 들리는 노래로 서로 이야기한대. 정말 멋지지?', 'e' => '호기심'],
                ['t' => ['물개'], 'q' => '{who}물개는 착한 친구야?', 'a' => '응, 길을 잃은 고래를 도와주는 다정한 친구야.', 'e' => '호기심'],
                ['t' => ['가족', '엄마', '소식'], 'q' => '작은 고래 엄마 만났어?', 'a' => '조금만 더 들어 보자. 용기를 내면 꼭 다시 만날 수 있을 거야.', 'e' => '걱정'],
            ],
            'Fairytale-010' => [
                ['t' => ['용', '용님', '비늘'], 'q' => '{who}용은 진짜 불 뿜어?', 'a' => '이 이야기 속 용은 따뜻한 입김으로 마을을 지켜 준대. 무섭지 않은 다정한 용이야.', 'e' => '무서움'],
                ['t' => ['우물', '우물물', '입김'], 'q' => '우물은 왜 안 얼었어?', 'a' => '용이 밤마다 따뜻한 입김을 불어 줬대. 그게 바로 용의 비밀이야.', 'e' => '호기심'],
                ['t' => ['날개'], 'q' => '나도 용 날개 타 보고 싶어!', 'a' => '와, 하늘을 나는 기분은 어떨까? 꿈속에서 같이 날아 보자.', 'e' => '기쁨'],
                ['t' => ['겉모습'], 'q' => '{who}겉모습이 무서워도 착할 수 있어?', 'a' => '그럼, 겉모습보다 마음이 더 중요해. 우리 친구들도 그렇지?', 'e' => '공감'],
                ['t' => ['눈보라', '겨울'], 'q' => '눈보라 오면 추워.', 'a' => '맞아, 아주 춥지. 그래서 따뜻한 옷을 입고 이불을 덮는 거야.', 'e' => '걱정'],
            ],
            'Fairytale-011' => [
                ['t' => ['달나라', '달'], 'q' => '{who}달나라에는 진짜 토끼가 살아?', 'a' => '옛날 사람들은 달 무늬가 떡방아 찧는 토끼처럼 보인다고 생각했대. 오늘 같이 찾아볼까?', 'e' => '호기심'],
                ['t' => ['로켓', '절구'], 'q' => '절구가 로켓이 돼?', 'a' => '상상 속에서는 뭐든 될 수 있지! 너라면 무엇으로 로켓을 만들고 싶어?', 'e' => '기쁨'],
                ['t' => ['우주', '별똥별'], 'q' => '우주는 얼마나 커?', 'a' => '끝이 안 보일 만큼 아주아주 커. 그 안에 별이 셀 수 없이 많대.', 'e' => '호기심'],
                ['t' => ['로봇', '모래바람'], 'q' => '{who}로봇은 무서운 거야?', 'a' => '아니, 이 로봇은 길을 알려 주는 친절한 친구야. 걱정하지 마.', 'e' => '무서움'],
                ['t' => ['떡', '떡방아'], 'q' => '토끼가 만든 떡 먹고 싶어!', 'a' => '쫄깃한 떡 맛있겠다. 내일 간식으로 떡 먹을까?', 'e' => '기쁨'],
            ],
            'Fairytale-012' => [
                ['t' => ['부엉이'], 'q' => '{who}부엉이는 왜 밤에 파티해?', 'a' => '부엉이는 밤에 깨어 있는 새라서 그래. 그래서 밤 파티를 제일 잘 연대.', 'e' => '호기심'],
                ['t' => ['초대장', '손님'], 'q' => '나도 초대장 받고 싶어!', 'a' => '벌써 받았는걸? 눈을 감으면 숲속 파티에 갈 수 있어.', 'e' => '기쁨'],
                ['t' => ['반딧불이', '등불'], 'q' => '반딧불이 등불 예쁘다.', 'a' => '그렇지? 반짝반짝 작은 불빛이 숲을 환하게 밝혀 줘.', 'e' => '기쁨'],
                ['t' => ['귀뚜라미', '연주', '북'], 'q' => '{who}귀뚜라미는 어떻게 노래해?', 'a' => '날개를 비벼서 귀뚤귀뚤 소리를 낸대. 신기하지?', 'e' => '호기심'],
                ['t' => ['자장가', '파티'], 'q' => '파티 끝나면 다들 자?', 'a' => '응, 자장가를 듣고 모두 포근하게 잠들어. 우리도 이제 코 잘 시간이야.', 'e' => '공감'],
            ],
        ];
    }

    /** 문장 키워드로 만드는 일반 질문(어울리지 않는 낱말은 STOP_WORDS 로 거른다) */
    public static function generic(): array
    {
        return [
            ['q' => '{who}{kw:이} 뭐야?', 'a' => '좋은 질문이야! {kw:은} 이야기에서 아주 중요한 거야. 다음 장면에서 또 나오는지 같이 들어 보자.', 'e' => '호기심', 'kw' => true],
            ['q' => '{kw:은} 진짜로 있어?', 'a' => '이야기 속 {kw:은} 상상 속에서 만나는 거야. 그래도 우리 마음속에는 언제나 있지!', 'e' => '호기심', 'kw' => true],
            ['q' => '나도 {kw} 보고 싶어!', 'a' => '그럼 오늘 밤 꿈에서 {kw:을} 만나 보자. {self}가 꼭 안아 줄게.', 'e' => '기쁨', 'kw' => true],
            ['q' => '{who}그다음엔 어떻게 돼?', 'a' => '궁금하지? 조금만 더 들으면 알 수 있어. 귀를 쫑긋 세워 볼까?', 'e' => '호기심', 'kw' => false],
            ['q' => '{who}이거 무서운 이야기야?', 'a' => '아니, 끝까지 들으면 마음이 따뜻해지는 이야기야. {self}가 옆에 있을게.', 'e' => '걱정', 'kw' => false],
            ['q' => '나도 해 보고 싶어!', 'a' => '좋아, 이야기 다 듣고 같이 해 보자. 정말 재미있겠다!', 'e' => '기쁨', 'kw' => false],
        ];
    }

    const STOP_WORDS = ['눈', '숨', '마음', '손', '몸', '고개', '얼굴', '등', '귀', '발', '힘', '목', '머리', '빛', '소리', '노래', '이야기', '덕분',
        '출발', '동작', '입버릇', '고민', '걱정', '호기심', '고마움', '사방', '틈', '볼', '배', '세수', '하품', '잠', '꿈', '밤', '저녁', '아침', '오후',
        '시간', '하루', '집', '길', '문', '모습', '웃음', '이마', '곁', '친구', '엄마', '할머니', '가족', '세상', '이름', '방향', '빈자리', '숨소리',
        '품', '팔', '발밑', '발끝', '발가락', '걸음', '목소리', '눈빛', '눈물', '대답', '냄새', '맛', '속도', '향기', '하늘', '색깔', '빛깔', '끝자락',
        '이웃', '마음속', '꿈속', '꿈나라', '잠자리', '머리맡', '눈꺼풀', '콧등', '증조할머니', '아기', '막내', '선수', '박수', '약속', '주머니'];

    // ───────────────────────── 만들기 ─────────────────────────

    private static function rnd(int $min, int $max): int
    {
        return $max <= $min ? $min : mt_rand($min, $max);
    }

    private static function rf(float $min, float $max): float
    {
        return $min + ($max - $min) * mt_rand() / mt_getrandmax();
    }

    private static function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private static function pick(array $list)
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /** [값 => 가중치] 에서 하나 */
    private static function weighted(array $weights)
    {
        $total = array_sum($weights);
        $r = self::rf(0, $total);
        foreach ($weights as $k => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $k;
            }
        }

        return array_keys($weights)[count($weights) - 1];
    }

    private static function dt(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    private static function add(string $key, $n = 1): void
    {
        self::$count[$key] = (isset(self::$count[$key]) ? self::$count[$key] : 0) + $n;
    }

    /** 회원별 역할. 0번은 데모 로그인 회원(엄마 준비됨 + 아빠 동화 준비 중) */
    private static function roleFor(int $i): string
    {
        $fixed = ['first', 'completed2', 'pending', 'completed', 'rejected', 'pending_plus', 'completed', 'cloning', 'completed', 'failed', 'none', 'pending', 'completed2', 'none'];
        if ($i < count($fixed)) {
            return $fixed[$i];
        }

        return self::chance(0.3) ? 'completed2' : 'completed';
    }

    private static function seed(int $userCount, array $stories): array
    {
        $now = time();
        self::$count = ['users' => 0, 'children' => 0, 'voices' => 0, 'samples' => 0, 'story_audios' => 0, 'clips' => 0, 'sessions' => 0,
            'interactions' => 0, 'answers' => 0, 'quota' => 0, 'usage' => 0, 'cost_krw' => 0.0, 'snapshots' => 0, 'inquiries' => 0, 'audits' => 0];
        $manifest = ['created_at' => self::dt($now), 'credit_snapshot_ids' => [], 'audit_ids' => [], 'job_log_ids' => []];
        $admin = db_one('SELECT id FROM admins ORDER BY (role = ?) DESC, id LIMIT 1', ['super']);
        $adminId = $admin ? (int) $admin['id'] : null;
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $scriptKeys = self::scriptKeys();

        $surnames = [['김', 'kim'], ['이', 'lee'], ['박', 'park'], ['최', 'choi'], ['정', 'jung'], ['강', 'kang'], ['조', 'cho'], ['윤', 'yoon'], ['장', 'jang'], ['임', 'lim'],
            ['한', 'han'], ['오', 'oh'], ['서', 'seo'], ['신', 'shin'], ['권', 'kwon'], ['황', 'hwang'], ['안', 'ahn'], ['송', 'song'], ['홍', 'hong'], ['문', 'moon']];
        $given = [['지현', 'jihyun'], ['민지', 'minji'], ['수진', 'sujin'], ['은영', 'eunyoung'], ['현우', 'hyunwoo'], ['준호', 'junho'], ['서연', 'seoyeon'], ['하늘', 'haneul'],
            ['동훈', 'donghoon'], ['혜진', 'hyejin'], ['성민', 'sungmin'], ['유진', 'yujin'], ['지훈', 'jihoon'], ['소영', 'soyoung'], ['재원', 'jaewon'], ['미경', 'mikyung'],
            ['상우', 'sangwoo'], ['나래', 'narae'], ['태현', 'taehyun'], ['보람', 'boram'], ['진영', 'jinyoung'], ['우진', 'woojin'], ['다은', 'daeun'], ['경민', 'kyungmin']];
        $kids = [['서아', 'girl'], ['도윤', 'boy'], ['하린', 'girl'], ['이준', 'boy'], ['지유', 'girl'], ['시우', 'boy'], ['아린', 'girl'], ['예준', 'boy'], ['수아', 'girl'], ['은우', 'boy'],
            ['지안', 'girl'], ['하준', 'boy'], ['유나', 'girl'], ['로운', 'boy'], ['채아', 'girl'], ['서준', 'boy'], ['윤슬', 'girl'], ['도하', 'boy'], ['나은', 'girl'], ['건우', 'boy']];
        $avatars = array_keys(avatar_presets());

        // 동화별 길이(기기 음성용 문장 시각 포함)
        $storyById = [];
        foreach ($stories as $s) {
            $s['device_timings'] = self::timings($s['sentences'], $s['est_ms']);
            $storyById[(int) $s['id']] = $s;
        }
        $popular = [];
        foreach ($stories as $k => $s) {
            $popular[(int) $s['id']] = [10, 8, 7, 6, 7, 5, 9, 6, 5, 8, 6, 7][$k % 12];
        }

        $users = [];
        $usedEmails = [];
        for ($i = 0; $i < $userCount; $i++) {
            $role = self::roleFor($i);
            $sur = $i === 0 ? ['김', 'kim'] : self::pick($surnames);
            $giv = $i === 0 ? ['지현', 'jihyun'] : $given[($i * 7 + mt_rand(0, 3)) % count($given)];
            $local = $i === 0 ? 'demo' : $giv[1] . '.' . $sur[1];
            $email = $local . '@' . self::DOMAIN;
            for ($n = 2; isset($usedEmails[$email]); $n++) {
                $email = $local . $n . '@' . self::DOMAIN;
            }
            $usedEmails[$email] = true;

            $minDays = ['first' => 58, 'completed' => 4, 'completed2' => 6, 'pending' => 1, 'pending_plus' => 8, 'cloning' => 2, 'rejected' => 10, 'failed' => 6, 'none' => 1];
            $days = $i === 0 ? 58 : self::rnd($minDays[$role], 59);
            $createdTs = $now - $days * 86400 - self::rnd(0, 6 * 3600);
            $provider = $i === 0 ? 'email' : self::weighted(['email' => 70, 'kakao' => 20, 'google' => 10]);
            $lastLogin = max($createdTs + 600, $now - self::rnd(600, 6 * 86400));
            $prefs = [
                'dark_mode' => self::chance(0.15),
                'playback_speed' => self::weighted(['1.0' => 80, '0.75' => 12, '1.25' => 8]) + 0.0,
                'autoplay_next' => self::chance(0.2),
                'highlight' => !self::chance(0.1),
                'text_size' => self::weighted(['md' => 75, 'lg' => 20, 'sm' => 5]),
                'sleep_timer_min' => (int) self::weighted(['0' => 70, '15' => 15, '30' => 15]),
                'hands_free' => false,
                'notify_voice_ready' => true,
                'notify_notice' => !self::chance(0.25),
            ];
            if ($i === 0) {
                $prefs['dark_mode'] = false;
                $prefs['playback_speed'] = 1.0;
            }
            $uid = db_insert('users', [
                'email' => $email,
                'password_hash' => $hash,
                'name' => $sur[0] . $giv[0],
                'phone' => sprintf('010-%04d-%04d', self::rnd(2000, 9899), self::rnd(1000, 9999)),
                'prefs' => json_encode_u($prefs),
                'signup_provider' => $provider,
                'terms_agreed_at' => self::dt($createdTs),
                'privacy_agreed_at' => self::dt($createdTs),
                'marketing_agreed_at' => self::chance(0.4) ? self::dt($createdTs) : null,
                'admin_memo' => null,
                'status' => ($i === $userCount - 1 && $userCount >= 12) ? 'blocked' : 'active',
                'last_login_at' => self::dt($lastLogin),
                'created_at' => self::dt($createdTs),
                'updated_at' => self::dt($lastLogin),
            ]);
            if ($provider !== 'email') {
                db_insert('user_social_accounts', [
                    'user_id' => $uid,
                    'provider' => $provider,
                    'provider_user_id' => 'demo-' . $provider . '-' . bin2hex(random_bytes(6)),
                    'email' => $email,
                    'last_login_at' => self::dt($lastLogin),
                    'created_at' => self::dt($createdTs),
                ]);
            }
            self::add('users');

            // 자녀 1~2명(만 3~7세)
            $children = [];
            $childCount = $i === 0 ? 2 : (self::chance(0.35) ? 2 : 1);
            for ($c = 0; $c < $childCount; $c++) {
                $kid = $i === 0 ? [['서아', 'girl'], ['도윤', 'boy']][$c] : $kids[($i * 3 + $c * 5 + mt_rand(0, 2)) % count($kids)];
                $age = $i === 0 ? [6, 4][$c] : self::rnd(3, 7);
                $birthTs = strtotime('-' . $age . ' years', $now) - self::rnd(10, 350) * 86400;
                $cid = db_insert('children', [
                    'user_id' => $uid,
                    'name' => $kid[0],
                    'birth_year' => (int) date('Y', $birthTs),
                    'birth_date' => date('Y-m-d', $birthTs),
                    'gender' => $kid[1],
                    'avatar' => $i === 0 ? ['rabbit', 'dino'][$c] : self::pick($avatars),
                    'sort_order' => $c,
                    'created_at' => self::dt($createdTs + 300 + $c * 60),
                    'updated_at' => self::dt($createdTs + 300 + $c * 60),
                ]);
                $children[] = ['id' => $cid, 'name' => $kid[0], 'favorite' => (int) self::pick($stories)['id']];
                self::add('children');
            }

            $users[] = ['id' => $uid, 'index' => $i, 'role' => $role, 'email' => $email, 'name' => $sur[0] . $giv[0], 'created' => $createdTs, 'children' => $children, 'voices' => []];
        }

        // 목소리
        $timeline = [];
        foreach ($users as $k => $u) {
            foreach (self::voicePlan($u['role']) as $plan) {
                $v = self::createVoice($u, $plan[0], $plan[1], $stories, $now, $adminId, $scriptKeys, $manifest, $timeline);
                $users[$k]['voices'][] = $v;
            }
        }

        // 재생 기록과 질문
        $sessionsFrom = $now - 30 * 86400;
        foreach ($users as $u) {
            self::createSessions($u, $storyById, $popular, max($u['created'] + 1800, $sessionsFrom), $now);
        }

        // 1:1 문의 3건(하나는 답변 완료)
        self::createInquiries($users, $now, $adminId);

        // 관리자 기록 2건(승인, 반려)
        self::createAudits($timeline, $adminId, $manifest);

        // 7일치 ElevenLabs 잔여 크레딧 기록
        self::createCreditSnapshots($now, $manifest);

        @file_put_contents(storage_path(self::MANIFEST), json_encode_u($manifest), LOCK_EX);

        return ['login' => $users[0]['email']];
    }

    /** 역할별 목소리 [호칭, 상태] */
    private static function voicePlan(string $role): array
    {
        $one = self::chance(0.8) ? '엄마' : '아빠';
        switch ($role) {
            case 'first':
                return [['엄마', 'completed'], ['아빠', 'processing']];
            case 'completed':
                return [[$one, 'completed']];
            case 'completed2':
                return [['엄마', 'completed'], [self::chance(0.6) ? '아빠' : '할머니', 'completed']];
            case 'pending':
                return [[$one, 'pending']];
            case 'pending_plus':
                return [['엄마', 'completed'], ['할머니', 'pending']];
            case 'cloning':
                return [['아빠', 'cloning']];
            case 'rejected':
                return [['엄마', 'completed'], ['아빠', 'rejected']];
            case 'failed':
                return [['엄마', 'completed'], ['할아버지', 'failed']];
        }

        return [];
    }

    private static function scriptKeys(): array
    {
        $file = APP_ROOT . '/app/Data/recording_scripts.php';
        if (!is_file($file)) {
            return [];
        }
        $scripts = require $file;

        return is_array($scripts) ? array_keys($scripts) : [];
    }

    /** 목소리 하나와 샘플, (만들어졌다면) 동화 오디오, 안내 음성, 사용 기록 */
    private static function createVoice(array $u, string $label, string $status, array $stories, int $now, ?int $adminId, array $scriptKeys, array &$manifest, array &$timeline): array
    {
        $created = $u['created'];
        $span = max(3600, $now - $created);
        $processed = null;
        $cloned = null;
        switch ($status) {
            case 'completed':
                $requested = $created + self::rnd(1800, (int) min(3 * 86400, $span * 0.4));
                $processed = min($requested + self::rnd(1800, 18 * 3600), $now - 3 * 3600);
                $requested = min($requested, $processed - 900);
                $cloned = $processed + self::rnd(60, 180);
                break;
            case 'processing':
                $requested = $now - self::rnd(20, 30) * 3600;
                $processed = $now - self::rnd(40, 60) * 60;
                $cloned = $processed + self::rnd(90, 150);
                break;
            case 'cloning':
                $requested = $now - self::rnd(18, 28) * 3600;
                $processed = $now - self::rnd(2, 6) * 60;
                break;
            case 'rejected':
                $requested = $now - self::rnd(3, 7) * 86400 - self::rnd(0, 20000);
                $processed = $requested + self::rnd(2, 20) * 3600;
                break;
            case 'failed':
                $requested = $now - self::rnd(2, 4) * 86400 - self::rnd(0, 20000);
                $processed = $requested + self::rnd(1, 10) * 3600;
                break;
            default: // pending
                $requested = $now - self::rnd(1, 40) * 3600;
        }
        $requested = max($requested, $created + 900);

        // 샘플 2~3개(브라우저 측정 품질 지표 포함)
        $sampleCount = self::rnd(2, 3);
        $quality = $status === 'rejected' ? 'poor' : ($status === 'failed' ? 'fair' : self::weighted(['good' => 75, 'fair' => 25]));
        $samples = [];
        $total = 0;
        for ($s = 0; $s < $sampleCount; $s++) {
            $ms = self::rnd(38000, 72000);
            $grade = $quality === 'poor' ? self::weighted(['poor' => 70, 'fair' => 30]) : ($quality === 'fair' ? self::weighted(['fair' => 70, 'good' => 30]) : self::weighted(['good' => 85, 'fair' => 15]));
            $snr = $grade === 'good' ? self::rf(25.5, 34.0) : ($grade === 'fair' ? self::rf(16.0, 24.5) : self::rf(8.0, 14.5));
            $noise = $grade === 'good' ? self::rf(-62, -50) : ($grade === 'fair' ? self::rf(-50, -42) : self::rf(-42, -33));
            $samples[] = [
                'ms' => $ms, 'grade' => $grade, 'snr' => round($snr, 1), 'noise' => round($noise, 1), 'peak' => round(self::rf(-7.5, -1.2), 1),
                'clips' => $grade === 'poor' ? self::rnd(3, 14) : ($grade === 'fair' ? self::rnd(0, 2) : 0),
                'source' => self::chance(0.85) ? 'record' : 'upload',
                'script' => $scriptKeys ? $scriptKeys[$s % count($scriptKeys)] : null,
            ];
            $total += $ms;
        }
        $score = 0.0;
        foreach ($samples as $smp) {
            $score += ['good' => 3, 'fair' => 2, 'poor' => 1][$smp['grade']] * $smp['ms'];
        }
        $avg = $score / max(1, $total);
        $overall = $avg >= 2.5 ? 'good' : ($avg >= 1.75 ? 'fair' : 'poor');

        $tuned = $status === 'completed' && self::chance(0.25);
        $batchDone = null;
        $pid = db_insert('voice_profiles', [
            'user_id' => $u['id'],
            'label' => $label,
            'icon' => null,
            'consent_at' => self::dt($requested),
            'consent_ip' => '127.0.0.1',
            'sample_total_ms' => $total,
            'quality_grade' => $overall,
            'status' => $status,
            'provider' => 'elevenlabs',
            'provider_voice_id' => $cloned ? 'demo' . bin2hex(random_bytes(8)) : null,
            'stability' => $tuned ? round(self::rf(0.55, 0.75), 2) : null,
            'similarity_boost' => $tuned ? round(self::rf(0.75, 0.9), 2) : null,
            'style' => $tuned ? round(self::rf(0.0, 0.2), 2) : null,
            'speaker_boost' => $tuned ? 1 : null,
            'requested_at' => self::dt($requested),
            'processed_at' => $processed ? self::dt($processed) : null,
            'processed_by' => $processed ? $adminId : null,
            'cloned_at' => $cloned ? self::dt($cloned) : null,
            'batch_status' => $status === 'completed' ? 'done' : ($status === 'processing' ? 'running' : 'none'),
            'reject_reason' => $status === 'rejected' ? '배경 소음(TV 소리)이 커서 목소리를 또렷하게 만들기 어려워요. 조용한 방에서 휴대폰을 입에서 한 뼘 정도 떨어뜨리고 다시 녹음해 주세요.' : null,
            'admin_memo' => $status === 'failed' ? '샘플 잡음 때문에 생성 실패. 재녹음 안내 예정' : null,
            'created_at' => self::dt($requested - 1800),
            'updated_at' => self::dt($processed ?: $requested),
        ]);
        self::add('voices');
        self::add('voice_' . $status);

        $t = $requested - 1500;
        foreach ($samples as $k => $smp) {
            $rel = 'voice-samples/' . $u['id'] . '/' . $pid . '/' . Storage::randomName('wav');
            $size = self::putWav($rel, 900, [220.0 + 30 * $k, 247.0 + 30 * $k]);
            $t += self::rnd(150, 420);
            db_insert('voice_samples', [
                'voice_profile_id' => $pid,
                'file_path' => $rel,
                'original_name' => $smp['source'] === 'record' ? '녹음 ' . ($k + 1) . '.wav' : $label . ' 목소리 ' . ($k + 1) . '.wav',
                'mime_type' => 'audio/wav',
                'file_size' => $size,
                'duration_ms' => $smp['ms'],
                'source' => $smp['source'],
                'script_key' => $smp['source'] === 'record' ? $smp['script'] : null,
                'snr_db' => $smp['snr'],
                'peak_db' => $smp['peak'],
                'noise_db' => $smp['noise'],
                'clip_count' => $smp['clips'],
                'quality_grade' => $smp['grade'],
                'created_at' => self::dt(min($t, $requested - 60)),
            ]);
            self::add('samples');
        }

        $voice = ['id' => $pid, 'label' => $label, 'status' => $status, 'ready_at' => null, 'audios' => []];
        if ($status === 'rejected') {
            $timeline[] = ['prio' => 1, 'action' => 'voice.reject', 'pid' => $pid, 'at' => $processed, 'detail' => ['reason' => '배경 소음이 커서 재녹음 필요']];
            self::jobLog($manifest, $pid, 'warn', '목소리 요청을 반려했습니다: 배경 소음이 커서 재녹음 필요', $processed);
        }
        if ($status === 'cloning') {
            self::jobLog($manifest, $pid, 'info', '목소리 생성을 승인했습니다. 작업을 등록했습니다.', $processed);
            self::jobLog($manifest, $pid, 'info', '샘플 ' . $sampleCount . '개(총 ' . self::koDuration($total) . ') 업로드', $processed + 40);
        }
        if ($status === 'failed') {
            self::usage($u['id'], 'elevenlabs', 'voice_clone', 'ivc', 'voice_profile', $pid, 'requests', 1, 0.0, self::rnd(3000, 8000), false, $processed + 70);
            self::jobLog($manifest, $pid, 'info', '샘플 ' . $sampleCount . '개(총 ' . self::koDuration($total) . ') 업로드', $processed + 30);
            self::jobLog($manifest, $pid, 'error', 'ElevenLabs 목소리 생성 실패: 샘플 음성의 잡음이 많아 목소리를 만들 수 없습니다.', $processed + 75);
        }
        if (!$cloned) {
            return $voice;
        }

        // 목소리 생성(ElevenLabs 즉석 복제)
        self::usage($u['id'], 'elevenlabs', 'voice_clone', 'ivc', 'voice_profile', $pid, 'requests', 1, 0.0, self::rnd(4000, 11000), true, $cloned);
        self::jobLog($manifest, $pid, 'info', 'ElevenLabs 목소리 생성 완료', $cloned);
        // 관리자 기록 후보: 지금 동화를 만드는 목소리의 승인을 먼저, 없으면 가장 최근에 준비된 목소리의 승인
        $timeline[] = ['prio' => $status === 'processing' ? 0 : 2, 'action' => 'voice.approve', 'pid' => $pid, 'at' => $processed,
            'detail' => ['stability' => (float) setting('elevenlabs.default_stability', 0.65), 'similarity_boost' => (float) setting('elevenlabs.default_similarity', 0.8), 'style' => (float) setting('elevenlabs.default_style', 0.0)]];

        // 동화 오디오: 준비됨이면 12편 모두, 동화 준비 중이면 9편 완료 + 1편 생성 중 + 나머지 대기
        $model = (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2');
        $gen = $cloned + self::rnd(40, 90);
        $doneCount = $status === 'completed' ? count($stories) : min(count($stories), 9);
        foreach ($stories as $k => $s) {
            $sid = (int) $s['id'];
            if ($k < $doneCount) {
                $gen += self::rnd(80, 150);
                if ($status === 'processing') {
                    $gen = min($gen, $now - 120);
                }
                $duration = (int) (round($s['est_ms'] * self::rf(0.94, 1.08) / 10) * 10);
                $timings = self::timings($s['sentences'], $duration);
                $rel = 'story-audio/' . $pid . '/' . $sid . '-' . substr((string) $s['content_hash'], 0, 8) . '.wav';
                $size = self::putWav($rel, 1500, [392.0, 440.0, 523.25, 440.0]);
                $aid = db_insert('story_audios', [
                    'story_id' => $sid,
                    'voice_profile_id' => $pid,
                    'status' => 'completed',
                    'file_path' => $rel,
                    'duration_ms' => $duration,
                    'sentence_timings' => json_encode_u($timings),
                    'content_hash' => $s['content_hash'],
                    'model_id' => $model,
                    'file_size' => $size,
                    'char_count' => (int) $s['char_count'],
                    'attempts' => 1,
                    'generated_at' => self::dt($gen),
                    'created_at' => self::dt($cloned + 30),
                    'updated_at' => self::dt($gen),
                ]);
                self::usage($u['id'], 'elevenlabs', 'story_tts', $model, 'story_audio', $aid, 'chars', (int) $s['char_count'],
                    self::elevenCost((int) $s['char_count'], $model), self::rnd(9000, 26000), true, $gen);
                if ($status === 'processing') {
                    self::jobLog($manifest, $pid, 'info', "'" . $s['title'] . "' 오디오 생성 완료 (길이 " . fmt_duration($duration) . ', ' . number_format((int) $s['char_count']) . '자)', $gen);
                }
                $voice['audios'][$sid] = ['id' => $aid, 'duration' => $duration, 'timings' => $timings];
                self::add('story_audios');
            } else {
                db_insert('story_audios', [
                    'story_id' => $sid,
                    'voice_profile_id' => $pid,
                    'status' => $k === $doneCount ? 'processing' : 'pending',
                    'char_count' => (int) $s['char_count'],
                    'attempts' => $k === $doneCount ? 1 : 0,
                    'created_at' => self::dt($cloned + 30),
                    'updated_at' => self::dt($k === $doneCount ? $now - 60 : $cloned + 30),
                ]);
            }
        }
        if ($status === 'completed') {
            $batchDone = $gen + self::rnd(20, 60);
            db_update('voice_profiles', ['batch_done_at' => self::dt($batchDone), 'updated_at' => self::dt($batchDone)], 'id = ?', [$pid]);
            self::jobLog($manifest, $pid, 'info', '동화 ' . count($stories) . '편 오디오 생성 완료', $batchDone);
            $voice['ready_at'] = $batchDone;
        }

        // 안내 음성(질문 한도 초과 대체 문장, 오류 안내)
        $clipModel = (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5');
        $clipAt = $cloned + 50;
        foreach (self::clipLines($stories) as $h => $line) {
            $rel = 'clips/' . $pid . '/' . Storage::randomName('wav');
            self::putWav($rel, 700, [523.25, 392.0]);
            $chars = mb_strlen($line['text']);
            $cid = db_insert('voice_clips', [
                'voice_profile_id' => $pid,
                'kind' => $line['kind'],
                'text' => $line['text'],
                'text_hash' => $h,
                'status' => 'completed',
                'file_path' => $rel,
                'duration_ms' => $chars * self::rnd(120, 150),
                'created_at' => self::dt($clipAt),
                'updated_at' => self::dt($clipAt),
            ]);
            self::usage($u['id'], 'elevenlabs', 'clip_tts', $clipModel, 'voice_clip', $cid, 'chars', $chars, self::elevenCost($chars, $clipModel), self::rnd(400, 900), true, $clipAt);
            $clipAt += self::rnd(2, 5);
            self::add('clips');
        }

        return $voice;
    }

    /** 질문 처리(QuestionService)가 찾는 것과 같은 해시(앞뒤 공백을 뗀 문장의 sha256)로 안내 문장을 모은다. */
    public static function clipLines(array $stories): array
    {
        $lines = [];
        foreach (['qa.fallback_lines' => 'fallback', 'qa.error_lines' => 'error'] as $key => $kind) {
            $list = array_filter((array) setting($key, []), function ($t) {
                return is_string($t) && trim($t) !== '';
            });
            if (!$list) {
                $list = (array) \App\Core\Settings::DEFAULTS[$key];
            }
            foreach ($list as $t) {
                $t = trim((string) $t);
                if ($t !== '' && mb_strlen($t) <= 500) {
                    $lines[hash('sha256', $t)] = ['kind' => $kind, 'text' => $t];
                }
            }
        }
        foreach ($stories as $s) {
            foreach (json_decode_array(isset($s['fallback_lines']) ? $s['fallback_lines'] : null) as $t) {
                $t = is_string($t) ? trim($t) : '';
                if ($t !== '' && mb_strlen($t) <= 500 && !isset($lines[hash('sha256', $t)])) {
                    $lines[hash('sha256', $t)] = ['kind' => 'fallback', 'text' => $t];
                }
            }
        }

        return $lines;
    }

    private static function koDuration(int $ms): string
    {
        $sec = (int) round($ms / 1000);

        return intdiv($sec, 60) . '분' . ($sec % 60 ? ' ' . ($sec % 60) . '초' : '');
    }

    private static function jobLog(array &$manifest, int $pid, string $level, string $message, int $at): void
    {
        $manifest['job_log_ids'][] = db_insert('job_logs', [
            'job_id' => null,
            'ref_type' => 'voice_profile',
            'ref_id' => $pid,
            'level' => $level,
            'message' => mb_substr($message, 0, 1000),
            'created_at' => self::dt($at),
        ]);
    }

    // ───────────────────────── 비용 ─────────────────────────

    private static function elevenCost(int $chars, string $model): float
    {
        $m = strtolower($model);
        $ratio = (strpos($m, 'flash') !== false || strpos($m, 'turbo') !== false) ? (float) setting('elevenlabs.flash_credit_ratio', 0.5) : 1.0;

        return $chars * $ratio / 1000 * (float) setting('elevenlabs.usd_per_1k_credits', 0.30);
    }

    private static function geminiCost(int $input, int $output, int $audio): float
    {
        return max(0, $input - $audio) / 1000000 * (float) setting('gemini.usd_per_1m_input', 0.30)
            + $audio / 1000000 * (float) setting('gemini.usd_per_1m_audio_input', 1.00)
            + $output / 1000000 * (float) setting('gemini.usd_per_1m_output', 2.50);
    }

    private static function krw(float $usd): float
    {
        return $usd * (float) setting('cost.usd_krw', 1400);
    }

    private static function usage(int $userId, string $provider, string $purpose, string $model, string $refType, int $refId, string $unitType, int $units, float $usd, int $latency, bool $ok, int $at): void
    {
        db_insert('api_usage_logs', [
            'provider' => $provider,
            'purpose' => $purpose,
            'model' => $model,
            'user_id' => $userId,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'unit_type' => $unitType,
            'units' => $units,
            'cost_usd' => round($usd, 6),
            'cost_krw' => round(self::krw($usd), 2),
            'latency_ms' => $latency,
            'success' => $ok ? 1 : 0,
            'created_at' => self::dt($at),
        ]);
        self::add('usage');
        self::add('cost_krw', round(self::krw($usd), 2));
    }

    // ───────────────────────── 재생 기록 ─────────────────────────

    private static function createSessions(array $u, array $storyById, array $popular, int $from, int $now): void
    {
        if (!$u['children'] || $from >= $now) {
            return;
        }
        $rate = $u['index'] === 0 ? 1.5 : self::weighted(['1.1' => 25, '0.6' => 45, '0.25' => 30]) + 0.0;
        $hours = [7 => 2, 8 => 2, 12 => 1, 13 => 2, 15 => 2, 16 => 2, 17 => 3, 18 => 5, 19 => 12, 20 => 18, 21 => 16, 22 => 7, 23 => 2];
        $starts = [];
        for ($day = (int) floor(($now - $from) / 86400); $day >= 0; $day--) {
            $base = strtotime(date('Y-m-d 00:00:00', $now - $day * 86400));
            $weekend = in_array((int) date('N', $base), [6, 7], true);
            $expected = $rate * ($weekend ? 1.3 : 1.0);
            $n = (int) floor($expected) + (self::chance($expected - floor($expected)) ? 1 : 0);
            for ($k = 0; $k < $n; $k++) {
                $ts = $base + (int) self::weighted($hours) * 3600 + self::rnd(0, 3599);
                if ($ts >= $from && $ts < $now - 900) {
                    $starts[] = $ts;
                }
            }
        }
        sort($starts);

        $lastEnd = 0;
        $count = count($starts);
        foreach ($starts as $idx => $start) {
            $start = max($start, $lastEnd + self::rnd(60, 900));
            if ($start >= $now - 600) {
                continue;
            }
            $isLast = $idx === $count - 1;
            $child = self::pick($u['children']);
            // 아이마다 좋아하는 동화를 더 자주 듣는다.
            $weights = $popular;
            $weights[$child['favorite']] = (isset($weights[$child['favorite']]) ? $weights[$child['favorite']] : 5) + 14;
            $storyId = (int) self::weighted($weights);

            $ready = [];
            foreach ($u['voices'] as $v) {
                if ($v['status'] === 'completed' && $v['ready_at'] !== null && $v['ready_at'] < $start) {
                    $ready[] = $v;
                }
            }
            $voice = $ready && !self::chance(0.08) ? ($ready[0]['label'] === '엄마' && self::chance(0.65) ? $ready[0] : self::pick($ready)) : null;

            // 데모 로그인 회원의 마지막 기록은 '다정한 용의 비밀'을 엄마 목소리로 듣다 멈춘 상태(이어 듣기 확인용)
            $resume = $u['index'] === 0 && $isLast;
            if ($resume) {
                foreach ($storyById as $sid => $s) {
                    if ($s['code'] === 'Fairytale-010') {
                        $storyId = $sid;
                    }
                }
                $voice = $ready ? $ready[0] : null;
            }
            $story = $storyById[$storyId];
            if ($voice && isset($voice['audios'][$storyId])) {
                $duration = $voice['audios'][$storyId]['duration'];
                $timings = $voice['audios'][$storyId]['timings'];
            } else {
                $voice = null;
                $duration = $story['est_ms'];
                $timings = $story['device_timings'];
            }

            $completed = $resume ? false : self::chance(0.75);
            $listened = $completed ? (int) round($duration * self::rf(0.97, 1.0)) : (int) round($duration * ($resume ? 0.36 : self::rf(0.08, 0.85)));
            $lastSeq = $completed ? (int) end($timings['sentences'])['seq'] : self::seqAt($timings, $listened);
            $position = $completed ? $duration : $listened;

            // 질문 수: 0~3개(듣다 일찍 멈추면 적게), 3개를 다 쓰면 가끔 한도 초과 질문
            $answers = (int) self::weighted(['0' => 30, '1' => 30, '2' => 22, '3' => 18]);
            if ($resume) {
                $answers = 1;
            } elseif (!$completed && $listened < $duration * 0.3) {
                $answers = min($answers, 1);
            }
            $quota = $answers === 3 ? (int) self::weighted(['0' => 55, '1' => 35, '2' => 10]) : 0;

            $qa = self::planQuestions($story, $timings, $lastSeq, $answers + $quota);
            $qaTime = count($qa) * self::rnd(11, 19) * 1000;
            $endTs = $start + (int) round(($listened + $qaTime) / 1000);

            $sessionId = db_insert('play_sessions', [
                'user_id' => $u['id'],
                'child_id' => $child['id'],
                'story_id' => $storyId,
                'voice_profile_id' => $voice ? $voice['id'] : null,
                'audio_source' => $voice ? 'voice' : 'device',
                'question_count' => min($answers, count($qa)),
                'fallback_count' => max(0, count($qa) - $answers),
                'last_sentence_seq' => $lastSeq,
                'last_position_ms' => $position,
                'listened_ms' => $listened,
                'completed' => $completed ? 1 : 0,
                'completed_at' => $completed ? self::dt($endTs) : null,
                'started_at' => self::dt($start),
                'ended_at' => self::dt($endTs),
                'updated_at' => self::dt($endTs),
            ]);
            self::add('sessions');
            $lastEnd = $endTs;

            $who = $voice ? $voice['label'] : (self::chance(0.7) ? '엄마' : '아빠');
            $offset = 0;
            $fallbackIdx = 0;
            foreach ($qa as $n => $q) {
                $at = $start + (int) round(($q['position'] + $offset) / 1000);
                $offset += self::rnd(11, 19) * 1000;
                if ($n < $answers) {
                    self::createAnswer($u['id'], $sessionId, $q, $voice, $who, $at);
                } else {
                    self::createQuota($sessionId, $q, $story, $fallbackIdx++, $at);
                }
            }
        }
    }

    /** 들은 범위 안에서 질문할 문장과 질문 내용을 고른다(문장 순서대로). */
    private static function planQuestions(array $story, array $timings, int $lastSeq, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $bySeq = [];
        foreach ($story['sentences'] as $s) {
            if ((int) $s['seq'] <= $lastSeq) {
                $bySeq[(int) $s['seq']] = $s;
            }
        }
        if (!$bySeq) {
            return [];
        }
        $tm = [];
        foreach ($timings['sentences'] as $t) {
            $tm[(int) $t['seq']] = $t;
        }
        $crafted = self::crafted();
        $pool = isset($crafted[$story['code']]) ? $crafted[$story['code']] : [];
        shuffle($pool);
        $out = [];
        $usedSeq = [];
        $usedQ = [];
        foreach ($pool as $c) {
            if (count($out) >= $count) {
                break;
            }
            $cands = [];
            foreach ($bySeq as $seq => $s) {
                if (!isset($usedSeq[$seq]) && array_intersect($c['t'], Text::keywords((string) $s['keywords']))) {
                    $cands[] = $seq;
                }
            }
            if ($cands) {
                $seq = self::pick($cands);
                $out[] = ['seq' => $seq, 'q' => $c['q'], 'a' => $c['a'], 'e' => $c['e'], 'kw' => ''];
                $usedSeq[$seq] = true;
                $usedQ[$c['q']] = true;
            }
        }
        $generic = self::generic();
        for ($guard = 0; count($out) < $count && $guard < 40; $guard++) {
            $seq = (int) self::pick(array_keys($bySeq));
            if (isset($usedSeq[$seq]) && count($usedSeq) < count($bySeq)) {
                continue;
            }
            $kws = array_values(array_diff(Text::keywords((string) $bySeq[$seq]['keywords']), self::STOP_WORDS));
            $g = self::pick($generic);
            if ($g['kw'] && !$kws) {
                continue;
            }
            if (isset($usedQ[$g['q']]) && !$g['kw']) {
                continue;
            }
            $out[] = ['seq' => $seq, 'q' => $g['q'], 'a' => $g['a'], 'e' => $g['e'], 'kw' => $kws ? self::pick($kws) : ''];
            $usedSeq[$seq] = true;
            $usedQ[$g['q']] = true;
        }
        usort($out, function ($a, $b) {
            return $a['seq'] - $b['seq'];
        });
        foreach ($out as $k => $q) {
            $t = isset($tm[$q['seq']]) ? $tm[$q['seq']] : ['start' => 0, 'end' => 0];
            $out[$k]['position'] = self::rnd((int) $t['start'], max((int) $t['start'], (int) $t['end']));
        }

        return $out;
    }

    private static function createAnswer(int $userId, int $sessionId, array $q, ?array $voice, string $who, int $at): void
    {
        $self = $who !== '' ? $who : '엄마';
        $question = self::fill($q['q'], $q['kw'], self::chance(0.55) ? $who : '', $self);
        $answer = self::fill($q['a'], $q['kw'], '', $self);
        $answer = mb_substr($answer, 0, (int) setting('qa.max_answer_chars', 120));
        $audioTokens = self::rnd(90, 170);
        $input = self::rnd(950, 1500) + $audioTokens;
        $output = self::rnd(45, 95);
        $llmMs = self::rnd(620, 1150);
        $ttsMs = $voice ? self::rnd(260, 560) : null;
        $latency = max(900, min(1800, $llmMs + ($ttsMs ?: 0) + self::rnd(90, 260)));
        $llmUsd = self::geminiCost($input, $output, $audioTokens);
        $answerModel = (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5');
        $chars = mb_strlen($answer);
        $ttsUsd = $voice ? self::elevenCost($chars, $answerModel) : 0.0;

        $qRel = 'questions/' . date('Ym', $at) . '/' . Storage::randomName('wav');
        self::putWav($qRel, 600, [330.0, 370.0]);
        $aRel = null;
        if ($voice) {
            $aRel = 'answers/' . date('Ym', $at) . '/' . Storage::randomName('wav');
            self::putWav($aRel, 800, [440.0, 494.0, 523.25]);
        }
        $iid = db_insert('interactions', [
            'play_session_id' => $sessionId,
            'sentence_seq' => $q['seq'],
            'position_ms' => $q['position'],
            'mode' => 'answer',
            'question_audio_path' => $qRel,
            'question_text' => $question,
            'answer_text' => $answer,
            'answer_audio_path' => $aRel,
            'emotion' => $q['e'],
            'llm_input_tokens' => $input,
            'llm_output_tokens' => $output,
            'tts_chars' => $voice ? $chars : 0,
            'cost_krw' => round(self::krw($llmUsd + $ttsUsd), 2),
            'latency_ms' => $latency,
            'llm_ms' => $llmMs,
            'tts_ms' => $ttsMs,
            'created_at' => self::dt($at),
        ]);
        self::usage($userId, 'gemini', 'answer_llm', (string) setting('gemini.model', 'gemini-2.5-flash'), 'interaction', $iid, 'tokens', $input + $output, $llmUsd, $llmMs, true, $at);
        if ($voice) {
            self::usage($userId, 'elevenlabs', 'answer_tts', $answerModel, 'interaction', $iid, 'chars', $chars, $ttsUsd, (int) $ttsMs, true, $at + 1);
        }
        self::add('interactions');
        self::add('answers');
    }

    private static function createQuota(int $sessionId, array $q, array $story, int $fallbackIdx, int $at): void
    {
        $lines = array_values(array_filter(json_decode_array(isset($story['fallback_lines']) ? $story['fallback_lines'] : null), 'is_string'));
        if (!$lines) {
            $lines = array_values(array_filter((array) setting('qa.fallback_lines', []), 'is_string'));
        }
        if (!$lines) {
            $lines = (array) \App\Core\Settings::DEFAULTS['qa.fallback_lines'];
        }
        $qRel = 'questions/' . date('Ym', $at) . '/' . Storage::randomName('wav');
        self::putWav($qRel, 600, [330.0, 370.0]);
        db_insert('interactions', [
            'play_session_id' => $sessionId,
            'sentence_seq' => $q['seq'],
            'position_ms' => $q['position'],
            'mode' => 'quota',
            'question_audio_path' => $qRel,
            'question_text' => null,
            'answer_text' => trim($lines[$fallbackIdx % count($lines)]),
            'latency_ms' => self::rnd(120, 320),
            'created_at' => self::dt($at),
        ]);
        self::add('interactions');
        self::add('quota');
    }

    // ───────────────────────── 문의, 관리자 기록, 크레딧 ─────────────────────────

    private static function createInquiries(array $users, int $now, ?int $adminId): void
    {
        $byRole = [];
        foreach ($users as $u) {
            $byRole[$u['role']][] = $u;
        }
        $pickUser = function (array $roles) use ($byRole, $users) {
            foreach ($roles as $r) {
                if (!empty($byRole[$r])) {
                    return $byRole[$r][0];
                }
            }

            return $users[count($users) > 1 ? 1 : 0];
        };
        $items = [
            [
                'user' => $pickUser(['rejected', 'completed']),
                'category' => 'voice',
                'title' => '아빠 목소리 재녹음은 어떻게 하나요?',
                'body' => "아빠 목소리가 '재녹음 필요'로 바뀌었어요. 거실에서 TV를 켜 둔 채 녹음해서 그런 것 같아요.\n기존 녹음을 지우고 다시 녹음하면 되나요? 다시 신청하면 얼마나 걸리는지도 궁금해요.",
                'status' => 'open',
                'ago' => self::rnd(20, 40) * 3600,
            ],
            [
                'user' => $pickUser(['completed2', 'completed']),
                'category' => 'playback',
                'title' => '아이 질문 뒤에 동화가 가끔 바로 이어지지 않아요',
                'body' => "아이가 중간에 질문하면 엄마 목소리로 대답은 잘 나오는데, 가끔 대답이 끝나고 동화가 바로 이어지지 않고 멈춰 있어요.\n아이폰 사파리에서 듣고 있습니다.",
                'status' => 'answered',
                'answer' => "안녕하세요, 르멤버 운영팀입니다.\n아이폰 사파리에서 화면이 꺼지면 소리 재생이 잠시 멈출 수 있어요. 재생 중에는 화면을 켜 두시거나, 멈췄을 때 재생 버튼을 한 번 눌러 주시면 멈춘 문장부터 이어서 들을 수 있습니다.\n같은 증상이 계속되면 사용 중인 iOS 버전을 알려 주세요. 감사합니다.",
                'ago' => self::rnd(4, 6) * 86400,
            ],
            [
                'user' => $pickUser(['pending_plus', 'completed']),
                'category' => 'account',
                'title' => '둘째 아이도 등록하고 싶어요',
                'body' => '첫째 아이만 등록되어 있는데 둘째도 같이 듣고 있어요. 아이를 한 명 더 등록하면 학습 리포트도 따로 볼 수 있나요?',
                'status' => 'open',
                'ago' => self::rnd(2, 8) * 3600,
            ],
        ];
        foreach ($items as $it) {
            $u = $it['user'];
            $created = max($u['created'] + 3600, $now - $it['ago']);
            $answered = $it['status'] === 'answered' ? min($now - 600, $created + self::rnd(3, 20) * 3600) : null;
            db_insert('inquiries', [
                'user_id' => $u['id'],
                'category' => $it['category'],
                'title' => $it['title'],
                'body' => $it['body'],
                'status' => $it['status'],
                'answer' => isset($it['answer']) ? $it['answer'] : null,
                'answered_by' => $answered ? $adminId : null,
                'answered_at' => $answered ? self::dt($answered) : null,
                'created_at' => self::dt($created),
                'updated_at' => self::dt($answered ?: $created),
            ]);
            self::add('inquiries');
        }
    }

    private static function createAudits(array $timeline, ?int $adminId, array &$manifest): void
    {
        usort($timeline, function ($a, $b) {
            return $a['prio'] !== $b['prio'] ? $a['prio'] - $b['prio'] : $b['at'] - $a['at'];
        });
        $chosen = array_slice($timeline, 0, 2);
        usort($chosen, function ($a, $b) {
            return $a['at'] - $b['at'];
        });
        foreach ($chosen as $t) {
            $manifest['audit_ids'][] = db_insert('admin_audit_logs', [
                'admin_id' => $adminId,
                'action' => $t['action'],
                'target_type' => 'voice_profile',
                'target_id' => $t['pid'],
                'detail' => json_encode_u($t['detail']),
                'ip' => '127.0.0.1',
                'created_at' => self::dt($t['at']),
            ]);
            self::add('audits');
        }
    }

    /** 하루 한 번(오전 9시 무렵) 잔여 크레딧을 확인한 기록. 사이에 쓴 크레딧은 위에서 만든 사용 기록으로 계산한다. */
    private static function createCreditSnapshots(int $now, array &$manifest): void
    {
        $limit = 500000;
        $ratio = (string) (float) setting('elevenlabs.flash_credit_ratio', 0.5);
        $points = [];
        for ($d = 6; $d >= 0; $d--) {
            $ts = strtotime(date('Y-m-d 09:00:00', $now - $d * 86400)) + self::rnd(0, 1500);
            $points[] = min($ts, $now - 300);
        }
        $usedBetween = function (int $from, int $to) use ($ratio) {
            return (float) db_value(
                "SELECT COALESCE(SUM(CASE WHEN model LIKE '%flash%' OR model LIKE '%turbo%' THEN units * ? ELSE units END), 0)
                   FROM api_usage_logs WHERE provider = 'elevenlabs' AND unit_type = 'chars' AND success = 1 AND created_at >= ? AND created_at < ?",
                [$ratio, self::dt($from), self::dt($to)]
            );
        };
        // 첫 기록 시점 잔량: 이번 기간 앞서 쓴 양을 뺀 값(너무 적으면 넉넉히)
        $window = $usedBetween($points[0], $now);
        $before = $usedBetween($points[0] - 20 * 86400, $points[0]);
        $remaining = (int) round(max($window + 15000, $limit - $before - self::rnd(2000, 6000)));
        $remaining = min($limit, $remaining);
        $prevTs = null;
        foreach ($points as $ts) {
            $estimate = null;
            if ($prevTs !== null) {
                $estimate = (int) round($remaining - $usedBetween($prevTs, $ts));
                // 미리듣기 같은 앱 밖 사용으로 실제 잔량이 조금 더 줄어든다.
                $remaining = max(0, $estimate - self::rnd(0, 180));
            }
            $manifest['credit_snapshot_ids'][] = db_insert('provider_credit_snapshots', [
                'provider' => 'elevenlabs',
                'remaining_units' => $remaining,
                'limit_units' => $limit,
                'internal_estimate' => $estimate,
                'checked_at' => self::dt($ts),
            ]);
            self::add('snapshots');
            $prevTs = $ts;
        }
    }
}

// 테스트에서 함수만 쓰려면 require 전에 DEMO_SEED_LIBRARY 를 정의한다.
if (!defined('DEMO_SEED_LIBRARY')) {
    exit(DemoSeed::main($argv));
}
