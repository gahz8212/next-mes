-- =========================================================================
-- Fix Missing Columns Migration Script for MySQL 8.0 / 5.7 / MariaDB
-- Safely adds missing columns and tables without duplicate column errors
-- =========================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS AddColumnIfNotExists$$
CREATE PROCEDURE AddColumnIfNotExists(
    IN target_table VARCHAR(64),
    IN target_column VARCHAR(64),
    IN column_definition VARCHAR(255)
)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = target_table
      AND COLUMN_NAME = target_column;

    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', target_table, '` ADD COLUMN `', target_column, '` ', column_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- 1. item 테이블 컬럼 보강
CALL AddColumnIfNotExists('item', 'company_id', 'INT DEFAULT NULL COMMENT ''고객사/거래처 ID''');
CALL AddColumnIfNotExists('item', 'unit_price', 'DECIMAL(12,2) DEFAULT 0.00 COMMENT ''기본 단가''');
CALL AddColumnIfNotExists('item', 'status', 'VARCHAR(20) DEFAULT ''ACTIVE'' COMMENT ''상태''');

-- 2. part_alias 테이블 컬럼 보강
CALL AddColumnIfNotExists('part_alias', 'company_id', 'INT DEFAULT NULL COMMENT ''거래처/고객사 ID''');

-- 3. work_order 테이블 컬럼 보강
CALL AddColumnIfNotExists('work_order', 'company_id', 'INT DEFAULT NULL');
CALL AddColumnIfNotExists('work_order', 'due_date', 'DATE DEFAULT NULL COMMENT ''납기일''');
CALL AddColumnIfNotExists('work_order', 'completed_at', 'DATETIME DEFAULT NULL');
CALL AddColumnIfNotExists('work_order', 'delivery_date', 'DATE DEFAULT NULL');
CALL AddColumnIfNotExists('work_order', 'shipped', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL AddColumnIfNotExists('work_order', 'shipped_at', 'DATETIME DEFAULT NULL');
CALL AddColumnIfNotExists('work_order', 'remark', 'VARCHAR(255) DEFAULT NULL');
CALL AddColumnIfNotExists('work_order', 'parent_wo_id', 'VARCHAR(50) DEFAULT NULL');

-- 4. material_inout 테이블 컬럼 보강
CALL AddColumnIfNotExists('material_inout', 'company_id', 'INT DEFAULT NULL COMMENT ''공급처/고객사 ID''');
CALL AddColumnIfNotExists('material_inout', 'supply_type', 'ENUM(''CONSIGNED'', ''PROCURED'') NOT NULL DEFAULT ''PROCURED'' COMMENT ''사급/도급 구분''');
CALL AddColumnIfNotExists('material_inout', 'order_no', 'VARCHAR(50) DEFAULT NULL COMMENT ''연결 수주 번호''');
CALL AddColumnIfNotExists('material_inout', 'bom_id', 'INT DEFAULT NULL COMMENT ''연결 BOM ID''');

-- 5. shipment 테이블 컬럼 보강
CALL AddColumnIfNotExists('shipment', 'company_id', 'INT DEFAULT NULL');
CALL AddColumnIfNotExists('shipment', 'invoice_no', 'VARCHAR(50) DEFAULT NULL');

-- 6. company 테이블 컬럼 보강
CALL AddColumnIfNotExists('company', 'biz_no', 'VARCHAR(30) DEFAULT NULL COMMENT ''사업자등록번호''');
CALL AddColumnIfNotExists('company', 'ceo_name', 'VARCHAR(50) DEFAULT NULL COMMENT ''대표자명''');
CALL AddColumnIfNotExists('company', 'type', 'VARCHAR(30) DEFAULT ''CUSTOMER'' COMMENT ''구분''');
CALL AddColumnIfNotExists('company', 'tel', 'VARCHAR(30) DEFAULT NULL COMMENT ''전화번호''');
CALL AddColumnIfNotExists('company', 'email', 'VARCHAR(100) DEFAULT NULL COMMENT ''이메일''');
CALL AddColumnIfNotExists('company', 'manager_name', 'VARCHAR(50) DEFAULT NULL COMMENT ''담당자명''');
CALL AddColumnIfNotExists('company', 'manager_dept', 'VARCHAR(50) DEFAULT NULL COMMENT ''담당자 부서''');
CALL AddColumnIfNotExists('company', 'manager_phone', 'VARCHAR(30) DEFAULT NULL COMMENT ''담당자 연락처''');
CALL AddColumnIfNotExists('company', 'manager_email', 'VARCHAR(100) DEFAULT NULL COMMENT ''담당자 이메일''');
CALL AddColumnIfNotExists('company', 'address', 'VARCHAR(255) DEFAULT NULL COMMENT ''주소''');
CALL AddColumnIfNotExists('company', 'bom_mapping', 'TEXT DEFAULT NULL COMMENT ''BOM 컬럼 매핑 JSON''');

-- 7. bom_master 테이블 컬럼 보강
CALL AddColumnIfNotExists('bom_master', 'item_id', 'INT DEFAULT NULL COMMENT ''연결 품목 ID''');
CALL AddColumnIfNotExists('bom_master', 'version', 'VARCHAR(20) DEFAULT ''v1.0'' COMMENT ''BOM 버전''');

DROP PROCEDURE IF EXISTS AddColumnIfNotExists;

-- 8. 사급 자재 정산/반납 마스터 테이블 생성 (없는 경우)
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

-- 9. 사급 자재 정산/반납 상세 테이블 생성 (없는 경우)
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
