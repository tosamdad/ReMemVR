# ReMemVR (르멤버)

가족의 목소리로 아이에게 동화를 읽어 주는 모바일웹 서비스의 저장소이다.
부모가 목소리를 녹음하면 ElevenLabs 로 목소리를 복제해 무료 동화 12편을 미리 만들어 두고,
아이는 동화를 듣다가 궁금한 것을 말로 물어볼 수 있다(Gemini 가 질문을 이해하고, 같은 목소리로 답한다).

폴더 구성

    public/              웹 루트(cafe24 www). 브라우저로 열리는 파일, 화면 스크립트와 그림
    public/_ops/         배포, 백업, 백그라운드 작업용 운영 엔드포인트(토큰 필수)
    server/              웹 루트 밖에 올라가는 앱 코드와 설정
    server/app/          공통 부품(Core), 컨트롤러, 업무 로직(Services)
    server/routes/       화면 주소 등록
    server/views/        화면 템플릿(user: 모바일 회원 화면, admin: 관리자 화면)
    server/migrations/   DB 스키마 변경 SQL (0001_설명.sql 형식)
    server/bin/          명령행 도구(migrate, backup, create-admin, worker, demo-seed, smoke)
    server/tests/        PHP 테스트
    src/css/             화면 스타일 원본(Tailwind)
    .github/workflows/   CI, 배포, DB 백업 워크플로
    docs/                개발, 운영 문서

문서

- docs/architecture.md: 코드 구조, 화면 규칙, 데이터 구조, 로컬 실행 방법
- docs/deploy-and-db.md: 배포, API 키 등록, DB 테이블 생성과 필드 추가, 백업과 복원
