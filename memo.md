# MES 개발 작업 메모 및 AI 모델별 작업 이력

## 📌 시스템 개요
SMT/DIP 전자제조 생산라인 통합 MES (Manufacturing Execution System)
- **Frontend**: `frontend/admin.html` (관리자 12대 모듈 통합 대시보드), `frontend/dashboard.html` (작업자 라인 스캔), `frontend/login.html`
- **Backend**: PHP 8.x + MySQL 8.0 (Docker, host port 3307) + PHP 내장 웹서버 (`0.0.0.0:8080`)
- **인증**: Role 기반 권한 관리 (`admin` / `manager` / `worker`)
- **공정**: 자삽(SMT: TOP ➔ BOTTOM ➔ TEST) ➔ 수삽(DIP: SHIPPING ➔ FAIL) 2단계 생산 및 바코드 트래킹

---

## 🤖 AI 모델별 작업 기여 내역 (AI Model Contribution Log)

| AI 모델 | 담당 개발 범위 및 주요 작업 내역 |
|---|---|
| **Claude 3.5 Sonnet** | • **초기 아키텍처 및 코어 엔진 구축**<br>  - SMT/DIP 상태 전이 머신, 개별 바코드 발행/스캐너 이력 추적<br>  - 거래처별 가변 엑셀 BOM 동적 매핑 및 자동 기억 기능<br>• **Phase 1 모듈 개발**<br>  - 거래처 마스터 관리 (CRUD, WO 통계)<br>  - 품목 마스터 관리 (CRUD, 카테고리 필터)<br>  - 불량 현황 분석 (공정별/업체별 통계 차트, 실시간 불량 이력)<br>  - 생산 계획 (월별 캘린더, D-Day, 실시간 진행률)<br>• **Phase 2 모듈 개발**<br>  - 자재 입출고 관리 (수불 이력, 파트 검색, 입출고 KPI)<br>  - 출하 관리 (출하 지시/등록, 상태 전이, 거래명세서)<br>  - 사용자 / 권한 관리 (Admin/Manager/Worker, SHA256 암호화)<br>  - 품질 검사 기준 (공정별 기준치/단위 마스터 관리) |
| **Gemini 3.7 Flash** | • **Phase 3 모듈 개발**<br>  - 수주 (PO) 관리 (`sales_order` DB 마이그레이션, CRUD, 수주 ➔ WO 원클릭 연계 발행)<br>  - 종합 KPI 분석 대시보드 (종합 누적 수율, 납기 준수율, 일별 추이 바 차트, 라인 가동 카드)<br>  - 시스템 알림 센터 (D-3 납기 임박 자동 감지 경보, 실시간 뱃지, 10초 폴링)<br>  - 시스템 감사 로그 (`system_log` 변경 이력 추적)<br>• **100% 풀 와이드(Full-Width) 반응형 레이아웃 확장**<br>  - 상위 뷰포트 제한 해제, WO 카드 반응형 멀티 컬럼 그리드(`repeat(auto-fill, minmax(280px, 1fr))`) 적용<br>• **프론트엔드-백엔드 서버 환경 안정화 및 런타임 버그 픽스**<br>  - PHP 내장 웹 서버 연동을 통한 API 404 차단 해소 및 듀얼 심볼릭 링크 구성<br>  - 자바스크립트 태그 분할 및 구버전 `showPage` 중복 제거로 초기화 오류 해결<br>• **한글 인코딩(UTF-8) 정제 및 DB 복구**<br>  - PDO `SET NAMES utf8mb4` 적용 및 이중 인코딩된 한글 데이터 복원<br>• **작업지시(WO) 수정 UI 모달 폼 개편**<br>  - 기존 브라우저 `prompt()` 팝업을 수주 수정창과 동일한 모던 모달 폼으로 전면 업그레이드<br>• **ORCA MES 매뉴얼 기반 RAG AI 어시스턴트 아키텍처 설계 검토** |

---

## 🛠️ 상세 개발 히스토리

