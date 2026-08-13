# MES 개발 작업 메모

## 2026-08-13 작업 내역

### 시스템 개요
SMT/DIP 생산라인 통합 MES (Manufacturing Execution System)
- **Frontend**: `frontend/admin.html` (관리자), `frontend/dashboard.html` (작업자)
- **Backend**: PHP + MySQL (Docker, port 3307)
- **인증**: localStorage `role` 키 (`admin` / `worker`)

---

### 오늘 구현/수정 목록

#### 1. 업체 코드(c2) 숨김 처리
- **문제**: 작업지시 등록 시 업체 선택 드롭박스에 `업체명 (C2)` 형태로 코드가 노출됨
- **수정**: `loadCompanies()` 에서 `${c.name}` 만 표시, `data-code` 속성으로 내부 보존

#### 2. 입력폼 엔터키 UX 개선
- 작업지시 등록 모달에서 엔터키로 다음 필드로 포커스 이동
- 흐름: 업체 선택 → 작업수량 → 납기일 → 등록하기 버튼
- 새 업체 추가 시: 업체명 입력 → 등록 버튼으로 포커스 이동

#### 3. BOM 엑셀 컬럼 매핑 자동 기억 기능
- **DB 변경**: `company` 테이블에 `bom_mapping VARCHAR(255)` 컬럼 추가
- **저장**: BOM 저장 시 해당 업체의 컬럼 순서(JSON 배열)를 DB에 자동 기록
- **복원**: 같은 업체의 BOM을 다시 열면 저전 저장된 순서로 드롭다운 자동 세팅
- **예외 처리**: 기존 BOM 데이터를 다시 불러올 때는 매핑 미적용 (3칸 고정)
- **관련 파일**: `backend/api/save_bom.php`, `backend/api/get_companies.php`, `frontend/admin.html`

#### 4. Admin 권한 체크 스크립트 개선
- `admin.html` 진입 시 role이 없으면 `throw new Error()`로 나머지 스크립트 실행 중단
- 기존: alert → redirect / 수정 후: 바로 redirect

#### 5. Admin 화면 10초 자동 새로고침
- `init()` 내에 `setInterval(loadWOList, 10000)` 추가
- 진행률, KPI 수치가 자동 갱신됨

#### 6. admin → worker → admin 로그아웃 버그 수정
- **문제**: worker 화면에서 로그아웃하면 role 제거 후 admin.html로 이동 → admin.html이 role 없어 로그인 페이지로 튕김
- **수정**: admin이 worker 화면 나갈 때 role 유지하고 admin.html로 이동 (세션 유지)
- **관련 파일**: `frontend/dashboard.html` `logout()` 함수

#### 7. 완료 작업 납품 기능 추가
- **DB 변경**: `work_order` 테이블에 `completed_at DATETIME`, `shipped TINYINT`, `shipped_at DATETIME` 추가
- **신규 API**: `backend/api/ship_wo.php` — 납품 처리 엔드포인트
- **화면**: 완료된 작업 카드에 📦 납품 버튼 추가, 납품 완료 시 ✅ 납품완료 (날짜) 로 변경

#### 8. 오늘 완료 KPI 카운트 버그 수정
- **문제**: `due_date`(납기일) 기준으로 오늘 완료 카운팅 → 납기일 없는 작업 누락
- **수정**: `completed_at`(실제 완료 시각) 기준으로 변경
- `update_process.php` DONE 상태 전환 시 `completed_at = NOW()` 기록 추가

#### 9. 완료 작업 카드에 완료일자 표시
- 작업지시 ID 옆에 `완료: YYYY-MM-DD` 형태로 표시

#### 10. 진행률 바 공정 단계 표시
- **자삽(SMT)**: 🔵 파란 뱃지
- **자삽완료 대기**: 🟢 초록 뱃지
- **수삽(DIP)**: 🟡 노란 뱃지

#### 11. 수삽 진행률 0부터 시작
- **문제**: DIP 시작 시에도 SMT 누적 수량 기준으로 진행률 표시
- **수정**: `dip_qty` 필드 신규 추가 (`SHIPPING` + `FAIL` 바코드 수)
- DIP 단계일 때만 `dip_qty / target_qty` 로 진행률 계산

#### 12. 완료 작업 업체 필터
- 완료된 작업 패널 오른쪽 상단에 업체 드롭박스 추가
- 전체 업체 / 개별 업체별 필터링
- 자동 새로고침 시 선택 상태 유지
- 정렬: 최근 완료 내림차순 (`completed_at` 기준)

---

### DB 스키마 변경 사항

