<?php
// backend/migrate.php - Automatic DB Migration Runner

require_once __DIR__ . '/core/Database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn() ?: 'unknown';

    $applied = [];
    $skipped = [];

    // Helper to directly add column with try/catch for error 1060 (Duplicate column)
    $addColumnDirect = function(string $table, string $column, string $definition) use ($pdo, &$applied, &$skipped) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            $applied[] = "Added column `{$table}`.`{$column}`";
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column') || str_contains($msg, '1060')) {
                $skipped[] = "Column `{$table}`.`{$column}` already exists";
            } else {
                throw $e;
            }
        }
    };

    // 1. item 테이블 컬럼
    $addColumnDirect('item', 'company_id', 'INT DEFAULT NULL COMMENT "고객사/거래처 ID"');
    $addColumnDirect('item', 'unit_price', 'DECIMAL(12,2) DEFAULT 0.00 COMMENT "기본 단가"');
    $addColumnDirect('item', 'status', 'VARCHAR(20) DEFAULT "ACTIVE" COMMENT "상태"');

    // 2. part_alias 테이블 컬럼
    $addColumnDirect('part_alias', 'company_id', 'INT DEFAULT NULL COMMENT "거래처/고객사 ID"');

    // 3. work_order 테이블 컬럼
    $addColumnDirect('work_order', 'company_id', 'INT DEFAULT NULL');
    $addColumnDirect('work_order', 'due_date', 'DATE DEFAULT NULL COMMENT "납기일"');
    $addColumnDirect('work_order', 'completed_at', 'DATETIME DEFAULT NULL');
    $addColumnDirect('work_order', 'delivery_date', 'DATE DEFAULT NULL');
    $addColumnDirect('work_order', 'shipped', 'TINYINT(1) NOT NULL DEFAULT 0');
    $addColumnDirect('work_order', 'shipped_at', 'DATETIME DEFAULT NULL');
    $addColumnDirect('work_order', 'remark', 'VARCHAR(255) DEFAULT NULL');
    $addColumnDirect('work_order', 'parent_wo_id', 'VARCHAR(50) DEFAULT NULL');

    // 4. material_inout 테이블 컬럼
    $addColumnDirect('material_inout', 'company_id', 'INT DEFAULT NULL COMMENT "공급처/고객사 ID"');
    $addColumnDirect('material_inout', 'supply_type', 'ENUM("CONSIGNED", "PROCURED") NOT NULL DEFAULT "PROCURED" COMMENT "사급/도급 구분"');
    $addColumnDirect('material_inout', 'order_no', 'VARCHAR(50) DEFAULT NULL COMMENT "연결 수주 번호"');
    $addColumnDirect('material_inout', 'bom_id', 'INT DEFAULT NULL COMMENT "연결 BOM ID"');

    // 5. shipment 테이블 컬럼
    $addColumnDirect('shipment', 'company_id', 'INT DEFAULT NULL');
    $addColumnDirect('shipment', 'invoice_no', 'VARCHAR(50) DEFAULT NULL');

    // 6. company 테이블 컬럼
    $addColumnDirect('company', 'biz_no', 'VARCHAR(30) DEFAULT NULL COMMENT "사업자등록번호"');
    $addColumnDirect('company', 'ceo_name', 'VARCHAR(50) DEFAULT NULL COMMENT "대표자명"');
    $addColumnDirect('company', 'type', 'VARCHAR(30) DEFAULT "CUSTOMER" COMMENT "구분"');
    $addColumnDirect('company', 'tel', 'VARCHAR(30) DEFAULT NULL COMMENT "전화번호"');
    $addColumnDirect('company', 'email', 'VARCHAR(100) DEFAULT NULL COMMENT "이메일"');
    $addColumnDirect('company', 'manager_name', 'VARCHAR(50) DEFAULT NULL COMMENT "담당자명"');
    $addColumnDirect('company', 'manager_dept', 'VARCHAR(50) DEFAULT NULL COMMENT "담당자 부서"');
    $addColumnDirect('company', 'manager_phone', 'VARCHAR(30) DEFAULT NULL COMMENT "담당자 연락처"');
    $addColumnDirect('company', 'manager_email', 'VARCHAR(100) DEFAULT NULL COMMENT "담당자 이메일"');
    $addColumnDirect('company', 'address', 'VARCHAR(255) DEFAULT NULL COMMENT "주소"');
    $addColumnDirect('company', 'bom_mapping', 'TEXT DEFAULT NULL COMMENT "BOM 컬럼 매핑 JSON"');

    // 7. bom_master 및 item 테이블 컬럼
    $addColumnDirect('bom_master', 'item_id', 'INT DEFAULT NULL COMMENT "연결 품목 ID"');
    $addColumnDirect('bom_master', 'version', 'VARCHAR(20) DEFAULT "v1.0" COMMENT "BOM 버전"');
    $addColumnDirect('bom_master', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT "생성일시"');
    $addColumnDirect('item', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT "생성일시"');

    // 8. barcode_history 및 feeder_setup, barcode_master 테이블 컬럼
    $addColumnDirect('barcode_history', 'process_data', 'JSON DEFAULT NULL COMMENT "설비 예지보전(PdM) 텔레메트리 데이터"');
    try {
        $pdo->exec("ALTER TABLE `barcode_history` MODIFY COLUMN `result_status` VARCHAR(50) NOT NULL DEFAULT 'PASS'");
        $applied[] = "Modified column `barcode_history`.`result_status` to VARCHAR(50)";
    } catch (\Throwable $e) {
        $skipped[] = "result_status modify: " . $e->getMessage();
    }
    $addColumnDirect('feeder_setup', 'points', 'DECIMAL(10,4) DEFAULT NULL');
    $addColumnDirect('feeder_setup', 'scanned_by', 'VARCHAR(50) DEFAULT "Worker"');
    $addColumnDirect('barcode_master', 'status', 'VARCHAR(50) DEFAULT "WAIT"');

    // 출하 완료(SHIPPED) 상태 데이터 중 출하완료일이 미래 날짜로 기록된 기존 DB 데이터 자동 보정
    try {
        $pdo->exec("UPDATE shipment SET ship_date = CURDATE() WHERE status = 'SHIPPED' AND ship_date > CURDATE()");
        $applied[] = "Corrected future ship_date for SHIPPED records to CURDATE()";
    } catch (\Throwable $e) {
        $skipped[] = "Fix shipment ship_date: " . $e->getMessage();
    }

    // 9. 신규 테이블 생성: consigned_return_master
    $pdo->exec("
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
    ");

    // 9. 신규 테이블 생성: consigned_return_detail
    $pdo->exec("
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
    ");

    echo json_encode([
        "status"          => "success",
        "database"        => $currentDb,
        "message"         => "Database migration completed successfully.",
        "applied_changes" => $applied,
        "skipped_count"   => count($skipped)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Migration failed: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
