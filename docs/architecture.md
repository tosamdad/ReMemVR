르멤버 애플리케이션 구조와 개발 규칙

이 문서는 코드를 고치거나 화면을 추가하는 개발자(사람, AI 모두)를 위한 기준서이다. 배포와 DB 운영은 docs/deploy-and-db.md 를 본다.

1. 한눈에 보기

- 회원 화면(모바일 웹): 부모가 가족 목소리를 녹음해 등록하고, 아이는 그 목소리로 동화를 듣다가 궁금한 것을 말로 물어본다.
- 관리자 화면(/admin): 목소리 생성 승인, 동화 오디오 사전 생성, 동화 관리, 회원과 통계, 운영 설정.
- 외부 API: ElevenLabs(목소리 복제, 음성 합성), Gemini(아이 질문 음성 인식과 답변 생성).
- 구조: PHP(프레임워크 없음) + MariaDB + Tailwind CSS(빌드) + 순수 자바스크립트. cafe24 웹호스팅(PHP 8.4, MariaDB 10.6)에서 동작하며 코드는 PHP 7.4 문법까지만 쓴다.

2. 폴더

    public/                 웹 루트(cafe24 /www)
      index.php             모든 화면 요청의 진입점(.htaccess 가 실제 파일이 아닌 요청을 보낸다)
      _ops/                 배포 워크플로가 부르는 운영 엔드포인트(migrate, backup, worker). OPS_TOKEN 필수
      assets/css/           빌드 결과(user.css, admin.css). 저장소에는 없고 npm run build 로 만든다
      assets/js/            app.js(공통), admin.js(관리자 공통), recorder.js(녹음), 화면별 스크립트
      assets/covers/        동화 표지 SVG
      assets/img/           아이콘, 일러스트
    server/                 앱 폴더(cafe24 /rememvr_app, 웹으로 열리지 않음)
      bootstrap.php         설정 로드, 자동 로더, 전역 함수
      app/Core/             라우터, 요청, 화면, 세션, 로그인, 설정, 저장소, HTTP 클라이언트 등 공통 부품
      app/Controllers/User/ 회원 화면 컨트롤러
      app/Controllers/Admin/ 관리자 화면 컨트롤러
      app/Services/         외부 API, 작업 큐, 질문 처리, 목소리 처리, 통계 같은 업무 로직
      routes/               경로 등록 파일(이름 순서로 모두 읽음)
      views/user/           회원 화면 템플릿
      views/admin/          관리자 화면 템플릿
      views/errors/         오류 화면
      migrations/           번호 붙은 SQL(0001, 0002 ...). 적용된 파일은 수정 금지
      tests/                PHP 테스트(php server/tests/run.php)
      bin/                  명령행 도구(migrate, backup, create-admin, demo-seed)
    src/css/                Tailwind 입력 CSS(user.css, admin.css)
    tailwind.user.config.js, tailwind.admin.config.js

저장 폴더(storage_path): 업로드 음성, 생성 오디오, 세션, 로그. 기본 위치는 앱 폴더의 상위(호스팅 홈)에 있는 rememvr_data/ 이고, 만들 수 없으면 server/storage/ 를 쓴다. DB 에는 저장 폴더 기준 상대 경로를 남긴다.

