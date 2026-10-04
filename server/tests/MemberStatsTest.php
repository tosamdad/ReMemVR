<?php
/**
 * 회원 통계(MemberStats)와 관리자 운영 메뉴의 순수 함수 테스트. DB 없이 돈다.
 */
use App\Controllers\Admin\AdminAccountController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\InquiryController;
use App\Controllers\Admin\NoticeController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\StoryController;
use App\Core\Settings;
use App\Services\MemberStats;

// ───────────── 백분위수, 레이턴시 ─────────────

test('백분위수는 가장 가까운 순위 방식이다', function () {
    $v = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];
    assert_same(500, MemberStats::percentile($v, 50));
    assert_same(1000, MemberStats::percentile($v, 95));
    assert_same(100, MemberStats::percentile($v, 1));
    assert_same(null, MemberStats::percentile([], 95));
    assert_same(42, MemberStats::percentile(['42'], 99), '한 개면 그 값');
    assert_same(300, MemberStats::percentile([900, 300, 100], 50), '정렬 후 계산');
});

test('레이턴시 요약: 단계별 평균과 안정 권역 판정', function () {
    $rows = [
        ['latency_ms' => 1000, 'llm_ms' => 600, 'tts_ms' => 300],
        ['latency_ms' => 1200, 'llm_ms' => 700, 'tts_ms' => 400],
        ['latency_ms' => 1400, 'llm_ms' => null, 'tts_ms' => null],
    ];
    $s = MemberStats::latencyFromRows($rows);
    assert_same(3, $s['samples']);
    assert_same(1200, $s['p50']);
    assert_same(1400, $s['p95']);
    assert_same(1200, $s['avg']);
    assert_same(650, $s['llm_avg'], 'LLM 은 값이 있는 행만 평균');
    assert_same(350, $s['tts_avg']);
    // 중간 단계: 100, 100, 1400 → 평균 533
    assert_same(533, $s['mid_avg']);
    assert_true($s['stable'], '1800ms 미만이면 안정');
    assert_same((int) round((1800 - 1400) * 100 / 1800), $s['margin_pct']);
});

test('레이턴시 기록이 없으면 null 과 불안정으로 둔다', function () {
    $s = MemberStats::latencyFromRows([]);
    assert_same(0, $s['samples']);
    assert_same(null, $s['p95']);
    assert_same(null, $s['avg']);
    assert_same(false, $s['stable']);
    assert_same(null, $s['margin_pct']);
});

test('P95 가 한계 이상이면 주의 권역', function () {
    $rows = [];
    for ($i = 0; $i < 20; $i++) {
        $rows[] = ['latency_ms' => $i < 18 ? 900 : 2500, 'llm_ms' => 500, 'tts_ms' => 300];
    }
    $s = MemberStats::latencyFromRows($rows);
    assert_same(2500, $s['p95']);
    assert_same(false, $s['stable']);
    assert_true($s['margin_pct'] < 0);
});

// ───────────── 히트맵 ─────────────

test('문장 순번을 10개 구간으로 나눈다', function () {
    assert_same(0, MemberStats::segmentOf(1, 30));
    assert_same(0, MemberStats::segmentOf(3, 30));
    assert_same(1, MemberStats::segmentOf(4, 30));
    assert_same(9, MemberStats::segmentOf(30, 30));
    assert_same(9, MemberStats::segmentOf(99, 30), '범위를 넘으면 마지막 구간');
    assert_same(0, MemberStats::segmentOf(1, 0), '문장이 없으면 0');
    // 문장이 10개보다 적으면 일부 구간이 빈다
    assert_same(0, MemberStats::segmentOf(1, 4));
    assert_same(7, MemberStats::segmentOf(4, 4));
});

