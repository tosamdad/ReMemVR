<?php
use App\Core\Settings;
use App\Core\Storage;
use App\Core\Text;
use App\Services\Playlists;
use App\Services\StoryRequests;

/**
 * 동화 생성 요청과 플레이리스트 테스트. DB 변경은 트랜잭션으로 되돌리고, 만든 파일은 끝에서 지운다.
 * 다른 게시 동화와 대기 작업이 결과에 섞이지 않도록 트랜잭션 안에서 잠시 숨긴다.
 */
function sr_tx(callable $fn): void
{
    $pdo = test_db();
    try {
        db_value('SELECT 1 FROM story_requests LIMIT 1');
        db_value('SELECT repeat_mode FROM playlists LIMIT 1');
    } catch (Throwable $e) {
        skip_test('테이블 없음(마이그레이션 필요)');
    }
    $GLOBALS['__sr_files'] = [];
    $pdo->beginTransaction();
    try {
        db_exec("UPDATE stories SET status = 'hidden' WHERE status = 'published'");
        db_exec("UPDATE jobs SET available_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE status = 'pending'");
        $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $prop = new ReflectionProperty(Settings::class, 'cache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        foreach ($GLOBALS['__sr_files'] as $rel) {
            Storage::delete($rel);
        }
    }
}

/** 테스트 회원 */
function sr_user(array $user = []): int
{
    return db_insert('users', array_merge([
        'email' => 'sr-test-' . bin2hex(random_bytes(4)) . '@example.com',
        'password_hash' => password_hash('test1234', PASSWORD_DEFAULT),
        'name' => '요청테스트',
        'status' => 'active',
    ], $user));
}

/** 회원의 목소리. $ready 면 복제가 끝난 목소리 */
function sr_voice(int $uid, string $label, bool $ready = true): int
{
    return db_insert('voice_profiles', [
        'user_id' => $uid,
        'label' => $label,
        'status' => $ready ? 'completed' : 'pending',
        'provider_voice_id' => $ready ? 'fake_sr_' . bin2hex(random_bytes(3)) : null,
        'cloned_at' => $ready ? now() : null,
    ]);
}

function sr_story(string $title): int
{
    $sid = db_insert('stories', [
        'title' => $title,
        'body' => '옛날 옛적에.',
        'status' => 'published',
        'char_count' => 6,
        'content_hash' => Text::hashSentences(['옛날 옛적에.']),
    ]);
    db_insert('story_sentences', ['story_id' => $sid, 'seq' => 1, 'content' => '옛날 옛적에.']);

    return $sid;
}

/** 완성된 동화 오디오(파일 포함) */
function sr_audio(int $sid, int $vid, string $status = 'completed'): void
{
    $rel = null;
    if ($status === 'completed') {
        $rel = Storage::put('story-audio/' . $vid . '/' . $sid . '-sr-test.wav', 'RIFF');
        $GLOBALS['__sr_files'][] = $rel;
    }
    $hash = db_value('SELECT content_hash FROM stories WHERE id = ?', [$sid]);
    db_insert('story_audios', [
        'story_id' => $sid,
        'voice_profile_id' => $vid,
        'status' => $status,
        'file_path' => $rel,
        'duration_ms' => 60000,
        'content_hash' => $hash,
    ]);
}