```sql
-- 업체 BOM 매핑 기억
ALTER TABLE company ADD COLUMN bom_mapping VARCHAR(255) DEFAULT NULL;

-- 작업지시 완료 추적 및 납품 관리
ALTER TABLE work_order
    ADD COLUMN completed_at DATETIME DEFAULT NULL,
    ADD COLUMN shipped TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN shipped_at DATETIME DEFAULT NULL;
```

---

### 현재 바코드 상태 흐름

```
WAIT → (자삽 시작)
→ TOP_DONE → BOTTOM_DONE → TEST_PASS   ← SMT 공정
→ (수삽 시작)
→ SHIPPING                              ← DIP 완료 (양품)
→ FAIL                                  ← 불량
```

### 작업지시 상태 흐름

```
READY → IN_PROGRESS (SMT 진행) → SMT_DONE → DIP_IN_PROGRESS (수삽 진행) → DONE (전체 완료)
```

---

## 2026-08-14 작업 내역 (Phase 1 & Phase 2 완성)

### 1. UI/UX 네비게이션 전면 리팩터링
- `admin.html`을 좌측 고정 사이드바 + 상단 브레드크럼/탑바 + 콘텐츠 패널 구조로 개편
- 모바일 반응형 사이드바 토글 지원
- 공통 디자인 시스템 (다크 테마, 통일된 테이블, 카드, 모달, KPI 스트립)

### 2. Phase 1 기능 완성
- **🏢 거래처 관리**: CRUD, 업체별 WO/양품/수량 현황 집계, 실시간 검색 필터
- **📦 품목 관리**: 품목코드/품목명/카테고리/단위 마스터 CRUD, 카테고리 셀렉트 필터
- **🔬 불량 현황**: 기간별 불량률/합격수 KPI, 공정별/업체별 불량 바 차트, 최근 50건 이력 테이블
- **📅 생산 계획**: 월별 작업지시 캘린더/테이블, D-Day 계산, 실시간 진행률 미니바

### 3. Phase 2 기능 완성
- **🏭 자재 입출고 관리**: 입고/출고 구분 등록, 파트번호 검색 및 기간 필터, 입출고량 KPI
- **🚚 출하 관리**: 출하 지시/등록, 대기→출하완료/취소 상태 변경 및 work_order 연동
- **👤 사용자/권한 관리**: Admin / Manager / Worker 역할 관리, 비밀번호 SHA256 암호화, 계정 활성화 토글
- **✔️ 품질 검사 기준**: 공정별 검사 항목/기준값/단위 마스터 관리, 활성 기준 필터

### 4. Phase 3 기능 완성
- **📑 수주 (PO) 관리**: 수주(PO) 등록·수정·삭제, 수주 ➔ 작업지시(WO) 및 바코드 원클릭 연계 발행, 고객사/상태별 필터
- **📊 종합 KPI 분석 대시보드**: 실시간 종합 수율, 납기 준수율(On-Time), 최근 7/14/30일 일별 생산/수율 추이 바 차트, SMT 라인 가동 상태 카드, 공정별 처리량/불량 점유율
- **🔔 시스템 알림 센터**: D-3 납기 임박 작업지시 자동 감지 경보, 실시간 미확인 알림 뱃지, 탑바 드롭다운 팝업, 10초 주기 자동 동기화
- **📜 시스템 활동 로그**: 작업지시·수주·출하·사용자 변경 등 주요 이벤트 실시간 감사 로그 추적

### 5. 신규 DB 스키마 & 마이그레이션
- `database/phase1_migration.sql` (item, company 확장)
- `database/phase2_migration.sql` (material_inout, shipment, users, quality_standard)
- `database/phase3_migration.sql` (sales_order, system_notification, system_log)

---

### 신규 생성 파일 목록

| 파일 | 설명 |
|---|---|
| `backend/api/create_company.php` | 업체 등록 |
| `backend/api/get_companies.php` | 업체 목록 조회 |
| `backend/api/get_admin_wo_list.php` | 관리자용 WO 목록 (KPI 포함) |
| `backend/api/get_kpi.php` | KPI 집계 |
| `backend/api/get_bom.php` | BOM 조회 |
| `backend/api/get_wo_list.php` | 작업자용 WO 목록 |
| `backend/api/create_wo.php` | WO 등록 |
| `backend/api/delete_wo.php` | WO 삭제 |
| `backend/api/update_wo.php` | WO 수정 |
| `backend/api/save_bom.php` | BOM 저장 + 업체 매핑 기록 |
| `backend/api/start_wo.php` | 자삽 시작 |
| `backend/api/start_dip_wo.php` | 수삽 시작 |
| `backend/api/ship_wo.php` | 납품 처리 |
| `frontend/admin.html` | 관리자 대시보드 |
| `frontend/login.html` | 로그인 화면 |
