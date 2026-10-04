르멤버 배포와 DB 운영 안내

1. 구조 요약

cafe24 호스팅 MariaDB 는 서버 내부(localhost)에서만 접속된다. 그래서 테이블 생성과 필드 추가는 외부에서 직접 하지 않고, 서버에 올라간 PHP 스크립트가 대신 실행한다.

- 스키마 변경은 server/migrations/ 에 번호 붙은 SQL 파일로 저장소에 남긴다.
- PR 이 main 에 머지되면 GitHub Actions 가 FTP 로 파일을 올린 뒤, 비밀 토큰을 붙여 https://사이트/_ops/migrate.php 를 호출한다.
- 이 스크립트가 서버 안에서 localhost 로 DB 에 접속해 아직 적용하지 않은 SQL 파일만 순서대로 실행하고, schema_migrations 테이블에 이력을 남긴다.
- 매일 새벽 3시 GitHub Actions 가 _ops/backup.php 를 호출해 DB 덤프를 받아 Actions 아티팩트로 보관한다.

서버 폴더 배치(호스팅 홈 폴더 기준 절대 경로. cafe24 는 FTP 로그인 직후 위치가 /www 이다)

    /www/            저장소의 public/ (웹 루트, 브라우저로 열리는 곳)
    /rememvr_app/    저장소의 server/ (설정, DB 접속 코드, 마이그레이션. 웹으로 열리지 않음)

2. 최초 1회 설정

2-1. GitHub Secrets 등록

저장소 Settings → Secrets and variables → Actions → Secrets 탭 → New repository secret 에서 아래 항목을 하나씩 등록한다. 접속정보는 채팅, 이슈, 커밋 어디에도 적지 않고 이곳에만 넣는다.

    FTP_SERVER      FTP 호스트. 보통 아이디.mycafe24.com
    FTP_USERNAME    FTP 아이디 (cafe24 호스팅 아이디)
    FTP_PASSWORD    FTP 비밀번호
    DB_NAME         DB 이름. cafe24 는 보통 호스팅 아이디와 같다
    DB_USER         DB 아이디. 보통 호스팅 아이디와 같다
    DB_PASSWORD     DB 비밀번호
    SITE_URL        사이트 주소. 예) https://아이디.mycafe24.com
    OPS_TOKEN       운영 스크립트 호출용 비밀 토큰. 32자 이상 임의 문자열
    BACKUP_PASSPHRASE   (선택) 백업 파일 암호화 비밀번호

외부 API 키(서비스 기능용, 없으면 해당 기능만 꺼진 상태로 배포된다)

    ELEVENLABS_API_KEY      ElevenLabs API 키. 목소리 복제와 동화, 답변 음성 합성. 목소리 복제는 Starter 이상 요금제 필요
    GEMINI_API_KEY          Google AI Studio 의 Gemini API 키. 아이 질문 음성 이해와 답변 생성
    KAKAO_REST_API_KEY      (선택) 카카오 로그인 REST API 키. Redirect URI: 사이트주소/auth/kakao/callback
    KAKAO_CLIENT_SECRET     (선택) 카카오 로그인 Client Secret 을 켰을 때만
    GOOGLE_CLIENT_ID        (선택) 구글 로그인 OAuth 클라이언트 ID. 승인된 리디렉션 URI: 사이트주소/auth/google/callback
    GOOGLE_CLIENT_SECRET    (선택) 구글 로그인 OAuth 클라이언트 보안 비밀

키를 등록하거나 바꾼 뒤에는 Actions → Deploy → Run workflow 로 한 번 배포해야 서버 설정에 반영된다.

OPS_TOKEN 은 아래 방법 중 하나로 만든다.

    터미널:      openssl rand -hex 32
    PowerShell:  -join ((48..57)+(97..102) | Get-Random -Count 64 | % {[char]$_})

cafe24 정보 위치: cafe24 호스팅 관리 → 나의 서비스 관리 → 서비스 접속 정보(FTP, DB 정보).

2-2. 선택 변수(Variables 탭)

기본값과 다를 때만 Settings → Secrets and variables → Actions → Variables 탭에 등록한다.

    FTP_PROTOCOL    기본 ftp. 상품이 FTPS 를 지원하면 ftps 로 지정한다(SFTP 는 지원하지 않음)
    FTP_PORT        기본 21
    FTP_WEB_DIR     기본 /www/
    FTP_APP_DIR     기본 /rememvr_app/  (웹 루트 밖에 둘 수 없는 상품이면 /www/_app/ 로 지정. server/.htaccess 가 외부 접근을 막는다)
    DB_HOST         기본 localhost
    BACKUP_RETENTION_DAYS   백업 보관 일수. 기본 30
    MAIL_FROM       보내는 메일 주소. 기본 no-reply@사이트도메인
    STORAGE_DIR     녹음 파일, 생성 오디오, 세션을 둘 서버 폴더(절대 경로 또는 앱 폴더 기준 상대 경로). 기본은 호스팅 홈의 rememvr_data

2-3. 테스트 서버(선택)

테스트용 cafe24 호스팅이 따로 있으면 같은 항목 이름 앞에 TEST_ 를 붙여 등록한다(TEST_FTP_SERVER, TEST_DB_NAME, TEST_OPS_TOKEN 등). 등록하면 Run workflow 에서 target 을 test 로 골라 테스트 서버에 배포할 수 있다.

2-4. 실서버 배포 승인자(선택)

Settings → Environments → production → Required reviewers 에 본인을 지정하면 실서버 배포 전에 승인 버튼을 한 번 더 누르게 된다. 비공개 저장소는 GitHub 유료 요금제에서만 이 기능이 보인다. 설정하면 main 머지 후 자동 배포도 승인 뒤에 진행된다.