test('요청은 준비된 목소리만 받고, 이미 요청했거나 만든 목소리는 건너뛴다', function () {
    sr_tx(function () {
        $uid = sr_user();
        $mom = sr_voice($uid, '엄마');
        $dad = sr_voice($uid, '아빠');
        $grandma = sr_voice($uid, '할머니', false);
        $other = sr_voice(sr_user(), '남의 목소리');
        $sid = sr_story('요청 테스트 동화');

        $res = StoryRequests::create($uid, $sid, [$mom, $grandma, $other]);
        assert_same(1, count($res['created']), '엄마만 요청된다');
        assert_same([['voice' => '할머니', 'reason' => '목소리가 아직 준비되지 않았어요']], $res['skipped']);

        // 같은 목소리로 다시 요청하면 건너뛰고, 다른 목소리는 받는다
        $res = StoryRequests::create($uid, $sid, [$mom, $dad]);
        assert_same(1, count($res['created']));
        assert_same('엄마', $res['skipped'][0]['voice']);
        assert_same('이미 요청했어요', $res['skipped'][0]['reason']);

        $states = StoryRequests::voiceStates($uid, $sid);
        assert_same('requested', $states[$mom]['state']);
        assert_same('requested', $states[$dad]['state']);
        assert_same('unready', $states[$grandma]['state']);
        assert_true(!isset($states[$other]), '다른 회원의 목소리가 보인다');

        // 남의 목소리만 고르면 요청할 수 없다
        $threw = false;
        try {
            StoryRequests::create($uid, $sid, [$other]);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '남의 목소리로 요청했다');

        // 공개 중이 아닌 동화는 요청할 수 없다
        db_exec("UPDATE stories SET status = 'hidden' WHERE id = ?", [$sid]);
        $threw = false;
        try {
            StoryRequests::create($uid, $sid, [$mom]);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '숨긴 동화를 요청했다');
    });
});

test('동시에 걸어 둘 수 있는 요청 수를 넘으면 요청을 받지 않는다', function () {
    sr_tx(function () {
        Settings::set('request.max_open', 2);
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $a = sr_story('한도 동화 1');
        $b = sr_story('한도 동화 2');
        $c = sr_story('한도 동화 3');
        StoryRequests::create($uid, $a, [$vid]);
        StoryRequests::create($uid, $b, [$vid]);
        assert_same(2, StoryRequests::openCount($uid));
        $threw = false;
        try {
            StoryRequests::create($uid, $c, [$vid]);
        } catch (RuntimeException $e) {
            $threw = strpos($e->getMessage(), '2건') !== false;
        }
        assert_true($threw, '한도를 넘었는데 요청을 받았다');
    });
});

test('회원은 확인 대기 요청만 취소하고, 관리자는 사유를 남겨 반려한다', function () {
    sr_tx(function () {
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $sid = sr_story('취소 반려 동화');
        $rid = StoryRequests::create($uid, $sid, [$vid])['created'][0];

        // 다른 회원은 취소할 수 없다
        $threw = false;
        try {
            StoryRequests::cancel(sr_user(), $rid);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '다른 회원이 취소했다');

        StoryRequests::cancel($uid, $rid);
        assert_same('canceled', db_value('SELECT status FROM story_requests WHERE id = ?', [$rid]));
        assert_same('none', StoryRequests::voiceStates($uid, $sid)[$vid]['state'], '취소하면 다시 요청할 수 있다');

        $rid = StoryRequests::create($uid, $sid, [$vid])['created'][0];
        $threw = false;
        try {
            StoryRequests::reject($rid, null, '  ');
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '사유 없이 반려했다');
        StoryRequests::reject($rid, null, '이 동화는 준비 중이에요');
        $r = db_one('SELECT status, reject_reason FROM story_requests WHERE id = ?', [$rid]);
        assert_same('rejected', $r['status']);
        assert_same('이 동화는 준비 중이에요', $r['reject_reason']);
        $threw = false;
        try {
            StoryRequests::cancel($uid, $rid);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '반려된 요청을 취소했다');

        // 반려된 동화는 다시 요청할 수 있다
        assert_same('rejected', StoryRequests::voiceStates($uid, $sid)[$vid]['state']);
        assert_same(1, count(StoryRequests::create($uid, $sid, [$vid])['created']));
    });
});

