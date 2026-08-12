-- 1. 기준정보 및 생산관리
CREATE TABLE product_master (
product_id VARCHAR(50) PRIMARY KEY,
product_name VARCHAR(100) NOT NULL
);
CREATE TABLE bom_master (
bom_id INT AUTO_INCREMENT PRIMARY KEY,
product_id VARCHAR(50) NOT NULL
);
CREATE TABLE bom_detail (
detail_id INT AUTO_INCREMENT PRIMARY KEY,
bom_id INT NOT NULL,
part_no VARCHAR(50) NOT NULL,
req_qty DECIMAL(10,4) NOT NULL
);
CREATE TABLE work_order (
wo_id VARCHAR(50) PRIMARY KEY,
bom_id INT NOT NULL,
target_qty INT NOT NULL,
status ENUM('READY', 'IN_PROGRESS', 'DONE') DEFAULT 'READY'
);
CREATE TABLE line_status (
line_id VARCHAR(50) PRIMARY KEY,
current_wo_id VARCHAR(50) NULL,
status ENUM('IDLE', 'RUN', 'DOWN') DEFAULT 'IDLE'
);
CREATE TABLE barcode_master (
barcode VARCHAR(100) PRIMARY KEY,
wo_id VARCHAR(50) NOT NULL,
status ENUM('WAIT', 'IN_PROCESS', 'TOP_DONE', 'BOTTOM_DONE', 'TEST_PASS', 'TEST_FAIL', 'SHIPPING', 'SCRAPPED') DEFAULT 'WAIT'
);
CREATE TABLE barcode_history (
history_id INT AUTO_INCREMENT PRIMARY KEY,
barcode VARCHAR(100) NOT NULL,
process_name VARCHAR(50) NOT NULL,
result_status ENUM('PASS', 'FAIL') NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. 자재 관리 (MSL 추적)
CREATE TABLE reel_master (
    reel_barcode VARCHAR(100) PRIMARY KEY,
    part_no VARCHAR(50) NOT NULL,
    msl_level INT DEFAULT 1,
    floor_life_hours INT DEFAULT 0,
    unsealed_at DATETIME NULL,
    status ENUM('READY', 'IN_USE', 'EXPIRED', 'EMPTY') DEFAULT 'READY'
);