2-5. 관리자 계정 만들기(최초 1회)

첫 배포 후 https://사이트주소/admin 에 접속하면 관리자 최초 설정 화면이 열린다. GitHub Secrets 에 넣은 OPS_TOKEN 값을 입력해 본인 확인을 하고 최고 관리자 계정을 만든다. 관리자가 한 명이라도 있으면 이 화면은 닫힌다. 다른 관리자는 관리자 화면 → 관리자 계정에서 추가한다.

3. 배포

- 실서버: PR 이 main 에 머지되면 자동 배포된다(public/, server/ 또는 배포 워크플로 변경 시).
- 수동 배포: Actions → Deploy → Run workflow → target(production 또는 test)과 ref(브랜치나 태그)를 골라 실행한다.
- 실서버 배포가 성공하면 prod-20261004-153000 같은 태그가 생긴다.
- 롤백: Run workflow 에서 ref 에 이전 prod- 태그를 넣고 실행한다. 단, DB 마이그레이션은 되돌리지 않는다. 스키마를 되돌려야 하면 되돌리는 SQL 을 새 번호 파일로 추가한다.

4. 테이블 생성과 필드 추가 방법

server/migrations/ 에 다음 번호로 새 파일을 만든다. 파일 이름은 숫자 4자리, 밑줄, 영문 소문자 설명 순서이다.

    server/migrations/0002_add_users_nickname.sql

    ALTER TABLE users
        ADD COLUMN nickname VARCHAR(50) NULL COMMENT '별명' AFTER name;

규칙

- 이미 서버에 적용된 파일은 절대 수정하지 않는다. 수정하면 체크섬이 달라져 다음 배포의 마이그레이션 단계가 실패한다. 변경은 항상 새 파일로 추가한다.
- 한 파일에 여러 문장을 넣을 수 있고 세미콜론으로 구분한다. DELIMITER, 프로시저, 트리거는 쓰지 않는다.
- MariaDB 의 CREATE, ALTER 는 즉시 반영되어 되돌릴 수 없다. 파일 중간에서 실패하면 앞 문장은 이미 반영된 상태이므로, 한 파일에는 함께 성공해야 하는 작은 변경만 담는다.
- 인덱스가 걸리는 VARCHAR 는 191자 이하로 둔다(구버전 MariaDB 의 utf8mb4 인덱스 길이 제한).
- PR 을 올리면 CI 가 실제 MariaDB(10.3, 10.6)에 마이그레이션을 적용해 보므로 문법 오류는 배포 전에 걸러진다.

로컬에서 확인할 때

    cp server/config.example.php server/config.php   # 로컬 DB 정보로 수정
    php server/bin/migrate.php status                 # 상태 확인
    php server/bin/migrate.php                        # 미적용 파일 적용

5. 백업과 복원

- 매일 03:00(한국 시각)에 Actions → DB Backup 이 실행된다. 수동 실행도 가능하다.
- 결과는 해당 실행 화면 아래 Artifacts 에서 내려받는다. 보관 기간이 지나면 자동 삭제된다.
- 덤프 마지막 줄에 "-- Dump completed" 가 없으면 실패로 처리되어 알림 메일이 온다.
- 복원: 파일을 내려받아 압축을 풀고(암호화했다면 gpg -d 로 먼저 복호화) cafe24 DB 관리 도구(phpMyAdmin)의 가져오기로 올린다. 복원은 기존 테이블을 지우고 덮어쓰므로 반드시 현재 DB 를 먼저 백업한 뒤 진행한다.

확인된 서버 환경(2026-10-04 접속 점검): PHP 8.4, Apache 모듈 방식, open_basedir 제한 없음, MariaDB 10.6, 홈 폴더 쓰기 가능.
접속정보가 맞는지는 Actions → Connection Check → Run workflow 로 언제든 다시 확인할 수 있다. 점검 파일은 끝나면 지워진다.

5-1. 서버 파일(녹음, 생성 오디오) 보관

DB 백업에는 녹음 파일과 생성 오디오가 들어가지 않는다. 파일은 서버 저장 폴더(기본 rememvr_data/)에 있으며 FTP 로 내려받아 따로 보관할 수 있다. 동화 오디오는 관리자 화면에서 다시 생성할 수 있지만 크레딧이 들고, 녹음 원본은 다시 만들 수 없으므로 주기적으로 내려받아 두기를 권한다.

5-2. 백그라운드 작업

목소리 생성, 동화 오디오 일괄 생성, 대체 음성 생성은 jobs 테이블에 쌓인 뒤 처리된다. cafe24 웹호스팅에는 예약 작업(cron)이 없으므로 다음 두 방법으로 진행된다.
- 사이트에 요청이 들어올 때 대기 작업이 있으면 서버가 스스로 /_ops/worker.php 를 호출한다(1분에 한 번 이하).
- 관리자 화면이 열려 있으면 45초마다 작업을 한 단계씩 진행한다. 목소리 관리 화면에서 처리 콘솔을 열어 두면 진행이 빠르다.

6. 보안

- _ops/migrate.php, _ops/backup.php 는 POST 와 OPS_TOKEN 이 모두 맞을 때만 동작하고, 그 외에는 404 를 돌려준다.
- 토큰이 노출되었다고 판단되면 GitHub Secrets 의 OPS_TOKEN 을 새 값으로 바꾸고 실서버 배포를 한 번 실행한다. 서버 설정 파일이 새 토큰으로 다시 만들어진다.
- 토큰이 평문으로 오가지 않도록 SITE_URL 은 https 주소를 쓴다(cafe24 무료 SSL 적용 후).
- server/config.php 는 배포할 때마다 Secrets 로 새로 만들어지며 저장소에는 올라가지 않는다(.gitignore).
