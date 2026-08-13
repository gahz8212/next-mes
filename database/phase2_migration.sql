-- =============================================
-- Phase 2 DB Migration: 자재 입출고, 출하 관리, 사용자 관리, 품질 검사 기준
-- =============================================

-- 1. 자재 입출고 관리 테이블
CREATE TABLE IF NOT EXISTS material_inout (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_no VARCHAR(50) NOT NULL COMMENT '자재 파트번호',
    part_name VARCHAR(100) DEFAULT NULL COMMENT '자재명',
    inout_type ENUM('IN','OUT') NOT NULL COMMENT '입고/출고',
    qty DECIMAL(10,2) NOT NULL COMMENT '수량',
    unit VARCHAR(20) DEFAULT 'EA' COMMENT '단위',
    wo_id VARCHAR(50) DEFAULT NULL COMMENT '연결 작업지시',
    company_id INT DEFAULT NULL COMMENT '공급처',
    note TEXT DEFAULT NULL COMMENT '비고',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 출하 지시 및 관리 테이블
CREATE TABLE IF NOT EXISTS shipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wo_id VARCHAR(50) NOT NULL COMMENT '작업지시',
    ship_qty INT NOT NULL COMMENT '출하 수량',
    ship_date DATE NOT NULL COMMENT '출하일',
    company_id INT DEFAULT NULL COMMENT '거래처',
    invoice_no VARCHAR(50) DEFAULT NULL COMMENT '거래명세서 번호',
    note TEXT DEFAULT NULL COMMENT '비고',
    status ENUM('PENDING','SHIPPED','CANCELLED') DEFAULT 'PENDING' COMMENT '출하상태',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 사용자 및 권한 관리 테이블
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

-- 4. 품질 검사 기준 테이블
CREATE TABLE IF NOT EXISTS quality_standard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    process_name VARCHAR(50) NOT NULL COMMENT '공정명',
    check_item VARCHAR(100) NOT NULL COMMENT '검사 항목',
    standard_value VARCHAR(100) DEFAULT NULL COMMENT '기준값',
    unit VARCHAR(20) DEFAULT NULL COMMENT '단위',
    is_active TINYINT(1) DEFAULT 1 COMMENT '활성 여부',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기본 계정 초기화 (admin / admin, worker / worker)
INSERT IGNORE INTO users (username, password_hash, name, role) 
VALUES ('admin', SHA2('admin', 256), '관리자', 'admin');
INSERT IGNORE INTO users (username, password_hash, name, role)
VALUES ('worker', SHA2('worker', 256), '작업자', 'worker');
