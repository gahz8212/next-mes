SET NAMES utf8mb4;

-- =========================================================================
-- Unified SMT MES Database Schema
-- Includes all core tables, relationships, and default seed data
-- =========================================================================

-- 1. 회사/거래처 마스터
CREATE TABLE IF NOT EXISTS company (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE COMMENT '거래처명',
    code VARCHAR(10) NOT NULL COMMENT '거래처 코드',
    biz_no VARCHAR(30) DEFAULT NULL COMMENT '사업자등록번호',
    ceo_name VARCHAR(50) DEFAULT NULL COMMENT '대표자명',
    type VARCHAR(30) DEFAULT 'CUSTOMER' COMMENT '구분(CUSTOMER/SUPPLIER/BOTH)',
    tel VARCHAR(30) DEFAULT NULL COMMENT '전화번호',
    email VARCHAR(100) DEFAULT NULL COMMENT '이메일',
    manager_name VARCHAR(50) DEFAULT NULL COMMENT '담당자명',
    manager_dept VARCHAR(50) DEFAULT NULL COMMENT '담당자 부서',
    manager_phone VARCHAR(30) DEFAULT NULL COMMENT '담당자 연락처',
    manager_email VARCHAR(100) DEFAULT NULL COMMENT '담당자 이메일',
    address VARCHAR(255) DEFAULT NULL COMMENT '주소',
    memo TEXT DEFAULT NULL COMMENT '메모',
    bom_mapping TEXT DEFAULT NULL COMMENT 'BOM 컬럼 매핑 정보 (JSON 문자열)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 품목 마스터
CREATE TABLE IF NOT EXISTS item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT DEFAULT NULL COMMENT '고객사/거래처 ID',
    item_code VARCHAR(50) DEFAULT NULL UNIQUE COMMENT '품목 코드',
    item_name VARCHAR(100) NOT NULL COMMENT '품목명',
    category VARCHAR(50) DEFAULT NULL COMMENT '카테고리 (예: PCB, 기구물 등)',
    unit VARCHAR(20) DEFAULT 'EA' COMMENT '단위 (EA, SET, m 등)',
    unit_price DECIMAL(12,2) DEFAULT 0.00 COMMENT '기본 단가',
    status VARCHAR(20) DEFAULT 'ACTIVE' COMMENT '상태 (ACTIVE, INACTIVE)',
    description TEXT DEFAULT NULL COMMENT '설명/비고',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 제품 마스터
CREATE TABLE IF NOT EXISTS product_master (
    product_id VARCHAR(50) PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. BOM 마스터
CREATE TABLE IF NOT EXISTS bom_master (
    bom_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) NOT NULL,
    item_id INT DEFAULT NULL COMMENT '연결 품목 ID',
    version VARCHAR(20) DEFAULT 'v1.0' COMMENT 'BOM 버전',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bom_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. BOM 상세
CREATE TABLE IF NOT EXISTS bom_detail (
    detail_id INT AUTO_INCREMENT PRIMARY KEY,
    bom_id INT NOT NULL,
    part_no VARCHAR(50) NOT NULL,
    part_name VARCHAR(255) DEFAULT NULL,
    req_qty DECIMAL(10,4) NOT NULL,
    points DECIMAL(10,4) DEFAULT '1.0000',
    provide_qty DECIMAL(12,2) DEFAULT NULL,
    location VARCHAR(100) DEFAULT NULL,
    feeder_slot VARCHAR(50) DEFAULT NULL,
    is_nc TINYINT(1) DEFAULT 0,
    INDEX idx_bd_bom (bom_id),
    INDEX idx_bd_part (part_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 작업지시
CREATE TABLE IF NOT EXISTS work_order (
    wo_id VARCHAR(50) PRIMARY KEY,
    bom_id INT DEFAULT NULL,
    company_id INT DEFAULT NULL,
    target_qty INT NOT NULL,
    status ENUM('READY', 'IN_PROGRESS', 'SMT_DONE', 'DIP_IN_PROGRESS', 'DONE', 'HOLD') DEFAULT 'READY',
    due_date DATE DEFAULT NULL COMMENT '납기일',
    completed_at DATETIME DEFAULT NULL COMMENT '완료 일시',
    delivery_date DATE DEFAULT NULL COMMENT '납품(출하) 예정일',
    shipped TINYINT(1) NOT NULL DEFAULT 0 COMMENT '납품 여부',
    shipped_at DATETIME DEFAULT NULL COMMENT '납품 일시',
    remark VARCHAR(255) DEFAULT NULL COMMENT '비고',
    parent_wo_id VARCHAR(50) DEFAULT NULL COMMENT '분할 작업지시 원본 ID',
    INDEX idx_wo_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 라인 가동 상태
CREATE TABLE IF NOT EXISTS line_status (
    line_id VARCHAR(50) PRIMARY KEY,
    current_wo_id VARCHAR(50) NULL,
    status ENUM('IDLE', 'RUN', 'DOWN') DEFAULT 'IDLE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 바코드 마스터
CREATE TABLE IF NOT EXISTS barcode_master (
    barcode VARCHAR(100) PRIMARY KEY,
    wo_id VARCHAR(50) NOT NULL,
    status VARCHAR(50) DEFAULT 'WAIT',
    INDEX idx_bm_wo (wo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 바코드 이력
CREATE TABLE IF NOT EXISTS barcode_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(100) NOT NULL,
    process_name VARCHAR(50) NOT NULL,
    result_status ENUM('PASS', 'FAIL') NOT NULL,
    process_data JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bh_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 릴 자재 마스터 (MSL 추적)
CREATE TABLE IF NOT EXISTS reel_master (
    reel_barcode VARCHAR(100) PRIMARY KEY,
    part_no VARCHAR(50) NOT NULL,
    msl_level INT DEFAULT 1,
    floor_life_hours INT DEFAULT 0,
    unsealed_at DATETIME NULL,
    status ENUM('READY', 'IN_USE', 'EXPIRED', 'EMPTY') DEFAULT 'READY',
    INDEX idx_reel_part (part_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. 피더 셋업 검증 상태
CREATE TABLE IF NOT EXISTS feeder_setup (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wo_id VARCHAR(50) NOT NULL,
    slot_no VARCHAR(50) NOT NULL,
    part_no VARCHAR(50) NOT NULL,
    location VARCHAR(100) DEFAULT NULL,
    req_qty DECIMAL(10,4) NOT NULL,
    status ENUM('PENDING', 'VERIFIED', 'MISMATCH') DEFAULT 'PENDING',
    reel_barcode VARCHAR(100) DEFAULT NULL,
    scanned_at DATETIME DEFAULT NULL,
    scanned_by VARCHAR(50) DEFAULT 'Worker',
    UNIQUE KEY uk_wo_slot (wo_id, slot_no),
    INDEX idx_fs_wo (wo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. 자재 입출고
CREATE TABLE IF NOT EXISTS material_inout (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_no VARCHAR(50) NOT NULL COMMENT '자재 파트번호',
    part_name VARCHAR(100) DEFAULT NULL COMMENT '자재명',
    inout_type ENUM('IN','OUT') NOT NULL COMMENT '입고/출고',
    supply_type ENUM('CONSIGNED', 'PROCURED') NOT NULL DEFAULT 'PROCURED' COMMENT '사급(CONSIGNED)/도급(PROCURED) 구분',
    qty DECIMAL(10,2) NOT NULL COMMENT '수량',
    unit VARCHAR(20) DEFAULT 'EA' COMMENT '단위',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연결 작업지시',
    company_id INT DEFAULT NULL COMMENT '공급처/고객사 ID',
    order_no VARCHAR(50) DEFAULT NULL COMMENT '연결 수주 번호',
    bom_id INT DEFAULT NULL COMMENT '연결 BOM ID',
    note TEXT DEFAULT NULL COMMENT '비고',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mat_part (part_no),
    INDEX idx_mat_company (company_id),
    INDEX idx_mat_order (order_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. 부품 교차 참조 (AVL / 대체 부품 맵핑)
CREATE TABLE IF NOT EXISTS part_alias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT DEFAULT NULL COMMENT '거래처/고객사 ID',
    standard_name VARCHAR(100) NOT NULL COMMENT '대표 파트명/표준 규격 (예: 칩저항 1005 10K 1%)',
    standard_code VARCHAR(50) DEFAULT NULL COMMENT '대표 표준 코드 (예: R1005-10K-F)',
    alias_part_no VARCHAR(100) NOT NULL COMMENT '실제 제조사/고객사 파트번호 (예: RC1005F103CS)',
    vendor_name VARCHAR(100) DEFAULT NULL COMMENT '제조사/공급처 (예: 삼성전기, YAGEO, LG전자)',
    description VARCHAR(255) DEFAULT NULL COMMENT '부품 사양/규격 메모',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_standard_name (standard_name),
    INDEX idx_alias_part_no (alias_part_no),
    INDEX idx_pa_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. 출하 관리
CREATE TABLE IF NOT EXISTS shipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wo_id VARCHAR(50) NOT NULL COMMENT '작업지시',
    ship_qty INT NOT NULL COMMENT '출하 수량',
    ship_date DATE NOT NULL COMMENT '출하일',
    company_id INT DEFAULT NULL COMMENT '거래처',
    invoice_no VARCHAR(50) DEFAULT NULL COMMENT '거래명세서 번호',
    note TEXT DEFAULT NULL COMMENT '비고',
    status ENUM('PENDING','SHIPPED','CANCELLED') DEFAULT 'PENDING' COMMENT '출하상태',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ship_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. 사용자 계정
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT '로그인 ID',
    password_hash VARCHAR(255) NOT NULL COMMENT '비밀번호 해시(SHA256)',
    name VARCHAR(50) NOT NULL COMMENT '이름',
    role ENUM('admin','manager','worker') DEFAULT 'worker' COMMENT '역할',
    department VARCHAR(50) DEFAULT NULL COMMENT '부서',
    is_active TINYINT(1) DEFAULT 1 COMMENT '활성 여부',
    last_login DATETIME DEFAULT NULL COMMENT '마지막 로그인 일시',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. 품질 검사 기준
CREATE TABLE IF NOT EXISTS quality_standard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    process_name VARCHAR(50) NOT NULL COMMENT '공정명',
    check_item VARCHAR(100) NOT NULL COMMENT '검사 항목',
    standard_value VARCHAR(100) DEFAULT NULL COMMENT '기준값',
    unit VARCHAR(20) DEFAULT NULL COMMENT '단위',
    is_active TINYINT(1) DEFAULT 1 COMMENT '활성 여부',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. 수주 관리 (마스터)
CREATE TABLE IF NOT EXISTS sales_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(50) NOT NULL UNIQUE COMMENT '수주 번호 / 고객 발주번호 (예: PO-20260818-001)',
    company_id INT NOT NULL COMMENT '발주 고객사 ID',
    item_code VARCHAR(50) DEFAULT NULL COMMENT '대표 품목 코드',
    item_name VARCHAR(100) DEFAULT NULL COMMENT '대표 품목명',
    order_qty INT NOT NULL DEFAULT 0 COMMENT '총 수주 수량',
    unit_price DECIMAL(12,2) DEFAULT 0.00 COMMENT '단가',
    total_price DECIMAL(14,2) DEFAULT 0.00 COMMENT '총 수주액',
    order_date DATE NOT NULL COMMENT '수주일자',
    due_date DATE NOT NULL COMMENT '기본 납기요청일',
    status ENUM('RECEIVED', 'IN_PRODUCTION', 'COMPLETED', 'CANCELLED') DEFAULT 'RECEIVED' COMMENT '수주 상태',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연계 발행된 작업지시(WO) ID',
    memo TEXT DEFAULT NULL COMMENT '수주 특이사항 / 메모',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_so_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17-1. 수주 품목 상세 (1:N 라인 아이템)
CREATE TABLE IF NOT EXISTS sales_order_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL COMMENT 'sales_order.id 참조',
    order_no VARCHAR(50) NOT NULL COMMENT '수주 번호',
    item_code VARCHAR(50) DEFAULT NULL COMMENT '품목 코드',
    item_name VARCHAR(100) NOT NULL COMMENT '품목명 (제품명)',
    order_qty INT NOT NULL DEFAULT 1 COMMENT '수주 수량',
    unit_price DECIMAL(12,2) DEFAULT 0.00 COMMENT '품목 단가',
    total_price DECIMAL(14,2) DEFAULT 0.00 COMMENT '공급가액',
    due_date DATE DEFAULT NULL COMMENT '품목별 납기일',
    status ENUM('RECEIVED', 'IN_PRODUCTION', 'COMPLETED', 'CANCELLED') DEFAULT 'RECEIVED' COMMENT '상태',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연계 발행된 작업지시(WO) ID',
    memo TEXT DEFAULT NULL COMMENT '품목 특이사항',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_soi_order_id (order_id),
    INDEX idx_soi_order_no (order_no),
    INDEX idx_soi_wo_id (wo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. 사급 자재 정산/반납 마스터
CREATE TABLE IF NOT EXISTS consigned_return_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_no VARCHAR(50) NOT NULL UNIQUE COMMENT '반납 전표 번호 (RET-YYYYMMDD-XXX)',
    order_no VARCHAR(50) DEFAULT NULL COMMENT '연결 수주 번호',
    company_id INT DEFAULT NULL COMMENT '고객사 ID',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연결 작업지시 ID',
    item_name VARCHAR(100) DEFAULT NULL COMMENT '제품명',
    shipped_qty INT DEFAULT 0 COMMENT '완제품 출하수량',
    return_date DATE NOT NULL COMMENT '반납일자',
    status ENUM('DRAFT','COMPLETED','CANCELLED') DEFAULT 'COMPLETED' COMMENT '상태',
    receiver_name VARCHAR(50) DEFAULT NULL COMMENT '인수자명',
    memo TEXT DEFAULT NULL COMMENT '비고',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_order (order_no),
    INDEX idx_crm_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18-1. 사급 자재 정산/반납 상세
CREATE TABLE IF NOT EXISTS consigned_return_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL COMMENT 'consigned_return_master.id 참조',
    part_no VARCHAR(50) NOT NULL COMMENT '자재 파트번호',
    part_name VARCHAR(100) DEFAULT NULL COMMENT '자재명',
    req_qty_per_unit DECIMAL(10,4) DEFAULT 0.0000 COMMENT '단위당 소요량',
    supplied_qty DECIMAL(10,2) DEFAULT 0.00 COMMENT '입고 수량',
    used_qty DECIMAL(10,2) DEFAULT 0.00 COMMENT '실제 사용량',
    expected_return_qty DECIMAL(10,2) DEFAULT 0.00 COMMENT '정산 잔여수량',
    actual_return_qty DECIMAL(10,2) DEFAULT 0.00 COMMENT '실제 반납수량',
    remark VARCHAR(255) DEFAULT NULL COMMENT '특이사항',
    INDEX idx_crd_return (return_id),
    INDEX idx_crd_part (part_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. 시스템 알림 센터
CREATE TABLE IF NOT EXISTS system_notification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('INFO', 'WARNING', 'DANGER', 'SUCCESS') DEFAULT 'INFO' COMMENT '알림 등급',
    title VARCHAR(100) NOT NULL COMMENT '알림 제목',
    message TEXT NOT NULL COMMENT '알림 내용',
    is_read TINYINT(1) DEFAULT 0 COMMENT '읽음 여부 (0:안읽음, 1:읽음)',
    link_url VARCHAR(255) DEFAULT NULL COMMENT '클릭 시 이동할 내부 링크 / 페이지',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. 시스템 활동 로그
CREATE TABLE IF NOT EXISTS system_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) DEFAULT 'system' COMMENT '작업 사용자 ID',
    action_type VARCHAR(50) NOT NULL COMMENT '작업 유형',
    description TEXT NOT NULL COMMENT '상세 작업 내용',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT '접속 IP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- Seed Data Initialization
-- =========================================================================

-- 기본 계정 (비밀번호: admin, worker)
INSERT IGNORE INTO users (username, password_hash, name, role) VALUES 
('admin', SHA2('admin', 256), '관리자', 'admin'),
('worker', SHA2('worker', 256), '작업자', 'worker');

-- 기본 거래처 (고객사 및 자재공급사)
INSERT IGNORE INTO company (id, name, code, type, tel, email, memo) VALUES 
(1, '에이텍 솔루션', 'ATE', 'CUSTOMER', '02-123-4567', 'sales@atech.com', '주요 SMT 외주 거래처'),
(2, '한성 전자', 'HSE', 'SUPPLIER', '031-998-1122', 'info@hansung.co.kr', 'BOM PCB 기판 및 부품 공급업체');

-- 기본 품목 마스터
INSERT IGNORE INTO item (company_id, item_code, item_name, category, unit, unit_price, status, description) VALUES
(1, 'PCB-MAIN-A', 'Main Board A타입', 'PCB', 'EA', 15000.00, 'ACTIVE', 'SMT 기본 탑재 메인보드'),
(1, 'PCB-SUB-B', 'Sub Board B타입', 'PCB', 'EA', 8500.00, 'ACTIVE', 'DIP 수삽용 서브보드'),
(2, 'ASM-PRO-A', 'Smart MES Controller 어셈블리', '완성품', 'SET', 45000.00, 'ACTIVE', '최종 완성 보드 컨트롤러');

-- 기본 라인 상태 등록
INSERT IGNORE INTO line_status (line_id, current_wo_id, status) VALUES
('LINE_01', NULL, 'IDLE'),
('LINE_02', NULL, 'IDLE');

-- 테스트용 릴 바코드 자재 등록
INSERT IGNORE INTO reel_master (reel_barcode, part_no, msl_level, floor_life_hours, status) VALUES 
('REEL-U2-MT29F', 'MT29F2G08ABAGAH4-IT:G', 3, 168, 'READY'),
('REEL-U16-LIS3', 'LIS3MDL', 1, 0, 'READY'),
('REEL-U15-BMA2', 'BMA250E', 1, 0, 'READY'),
('REEL-U36-XC61', 'XC61FC2512MR', 1, 0, 'READY'),
('REEL-U12-BMC2', 'BMC-2703', 1, 0, 'READY'),
('REEL-U11-MAX1', 'MAX1554ETA', 2, 720, 'READY'),
('REEL-U31-SY80', 'SY8008CAAC', 1, 0, 'READY');

-- 초기 알림 데이터
INSERT IGNORE INTO system_notification (type, title, message, is_read, link_url) VALUES 
('INFO', '🚀 Smart MES 초기 실행', 'SMT/DIP 생산라인 관제 시스템이 정상 기동되었습니다.', 0, 'kpi');

-- 초기 로그
INSERT IGNORE INTO system_log (username, action_type, description) VALUES
('system', 'SYSTEM_INIT', 'Smart MES 통합 데이터베이스가 성공적으로 초기화되었습니다.');
