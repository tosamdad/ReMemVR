# ReMemVR (르멤버)

가족의 목소리로 아이에게 동화를 읽어 주는 모바일웹 서비스의 저장소이다.

폴더 구성

    public/              웹 루트(cafe24 www). 브라우저로 열리는 파일
    public/_ops/         배포와 백업용 운영 엔드포인트(토큰 필수)
    server/              웹 루트 밖에 올라가는 앱 코드와 설정
    server/migrations/   DB 스키마 변경 SQL (0001_설명.sql 형식)
    server/bin/          로컬과 CI 용 명령(migrate.php, backup.php)
    .github/workflows/   CI, 배포, DB 백업 워크플로
    docs/                운영 문서

배포, DB 테이블 생성과 필드 추가, 백업과 복원 방법은 docs/deploy-and-db.md 에 정리했다.