test('생성 시작은 작업을 등록하고, 이미 만든 오디오가 있으면 바로 완성으로 본다', function () {
    sr_tx(function () {
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $a = sr_story('생성 시작 동화');
        $b = sr_story('이미 만든 동화');
        $ra = StoryRequests::create($uid, $a, [$vid])['created'][0];
        $rb = StoryRequests::create($uid, $b, [$vid])['created'][0];
        sr_audio($b, $vid);

        $res = StoryRequests::approve([$ra, $rb], null);
        assert_same(2, $res['approved']);
        assert_same([], $res['errors']);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'story_tts' AND ref_id = ? AND status = 'pending'", [$vid]));
        assert_same('making', StoryRequests::state(StoryRequests::latestFor($uid, $a, $vid)));
        assert_same('done', StoryRequests::state(StoryRequests::latestFor($uid, $b, $vid)));
        assert_true(db_value('SELECT completed_at FROM story_requests WHERE id = ?', [$rb]) !== null, '이미 만든 오디오인데 완성으로 보지 않는다');
        // 만드는 중 요청이 남아 있으면 완성 메일은 아직 보내지 않는다
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_type = 'user' AND ref_id = ?", [$uid]));

        // 이미 생성을 시작한 요청은 건너뛴다
        $res = StoryRequests::approve([$ra], null);
        assert_same(0, $res['approved']);
        assert_contains('생성 중 상태라 건너뜁니다', $res['errors'][0]);

        // 오디오가 완성되면 묶어서 메일 한 통
        $rel = Storage::put('story-audio/' . $vid . '/' . $a . '-sr-test.wav', 'RIFF');
        $GLOBALS['__sr_files'][] = $rel;
        db_exec("UPDATE story_audios SET status = 'completed', file_path = ? WHERE story_id = ? AND voice_profile_id = ?", [$rel, $a, $vid]);
        StoryRequests::syncAudio($a, $vid);
        $mails = db_all("SELECT payload FROM jobs WHERE type = 'mail' AND ref_type = 'user' AND ref_id = ?", [$uid]);
        assert_same(1, count($mails));
        assert_contains('요청하신 동화 2편이 완성되었어요', json_decode($mails[0]['payload'], true)['subject']);
        StoryRequests::syncAudio($a, $vid);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_type = 'user' AND ref_id = ?", [$uid]), '메일을 두 번 보냈다');

        $counts = StoryRequests::userCounts($uid);
        assert_same(['all' => 2, 'making' => 0, 'done' => 2, 'rejected' => 0], $counts);
        assert_same(2, count(StoryRequests::forUser($uid, 'done')));
        assert_same(0, count(StoryRequests::forUser($uid, 'making')));
    });
});