3. 요청 처리 흐름

    public/index.php → App\Core\App::run()
      → 경로가 /admin 으로 시작하면 관리자 세션(RMADMIN), 아니면 회원 세션(RMSESS) 시작
      → server/routes/*.php 등록 → Router::dispatch
      → POST 는 CSRF 토큰 검사(폼: csrf_field(), fetch: X-CSRF-Token 헤더. RM.api 가 자동으로 붙인다)
      → 컨트롤러 메서드 실행. 배열을 돌려주면 JSON, 문자열이면 HTML
      → abort(404) 같은 HttpException 은 오류 화면 또는 JSON 으로 바뀐다

경로 등록 예

    $router->get('/player/{id:\d+}', [App\Controllers\User\PlayerController::class, 'show']);
    $router->post('/api/play-sessions/{id:\d+}/question', [App\Controllers\User\PlayerController::class, 'question']);
    $router->post('/_hook', $handler, ['csrf' => false]);   // 외부 호출만 CSRF 제외

경로 변수는 메서드 인자로 순서대로(문자열) 들어온다. 정수로 쓸 때는 (int) 로 바꾼다.

4. 전역 함수(server/app/Core/helpers.php)

    설정      config('gemini.api_key'), setting('qa.max_questions'), provider_ready('elevenlabs')
    DB        db(), db_all($sql, $params), db_one(), db_value(), db_exec(), db_insert($table, $data), db_update($table, $data, 'id = ?', [$id]), db_tx(fn)
              정수 인자는 PARAM_INT 로 묶인다(LIMIT ? 에 (int) 를 넘긴다). 같은 이름의 :param 을 한 쿼리에서 두 번 쓰지 않는다.
    요청      input('key'), Request::str('key'), Request::int('key'), Request::file('audio'), Request::uploadError('audio'), Request::wantsJson()
    응답      redirect('/home'), redirect_back('/fallback'), abort(403, '메시지'), json_ok([...]), json_error('메시지', 422)
    화면      view('user/home', $data), layout('user/layout', [...]), section('scripts') ... endsection(), partial('user/partials/x', $data)
    폼        csrf_field(), old('email'), errors('email'), back_with_errors($errors), flash('success', '저장했습니다')
    로그인    current_user(), require_user(), current_admin(), require_admin('super'), admin_audit('voice.approve', 'voice_profile', $id, $detail)
    경로      url('/stories', ['tag' => '모험']), absolute_url('/auth/kakao/callback'), asset('js/player.js'), cover_url($story), storage_path('logs')
    서식      e($text), fmt_duration($ms), fmt_krw($n), fmt_number($n), time_ago($datetime), child_age($child), str_limit($s, 40), mask_phone(), mask_email()
    기타      app_log('error', '메시지', $context), json_decode_array($json), json_encode_u($value), now(), child_avatar($child, 'w-10 h-10 text-xl'), avatar_presets(), voice_icon($voice), voice_status_label($status)

공통 클래스(App\Core)

    Auth          회원: Auth::user(), Auth::login($row), Auth::logout(), Auth::children(), Auth::child(), Auth::selectChild($id), Auth::prefs(), Auth::savePrefs([...])
    AdminAuth     관리자: AdminAuth::admin(), login(), logout(), needsSetup()
    Session       get/set/forget/regenerate/destroy
    Validator     Validator::make($_POST, ['email' => 'required|email|max:191'], ['email' => '이메일'])
    Settings      Settings::get/set/all, 기본값은 Settings::DEFAULTS
    Storage       put, putUploaded, get, delete, exists, size, randomName('wav'), mimeFor, extForMime, stream($rel) (Range 지원, 종료함)
    HttpClient    HttpClient::request('POST', $url, ['headers' => [], 'json' => [] | 'multipart' => [] | 'form' => [], 'timeout' => 30])
    Mailer        Mailer::send($to, $subject, $text)
    RateLimiter   RateLimiter::hit('login:' . client_ip(), 10, 600)
    Text          splitSentences($body), words($sentence), hashSentences($list), keywords('a, b'), charCount($s)

5. 화면(템플릿) 규칙

- 템플릿 첫 줄에서 레이아웃을 정한다.
    회원:   layout('user/layout', ['title' => '홈', 'nav' => 'home'])
            nav: home | voice | player | report, header: main(기본) | sub(뒤로 가기 + 제목, back, headerTitle, headerAction) | none, showNav: false 면 하단 탭 숨김
            로그인, 회원가입 같은 화면은 layout('user/layout_auth', ['title' => '로그인'])
    관리자: layout('admin/layout', ['title' => '대시보드', 'active' => 'dashboard'])
            active: dashboard | voices | stories | members | notices | inquiries | settings | admins | audit
            로그인, 최초 설정은 layout('admin/layout_auth', ['title' => '관리자 로그인'])
- 출력은 항상 e() 로 감싼다. JSON 을 스크립트에 넣을 때는 json_encode_u() 결과를 <script> 안에 그대로 쓰되, 사용자 입력이 섞이면 JSON_HEX_TAG 를 쓴다.
- 페이지 전용 스크립트는 public/assets/js/ 에 파일로 두고 section('scripts') 에서 <script src="<?= e(asset('js/player.js')) ?>"></script> 로 넣는다.
- 디자인 시안(Google Stitch)의 클래스 이름을 그대로 쓴다. 색 토큰(primary, primary-container, secondary-container, surface-container-lowest, on-surface-variant 등), 글자 토큰(text-headline-xl-mobile, text-headline-md, text-body-md, text-label-lg, text-label-sm, 관리자는 text-label-md, text-headline-lg), 간격 토큰(px-margin-mobile, gap-gutter, p-md 등)이 tailwind 설정에 들어 있다.
- 회원 화면 공통 부품 클래스(src/css/user.css): .card, .field, .field-label, .field-error, .btn-primary, .btn-secondary, .btn-ghost, .switch, .bento-card, .icon-fill, .no-scrollbar, .glass
- 관리자 화면 공통 부품 클래스(src/css/admin.css): .a-card, .a-input, .a-label, .a-btn-primary, .a-btn-secondary, .a-btn-tonal, .a-btn-danger, .a-chip, .a-table, .switch, .console-log
- 아이콘은 Material Symbols Outlined(<span class="material-symbols-outlined">mic</span>). 채운 아이콘은 icon-fill 클래스를 더한다.
- 회원 화면은 다크 모드를 지원한다. 색은 CSS 변수라서 토큰 클래스만 쓰면 자동으로 바뀐다. bg-white, text-black 같은 고정 색은 쓰지 않는다(시안의 bg-white/90 배지 정도만 예외).
- 사진 대신 아이콘, 이모지 아바타(child_avatar), SVG 일러스트를 쓴다. 외부 이미지 주소를 넣지 않는다.
- 새 화면을 만들 때도 같은 디자인 시스템(둥근 카드, 부드러운 그림자, 파스텔 색)을 유지한다.

6. 자바스크립트 공통 객체

    RM.api(path, {method, body})   CSRF 헤더 포함 fetch. JSON 반환, 실패 시 Error(message)
    RM.toast(message, type)        success | error | info
    RM.url(path), RM.fmtTime(ms), RM.setDark(bool), RM.escapeHtml(s), RM.config
    RMRecorder                     recorder.js. 마이크 녹음 → WAV + 품질 지표 + 음성 감지(사용법은 파일 머리말)
    RMAdmin.tickWorker()           admin.js. 관리자 화면이 열려 있는 동안 45초마다 작업 처리기를 한 번 돌린다
    RM.confirm(message, {ok, danger}), RM.alert(message)   화면을 어둡게 덮는 팝업(Promise). 브라우저 기본 알림창(confirm, alert)은 쓰지 않는다
    <form data-confirm="...">, <form data-ajax>   확인 팝업(data-confirm-ok, data-confirm-danger), fetch 제출(응답의 message 를 토스트, redirect 로 이동, rm:success 이벤트)
    목록의 keep 파라미터(/admin/requests, /admin/voices)   처리한 줄을 지금 탭 조건과 관계없이 남겨, 상태가 바뀌어도 줄이 사라지지 않고 상태만 바뀐다

7. 데이터 구조 요약(0001, 0002, 0004)

- users(회원), children(자녀: birth_date, gender, avatar), user_social_accounts, password_resets
- voice_profiles(가족 목소리). status 흐름:
    draft(녹음 중) → pending(검토 대기, 사용자가 동의하고 제출) → cloning(ElevenLabs 목소리 생성 중) → completed(준비됨)
    운영 설정 voice.auto_clone_on_submit(기본 켬, 0005)이면 제출하자마자 자동 승인되어 바로 cloning 으로 간다(관리자 검토 없음).
    녹음 샘플은 하나로 합치지 않고 모두 ElevenLabs 즉시 목소리 복제(IVC)에 함께 보낸다(여러 파일을 받는다). 끝내 실패하면 운영 알림 메일을 보낸다.
    키 권한, 요금제, 결제처럼 다시 해도 같은 오류(400, 401, 402, 403, 413, 422)는 재시도하지 않고 바로 failed 로 끝낸다.
    failed 는 회원(목소리 상세, 목록 카드의 다시 만들기)과 관리자(목록, 상세의 다시 생성) 모두 다시 만들 수 있다. 관리자 목록에는 마지막 실패 사유(jobs.last_error)가 보인다.
    목소리가 준비되어도 동화를 한꺼번에 만들지 않는다. 동화는 회원이 골라 요청하고 관리자가 생성을 시작한 것만 만든다(story_requests).
    processing 은 예전 일괄 생성 방식의 상태로, 0004 에서 completed 로 옮겼고 지금은 쓰지 않는다.
    rejected(반려, 재녹음 필요), failed(생성 실패). 삭제는 deleted_at(소프트 삭제) + ElevenLabs 목소리 삭제 작업
    stability, similarity_boost, style, speaker_boost: 관리자가 조율하는 합성 파라미터(NULL 이면 설정 기본값)
    batch_status: none | queued | running | done | partial | failed (이 목소리로 생성을 시작한 요청 동화의 진행)
- voice_samples(목소리 샘플: 길이, snr_db, peak_db, noise_db, clip_count, quality_grade 는 브라우저에서 측정해 보낸다)
- stories(동화: code, category, est_duration_sec, barge_in_enabled, max_questions, vad_min_ms, aec_level, fallback_lines, content_hash)
  story_sentences(문장: seq, content, keywords, ref_start_ms, ref_end_ms)
  cover_image_path 는 'assets:covers/01.svg'(정적 파일) 또는 'storage:covers/파일'(업로드)
- story_audios(목소리별 동화 오디오: status pending | processing | completed | failed, file_path, duration_ms, sentence_timings, content_hash)
  content_hash 가 stories.content_hash 와 다르면 옛 본문으로 만든 오디오이다(다시 생성 필요).
- story_requests(동화 생성 요청: user_id, story_id, voice_profile_id, status requested | approved | rejected | canceled,
  reject_reason, processed_by, processed_at, completed_at, notified_at)
  화면의 진행 단계는 status 와 같은 동화, 같은 목소리의 story_audios 상태로 계산한다(StoryRequests::state):
  requested(확인 대기) → approved 이면 making(만드는 중) | done(완성) | failed(생성 실패, 회원에게는 확인 중), 그 밖에 rejected, canceled
- playlists(회원 플레이리스트: name, repeat_mode off | all | one, shuffle 0 | 1),
  playlist_items(story_id + voice_profile_id, sort_order, 같은 플레이리스트에 같은 동화, 목소리는 한 번만)
- voice_clips(목소리별 짧은 음성: 질문 한도 초과 대체 문장, 오류 안내, 미리듣기)
- play_sessions(동화 1회 재생: voice_profile_id NULL 이면 기기 음성, audio_source voice | device, question_count, fallback_count, listened_ms, completed)
- interactions(질문과 답변: mode answer | quota | fallback | error | disabled | budget, question_text, answer_text, answer_audio_path, emotion, latency_ms, llm_ms, tts_ms)
- api_usage_logs(외부 API 사용량과 비용), provider_credit_snapshots(ElevenLabs 잔여 크레딧)
- jobs(백그라운드 작업), job_logs(작업 기록, 관리자 처리 콘솔), settings(운영 설정 JSON)
- notices, faqs, inquiries(공지, 자주 묻는 질문, 1:1 문의), admin_audit_logs, rate_limits

sentence_timings JSON 형식(story_audios)

    {"v":1,"duration":183200,"sentences":[{"seq":1,"start":0,"end":4200,"words":[[0,350],[380,900]]}, ...]}

    시각은 밀리초. words 는 Text::words(문장) 로 나눈 단어 순서와 같다. seq 는 생성 당시 story_sentences.seq 이다.

8. 업무 로직(server/app/Services) 약속

다른 화면에서 부르는 함수는 아래 이름과 반환 형식을 지킨다.

    Jobs::enqueue(string $type, array $payload, array $opts = []): int
        opts: priority(작을수록 먼저, 기본 5), ref_type, ref_id, delay_seconds, max_attempts
        type: voice_clone | story_tts | voice_clips | voice_delete | credit_sync | mail
    Jobs::log(?int $jobId, ?string $refType, ?int $refId, string $level, string $message): void
    Jobs::stats(): array   ['pending'=>n, 'running'=>n, 'failed_24h'=>n, 'done_today'=>n, 'by_type'=>[...]]
    Worker::run(int $maxSeconds = 20): array   ['processed'=>n, 'remaining'=>n]
    Worker::kick(): void   응답을 기다리지 않고 /_ops/worker.php 를 호출해 백그라운드 처리를 시작한다

    VoiceService::submit(int $profileId): void                       사용자 제출(draft → pending), 관리자 알림 메일
    VoiceService::approve(int $profileId, ?int $adminId, array $params = []): void   cloning 으로 바꾸고 voice_clone 작업 등록
    VoiceService::retryByUser(int $profileId): void                  회원의 다시 만들기(failed → cloning)
    VoiceService::failReasons(array $profileIds): array              실패한 목소리의 마지막 실패 사유 [id => 문구]
    VoiceService::reject(int $profileId, ?int $adminId, string $reason): void
    VoiceService::saveParams(int $profileId, array $params): void    stability, similarity_boost, style, speaker_boost
    VoiceService::queueStories(int $profileId, ?array $storyIds = null, bool $force = false): int   동화 오디오 생성 작업 등록 개수
        $storyIds 가 null 이면 이 목소리로 생성을 시작한(approved) 요청의 동화만 대상이다.
    VoiceService::progress(int $profileId): array   생성을 시작한 요청 기준 ['total'=>5, 'completed'=>3, 'failed'=>0, 'pending'=>2, 'percent'=>60]
    VoiceService::refresh(int $profileId): array    작업과 오디오 상태로 batch_status 를 다시 계산(목소리 status 는 바꾸지 않는다)

    StoryRequests::create(int $userId, int $storyId, array $voiceIds): array   ['created'=>[id], 'skipped'=>[['voice','reason']]]
        준비되지 않은 목소리, 이미 요청했거나 만든 목소리는 건너뛴다. 동시 요청 한도(request.max_open), 관리자 알림 메일,
        request.auto_approve 가 켜져 있으면 바로 생성 시작
    StoryRequests::cancel(int $userId, int $id), reject(int $id, ?int $adminId, string $reason)
    StoryRequests::approve(array $ids, ?int $adminId): array   ['approved'=>n, 'errors'=>[...]] 확인 대기, 반려, 실패 요청을 생성 시작
    StoryRequests::syncAudio(int $storyId, int $voiceId): void   story_tts 가 끝나면 Worker 가 부른다. 완성 시각을 남기고,
        회원의 만드는 중 요청이 모두 끝나면 완성 메일을 한 통으로 묶어 보낸다
    StoryRequests::voiceStates(int $userId, int $storyId), forUser(int $userId, string $filter), userCounts(int $userId)

    Playlists::create / rename / delete / setMode / addItem(완성 오디오만) / removeItem / moveItem / items / addable / forUser
    Playlists::order(array $playlist, int $seed): array   들을 수 있는 항목. 랜덤이면 seed 로 섞는다(같은 seed 면 같은 순서)
    Playlists::context(array $playlist, int $storyId, string $voice, int $pos, int $seed): ?array
        ['playlist'=>[id, name, pos, total, repeat, shuffle, seed, url], 'next'=>[pos, seed, item, url]|null]
        한 편 반복이면 같은 편, 전체 반복이면 끝에서 처음(랜덤이면 seed + 1 로 새로 섞음), 한 번만이면 끝에서 null
    VoiceService::delete(int $profileId): void      소프트 삭제 + voice_delete 작업
    VoiceService::testSpeak(int $profileId, string $text): array   ['ok'=>bool, 'audio_url'=>?, 'error'=>?] 관리자 테스트 재생(동기 합성)

    QuestionService::ask(array $user, array $session, string $audioTmpPath, string $mime, int $sentenceSeq, int $positionMs): array
        $session 은 이미 본인 것으로 확인한 play_sessions 행
        반환 ['ok'=>true, 'mode'=>'answer'|'quota'|'fallback'|'error'|'disabled'|'budget', 'question_text'=>?, 'answer_text'=>string,
              'audio_url'=>?string(null 이면 화면이 기기 음성으로 answer_text 를 읽는다), 'remaining'=>int, 'interaction_id'=>?int, 'latency_ms'=>int]

    Usage::log(array $row): void   provider, purpose, model, user_id, ref_type, ref_id, unit_type, units, cost_usd, latency_ms, success (cost_krw 는 환율 설정으로 계산)
    Usage::todayCostKrw(): float
    Usage::krw(float $usd): float,  Usage::elevenlabsCreditRatio(string $model): float,  Usage::summary(string $from, string $to): array
    Health::status(bool $fresh = false): array   ['gemini'=>[ok, ms, message], 'elevenlabs'=>[ok, ms, message, credits], 'storage'=>[...], 'db'=>[...], 'worker'=>[...]]
        결과는 설정 health.cache 에 300초 보관한다(관리자 설정 화면에서 고치는 항목이 아니다). Health::allOk(): bool

    ElevenLabs::addVoice(string $name, array $files, string $description = '', array $usage = [])   usage: user_id, ref_type, ref_id
    ElevenLabs::synthesize(string $voiceId, string $text, array $opts = [])   opts 에 timeout(초, 기본 180), chunk_chars(긴 글 분할 길이)도 받는다
    Alignment::joinText / build / estimate / sentenceAt(array $timings, int $ms): ?int

9. 주소 약속

회원(세션 RMSESS)

    /                       방문자 소개(로그인 상태면 /home)
    /login /signup /logout /password/forgot /password/reset/{token} /auth/{kakao|google} /auth/{provider}/callback
    /onboarding             첫 자녀 등록
    /home                   홈
    /stories                동화 전체 목록(분류 필터, 검색)
    /stories/{id}           동화 상세: 가족 목소리별 상태, 완성된 목소리로 듣기, 목소리를 골라 생성 요청(POST /stories/{id}/request)
    /library?tab=all|making|done|rejected   내 동화: 생성 요청 목록, 완성 동화 듣기와 플레이리스트 담기, 요청 취소
                            (POST /library/requests/{id}/cancel)
    /playlists /playlists/{id} /playlists/{id}/play(?item=)   플레이리스트(반복, 랜덤, 순서 바꾸기, 담기)
    /player                 마지막으로 듣던 동화(없으면 첫 동화)
    /player/{storyId}?voice={voiceProfileId|device}&pl={플레이리스트}&pos={순번}&seed={랜덤 순서}&auto=1
                            pl 이 있으면 한 편이 끝날 때 플레이리스트의 다음 편으로 넘어가고, auto=1 이면 열리자마자 재생한다.
                            잠자기 타이머 마감 시각은 sl 로 다음 편에 넘긴다
    /report?range=7|30      학습 리포트
    /voice-lab /voice-lab/new /voice-lab/{id} /voice-lab/{id}/record
    /settings /settings/profile /settings/password /settings/children ... /settings/playback /settings/notices /settings/support /settings/terms /settings/privacy /settings/withdraw
    /api/...                화면에서 fetch 로 부르는 JSON
        POST /api/settings/prefs                      Auth::PREF_DEFAULTS 키만 받아 저장, {ok, prefs}
        POST /settings/children/{id}/select           Accept: application/json 이면 {ok, child_id, message}
        POST /api/play-sessions, /api/play-sessions/{id}/progress|complete|question
        GET /api/voice-lab/status, POST /api/voice-lab/{id}/samples, /api/voice-lab/{id}/samples/{sampleId}/delete
    /media/sample/{id} /media/story-audio/{id} /media/clip/{id} /media/question/{interactionId} /media/answer/{interactionId} /media/cover/{storyId}
                            본인 것만 내려준다(표지는 공개). Range 지원

관리자(세션 RMADMIN, 경로 /admin)

    /admin/login /admin/logout /admin/setup(관리자가 한 명도 없을 때만, OPS_TOKEN 입력 필요)
    /admin/dashboard /admin/requests(?status, q, voice) /admin/voices /admin/voices/{id} /admin/stories /admin/stories/new /admin/stories/{id}
    /admin/members /admin/members/export.csv /admin/settings /admin/notices /admin/faqs /admin/inquiries /admin/admins /admin/audit
    /admin/media/...        관리자용 미디어(회원 소유 확인 없이 내려줌)
    /admin/api/worker/tick  작업 처리기 한 단계 실행(관리자 화면이 45초마다 호출)

10. 외부 API 키와 개발 모드

- 실서버 config.php 는 배포 때 GitHub Secrets 로 만든다: ELEVENLABS_API_KEY, GEMINI_API_KEY, KAKAO_REST_API_KEY, KAKAO_CLIENT_SECRET, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET(모두 선택).
- 키가 없으면 해당 기능은 안내 문구와 함께 꺼진다(예: 목소리 생성 승인 버튼 비활성, 질문하기 대신 기기 음성 안내, 간편 로그인 버튼 준비 중).
- 로컬 개발에서 config.php 의 providers_fake 를 true 로 두면 외부 API 대신 가짜 응답을 쓴다(합성 음성은 삐 소리 WAV, 답변은 고정 문장). 실서버 설정에는 이 값이 들어가지 않는다.

11. 로컬 실행

    cp server/config.example.php server/config.php    # DB 정보 수정, providers_fake true 권장
    php server/bin/migrate.php                         # 테이블 생성
    php server/bin/create-admin.php admin 비밀번호 이름  # 관리자 계정
    php server/bin/demo-seed.php                       # (선택) 데모 회원, 재생 기록
    npm install && npm run build                       # 화면 스타일
    php -S 127.0.0.1:8000 -t public public/index.php   # http://127.0.0.1:8000
    php server/tests/run.php                           # 테스트

여러 설정을 나눠 쓸 때는 환경 변수 REMEMVR_CONFIG=/경로/config.php 로 설정 파일을 지정한다.

12. 코드 규칙

- PHP 7.4 문법까지만 쓴다(match, nullsafe ?->, 이름 있는 인자, enum, readonly, str_contains, str_starts_with 금지). 화살표 함수(fn)는 7.4 에서 가능하다.
- SQL 은 항상 준비문 자리표시자로 값을 넘긴다. 테이블, 열 이름을 사용자 입력으로 만들지 않는다.
- 회원 데이터 조회는 반드시 user_id 조건을 건다(남의 자녀, 목소리, 재생 기록에 접근 불가).
- 비밀번호는 password_hash(PASSWORD_DEFAULT), 비교는 password_verify. 토큰 비교는 hash_equals.
- 업로드 파일은 확장자와 크기를 확인하고 저장 폴더에 무작위 이름으로 둔다. 웹 루트에 두지 않는다.
- API 키, 비밀번호를 로그나 화면에 남기지 않는다.
- 주석과 화면 문구는 한국어로 쓴다.
