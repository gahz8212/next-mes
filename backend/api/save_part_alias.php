<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

try {
    // 다건 일괄 등록 (배열 전달 시)
    if (isset($input['batch']) && is_array($input['batch'])) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO part_alias (standard_name, standard_code, alias_part_no, vendor_name, description) 
                                VALUES (?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                standard_name = VALUES(standard_name),
                                standard_code = VALUES(standard_code),
                                vendor_name = VALUES(vendor_name),
                                description = VALUES(description)");
        $count = 0;
        foreach ($input['batch'] as $row) {
            $stdName = trim($row['standard_name'] ?? '');
            $aliasPn = trim($row['alias_part_no'] ?? '');
            if (!empty($stdName) && !empty($aliasPn)) {
                $stdCode = trim($row['standard_code'] ?? '') ?: null;
                $vendor  = trim($row['vendor_name'] ?? '') ?: null;
                $desc    = trim($row['description'] ?? '') ?: null;
                $stmt->execute([$stdName, $stdCode, $aliasPn, $vendor, $desc]);
                $count++;
            }
        }
        $pdo->commit();
        echo json_encode(["status" => "success", "message" => "총 {$count}건의 부품 교차 매핑이 저장되었습니다.", "count" => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 단건 등록/수정
    $id            = !empty($input['id']) ? (int)$input['id'] : null;
    $standard_name = trim($input['standard_name'] ?? '');
    $standard_code = trim($input['standard_code'] ?? '') ?: null;
    $alias_part_no = trim($input['alias_part_no'] ?? '');
    $vendor_name   = trim($input['vendor_name'] ?? '') ?: null;
    $description   = trim($input['description'] ?? '') ?: null;

    if (empty($standard_name) || empty($alias_part_no)) {
        echo json_encode(["status" => "error", "message" => "대표 파트명과 실제 파트번호는 필수입니다."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($id) {
        $stmt = $pdo->prepare("UPDATE part_alias SET standard_name = ?, standard_code = ?, alias_part_no = ?, vendor_name = ?, description = ? WHERE id = ?");
        $stmt->execute([$standard_name, $standard_code, $alias_part_no, $vendor_name, $description, $id]);
        $msg = "부품 교차 매핑 수정 완료";
    } else {
        $stmt = $pdo->prepare("INSERT INTO part_alias (standard_name, standard_code, alias_part_no, vendor_name, description) 
                                VALUES (?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                standard_name = VALUES(standard_name),
                                standard_code = VALUES(standard_code),
                                vendor_name = VALUES(vendor_name),
                                description = VALUES(description)");
        $stmt->execute([$standard_name, $standard_code, $alias_part_no, $vendor_name, $description]);
        $id = (int)$pdo->lastInsertId();
        $msg = "부품 교차 매핑 등록 완료";
    }

    echo json_encode(["status" => "success", "message" => $msg, "data" => ["id" => $id]], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