test('생성에 실패한 요청은 확인 중으로 보이고, 관리자가 다시 생성하거나 반려한다', function () {
    sr_tx(function () {
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $sid = sr_story('실패 동화');
        $rid = StoryRequests::create($uid, $sid, [$vid])['created'][0];
        StoryRequests::approve([$rid], null);
        db_exec("UPDATE story_audios SET status = 'failed', error_message = 'fake' WHERE story_id = ? AND voice_profile_id = ?", [$sid, $vid]);
        $row = StoryRequests::latestFor($uid, $sid, $vid);
        assert_same('failed', StoryRequests::state($row));
        assert_same('확인 중', StoryRequests::stateLabel('failed'));
        assert_true(StoryRequests::adminCounts()['failed'] >= 1, '관리자 생성 실패 개수');

        // 다시 생성: 실패한 오디오를 대기로 돌리고 작업을 등록한다
        db_exec("UPDATE jobs SET status = 'failed' WHERE type = 'story_tts' AND ref_id = ?", [$vid]);
        $res = StoryRequests::approve([$rid], null);
        assert_same(1, $res['approved']);
        assert_same('pending', db_value('SELECT status FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $vid]));
        assert_same('making', StoryRequests::state(StoryRequests::latestFor($uid, $sid, $vid)));
    });
});

test('플레이리스트는 회원의 완성 동화만 한 번씩 담고 순서를 바꾼다', function () {
    sr_tx(function () {
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $a = sr_story('담기 동화 1');
        $b = sr_story('담기 동화 2');
        $c = sr_story('만드는 중 동화');
        sr_audio($a, $vid);
        sr_audio($b, $vid);
        sr_audio($c, $vid, 'pending');

        $threw = false;
        try {
            Playlists::create($uid, " \t<> ");
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '빈 이름으로 만들었다');
        $pl = Playlists::create($uid, '  잠자리   <b>동화</b> ');
        assert_same('잠자리 b동화/b', db_value('SELECT name FROM playlists WHERE id = ?', [$pl]));
        assert_same('all', db_value('SELECT repeat_mode FROM playlists WHERE id = ?', [$pl]));

        assert_same(true, Playlists::addItem($uid, $pl, $a, $vid));
        assert_same(false, Playlists::addItem($uid, $pl, $a, $vid), '같은 동화를 두 번 담았다');
        assert_same(true, Playlists::addItem($uid, $pl, $b, $vid));
        foreach ([[$c, $vid], [$a, sr_voice(sr_user(), '남')]] as $bad) {
            $threw = false;
            try {
                Playlists::addItem($uid, $pl, $bad[0], $bad[1]);
            } catch (RuntimeException $e) {
                $threw = true;
            }
            assert_true($threw, '완성되지 않았거나 남의 동화를 담았다');
        }
        // 다른 회원은 이 플레이리스트에 담을 수 없다
        $threw = false;
        try {
            Playlists::addItem(sr_user(), $pl, $a, $vid);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, '다른 회원이 담았다');

        $items = Playlists::items($pl);
        assert_same([$a, $b], array_column($items, 'story_id'));
        assert_same([true, true], array_column($items, 'playable'));
        assert_same(0, count(array_filter(Playlists::addable($uid, $pl), static function ($r) use ($a, $b) {
            return in_array((int) $r['story_id'], [$a, $b], true);
        })), '이미 담은 동화가 담기 목록에 있다');

        Playlists::moveItem($uid, $pl, $items[1]['id'], -1);
        assert_same([$b, $a], array_column(Playlists::items($pl), 'story_id'));
        Playlists::moveItem($uid, $pl, $items[1]['id'], -1);
        assert_same([$b, $a], array_column(Playlists::items($pl), 'story_id'), '맨 위에서 더 올라가면 그대로');

        // 동화를 숨기면 담긴 채로 들을 수 없게 표시한다
        db_exec("UPDATE stories SET status = 'hidden' WHERE id = ?", [$a]);
        assert_same([true, false], array_column(Playlists::items($pl), 'playable'));

        Playlists::removeItem($uid, $pl, $items[0]['id']);
        assert_same([$b], array_column(Playlists::items($pl), 'story_id'));
        $list = Playlists::forUser($uid);
        assert_same(1, $list[0]['count']);
        assert_same(60000, $list[0]['duration_ms']);
    });
});

test('플레이리스트 재생 순서: 차례, 전체 반복, 한 편 반복, 랜덤', function () {
    sr_tx(function () {
        $uid = sr_user();
        $vid = sr_voice($uid, '엄마');
        $pl = Playlists::create($uid, '순서 테스트');
        $sids = [];
        for ($i = 1; $i <= 5; $i++) {
            $sid = sr_story('순서 동화 ' . $i);
            sr_audio($sid, $vid);
            Playlists::addItem($uid, $pl, $sid, $vid);
            $sids[] = $sid;
        }
        $v = (string) $vid;
        $get = function () use ($uid, $pl) {
            return Playlists::find($uid, $pl);
        };

        // 차례대로, 한 번만: 마지막 편 다음은 없다
        Playlists::setMode($uid, $pl, 'off', false);
        $order = Playlists::order($get(), 7);
        assert_same($sids, array_column($order, 'story_id'));
        $ctx = Playlists::context($get(), $sids[0], $v, 0, 7);
        assert_same(1, $ctx['next']['pos']);
        assert_same($sids[1], $ctx['next']['item']['story_id']);
        assert_contains('pl=' . $pl, $ctx['next']['url']);
        assert_contains('auto=1', $ctx['next']['url']);
        assert_same(null, Playlists::context($get(), $sids[4], $v, 4, 7)['next']);
        // 주소의 순번이 틀려도 동화와 목소리로 찾아 맞춘다
        assert_same(2, Playlists::context($get(), $sids[2], $v, 0, 7)['playlist']['pos']);
        // 플레이리스트에 없는 동화, 목소리면 null
        assert_same(null, Playlists::context($get(), $sids[2], 'device', 2, 7));

        // 전체 반복: 끝에서 처음으로
        Playlists::setMode($uid, $pl, 'all', null);
        $ctx = Playlists::context($get(), $sids[4], $v, 4, 7);
        assert_same(0, $ctx['next']['pos']);
        assert_same($sids[0], $ctx['next']['item']['story_id']);

        // 한 편 반복: 같은 편
        Playlists::setMode($uid, $pl, 'one', null);
        $ctx = Playlists::context($get(), $sids[2], $v, 2, 7);
        assert_same($sids[2], $ctx['next']['item']['story_id']);
        assert_same('한 편 반복', $ctx['playlist']['repeat_label']);

        // 랜덤: 같은 seed 는 같은 순서, 모든 편이 한 번씩 나온다
        Playlists::setMode($uid, $pl, 'all', true);
        $o1 = array_column(Playlists::order($get(), 12345), 'story_id');
        $o2 = array_column(Playlists::order($get(), 12345), 'story_id');
        assert_same($o1, $o2);
        $sorted = $o1;
        sort($sorted);
        $expected = $sids;
        sort($expected);
        assert_same($expected, $sorted);
        $different = false;
        for ($seed = 1; $seed <= 20 && !$different; $seed++) {
            $different = array_column(Playlists::order($get(), $seed), 'story_id') !== $sids;
        }
        assert_true($different, '랜덤인데 순서가 섞이지 않는다');
        // 랜덤 + 전체 반복: 한 바퀴가 끝나면 다음 seed 로 새로 섞는다
        $ctx = Playlists::context($get(), $o1[4], $v, 4, 12345);
        assert_same(12346, $ctx['next']['seed']);
        assert_same(0, $ctx['next']['pos']);
        assert_same(array_column(Playlists::order($get(), 12346), 'story_id')[0], $ctx['next']['item']['story_id']);
        assert_true($ctx['playlist']['shuffle'], 'shuffle 표시');

        // 잘못된 반복 값은 무시한다
        Playlists::setMode($uid, $pl, 'forever', null);
        assert_same('all', $get()['repeat_mode']);
    });
});

test('자동 생성 설정이면 요청하자마자 만들기 시작하고, 진행 단계를 본인 요청만 돌려준다', function () {
    sr_tx(function () {
        if (!App\Services\ElevenLabs::ready()) {
            skip_test('ElevenLabs 키(또는 가짜 모드)가 없다');
        }
        $uid = sr_user();
        $mom = sr_voice($uid, '엄마');
        $sid = sr_story('자동 생성 테스트 동화');

        // 관리자 확인 설정: 요청만 남는다
        Settings::set('request.auto_approve', false);
        $res = StoryRequests::create($uid, $sid, [$mom]);
        assert_same(false, $res['auto']);
        assert_same(0, $res['approved']);
        $manual = $res['created'][0];
        assert_same([(string) $manual => 'requested'], StoryRequests::statesFor($uid, [$manual]));
        StoryRequests::cancel($uid, $manual);

        // 자동 생성 설정: 바로 생성을 시작해 만드는 중이 되고, 오디오가 완성되면 완성으로 바뀐다
        Settings::set('request.auto_approve', true);
        $res = StoryRequests::create($uid, $sid, [$mom]);
        assert_same(true, $res['auto']);
        assert_same(1, $res['approved']);
        $rid = $res['created'][0];
        assert_same([(string) $rid => 'making'], StoryRequests::statesFor($uid, [$rid, 999999999]));
        db_exec('DELETE FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $mom]);
        sr_audio($sid, $mom);
        $states = StoryRequests::statesFor($uid, [$rid, $manual]);
        assert_same('done', $states[(string) $rid]);
        assert_same('canceled', $states[(string) $manual]);

        // 다른 회원은 남의 요청 상태를 볼 수 없다
        assert_same([], StoryRequests::statesFor(sr_user(), [$rid]));
    });
});
