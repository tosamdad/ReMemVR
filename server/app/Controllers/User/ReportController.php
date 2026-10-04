<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Services\Progress;

/**
 * 학습 리포트(시안 _4). range=7 은 이번 주(월~일), range=30 은 오늘까지 최근 30일.
 * 이전 같은 길이 기간과 읽은 책 수를 비교한다.
 */
class ReportController
{
    public function index(): string
    {
        require_user();
        $child = Auth::child();
        if ($child === null) {
            redirect('/onboarding');
        }
        $range = Request::int('range', 7) === 30 ? 30 : 7;
        $scope = Progress::currentScope();
        $now = new \DateTimeImmutable('now');
        $today = $now->setTime(0, 0, 0);

        if ($range === 7) {
            $from = Progress::weekStart($now);
            $to = $from->modify('+7 days');
            $prevFrom = $from->modify('-7 days');
        } else {
            $from = $today->modify('-29 days');
            $to = $today->modify('+1 day');
            $prevFrom = $from->modify('-30 days');
        }

        $totals = Progress::totals($scope);
        $period = Progress::period($scope, $from, $to, $prevFrom, $range === 7, $now);
        $level = Progress::level(Progress::xp($totals['completed'], $totals['answered'], $totals['listened_ms']));

        return view('user/report/index', [
            'child' => $child,
            'range' => $range,
            'periodLabel' => $range === 7 ? '이번 주' : '최근 30일',
            'prevLabel' => $range === 7 ? '지난주' : '이전 30일',
            'hasAny' => $totals['sessions'] > 0,
            'totals' => $totals,
            'period' => $period,
            'level' => $level,
            'headline' => self::headline($child, $range, $period),
        ]);
    }

    /** 맨 위 칭찬 문구 */
    private static function headline(array $child, int $range, array $p): array
    {
        $name = (string) $child['name'];
        $when = $range === 7 ? '이번 주에만' : '최근 30일 동안';
        if ($p['started'] === 0) {
            return [
                'title' => '기다리고 있었어, ' . $name . '!',
                'body' => ($range === 7 ? '이번 주에는' : '최근 30일 동안') . ' 아직 떠난 모험이 없어요. 오늘 밤 이야기 한 편 함께 들어 볼까요?',
            ];
        }
        $body = $when . ' ' . $p['stories_started'] . '번의 새로운 모험을 떠났네요.';
        $words = count($p['words']['heard']);
        $questions = count($p['questions']);
        if ($words > 0) {
            $body .= ' ' . $name . '의 어휘력이 봄 정원처럼 쑥쑥 자라나고 있어요!';
        } elseif ($questions > 0) {
            $body .= ' 궁금한 것도 씩씩하게 물어봤어요!';
        } elseif ($p['books'] > 0) {
            $body .= ' 끝까지 귀 기울여 들은 모습이 정말 멋져요!';
        } else {
            $body .= ' 다음 이야기도 함께 들어 볼까요?';
        }

        return ['title' => '정말 잘했어, ' . $name . '!', 'body' => $body];
    }
}
