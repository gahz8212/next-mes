# 03. POP(Point of Production) 단말 프론트엔드 명세서
**작성일:** 2026-08-12
**목표:** MES 현장 작업자용 바코드 스캔 화면(POP)의 프론트엔드 아키텍처 및 백엔드 연동 구현

## 1. 디렉토리 구조 (순수 바닐라 환경)
외부 인터넷(CDN) 및 Tailwind 의존성을 완전히 제거한다.
* `frontend/pop.html`: DOM 뼈대
* `frontend/css/pop.css`: 레이아웃(Flex/Grid) 및 상태 점멸 애니메이션
* `frontend/js/pop.js`: 오디오 피드백, 글로벌 키다운 이벤트, API 통신

## 2. 텍스트 와이어프레임 (UI 레이아웃 구성)
화면은 크게 3개의 수직 영역(Header, Main, Footer)으로 구성되며, 터치/원거리 시야에 적합하도록 큼직하게 디자인한다.

* **[상단 헤더] (고정 영역)**
  * 좌측: 라인명(`SMT-LINE-01`), 현재 공정명(`자재 셋업`)
  * 우측: 생산 모델(`BOARD-A1`), 작업자 정보(`성현 (OP-001)`)
* **[중앙 메인 패널] (동적 상태 영역, 가장 넓게 차지)**
  * 평상시: 회색 테두리, "WAITING FOR SCAN" 텍스트, "바코드를 스캔하세요" 안내문.
  * 입력 시: 작업자가 타이핑/스캔 중인 바코드 텍스트가 실시간으로 하단에 표시됨.
  * 결과(PASS): 패널 전체 녹색 점멸, "PASS" 텍스트 표출.
  * 결과(FAIL): 패널 전체 적색 점멸, "REJECTED" 텍스트 표출.
* **[하단 이력 테이블] (Traceability, 고정 높이)**
  * 4개의 컬럼(시간, 바코드, 결과(PASS/FAIL), 메시지)으로 구성.
  * 최근 스캔 이력이 위로 추가되며, 최대 5건까지만 화면에 유지됨.

## 3. 핵심 UI/UX 요구사항
* **마우스 프리(Mouse-free):** 입력창(`input`)이 없어도 `document` 전역(Global)에서 `keydown` 이벤트를 가로채어 바코드 버퍼를 채움 (Enter 키로 제출).
* **오디오 피드백:** `window.AudioContext` 사용. (PASS: 고음 0.3초, FAIL: 저음 0.6초)
* **비주얼 복귀:** 스캔 결과(PASS/FAIL) 표출 후 3초 뒤 자동으로 '대기(WAITING)' 상태로 복귀.

## 4. 백엔드 연동 규격 (API Fetch)
* **Target URL:** `/api/update_process.php`
* **Method:** `POST`
* **Payload (JSON):** `{"barcode": "[스캔된 바코드]", "process_name": "MATERIAL_SETUP"}`