test('히트맵: 질문 수를 구간별로 모으고 피크를 찾는다', function () {
    $sentences = [];
    for ($i = 1; $i <= 20; $i++) {
        $sentences[] = ['seq' => $i, 'content' => '문장 ' . $i, 'keywords' => $i === 13 ? '고래, 바다' : ''];
    }
    $counts = [1 => 2, 13 => 5, 14 => 1, 20 => 3];
    $timings = [];
    for ($i = 1; $i <= 20; $i++) {
        $timings[$i] = [($i - 1) * 4000, $i * 4000];
    }
    $h = MemberStats::buildHeatmap(['id' => 7], $sentences, $counts, $timings);
    assert_same(20, $h['sentence_count']);
    assert_same(11, $h['counted']);
    assert_same(6, $h['max_count'], '13, 14번은 같은 구간(7번째)');
    assert_same(6, $h['peak']);
    assert_same(10, count($h['segments']));
    assert_same(13, $h['segments'][6]['first_seq']);
    assert_same(14, $h['segments'][6]['last_seq']);
    assert_same('고래', $h['segments'][6]['label'], '구간 첫 문장의 첫 키워드');
    assert_same(48000, $h['segments'][6]['start_ms']);
    assert_same(56000, $h['segments'][6]['end_ms']);
    assert_same(80000, $h['end_ms']);
    assert_true($h['has_times']);
    assert_true(abs($h['segments'][6]['share'] - 6 / 11) < 0.0001);
});

test('히트맵: 질문이 없으면 피크가 없다', function () {
    $h = MemberStats::buildHeatmap(['id' => 1], [['seq' => 1, 'content' => '하나', 'keywords' => '']], [], []);
    assert_same(0, $h['counted']);
    assert_same(null, $h['peak']);
    assert_same(false, $h['has_times']);
    assert_same(null, $h['end_ms']);
});

// ───────────── 이용 시간대, 회원 표시 ─────────────

test('주 이용 시간대: 가장 많은 시각, 같으면 늦은 시각', function () {
    assert_same(21, MemberStats::primaryHour([20 => 3, 21 => 5, 8 => 1]));
    assert_same(21, MemberStats::primaryHour([20 => 4, 21 => 4]));
    assert_same(null, MemberStats::primaryHour([]));
    assert_same(null, MemberStats::primaryHour([9 => 0]));
});

test('시각대 이름과 범위 문구', function () {
    assert_same('아침', MemberStats::hourLabel(7));
    assert_same('낮잠', MemberStats::hourLabel(14));
    assert_same('낮', MemberStats::hourLabel(11));
    assert_same('저녁', MemberStats::hourLabel(19));
    assert_same('취침 전', MemberStats::hourLabel(21));
    assert_same('심야', MemberStats::hourLabel(23));
    assert_same('심야', MemberStats::hourLabel(2));
    assert_same('21:00 ~ 22:00 (취침 전)', MemberStats::hourRange(21));
    assert_same('23:00 ~ 00:00 (심야)', MemberStats::hourRange(23));
});

test('회원 번호 검색어를 읽는다', function () {
    assert_same(12, MemberStats::parseMemberId('#RM-0012'));
    assert_same(12, MemberStats::parseMemberId('rm12'));
    assert_same(5, MemberStats::parseMemberId('5'));
    assert_same(7, MemberStats::parseMemberId('#7'));
    assert_same(null, MemberStats::parseMemberId('김민지'));
    assert_same(null, MemberStats::parseMemberId('RM-abc'));
    assert_same('#RM-0012', MemberStats::memberCode(12));
    assert_same('#RM-12345', MemberStats::memberCode(12345));
});

test('회원 상태: 탈퇴 > 정지 > 목소리 준비 여부', function () {
    $u = ['status' => 'active', 'deleted_at' => null];
    assert_same('active', MemberStats::memberStatus($u, [['status' => 'draft'], ['status' => 'completed']])['key']);
    assert_same('active', MemberStats::memberStatus($u, [['status' => 'processing']])['key'], '동화 생성 중이어도 목소리는 준비됨');
    assert_same('voice_waiting', MemberStats::memberStatus($u, [['status' => 'pending']])['key']);
    assert_same('voice_needed', MemberStats::memberStatus($u, [['status' => 'rejected']])['key']);
    assert_same('voice_needed', MemberStats::memberStatus($u, [])['key']);
    assert_same('blocked', MemberStats::memberStatus(['status' => 'blocked', 'deleted_at' => null], [['status' => 'completed']])['key']);
    assert_same('withdrawn', MemberStats::memberStatus(['status' => 'active', 'deleted_at' => '2026-01-01 00:00:00'], [])['key']);
    assert_same('정상 이용중', MemberStats::memberStatus($u, [['status' => 'completed']])['label']);
});

