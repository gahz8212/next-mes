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
READY → IN_PROGRESS → SMT_DONE → DIP_IN_PROGRESS → DONE
```

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
