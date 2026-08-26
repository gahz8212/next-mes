# 📘 MES 개발 & AI 협업 종합 가이드북 (Lessons Learned & Architecture Rules)

본 문서는 지난 2주간 SMT/DIP MES(제조실행시스템) 프로젝트를 구축하고 고도화하는 과정에서 얻은 **핵심 아키텍처 원칙, DB 및 데이터 무결성 규칙, AI 협업/프롬프트 가이드라인**을 상세히 기록한 종합 지침서입니다.  
향후 새로운 MES 앱을 개발하거나 기존 시스템을 확장할 때 반드시 본 가이드를 준수하여 시행착오를 제로화합니다.

---

## 📌 1. 하드코딩 제로 원칙 (Zero Hardcoding Principle)

### 1.1 동적 데이터 DB 바인딩 100% 의무화
- **금지 대상**: 거래처명(LG전자, 삼성 등), 품목명(Main Board A타입 등), 공정 라인 명칭(SMT 1호기, DIP 등), 단가, 목표 수량, 상태 라벨 텍스트 등.
- **원칙**: 모든 비즈니스 데이터는 UI 코드나 자바스크립트/PHP 스크립트에 고정 텍스트로 적지 않고, **데이터베이스(`company`, `item`, `product_master`, `work_order`, `shipment` 등)에서 REST API를 통해 동적으로 조회**하여 바인딩해야 합니다.
- **폴백(Fallback) 조인 설계**:
  ```sql
  -- 데이터 누락 방지를 위한 N중 폴백 조인 예시 (ShipmentController 참조)
  COALESCE(
      c.name, 
      (SELECT c2.name FROM company c2 WHERE c2.id = w.company_id), 
      '기본 거래처'
  ) AS company_name
  ```

### 1.2 시스템 환경 설정 및 Port 분리
- 로컬 Docker / 테스트 / 프로덕션(AWS EC2) 등 환경별 포트 및 URL은 `.env` 또는 중앙 설정 파일(`config.php` / `Database.php`)로 관리합니다.
- Node-RED 및 REST API 엔드포인트 URL을 코드 내에 개별 작성하지 않고 상대 경로(`../backend/api/...`) 또는 공통 상수로 일원화합니다.

---

## 📌 2. SMT/DIP MES 공정 & 데이터 무결성 룰 (Data Integrity Rules)

### 2.1 수주 ➔ 작업지시 ➔ 출하 3단계 정합성
1. **수주 (`sales_order`)**: 고객사 요청 수주 및 품목 등록 (`sales_order_item`).
2. **작업지시 (`work_order`)**: 수주 품목별 1:1 또는 1:N 생성 (`wo_id`).
3. **출하 (`shipment`)**: 
   - 완료된 작업지시만 출하 대기(`PENDING`) 목록에 노출.
   - 무효/테스트성 유령 작업지시가 생성되지 않도록 `sales_order_item`과 `work_order` 간 외래키/연결 관계를 엄격히 검증.

### 2.2 출하 날짜 & 상태 처리 로직 (Shipment Date Rules)
- **상태별 라벨 표기 분리**:
  - **출하 대기 (`PENDING`)**: **`📅 출하예정일`** (생산계획 메뉴의 고객사 납품예정일 `delivery_date`과 명칭 통일)
  - **출하 완료 (`SHIPPED`)**: **`📅 출하완료일`** (실제 출하 처리가 완료된 날짜)
- **출하 완료 시 날짜 자동 갱신 및 미래 날짜 방어**:
  - 작업자가 [출하 완료] 버튼을 누른 순간 **버튼을 누른 당일 시점(`CURDATE()`)이 출하완료일로 자동 기록**되어야 합니다.
  - 실수로 미래 날짜(예: 8/28, 8/31)가 수동 입력되더라도 **오늘 날짜(`date('Y-m-d')`) 이하로 자동 제한(Cap)**하는 방어 가드를 백엔드에 구축해야 합니다.

### 2.3 경영진(CEO) 대시보드 실시간 자동 연동
- 출하 완료(`SHIPPED`) 처리 시:
  1. `shipment.status = 'SHIPPED'`
  2. `work_order.shipped = 1`, `shipped_at = NOW()`
  3. `sales_order_item.status = 'COMPLETED'`
  4. 연결된 수주 전체 완료 시 `sales_order.status = 'COMPLETED'`