test('자녀 표시 문구', function () {
    $year = (int) date('Y');
    assert_same('박시우 (5세, 남아)', MemberStats::childText(['name' => '박시우', 'birth_year' => $year - 6, 'gender' => 'boy']));
    assert_same('하은 (여아)', MemberStats::childText(['name' => '하은', 'gender' => 'girl']));
    assert_same('도윤', MemberStats::childText(['name' => '도윤']));
});

test('ElevenLabs 모델 이름 표시', function () {
    assert_same('ElevenLabs Flash v2.5', MemberStats::modelLabel('eleven_flash_v2_5'));
    assert_contains('Multilingual', MemberStats::modelLabel('eleven_multilingual_v2'));
});

// ───────────── 동화 CMS 입력 ─────────────

test('타임코드 읽기', function () {
    assert_same(null, StoryController::parseTime(''));
    assert_same(83000, StoryController::parseTime('01:23'));
    assert_same(83500, StoryController::parseTime('1:23.5'));
    assert_same(3723000, StoryController::parseTime('1:02:03'));
    assert_same(false, StoryController::parseTime('1:75'));
    assert_same(false, StoryController::parseTime('abc'));
    assert_same(false, StoryController::parseTime('12'));
});

test('문장 표 정리: 빈 행은 건너뛰고 오류는 행 번호로', function () {
    list($rows, $errors) = StoryController::parseSentences([
        ['content' => '  옛날 옛날에  ', 'keywords' => '옛날, 시작,  ', 'start' => '00:00', 'end' => '00:04'],
        ['content' => '', 'keywords' => '', 'start' => '', 'end' => ''],
        ['content' => '', 'keywords' => '숲', 'start' => '', 'end' => ''],
        ['content' => '토끼가 살았어요.', 'keywords' => '', 'start' => '00:09', 'end' => '00:05'],
        'not-array',
    ]);
    assert_same(2, count($rows));
    assert_same('옛날 옛날에', $rows[0]['content']);
    assert_same(0, $rows[0]['start']);
    assert_same(4000, $rows[0]['end']);
    assert_true(isset($errors['sentences.2.content']), '내용 없이 키워드만 있는 행');
    assert_true(isset($errors['sentences.3.end']), '종료가 시작보다 빠름');
    assert_true(!isset($errors['sentences.1.content']), '완전히 빈 행은 오류 아님');
});

// ───────────── 운영 설정 ─────────────

test('설정 정리: 숫자 범위 맞춤, 형식 오류, 체크박스, 목록', function () {
    $current = Settings::DEFAULTS;
    $current['elevenlabs.model_story'] = 'eleven_turbo_v2_5';
    list($clean, $errors) = SettingsController::normalize([
        'qa.max_questions' => '99',
        'qa.vad_min_speech_ms' => '1,200',
        'qa.max_answer_chars' => 'abc',
        'qa.enabled' => ['0', '1'],
        'elevenlabs.speaker_boost' => '0',
        'elevenlabs.model_story' => 'eleven_turbo_v2_5',
        'elevenlabs.model_answer' => 'gpt-4',
        'qa.fallback_lines' => "첫 줄\n\n첫 줄\n둘째 줄\r\n",
        'qa.blocked_words' => '바보, 멍청이,바보',
        'support.email' => 'not-mail',
        'app.brand' => '  르멤버  ',
    ], $current);
    assert_same(10, $clean['qa.max_questions'], '최댓값으로 맞춘다');
    assert_same(1200, $clean['qa.vad_min_speech_ms'], '쉼표를 지운다');
    assert_true(isset($errors['qa.max_answer_chars']));
    assert_same(true, $clean['qa.enabled'], '숨은 0 뒤의 체크박스 값');
    assert_same(false, $clean['elevenlabs.speaker_boost']);
    assert_same('eleven_turbo_v2_5', $clean['elevenlabs.model_story'], '목록 밖이어도 지금 값은 유지');
    assert_true(isset($errors['elevenlabs.model_answer']), '목록 밖 새 값은 오류');
    assert_same(['첫 줄', '둘째 줄'], $clean['qa.fallback_lines']);
    assert_same(['바보', '멍청이'], $clean['qa.blocked_words']);
    assert_true(isset($errors['support.email']));
    assert_same('르멤버', $clean['app.brand']);
    assert_true(!array_key_exists('legal.terms', $clean), '화면에 없던 키는 건드리지 않는다');
});

