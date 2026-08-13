-- =============================================
-- Phase 3 DB Migration: 수주 관리, KPI 분석 보조, 알림 및 시스템 로그
-- =============================================

-- 1. 수주(PO: Purchase/Sales Order) 관리 테이블
CREATE TABLE IF NOT EXISTS sales_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(50) NOT NULL UNIQUE COMMENT '수주 번호 (예: PO-20260814-001)',
    company_id INT NOT NULL COMMENT '발주 고객사 ID',
    item_code VARCHAR(50) DEFAULT NULL COMMENT '수주 품목 코드',
    item_name VARCHAR(100) DEFAULT NULL COMMENT '수주 품목명',
    order_qty INT NOT NULL COMMENT '수주 수량',
    unit_price DECIMAL(12,2) DEFAULT 0 COMMENT '단가',
    total_price DECIMAL(14,2) DEFAULT 0 COMMENT '총 수주액',
    order_date DATE NOT NULL COMMENT '수주일자',
    due_date DATE NOT NULL COMMENT '납기요청일',
    status ENUM('RECEIVED', 'IN_PRODUCTION', 'COMPLETED', 'CANCELLED') DEFAULT 'RECEIVED' COMMENT '수주 상태',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연계 발행된 작업지시(WO) ID',
    memo TEXT DEFAULT NULL COMMENT '수주 특이사항 / 메모',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 시스템 알림(Notification / Alert) 테이블
CREATE TABLE IF NOT EXISTS system_notification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('INFO', 'WARNING', 'DANGER', 'SUCCESS') DEFAULT 'INFO' COMMENT '알림 등급',
    title VARCHAR(100) NOT NULL COMMENT '알림 제목',
    message TEXT NOT NULL COMMENT '알림 내용',
    is_read TINYINT(1) DEFAULT 0 COMMENT '읽음 여부 (0:안읽음, 1:읽음)',
    link_url VARCHAR(255) DEFAULT NULL COMMENT '클릭 시 이동할 내부 링크 / 페이지',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. 시스템 활동 로그(Audit / Activity Log) 테이블
CREATE TABLE IF NOT EXISTS system_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) DEFAULT 'system' COMMENT '작업 사용자 ID',
    action_type VARCHAR(50) NOT NULL COMMENT '작업 유형 (WO_CREATE, SHIPMENT, STATUS_CHANGE 등)',
    description TEXT NOT NULL COMMENT '상세 작업 내용',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT '접속 IP',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. 초기 샘플 수주 및 알림 데이터 생성 (테스트용)
INSERT IGNORE INTO sales_order (order_no, company_id, item_code, item_name, order_qty, unit_price, total_price, order_date, due_date, status, memo)
SELECT 'PO-2026-001', id, 'PCB-MAIN-A', 'Main Board A타입', 1000, 15000, 15000000, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'RECEIVED', '긴급 납기 요청건'
FROM company LIMIT 1;

INSERT IGNORE INTO system_notification (type, title, message, is_read, link_url) VALUES 
('WARNING', '🚨 납기 임박 작업지시', '납기가 3일 이내로 남은 작업지시가 1건 있습니다.', 0, 'wo'),
('INFO', '📦 신규 수주 등록', 'PO-2026-001 신규 수주가 접수되었습니다. (1,000 EA)', 0, 'order'),
('SUCCESS', '✅ 일일 수율 목표 달성', '금일 평균 생산 수율이 98.5%를 기록하였습니다.', 1, 'kpi');

INSERT IGNORE INTO system_log (username, action_type, description) VALUES
('admin', 'SYSTEM_INIT', 'Phase 3 시스템 및 데이터베이스 모듈이 정상 활성화되었습니다.');