- CEO 대시보드(`ceo.html`)는 5초 주기로 집계 API를 호출하여 **출하 완료 매출액(`completed_revenue`)과 수주 진행률**이 실시간 자동 반영되도록 연동되어야 합니다.

---

## 📌 3. Docker & 서버 배포 안정성 수칙

### 3.1 Docker 파일 바인딩 잠금(EBUSY) 이슈 방지
- **문제**: Linux Docker 환경에서 `flows.json` 같은 단일 파일을 호스트와 바인드 마운트(`- ./flows.json:/data/flows.json`)하면, 컨테이너 내부(Node-RED 등)에서 Atomic Write (rename) 시 `EBUSY: resource busy or locked` 오류가 발생하며 배포가 멈춥니다.
- **해결책**: 단일 파일 마운트 대신 디렉토리 볼륨(`nodered_data:/data`)을 사용하고, 설정/플로우 배포는 REST API(`deploy_nodered_docker.py`) 또는 디렉토리 단위 마운트를 활용합니다.

### 3.2 멱등성(Idempotency)을 보장하는 자동 마이그레이션 (`migrate.php`)
- DB 변경 사항은 직접 SQL을 실행하기보다 `backend/migrate.php` 스크립트에 포함시킵니다.
- 이미 컬럼이 존재할 경우(오류 1060 등) 안전하게 예외를 처리하여 여러 번 실행해도 시스템이 멈추지 않도록 구축합니다.

---

## 📌 4. AI 개발 협업 & 프롬프트 가이드라인 (Prompting Best Practices)

지난 2주간의 경험을 통해 AI와 협업할 때 답답함을 줄이고 정확도를 100%로 올리는 프롬프트 및 맥락 관리 전략입니다.

### 4.1 피해야 할 프롬프트 방식 vs 권장하는 방식
| 구분 | ❌ 피해야 할 방식 | ⭕ 권장하는 방식 (효율적) |
|---|---|---|
| **요구사항 전달** | "이거 이상해. 왜 이래?" (단편적 표현) | "출하 관리 화면에서 대기 상태 건의 날짜 라벨이 '출하완료일'로 나옵니다. 대기일 땐 '출하예정일'로 바꾸고 완료 버튼 누를 때 오늘 날짜로 바뀌게 해줘." |
| **대상 서버 지정** | "배포해줘" (로컬인지 실서버인지 모호) | "로컬 검증 후 실서버(`https://mes.memyself.shop`)까지 git push하여 반영해줘." |
| **오류 보고** | "버튼 눌렀는데 안 됨" | "콘솔 에러 메시지나 네트워크 응답을 포함하여 어떤 버튼을 눌렀을 때 반응이 없는지 구체적 위치 명시." |

### 4.2 컨텍스트 메모리 (`MEMO.MD` / `gemini.md`) 활용법
- AI는 대화가 길어지거나 세션이 새로 시작되면 과거 의도와 세부 설계를 잊을 수 있습니다.
- 주요 변경 사항이나 시스템 룰(테이블 구조, API 명세, 비즈니스 로직)은 매 작업 완료 시 `memo.md`에 기록하고, 다음 세션 시작 시 AI가 먼저 이 문서를 참조하도록 프롬프트합니다.

---

## 📌 5. 신규 MES 프로젝트 개발 체크리스트 (Checklist for New MES Apps)

새로운 MES 앱을 만들 때 개발 착수 전/후에 아래 체크리스트를 반드시 확인합니다.

- [ ] **DB 테이블 설계**: `company`, `item`, `bom_master`, `work_order`, `shipment`, `sales_order` 기본 구조 상호 참조 확인
- [ ] **하드코딩 검증**: UI HTML 및 JS 코드에 고정 텍스트(회사명, 제품명, 상태 텍스트 등)가 남아있지 않은지 수색
- [ ] **상태값 통일**: `WAIT` / `IN_PROGRESS` / `DONE` / `PENDING` / `SHIPPED` / `COMPLETED` 상태 플로우 일치
- [ ] **날짜 검증**: 오늘 이후 날짜의 완료 처리 금지, 대기/완료 라벨 분리
- [ ] **실서버 CI/CD 연동**: `.github/workflows/deploy.yml` 및 `migrate.php` 연동 확인
- [ ] **AI 메모리 갱신**: `memo.md` 및 `MES_DEVELOPMENT_GUIDE.md` 최신화

---

> **기록자**: Antigravity AI Assistant  
> **최종 수정일**: 2026-08-26  
> **프로젝트**: Next-MES (Smart Factory Manufacturing Execution System)
