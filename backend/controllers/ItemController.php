<?php
// backend/controllers/ItemController.php

class ItemController {
    /**
     * 1. 품목 목록 조회 (최신 BOM 버전 및 부품 수 집계 포함)
     */
    public static function getItems(): void {
        try {
            $pdo = Database::getConnection();
            $sql = "
                SELECT 
                    i.*,
                    COALESCE(c.name, '-') as company_name,
                    c.code as company_code,
                    bm.bom_id,
                    COALESCE(bm.version, 'v1.0') as bom_version,
                    COALESCE(bd_cnt.part_count, 0) as bom_part_count
                FROM item i
                LEFT JOIN company c ON i.company_id = c.id
                LEFT JOIN (
                    SELECT bom_id, item_id, version
                    FROM bom_master bm1
                    WHERE bom_id = (
                        SELECT MAX(bom_id) FROM bom_master bm2 WHERE bm2.item_id = bm1.item_id
                    )
                ) bm ON i.id = bm.item_id
                LEFT JOIN (
                    SELECT bom_id, COUNT(*) as part_count
                    FROM bom_detail
                    GROUP BY bom_id
                ) bd_cnt ON bm.bom_id = bd_cnt.bom_id
                ORDER BY i.item_code ASC
            ";
            $stmt = $pdo->query($sql);
            Response::json(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 2. 품목 등록
     */
    public static function createItem(): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $company_id  = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $item_code   = trim($input['item_code'] ?? '');
            $item_name   = trim($input['item_name'] ?? '');
            $category    = isset($input['category']) && $input['category'] !== '' ? trim($input['category']) : null;
            $unit        = !empty($input['unit']) ? trim($input['unit']) : 'EA';
            $description = isset($input['description']) && $input['description'] !== '' ? trim($input['description']) : null;

            if ($item_code === '' || $item_name === '') {
                Response::error("품목 코드와 품목명은 필수 입력 항목입니다.");
            }

            $stmt = $pdo->prepare("INSERT INTO item (company_id, item_code, item_name, category, unit, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $item_code, $item_name, $category, $unit, $description]);
            $id = (int)$pdo->lastInsertId();

            Response::json([
                "status" => "success",
                "data" => [
                    "id" => $id,
                    "company_id" => $company_id,
                    "item_code" => $item_code,
                    "item_name" => $item_name
                ]
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Response::error("이미 존재하는 품목 코드입니다.");
            } else {
                Response::error($e->getMessage());
            }
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 3. 품목 수정
     */
    public static function updateItem(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();

            $itemId      = $id ?? ($input['id'] ?? null);
            $company_id  = !empty($input['company_id']) ? (int)$input['company_id'] : null;
            $item_code   = trim($input['item_code'] ?? '');
            $item_name   = trim($input['item_name'] ?? '');
            $category    = isset($input['category']) && $input['category'] !== '' ? trim($input['category']) : null;
            $unit        = !empty($input['unit']) ? trim($input['unit']) : 'EA';
            $description = isset($input['description']) && $input['description'] !== '' ? trim($input['description']) : null;

            if (empty($itemId) || $item_code === '' || $item_name === '') {
                Response::error("필수 입력 항목이 누락되었습니다.");
            }

            $stmt = $pdo->prepare("UPDATE item SET company_id = ?, item_code = ?, item_name = ?, category = ?, unit = ?, description = ? WHERE id = ?");
            $stmt->execute([$company_id, $item_code, $item_name, $category, $unit, $description, $itemId]);

            Response::json(["status" => "success", "message" => "품목이 수정되었습니다."]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Response::error("이미 존재하는 품목 코드입니다.");
            } else {
                Response::error($e->getMessage());
            }
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 4. 품목 삭제
     */
    public static function deleteItem(?string $id = null): void {
        try {
            $pdo = Database::getConnection();
            $input = Request::getBody();
            $itemId = $id ?? ($input['id'] ?? null);

            if (empty($itemId)) {
                Response::error("ID가 누락되었습니다.");
            }

            $stmt = $pdo->prepare("DELETE FROM item WHERE id = ?");
            $stmt->execute([$itemId]);

            Response::json(["status" => "success", "message" => "품목이 삭제되었습니다."]);
        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
