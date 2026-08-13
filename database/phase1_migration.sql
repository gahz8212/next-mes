-- =============================================
-- Phase 1 DB Migration: 거래처, 품목, 생산계획 확장
-- 실행: MySQL CLI 또는 phpMyAdmin에서 실행
-- =============================================

-- 1. company 테이블에 컬럼 추가 (이미 있으면 IGNORE됨)
ALTER TABLE company 
    ADD COLUMN IF NOT EXISTS tel VARCHAR(30) DEFAULT NULL COMMENT '전화번호',
    ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT NULL COMMENT '이메일',
    ADD COLUMN IF NOT EXISTS memo TEXT DEFAULT NULL COMMENT '메모',
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- 2. 품목 마스터 테이블 생성
CREATE TABLE IF NOT EXISTS item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL UNIQUE COMMENT '품목 코드',
    item_name VARCHAR(100) NOT NULL COMMENT '품목명',
    category VARCHAR(50) DEFAULT NULL COMMENT '카테고리 (예: PCB, 기구물 등)',
    unit VARCHAR(20) DEFAULT 'EA' COMMENT '단위 (EA, SET, m 등)',
    description TEXT DEFAULT NULL COMMENT '설명/비고',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. work_order 테이블에 due_date 컬럼이 없으면 추가 (기존에 있으면 무시)
ALTER TABLE work_order
    ADD COLUMN IF NOT EXISTS due_date DATE DEFAULT NULL COMMENT '납기일';

-- 4. barcode_master에 FAIL 상태 추가 (ENUM 확장)
-- 기존 ENUM에 FAIL이 없는 경우
ALTER TABLE barcode_master 
    MODIFY COLUMN status ENUM('WAIT','IN_PROCESS','TOP_DONE','BOTTOM_DONE','TEST_PASS','TEST_FAIL','SHIPPING','FAIL','SCRAPPED') DEFAULT 'WAIT';

-- 5. 샘플 데이터 (선택사항 - 테스트용)
-- INSERT IGNORE INTO item (item_code, item_name, category, unit) VALUES
--     ('PCB-001', 'Main Board A타입', 'PCB', 'EA'),
--     ('PCB-002', 'Sub Board B타입', 'PCB', 'EA'),
--     ('ASM-001', '완성품 어셈블리', '완성품', 'SET');