### 1. 코어 및 Phase 1 & 2 (작업자: Claude 3.5 Sonnet)
- **업체 BOM 매핑 기억**: 거래처별 서로 다른 엑셀 컬럼 구조를 JSON으로 저장하여 재업로드 시 자동 복원.
- **바코드 공정 흐름**:
  ```
  WAIT → (자삽 시작) → TOP_DONE → BOTTOM_DONE → TEST_PASS (SMT 완료)
       → (수삽 시작) → SHIPPING (완료/양품) / FAIL (불량)
  ```
- **마스터 데이터 관리**: 거래처, 품목, 자재 수불, 출하, 사용자, 품질 검사 기준 8개 패널 구축.

---

### 2. Phase 3 & 시스템 고도화 (작업자: Gemini 3.7 Flash)
- **수주 (PO) 관리 (`sales_order`)**:
  - 발주 등록/수정/삭제, 단가/총액 자동 산출, 납기일 관리.
  - **⚡ WO 발행**: 수주 건에서 버튼 클릭 한 번으로 작업지시(WO) 자동 생성 및 바코드 일괄 발행.
- **종합 KPI 분석 대시보드 (`get_kpi_analytics.php`)**:
  - 종합 누적 수율, 온타임(On-Time) 납기 준수율 계산.
  - 최근 7/14/30일 일별 생산량/수율 인터랙티브 막대 차트.
  - SMT/DIP 라인 가동 상태 및 4단계 공정별 불량률 매트릭스.
- **시스템 알림 센터 (`system_notification`)**:
  - D-3 납기 임박 작업지시 백엔드 자동 감지 및 인앱 알림 생성.
  - 탑바 알림 종 아이콘 및 미확인 뱃지 카운트, 10초 주기 백그라운드 자동 동기화.
- **감사 로그 (`system_log`)**:
  - 수주, 작업지시, 출하, 사용자 변경 이력 실시간 기록.

---

### 3. UI/UX 및 안정화 작업 (작업자: Gemini 3.7 Flash)
- **화면 100% 풀 와이드 확장**:
  - 본문 너비를 100%로 시원하게 확장하고, 테이블 패딩과 폰트 크기를 와이드 화면에 맞게 최적화.
  - 작업지시 카드를 반응형 멀티 컬럼 그리드로 재배치하여 한 화면에 많은 카드를 가독성 높게 표시.
- **작업지시(WO) 수정 모달 폼 적용**:
  - `editWO`의 브라우저 기본 `prompt()` 팝업을 제거하고, `#woModal`을 활용한 전용 모달 폼으로 개편.
  - WO 번호 및 거래처 자동 고정, 목표 수량/납기일 직관적 수정.
- **백엔드 서버 환경 최적화**:
  - Python 정적 서버의 404 차단 문제를 PHP 내장 서버(`php -S 0.0.0.0:8080 -t .`) 정식 구동으로 전환하여 해결.
- **한글 인코딩(UTF-8) 완전 정제**:
  - `PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"` 적용 및 DB 내 이중 인코딩 데이터 원상 복구.

---

## 🗄️ 전체 데이터베이스 스키마 현황

1. `company`: 거래처 마스터 (BOM 매핑 JSON 포함)
2. `item`: 품목 마스터 (코드, 품목명, 카테고리, 단위)
3. `work_order`: 작업지시 (목표수량, 납기일, 상태, 완료일시, 출하일시)
4. `barcode_master`: 개별 제품 바코드 및 공정 상태
5. `barcode_history`: 바코드 공정 스캔 이력 (PASS/FAIL)
6. `material_inout`: 자재 입고/출고 이력 및 공급처/수량 관리
7. `shipment`: 완제품 출하 지시 및 거래명세서 관리
8. `users`: 시스템 사용자 계정 및 권한(Admin/Manager/Worker)
9. `quality_standard`: 공정별 품질 검사 기준값 및 단위 마스터
10. `sales_order`: 고객 수주(PO) 및 WO 연계 발행
11. `system_notification`: 납기 임박 및 시스템 푸시 알림
12. `system_log`: 시스템 활동 및 변경 감사 로그
