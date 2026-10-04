<?php
/**
 * 관리자 대시보드/목소리 관리 화면의 표시용 계산 점검.
 * DB 를 쓰는 테스트는 읽기(SELECT)만 한다.
 */
use App\Controllers\Admin\VoiceController;
use App\Services\DashboardStats;

test('SNR 값으로 음질 등급을 나눈다', function () {
    assert_same('good', DashboardStats::gradeInfo(31.4)['key']);
    assert_same('good', DashboardStats::gradeInfo(25)['key'], '경계값 25dB');
    assert_same('fair', DashboardStats::gradeInfo(24.9)['key']);
    assert_same('fair', DashboardStats::gradeInfo(15)['key'], '경계값 15dB');
    assert_same('poor', DashboardStats::gradeInfo(9.5)['key']);
    assert_same('최상', DashboardStats::gradeInfo(30)['short']);
    assert_same('우수 (SNR 25dB+)', DashboardStats::gradeInfo(30)['label']);
    assert_same('불량 (노이즈 감지)', DashboardStats::gradeInfo(3)['label']);
});

test('저장된 품질 등급이 있으면 SNR 보다 우선한다', function () {
    assert_same('poor', DashboardStats::gradeInfo(40, 'poor')['key']);
    assert_same('fair', DashboardStats::gradeInfo(null, 'fair')['key']);
});

test('측정값이 없으면 품질 측정 없음으로 표시한다', function () {
    $g = DashboardStats::gradeInfo(null);
    assert_same(null, $g['key']);
    assert_same('muted', $g['tone']);
    assert_same('품질 측정 없음', DashboardStats::gradeInfo('', 'unknown')['label']);
});

test('신청 번호를 #REQ-0000 형식으로 만든다', function () {
    assert_same('#REQ-0007', DashboardStats::reqId(7));
    assert_same('#REQ-12345', DashboardStats::reqId('12345'));
});

test('ElevenLabs 목소리 id 를 가린다', function () {
    assert_same('EL_k8x9…7a', DashboardStats::maskVoiceId('k8x9Qw3RtYuP0aZ7a'));
    assert_same('EL_abc', DashboardStats::maskVoiceId('abc'));
    assert_same('', DashboardStats::maskVoiceId(null));
    assert_same('', DashboardStats::maskVoiceId(''));
});

test('대기 시간을 한국어로 줄여 쓴다', function () {
    assert_same('-', DashboardStats::koSpan(null));
    assert_same('1분 미만', DashboardStats::koSpan(30));
    assert_same('14분', DashboardStats::koSpan(14 * 60 + 59));
    assert_same('2시간', DashboardStats::koSpan(7200));
    assert_same('2시간 5분', DashboardStats::koSpan(7200 + 300));
    assert_same('3일 4시간', DashboardStats::koSpan(3 * 86400 + 4 * 3600 + 100));
    assert_same('1분 미만', DashboardStats::koSpan(-5), '음수는 0으로 본다');
});

test('샘플 길이와 응답 지연을 읽기 쉽게 쓴다', function () {
    assert_same('0초', DashboardStats::koDuration(0));
    assert_same('45초', DashboardStats::koDuration(45200));
    assert_same('4분 12초', DashboardStats::koDuration(252000));
    assert_same('2분 05초', DashboardStats::koDuration(125000));
    assert_same('3분', DashboardStats::koDuration(180000));
    assert_same('-', DashboardStats::koLatency(null));
    assert_same('840ms', DashboardStats::koLatency(840));
    assert_same('1.18초', DashboardStats::koLatency(1180));
});

test('목소리 목록 검색어를 조건으로 바꾼다', function () {
    $base = ['status' => '', 'q' => '', 'from' => '', 'to' => '', 'grade' => ''];

    list($sql, $params) = VoiceController::where(array_merge($base, ['q' => '#REQ-0012']));
    assert_contains('vp.id = ?', $sql);
    assert_same([12], $params);

    list($sql, $params) = VoiceController::where(array_merge($base, ['q' => '#12']));
    assert_same([12], $params);

    list($sql, $params) = VoiceController::where(array_merge($base, ['q' => '010-8291']));
    assert_contains('u.phone', $sql);
    assert_same([108291, '%0108291%'], $params);

    list($sql, $params) = VoiceController::where(array_merge($base, ['q' => '민서_%']));
    assert_contains('children', $sql);
    assert_same('%민서\\_\\%%', $params[0], 'LIKE 특수문자는 이스케이프한다');

    list($sql, $params) = VoiceController::where(array_merge($base, ['status' => 'pending', 'grade' => 'good', 'from' => '2026-10-01', 'to' => '2026-10-03']));
    assert_contains('vp.status = ?', $sql);
    assert_contains('vp.quality_grade = ?', $sql);
    assert_same(['pending', 'good', '2026-10-01 00:00:00', '2026-10-04 00:00:00'], $params);
});

test('상태별 건수와 목록 KPI 를 읽어 온다', function () {
    test_db();
    $counts = DashboardStats::statusCounts();
    foreach (['all', 'pending', 'cloning', 'processing', 'completed', 'rejected', 'failed'] as $k) {
        assert_true(isset($counts[$k]) && is_int($counts[$k]), $k . ' 건수가 정수가 아니다');
    }
    $sum = $counts['pending'] + $counts['cloning'] + $counts['processing'] + $counts['completed'] + $counts['rejected'] + $counts['failed'];
    assert_same($counts['all'], $sum, '전체 건수는 상태별 건수의 합');

    $kpi = DashboardStats::voiceListKpis();
    assert_same($counts['pending'], $kpi['pending']);
    $ready = $kpi['readiness'];
    assert_true($ready['percent'] === null || ($ready['percent'] >= 0 && $ready['percent'] <= 100), '준비율은 0~100%');
});
