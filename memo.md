# SMT MES 프로젝트 진행 현황 요약 (memo.md)

## 1. 프로젝트 초기 셋업 완료
- **프론트엔드 (HTML/CSS/JS)**: `frontend/css`, `frontend/js` 디렉토리 구성 및 ORCA 디자인 원칙이 적용된 `style.css` 생성.
- **백엔드 (PHP API)**: `backend/api`, `backend/uploads` 디렉토리 구성. 데이터베이스 연결을 위한 `config.php` 및 자재 스캔/검증 로직을 담당하는 `scan_feeder.php` 구축.
- **데이터베이스 (MySQL)**: `database/init` 디렉토리 내에 자동 초기화를 위한 `02_DB_Schema.sql` 배치.
- **문서화 (Docs)**: 프로젝트의 청사진인 마스터 프롬프트(`00_Master_Prompt.md`)와 공정 시나리오(`01_Production_Scenario.md`) 문서화.
- **인프라 (Docker)**: MySQL 8.0(3307 포트 매핑) 및 Node-RED(1881 포트 매핑) 컨테이너 구동을 위한 `docker-compose.yml` 세팅 완료.

## 2. 핵심 개발 원칙 (마스터 프롬프트)
- **명확한 기술 스택 분리**: 프론트엔드는 순수 Vanilla 웹 기술, 백엔드는 순수 PHP API로 구성 (HTML 혼용 금지).
- **ORCA 스타일 가이드 준수**: 넓은 여백, 둥근 모서리의 카드형 UI, 단일 `style.css` 사용. Tailwind 금지.
- **데이터 무결성 확보**: 모든 공정 이동 상태는 덮어쓰지 않고 `barcode_history`에 반드시 이력을 남김.
- **스마트 포카요케**: 자재 스캔 시 BOM 일치 여부와 MSL(Floor Life) 제한 시간을 검증하며 최초 스캔 시 자동 개봉 처리.

## 3. 향후 진행 목표
- [x] 메인 대시보드 UI 제작 (ORCA 스타일 적용)
- [x] BOM 업로드 및 작업 지시서 생성 페이지
- [x] 생산 라인(Node-RED 연동) 기반 SMT 실장 추적 및 엣지 통신 구현

## 4. 추가 구현 사항
- **POP(Point of Production) 단말 프론트엔드 구축 완료**: `frontend/pop.html`, `css/pop.css`, `js/pop.js` 생성.
  - 마우스 프리(Global Keydown) 바코드 스캔 로직 구현.
  - 작업 상태(PASS/FAIL)에 따른 화면 점멸 애니메이션 및 오디오 피드백 적용.
  - `update_process.php` 백엔드 API 연동 완료.