test('설정 정리: 녹음 길이는 최소 ≤ 권장 ≤ 최대', function () {
    list(, $errors) = SettingsController::normalize([
        'voice.min_sample_seconds' => '120',
        'voice.recommended_sample_seconds' => '60',
        'voice.max_sample_seconds' => '300',
    ], Settings::DEFAULTS);
    assert_true(isset($errors['voice.recommended_sample_seconds']));
    list(, $errors) = SettingsController::normalize(['qa.fallback_lines' => " \n "], Settings::DEFAULTS);
    assert_true(isset($errors['qa.fallback_lines']), '대체 문장은 한 줄 이상');
});

test('설정 화면의 모델 목록 문구', function () {
    assert_same('Multilingual v2 (음질 우선, 1자당 1크레딧)', SettingsController::ELEVEN_MODELS['eleven_multilingual_v2']);
    assert_same('Flash v2.5 (비용 절반, 1자당 0.5크레딧)', SettingsController::ELEVEN_MODELS['eleven_flash_v2_5']);
    foreach (array_keys(SettingsController::SCHEMA) as $key) {
        assert_true(array_key_exists($key, Settings::DEFAULTS), $key . ' 기본값이 있어야 한다');
    }
});

// ───────────── 공지, 문의, 관리자, 감사 로그 ─────────────

test('공지 게시 시각 읽기', function () {
    assert_same(null, NoticeController::parseDateTime(''));
    assert_same('2026-11-01 09:00:00', NoticeController::parseDateTime('2026-11-01T09:00'));
    assert_same('2026-11-01 09:00:30', NoticeController::parseDateTime('2026-11-01 09:00:30'));
    assert_same(false, NoticeController::parseDateTime('2026-13-40T99:00'));
    assert_same(false, NoticeController::parseDateTime('내일'));
    assert_same(false, NoticeController::parseDateTime('1990-01-01T00:00'));
});

test('공지 상태 칩', function () {
    assert_same('임시 저장', NoticeController::stateOf(['status' => 'draft', 'published_at' => null])[0]);
    assert_same('예약', NoticeController::stateOf(['status' => 'published', 'published_at' => date('Y-m-d H:i:s', time() + 3600)])[0]);
    assert_same('게시 중', NoticeController::stateOf(['status' => 'published', 'published_at' => null])[0]);
});

test('문의 대기 시간 문구', function () {
    assert_same('1분', InquiryController::waitText(0));
    assert_same('45분', InquiryController::waitText(45));
    assert_same('3시간', InquiryController::waitText(200));
    assert_same('2일 1시간', InquiryController::waitText(2 * 1440 + 70));
    assert_same('기타', InquiryController::categoryLabel('unknown'));
    assert_same('목소리 등록', InquiryController::categoryLabel('voice'));
});

test('관리자 비밀번호 규칙과 임시 비밀번호', function () {
    assert_same('password', AdminAccountController::passwordError('', '')[0]);
    assert_same('password', AdminAccountController::passwordError('short', 'short')[0]);
    assert_same('password_confirmation', AdminAccountController::passwordError('longenough1', 'longenough2')[0]);
    assert_same(null, AdminAccountController::passwordError('longenough1', 'longenough1'));
    $a = AdminAccountController::tempPassword();
    $b = AdminAccountController::tempPassword();
    assert_same(14, strlen($a));
    assert_true($a !== $b, '매번 다르다');
    assert_true(!preg_match('/[0O1lI]/', $a), '헷갈리는 글자 없음');
    assert_same(null, AdminAccountController::passwordError($a, $a), '임시 비밀번호도 규칙을 지킨다');
});

test('감사 로그 상세 JSON 펼치기', function () {
    list($summary, $pretty) = AuditController::detail('{"keys":["qa.enabled"],"ok":true,"n":null}');
    assert_same('keys: ["qa.enabled"], ok: true, n: null', $summary);
    assert_contains("\n", $pretty);
    assert_contains('"qa.enabled"', $pretty);
    assert_same(['', ''], AuditController::detail(null));
    assert_same(['평문', '평문'], AuditController::detail('평문'));
    assert_same('동화 수정', AuditController::actionLabel('story.update'));
    assert_same('job.custom', AuditController::actionLabel('job.custom'), '모르는 작업은 코드 그대로');
});
